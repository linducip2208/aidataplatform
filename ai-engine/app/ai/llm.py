"""OpenAI-compatible + OpenRouter-compatible LLM client over httpx.

Every public call degrades instead of raising: with no API key, or when the provider is
unreachable, :func:`chat` returns a grounded-by-nothing offline marker with
``offline=True`` so the caller can build its own answer from real data instead of showing a
model error. The API key, the base URL and the provider's error text never leave this module
in a log line or a response body: failures are reported by exception type and HTTP status
only.
"""
from __future__ import annotations

import hashlib
import time
from typing import Any, Dict, List, Optional

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


def _headers() -> Dict[str, str]:
    """Build the outbound auth headers. The key is read from settings, never logged."""
    key = settings.effective_llm_api_key()
    h = {"Content-Type": "application/json"}
    if key:
        h["Authorization"] = f"Bearer {key}"
    if settings.llm_provider.lower() == "openrouter":
        h["HTTP-Referer"] = "https://aidataplatform.local"
        h["X-Title"] = "AI Data Platform"
    return h


def _error_summary(exc: BaseException) -> str:
    """Describe a provider failure without leaking the URL, headers or response body."""
    status = getattr(getattr(exc, "response", None), "status_code", None)
    if status is not None:
        return f"{type(exc).__name__} http_status={status}"
    return type(exc).__name__


def _attempts() -> int:
    """Number of attempts to make, clamped so a bad env value cannot spin."""
    return max(1, min(MAX_RETRIES_CEILING, int(settings.llm_max_retries or 1)))


def _timeout() -> float:
    """Per-attempt timeout in seconds, clamped to a sane range."""
    return max(1.0, min(300.0, float(settings.llm_timeout_seconds or 60)))


def _offline(message: str, error: str = "") -> Dict[str, Any]:
    """Return the offline completion shape with no provider text in it."""
    raw: Dict[str, Any] = {"offline": True}
    if error:
        raw["error"] = error
    return {"content": message, "tool_calls": [], "raw": raw, "offline": True}


def chat(messages: List[Dict[str, str]], model: Optional[str] = None,
         json_mode: bool = False, tools: Optional[List[Dict]] = None,
         max_tokens: int = 1500, temperature: float = 0.2) -> Dict[str, Any]:
    """Run one chat completion.

    Returns ``{"content": str, "tool_calls": list, "raw": dict, "offline": bool}``. When no
    API key is configured, or every attempt fails, ``offline`` is ``True`` and ``content``
    is :data:`OFFLINE_NOTE` — a caller must then answer from its own data instead of showing
    this string. The provider's error text is reduced to an exception type and HTTP status
    and lives only in ``raw["error"]`` and the log.
    """
    mdl = model or settings.llm_model
    base = settings.effective_llm_base_url().rstrip("/")
    url = f"{base}/chat/completions"
    payload: Dict[str, Any] = {"model": mdl, "messages": messages,
                               "max_tokens": max(1, int(max_tokens)), "temperature": temperature}
    if json_mode:
        payload["response_format"] = {"type": "json_object"}
    if tools:
        payload["tools"] = tools
    if not settings.effective_llm_api_key():
        return _offline(OFFLINE_NOTE)

    attempts = _attempts()
    last_err = "not attempted"
    with httpx.Client(timeout=_timeout()) as client:
        for attempt in range(attempts):
            try:
                r = client.post(url, json=payload, headers=_headers())
                r.raise_for_status()
                data = r.json()
                choice = (data.get("choices") or [{}])[0]
                msg = choice.get("message") or {}
                return {"content": msg.get("content") or "",
                        "tool_calls": msg.get("tool_calls") or [],
                        "raw": data, "offline": False}
            except Exception as exc:
                last_err = _error_summary(exc)
                log.error(f"llm chat attempt {attempt + 1}/{attempts} failed: {last_err}")
                if attempt < attempts - 1:
                    time.sleep(0.5 * (attempt + 1))
    return _offline(OFFLINE_NOTE, error=last_err)


def embed(texts: List[str], model: Optional[str] = None) -> List[List[float]]:
    """Return one embedding vector per input text, in input order.

    Calls the provider in batches when a key is configured. Any batch that fails, or that
    comes back short or malformed, falls back to the deterministic :func:`_hash_embed` for
    exactly those texts, so the result always has the same length as the input. Without a
    key the whole batch is hashed, which is why the platform still works offline.
    """
    if not texts:
        return []
    items = [str(t) for t in texts]
    # Only the first MAX_EMBED_TEXTS go to the provider; the tail is hashed so the
    # returned list always has exactly one vector per input, in input order.
    provider_items = items[:MAX_EMBED_TEXTS]
    mdl = model or settings.llm_embedding_model
    vectors: List[List[float]] = []

    if settings.effective_llm_api_key() and provider_items:
        base = settings.effective_llm_base_url().rstrip("/")
        try:
            with httpx.Client(timeout=_timeout()) as client:
                for start in range(0, len(provider_items), EMBED_BATCH_SIZE):
                    batch = provider_items[start:start + EMBED_BATCH_SIZE]
                    r = client.post(f"{base}/embeddings", json={"model": mdl, "input": batch},
                                    headers=_headers())
                    r.raise_for_status()
                    rows = r.json().get("data") or []
                    # A provider may return rows out of order, or fewer than asked for, so
                    # the position is taken from "index" and every text gets one slot.
                    by_index: Dict[int, List[float]] = {}
                    for pos, row in enumerate(rows):
                        idx = row.get("index")
                        if not isinstance(idx, int) or not 0 <= idx < len(batch):
                            idx = pos
                        vec = row.get("embedding")
                        if isinstance(vec, list) and vec:
                            by_index[idx] = [float(x) for x in vec if isinstance(x, (int, float))]
                    for i in range(len(batch)):
                        vectors.append(by_index.get(i, []))
        except Exception as exc:
            log.error(f"embeddings failed, falling back to hash vectors: {_error_summary(exc)}")
            return [_hash_embed(t) for t in items]

    if len(vectors) < len(items):
        vectors.extend([] for _ in range(len(items) - len(vectors)))
    filled = sum(1 for v in vectors if v)
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
