"""OpenAI-compatible + OpenRouter-compatible LLM client over httpx.

Every public call degrades instead of raising: with no API key, or when the provider is
unreachable, :func:`chat` returns a grounded-by-nothing offline marker with
``offline=True`` so the caller can build its own answer from real data instead of showing a
model error.

Four properties this module guarantees, each of them verified against a stub server:

* **No credential leaves the module.** The key is read in :func:`_headers` and nowhere else,
  it is only ever attached to a request whose URL passed the scheme and userinfo checks in
  :func:`_base_url`, and :func:`_scrub` removes it from any string that reaches a log line or
  a return value. The provider's own body is never handed back either: ``raw`` is reduced to
  a fixed set of non-sensitive fields, so a provider that echoes the key it was sent cannot
  reflect it into an answer, an ``ai_messages`` row or a report.
* **Retries back off instead of amplifying.** A 429 or 503 is retried after an exponential
  wait that honours ``Retry-After``; a 400/401/403/404 is not retried at all, because
  repeating a request the provider has already rejected only adds load.
* **A hung provider cannot pin a worker.** The per-attempt timeout is set on the client and
  the whole loop is additionally bounded by :data:`MAX_TOTAL_SECONDS`.
* **An empty or filtered completion is a degradation, not an answer.** A blank body, a
  ``content_filter`` stop and a body that is not a JSON object all return ``offline=True``.
"""
from __future__ import annotations

import hashlib
import random
import re
import time
from typing import Any, Dict, List, Optional, Sequence, Tuple
from urllib.parse import urlsplit, urlunsplit

import httpx

from app.core.config import settings
from app.core.logging import get_logger

log = get_logger("ai.llm", "chat")

EMBED_BATCH_SIZE = 64
MAX_EMBED_TEXTS = 512
MAX_RETRIES_CEILING = 5
HASH_EMBED_DIM = 128
HASH_EMBED_MAX_TOKENS = 20000
OFFLINE_NOTE = "[offline-llm] Model bahasa tidak dikonfigurasi atau tidak terjangkau."

# A provider that answers with 200 KB of text is not producing an answer. The body is
# rejected before it is parsed so a runaway completion cannot become an ai_messages row, a
# report narrative or a Celery result payload.
MAX_RESPONSE_BYTES = 512 * 1024
MAX_CONTENT_CHARS = 20000
# Upper bound for the whole retry loop. The per-attempt timeout alone would let a worker
# thread sit for attempts x timeout, which is 25 minutes at the configured maxima.
MAX_TOTAL_SECONDS = 120.0
MAX_BACKOFF_SECONDS = 8.0
RETRY_BASE_SECONDS = 0.5
# Statuses worth repeating. Everything else in 4xx is the provider rejecting the request
# itself: a second identical request gets the same rejection and doubles the load.
RETRYABLE_STATUS = frozenset({408, 409, 425, 429, 500, 502, 503, 504})
# finish_reason values that mean "the model declined", not "the model answered".
REFUSAL_REASONS = frozenset({"content_filter"})
ALLOWED_SCHEMES = frozenset({"http", "https"})

# --- provider registry -------------------------------------------------------
# Logical providers the engine knows how to talk to. "openai-compatible" and
# "openrouter" are both OpenAI-compatible HTTP dialects resolved from settings;
# "offline" is the degraded no-network provider, which never sends a request.
# ``resolve_provider`` maps the free-form LLM_PROVIDER setting onto this
# registry so an unknown value degrades to offline instead of raising.
PROVIDERS = {
    "openai-compatible": {"kind": "http", "auth": "bearer", "dialect": "openai"},
    "openai": {"kind": "http", "auth": "bearer", "dialect": "openai"},
    "openrouter": {"kind": "http", "auth": "bearer", "dialect": "openai",
                   "extra_headers": ("HTTP-Referer", "X-Title")},
    "offline": {"kind": "local", "auth": "none", "dialect": "none"},
}

# Canonical insufficient-data answer. Returned whenever a grounded synthesis
# has no evidence to stand on: a missing number is always preferable to an
# invented one, and every caller funnels through here so the wording is stable.
INSUFFICIENT_DATA_ANSWER = (
    "Data belum tersedia: tidak ada evidence yang mendukung jawaban atas "
    "pertanyaan ini, sehingga tidak ada angka yang dapat ditampilkan.")
_FALLBACK_PROVIDER_NOTE = "fallback"


def resolve_provider(name: Any = None) -> str:
    """Map a provider name onto the registry, defaulting to ``offline``.

    Unknown, blank or non-string values resolve to ``"offline"`` rather than
    raising, because provider resolution runs on the chat hot path where an
    exception would turn a misconfigured (but answerable-from-data) turn into
    a 500.
    """
    key = str(name if name is not None else settings.llm_provider or "").strip().lower()
    if key in PROVIDERS:
        return key
    if key in ("oai", "open-ai"):
        return "openai-compatible"
    return "offline"


def _api_key() -> str:
    """The effective key, or "" when none is configured. Never logged, never returned."""
    return str(settings.effective_llm_api_key() or "")


def _scrub(value: Any) -> Any:
    """Return ``value`` with the configured API key removed from any string inside it.

    A defensive last line, not the primary control: an ``httpx`` traceback embeds the
    request headers, and a provider error body can echo the bearer token it received, so
    every string that leaves this module goes through here first.
    """
    key = _api_key()
    if not key:
        return value
    if isinstance(value, str):
        return value.replace(key, "***")
    if isinstance(value, dict):
        return {k: _scrub(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_scrub(v) for v in value]
    return value


def _base_url() -> str:
    """Return the provider base URL, or "" when it is unusable.

    Three checks matter for the key. A blank ``LLM_BASE_URL`` is refused outright rather
    than falling through ``config.effective_llm_base_url`` to ``openai_base_url``: a blank
    value is almost always a failed ``${VAR}`` substitution in compose, and the fallback
    would ship the bearer token to api.openai.com, which is not where a self-hosted
    provider operator believes they are talking. Any ``user:password@`` userinfo is
    stripped, because it would otherwise ride along in an exception message and in every
    request this module makes. A scheme that is not http(s) rejects the URL rather than
    letting httpx attach the ``Authorization`` header to it.
    """
    if settings.llm_provider.lower() != "openrouter" and not str(settings.llm_base_url or "").strip():
        return ""
    raw = str(settings.effective_llm_base_url() or "").strip()
    if not raw:
        return ""
    try:
        parts = urlsplit(raw)
    except ValueError:
        return ""
    if parts.scheme.lower() not in ALLOWED_SCHEMES or not parts.netloc:
        return ""
    netloc = parts.netloc.rsplit("@", 1)[-1]
    return urlunsplit((parts.scheme, netloc, parts.path, parts.query, parts.fragment))


def _headers() -> Dict[str, str]:
    """Build the outbound auth headers. The key is read from settings, never logged."""
    h = {"Content-Type": "application/json"}
    if _api_key():
        h["Authorization"] = f"Bearer {_api_key()}"
    if settings.llm_provider.lower() == "openrouter":
        h["HTTP-Referer"] = "https://aidataplatform.local"
        h["X-Title"] = "AI Data Platform"
    return h


def _error_summary(exc: BaseException) -> str:
    """Describe a provider failure without leaking the URL, headers or response body."""
    status = getattr(getattr(exc, "response", None), "status_code", None)
    if status is not None:
        return str(_scrub(f"{type(exc).__name__} http_status={status}"))
    return type(exc).__name__


def _attempts() -> int:
    """Number of attempts to make, clamped so a bad env value cannot spin."""
    return max(1, min(MAX_RETRIES_CEILING, int(settings.llm_max_retries or 1)))


def _timeout() -> float:
    """Per-attempt timeout in seconds, clamped to a sane range."""
    return max(1.0, min(300.0, float(settings.llm_timeout_seconds or 60)))


def _retry_after_seconds(response: Optional[httpx.Response]) -> float:
    """Read a delta-seconds ``Retry-After``, or 0.0 when the provider sent none."""
    if response is None:
        return 0.0
    raw = (response.headers.get("Retry-After") or "").strip()
    try:
        return max(0.0, float(raw))
    except ValueError:
        return 0.0


def _backoff(attempt: int, retry_after: float = 0.0) -> float:
    """Seconds to wait before the next attempt: exponential, jittered, header-aware.

    The jitter matters on a rate-limited provider: without it every concurrent worker
    retries on the same schedule and the requests arrive as a burst. ``Retry-After`` wins
    when the provider sent one, because it is the only value that knows the real quota
    window.
    """
    wait = max(retry_after, RETRY_BASE_SECONDS * (2 ** max(0, attempt)))
    return min(MAX_BACKOFF_SECONDS, wait) * (0.5 + random.random() / 2)


def _offline(message: str, error: str = "", provider: str = "offline",
             fallback_steps: Optional[List[str]] = None) -> Dict[str, Any]:
    """Return the offline completion shape with no provider text in it."""
    raw: Dict[str, Any] = {"offline": True, "provider": provider or "offline"}
    if error:
        raw["error"] = str(_scrub(error))
    if fallback_steps:
        raw["fallback_steps"] = list(fallback_steps)
    return {"content": message, "tool_calls": [], "raw": raw, "offline": True}


def _summary(data: Dict[str, Any], content: str) -> Dict[str, Any]:
    """Reduce a parsed completion to the non-sensitive fields worth keeping.

    The provider's full body is deliberately dropped rather than returned: it can be
    arbitrarily large, and an upstream that quotes the ``Authorization`` header back in an
    error envelope would otherwise put the key into ``raw`` and from there into a log line,
    an answer or a report.
    """
    choice = (data.get("choices") or [{}])[0]
    if not isinstance(choice, dict):
        choice = {}
    usage = data.get("usage")
    return {
        "id": str(data.get("id") or "")[:128],
        "model": str(data.get("model") or "")[:128],
        "finish_reason": str(choice.get("finish_reason") or "")[:64],
        "content_chars": len(content),
        "usage": _scrub(usage) if isinstance(usage, dict) else {},
        "truncated": len(content) >= MAX_CONTENT_CHARS,
        "provider": resolve_provider(),
    }


def _parse_completion(r: httpx.Response) -> Tuple[str, List[Any], Dict[str, Any]]:
    """Return ``(content, tool_calls, summary)`` or raise ``ValueError`` for a bad body.

    The body is checked three times before it is used: it must be within the size cap, it
    must parse as JSON, and it must be a JSON *object*. A body that is a JSON array or a
    bare string parses fine and then has no ``.get``, which used to surface as an
    ``AttributeError`` from inside the retry loop.
    """
    declared = r.headers.get("Content-Length")
    if declared and declared.isdigit() and int(declared) > MAX_RESPONSE_BYTES:
        raise ValueError("response_too_large")
    body = r.content
    if len(body) > MAX_RESPONSE_BYTES:
        raise ValueError("response_too_large")
    try:
        data = r.json()
    except ValueError:
        raise ValueError("invalid_json") from None
    if not isinstance(data, dict):
        raise ValueError("unexpected_payload")
    choices = data.get("choices")
    if not isinstance(choices, list) or not choices or not isinstance(choices[0], dict):
        raise ValueError("no_choices")
    msg = choices[0].get("message")
    if not isinstance(msg, dict):
        raise ValueError("no_message")
    finish = str(choices[0].get("finish_reason") or "")
    if finish in REFUSAL_REASONS:
        raise ValueError("refused")
    content = msg.get("content")
    if not isinstance(content, str) or not content.strip():
        # An empty body is a degradation. Reporting it as a successful completion is how a
        # refusal or a truncated response reaches the browser as if it were an answer.
        raise ValueError("empty_content")
    content = content[:MAX_CONTENT_CHARS]
    tool_calls = msg.get("tool_calls")
    if not isinstance(tool_calls, list):
        tool_calls = []
    return content, tool_calls, _summary(data, content)


def chat(messages: List[Dict[str, str]], model: Optional[str] = None,
          json_mode: bool = False, tools: Optional[List[Dict]] = None,
          max_tokens: int = 1500, temperature: float = 0.2,
          required_keys: Optional[Sequence[str]] = None) -> Dict[str, Any]:
    """Run one chat completion.

    Returns ``{"content": str, "tool_calls": list, "raw": dict, "offline": bool}``. When no
    API key is configured, the base URL is unusable, or every attempt fails, ``offline`` is
    ``True`` and ``content`` is :data:`OFFLINE_NOTE` — a caller must then answer from its own
    data instead of showing this string. The same is true of an empty, oversized or filtered
    completion, which are degradations rather than answers. The provider's error text is
    reduced to an exception type and HTTP status and lives only in ``raw["error"]`` and the
    log.

    ``required_keys`` enables response validation for structured outputs: when given, the
    content must parse as a JSON object containing every listed key, otherwise the
    completion is treated as malformed and degrades to offline rather than handing a
    half-shaped object to the caller.
    """
    mdl = model or settings.llm_model
    base = _base_url()
    if not _api_key():
        return _offline(OFFLINE_NOTE)
    if not base:
        log.error("llm chat skipped: LLM_BASE_URL is empty or not an http(s) URL")
        return _offline(OFFLINE_NOTE, error="invalid_base_url")
    url = f"{base.rstrip('/')}/chat/completions"
    payload: Dict[str, Any] = {"model": mdl, "messages": messages,
                               "max_tokens": max(1, int(max_tokens)), "temperature": temperature}
    if json_mode:
        payload["response_format"] = {"type": "json_object"}
    if tools:
        payload["tools"] = tools

    attempts = _attempts()
    last_err = "not attempted"
    deadline = time.monotonic() + MAX_TOTAL_SECONDS
    with httpx.Client(timeout=_timeout()) as client:
        for attempt in range(attempts):
            try:
                r = client.post(url, json=payload, headers=_headers())
                r.raise_for_status()
                content, tool_calls, summary = _parse_completion(r)
                if required_keys:
                    parsed, problem = validate_structured(content, required_keys)
                    if parsed is None:
                        last_err = f"malformed_response {problem}"
                        log.error(f"llm chat attempt {attempt + 1}/{attempts} failed: {last_err}")
                        return _offline(OFFLINE_NOTE, error=last_err)
                return {"content": content, "tool_calls": tool_calls,
                        "raw": summary, "offline": False}
            except httpx.HTTPStatusError as exc:
                last_err = _error_summary(exc)
                status = exc.response.status_code
                # A 4xx the provider will answer identically forever is not retried; that
                # is load added to a rate-limited or rejecting provider for no gain.
                if status not in RETRYABLE_STATUS:
                    log.error(f"llm chat rejected (not retried): {last_err}")
                    return _offline(OFFLINE_NOTE, error=last_err)
                log.error(f"llm chat attempt {attempt + 1}/{attempts} failed: {last_err}")
                wait = _backoff(attempt, _retry_after_seconds(exc.response))
            except ValueError as exc:
                # A malformed body does not become well-formed on a retry, and the request
                # already succeeded, so repeating it only multiplies provider load.
                last_err = f"malformed_response {exc}"
                log.error(f"llm chat attempt {attempt + 1}/{attempts} failed: {last_err}")
                return _offline(OFFLINE_NOTE, error=last_err)
            except Exception as exc:
                last_err = _error_summary(exc)
                log.error(f"llm chat attempt {attempt + 1}/{attempts} failed: {last_err}")
                wait = _backoff(attempt)
            if attempt >= attempts - 1 or time.monotonic() + wait > deadline:
                break
            time.sleep(wait)
    return _offline(OFFLINE_NOTE, error=last_err)


def chat_with_fallback(messages: List[Dict[str, str]],
                       primary: Optional[str] = None,
                       fallback: Optional[str] = None,
                       **kwargs: Any) -> Dict[str, Any]:
    """Run :func:`chat` on a primary→fallback→offline chain, logging each step.

    ``primary`` defaults to the configured model; ``fallback`` defaults to the
    same model (i.e. one more attempt through the normal retry loop) unless a
    distinct fallback model is configured. Every step is logged at warning
    level on failure, and the returned ``raw`` carries ``fallback_steps`` — the
    ordered model names that were tried — so a degraded answer is auditable.
    The final offline marker has ``provider: "offline"`` and content
    :data:`OFFLINE_NOTE`, exactly like :func:`chat` on total failure.
    """
    from app.core.config import settings as _settings

    steps: List[str] = []
    first = primary or _settings.llm_model
    second = fallback
    for attempt_no, mdl in enumerate([m for m in (first, second) if m]):
        steps.append(str(mdl))
        try:
            out = chat(messages, model=mdl, **kwargs)
        except Exception as exc:
            log.warning(f"llm fallback step {attempt_no + 1} ({mdl}) raised: "
                        f"{type(exc).__name__}")
            continue
        if isinstance(out, dict) and not out.get("offline"):
            raw = dict(out.get("raw") or {})
            raw["fallback_steps"] = steps
            out["raw"] = raw
            return out
        log.warning(f"llm fallback step {attempt_no + 1} ({mdl}) degraded: "
                    f"{(out or {}).get('raw', {}).get('error', 'offline')}")
    log.error(f"llm fallback chain exhausted after {len(steps)} step(s)")
    return _offline(OFFLINE_NOTE, error="fallback_chain_exhausted",
                    provider=_FALLBACK_PROVIDER_NOTE, fallback_steps=steps)


def validate_structured(content: Any, required_keys: Sequence[str]) -> Tuple[Any, str]:
    """JSON-shape check for structured (``json_mode``) outputs.

    Returns ``(parsed, "")`` when ``content`` parses as a JSON object holding
    every key in ``required_keys``, else ``(None, <reason>)`` where reason is
    one of ``not_json``, ``not_object`` or ``missing_keys:<a,b>``. Fenced code
    blocks are tolerated; anything else is the model's problem, reported — not
    repaired — so a caller never acts on a guessed shape.
    """
    import json as _json
    import re as _re

    text = str(content or "").strip()
    if text.startswith("```"):
        text = _re.sub(r"^```[a-zA-Z]*\s*", "", text)
        text = _re.sub(r"\s*```$", "", text).strip()
    start, end = text.find("{"), text.rfind("}")
    if start < 0 or end <= start:
        return None, "not_json"
    try:
        parsed = _json.loads(text[start:end + 1])
    except Exception:
        return None, "not_json"
    if not isinstance(parsed, dict):
        return None, "not_object"
    missing = [k for k in required_keys if k not in parsed]
    if missing:
        return None, "missing_keys:" + ",".join(missing)
    return parsed, ""


_NUMBER_RE = re.compile(
    r"(?<![\w.])\d[\d.,]*(?:\s*%\s*%?)?(?![\w%])")


def numbers_in(text: Any) -> List[str]:
    """Return the number-like tokens in ``text``, normalised for comparison.

    Thousands separators and surrounding whitespace are stripped so ``"1,250"``
    and ``"1250"`` compare equal; a trailing ``%`` is kept because ``12`` and
    ``12%`` are different claims. Deterministic and locale-free by design.
    """
    out: List[str] = []
    for match in _NUMBER_RE.finditer(str(text or "")):
        token = re.sub(r"[\s,]", "", match.group(0)).strip()
        if token:
            out.append(token)
    return out


def unverified_numbers(content: Any, evidence_texts: Sequence[Any]) -> List[str]:
    """Return numbers in ``content`` that appear in none of ``evidence_texts``.

    Comparison is substring on the normalised forms from :func:`numbers_in`.
    An empty list means every figure the answer states was fetched, not
    invented — the check the agent runs before it trusts a model synthesis.
    """
    corpus = " ".join(str(t or "") for t in evidence_texts)
    normalised_corpus = re.sub(r"[\s,]", "", corpus)
    return [n for n in numbers_in(content)
            if re.sub(r"[\s,]", "", n) not in normalised_corpus]


def refuse_no_evidence(query: Any = "") -> str:
    """Return the canonical insufficient-data answer for an evidence-less turn."""
    question = str(query or "").strip()
    if question:
        return (f"{INSUFFICIENT_DATA_ANSWER} (pertanyaan: "
                f"{question[:200]})")
    return INSUFFICIENT_DATA_ANSWER


def ensure_grounded(content: Any, evidence_texts: Sequence[Any],
                    query: Any = "") -> Tuple[str, bool, List[str]]:
    """Enforce the no-evidence policy on a model synthesis.

    Returns ``(answer, grounded, unverified)``. With no evidence at all the
    answer is replaced by :func:`refuse_no_evidence` — never an invented
    number. With evidence, numbers that cannot be found in it are reported in
    ``unverified`` and the caller (the agent) decides; the text itself is left
    untouched here so this function stays a pure check.
    """
    texts = [str(t or "") for t in evidence_texts if str(t or "").strip()]
    if not texts:
        return refuse_no_evidence(query), False, numbers_in(content)
    return str(content or ""), True, unverified_numbers(content, texts)


def embed(texts: List[str], model: Optional[str] = None) -> List[List[float]]:
    """Return one embedding vector per input text, in input order.

    Calls the provider in batches when a key is configured. Any batch that fails, or that
    comes back short or malformed, falls back to the deterministic :func:`_hash_embed` for
    exactly those texts, so the result always has the same length as the input. A batch that
    did succeed keeps its vectors: one failed batch out of eight must not silently discard
    the other seven. Without a key the whole batch is hashed, which is why the platform still
    works offline.
    """
    if not texts:
        return []
    items = [str(t) for t in texts]
    # Only the first MAX_EMBED_TEXTS go to the provider; the tail is hashed so the
    # returned list always has exactly one vector per input, in input order.
    provider_items = items[:MAX_EMBED_TEXTS]
    mdl = model or settings.llm_embedding_model
    vectors: List[List[float]] = []
    filled = 0

    if _api_key() and provider_items:
        base = _base_url()
        if not base:
            log.error("embeddings skipped: LLM_BASE_URL is empty or not an http(s) URL")
        else:
            try:
                with httpx.Client(timeout=_timeout()) as client:
                    for start in range(0, len(provider_items), EMBED_BATCH_SIZE):
                        batch = provider_items[start:start + EMBED_BATCH_SIZE]
                        try:
                            r = client.post(f"{base.rstrip('/')}/embeddings",
                                            json={"model": mdl, "input": batch},
                                            headers=_headers())
                            r.raise_for_status()
                            data = r.json()
                        except Exception as exc:
                            # Per batch, not per call: the batches that already returned
                            # keep their vectors instead of the whole ingest degrading.
                            log.error(f"embeddings batch {start // EMBED_BATCH_SIZE} failed, "
                                      f"hashing those texts: {_error_summary(exc)}")
                            break
                        rows = data.get("data") if isinstance(data, dict) else None
                        if not isinstance(rows, list):
                            log.error(f"embeddings batch {start // EMBED_BATCH_SIZE} returned an "
                                      f"unexpected payload, hashing those texts")
                            break
                        # A provider may return rows out of order, or fewer than asked for, so
                        # the position is taken from "index" and every text gets one slot.
                        by_index: Dict[int, List[float]] = {}
                        dim = 0
                        for pos, row in enumerate(rows):
                            if not isinstance(row, dict):
                                continue
                            idx = row.get("index")
                            if not isinstance(idx, int) or not 0 <= idx < len(batch):
                                idx = pos
                            vec = row.get("embedding")
                            if not isinstance(vec, list) or not vec:
                                continue
                            values = [float(x) for x in vec if isinstance(x, (int, float))]
                            if len(values) != len(vec):
                                continue
                            if not dim:
                                dim = len(values)
                            elif len(values) != dim:
                                # A ragged batch would write two different widths into the
                                # embedding column; the offending row is hashed instead.
                                continue
                            by_index[idx] = values
                        for i in range(len(batch)):
                            vec = by_index.get(i) or []
                            filled += 1 if vec else 0
                            vectors.append(vec)
            except Exception as exc:
                log.error(f"embeddings transport failed, hashing the rest: {_error_summary(exc)}")

    if len(vectors) < len(items):
        vectors.extend([] for _ in range(len(items) - len(vectors)))
    if filled < len(items):
        log.warning(f"embeddings returned {filled}/{len(items)} usable vectors, hashing the rest")
    return [v if v else _hash_embed(items[i]) for i, v in enumerate(vectors)]


def _hash_embed(text: str, dim: int = HASH_EMBED_DIM) -> List[float]:
    """Return a deterministic normalised bag-of-words vector for offline use.

    The token walk is capped so one pathological document cannot turn an offline ingest
    into a CPU burn. The dimension is deliberately small; callers must pad it to the
    ``rag_chunks.embedding`` column width before writing.
    """
    vec = [0.0] * dim
    for i, tok in enumerate(str(text).lower().split()):
        if i >= HASH_EMBED_MAX_TOKENS:
            break
        h = int(hashlib.md5(tok.encode("utf-8", "replace")).hexdigest(), 16)
        vec[h % dim] += 1.0
    n = sum(v * v for v in vec) ** 0.5 or 1.0
    return [v / n for v in vec]
