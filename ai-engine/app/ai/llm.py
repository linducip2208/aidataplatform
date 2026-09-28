"""OpenAI-compatible + OpenRouter-compatible LLM client over httpx."""
from __future__ import annotations

import time
from typing import Any, Dict, List, Optional

import httpx

from app.core.config import settings
from app.core.logging import get_logger

log = get_logger("ai.llm", "chat")


def _headers() -> Dict[str, str]:
    key = settings.effective_llm_api_key()
    h = {"Content-Type": "application/json"}
    if key:
        h["Authorization"] = f"Bearer {key}"
    if settings.llm_provider.lower() == "openrouter":
        h["HTTP-Referer"] = "https://aidataplatform.local"
        h["X-Title"] = "AI Data Platform"
    return h


def chat(messages: List[Dict[str, str]], model: Optional[str] = None,
         json_mode: bool = False, tools: Optional[List[Dict]] = None,
         max_tokens: int = 1500, temperature: float = 0.2) -> Dict[str, Any]:
    """Chat completion with retry. Returns {'content': str, 'tool_calls': [...], 'raw': ...}.

    Offline fallback: if no API key or request fails, returns a local echo summary
    so unit tests and dev work without network.
    """
    mdl = model or settings.llm_model
    base = settings.effective_llm_base_url().rstrip("/")
    url = f"{base}/chat/completions"
    payload: Dict[str, Any] = {"model": mdl, "messages": messages,
                               "max_tokens": max_tokens, "temperature": temperature}
    if json_mode:
        payload["response_format"] = {"type": "json_object"}
    if tools:
        payload["tools"] = tools
    if not settings.effective_llm_api_key():
        return {"content": _offline_summary(messages), "tool_calls": [], "raw": {"offline": True}}
    last_err = ""
    for attempt in range(max(1, settings.llm_max_retries)):
        try:
            with httpx.Client(timeout=settings.llm_timeout_seconds) as client:
                r = client.post(url, json=payload, headers=_headers())
                r.raise_for_status()
                data = r.json()
                choice = (data.get("choices") or [{}])[0]
                msg = choice.get("message") or {}
                return {"content": msg.get("content") or "",
                        "tool_calls": msg.get("tool_calls") or [],
                        "raw": data}
        except Exception as exc:
            last_err = str(exc)
            log.error(f"llm chat attempt {attempt+1} failed: {exc}")
            time.sleep(0.5 * (attempt + 1))
    return {"content": _offline_summary(messages) + f"\n\n(note: LLM unreachable: {last_err})",
            "tool_calls": [], "raw": {"offline": True, "error": last_err}}


def embed(texts: List[str], model: Optional[str] = None) -> List[List[float]]:
    """Embeddings via API with hashlib fallback (offline deterministic vectors)."""
    if not texts:
        return []
    mdl = model or settings.llm_embedding_model
    if settings.effective_llm_api_key():
        try:
            base = settings.effective_llm_base_url().rstrip("/")
            with httpx.Client(timeout=settings.llm_timeout_seconds) as client:
                r = client.post(f"{base}/embeddings", json={"model": mdl, "input": texts}, headers=_headers())
                r.raise_for_status()
                data = r.json()
                return [d["embedding"] for d in data.get("data", [])]
        except Exception as exc:
            log.error(f"embeddings failed, fallback: {exc}")
    return [_hash_embed(t) for t in texts]


def _hash_embed(text: str, dim: int = 128) -> List[float]:
    import hashlib

    vec = [0.0] * dim
    for tok in str(text).lower().split():
        h = int(hashlib.md5(tok.encode()).hexdigest(), 16)
        vec[h % dim] += 1.0
    n = sum(v * v for v in vec) ** 0.5 or 1.0
    return [v / n for v in vec]


def _offline_summary(messages: List[Dict[str, str]]) -> str:
    last = messages[-1].get("content", "") if messages else ""
    return f"[offline-llm] Ringkasan: {last[:500]}"
