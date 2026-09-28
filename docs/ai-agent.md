# AI Agent

Conversational analyst over datasets + warehouse. Stateless HTTP + optional server memory.
Queue: `agent`. LLM via `LLM_PROVIDER` (default OpenRouter).

## 1. Chat

```bash
curl -X POST http://fastapi:8000/api/v1/agent/chat -H "X-Service-Key: $SERVICE_API_KEY" \
 -H 'Content-Type: application/json' \
 -d '{"dataset_id":"<uuid>","message":"Top 3 churn drivers last quarter?"}'
# -> {reply, tool_calls:[...], usage:{prompt,completion}}
```

Pass `session_id` to continue a thread (memory in `ai.chat_memory`, 50-turn window).
Omit `dataset_id` for general Q&A (no data tools). UI: **Agent** page (Laravel proxies
with user auth; service key added server-side, never in browser).

## 2. Tools (what the agent can do)

| Tool | Reads | Guard |
|---|---|---|
| `sql_query` (read-only) | `warehouse`/`analytics` | allowlisted schemas, `SELECT` only, 5 s timeout, 1 k row cap |
| `quality_lookup` | `warehouse.quality_reports` | none (read) |
| `model_lookup` | `ml.registry` (production only) | hides draft metrics from viewers |
| `rag_search` | `ai.embeddings` | scoped to `dataset_id` |

Writes are forbidden — the agent proposes, the user confirms via UI actions.

## 3. Config

`.env`: `LLM_PROVIDER=openrouter`, `LLM_MODEL=anthropic/claude-3.5-sonnet`,
`LLM_FALLBACK_MODEL=openai/gpt-4o-mini`, `LLM_TEMPERATURE=0.2`, `LLM_MAX_TOKENS=4096`,
`LLM_TIMEOUT_S=120`. Swap provider without code change (abstraction in ai-engine).
Temperature low (0.2) for analytical answers; raise for brainstorming via per-call override.

## 4. Safety

- Prompt-injection: system prompt frames data as untrusted; `sql_query` allowlist blocks
  DDL/DML; output filter strips suspected exfil patterns (see `security.md`).
- Cost: `usage` returned per call; Beat aggregates daily spend estimate to Prometheus.
- Abuse: per-user rate limit in Laravel (60 msg/min analyst, 600 admin).

## 5. Debug

`tool_calls` in every response shows exact SQL/RAG queries — replay manually to verify.
Logs: `celery-worker` (`agent` queue) + FastAPI structured logs with `session_id`.
Stuck/long replies: client timeout 120 s; heavy questions fan out to Beat-scheduled summaries.

## 6. Session memory model

`ai.chat_memory(session_id, turn, role, content)` keeps the last 50 turns; older turns
summarized by Beat into a rolling summary row (same table, `role='summary'`). New
`session_id` (uuid v4, client-generated) starts a clean thread. Delete a thread via
`DELETE /api/v1/agent/sessions/{id}` (service key; Laravel exposes per-user delete).
Memory is scoped per user + dataset — never shared across users.

## 7. Multi-dataset questions

Pass `dataset_ids: ["<a>", "<b>"]` (plural) to compare sources; the agent runs
`sql_query` per dataset and merges. Keep to ≤ 3 datasets per call for latency.
Cross-dataset joins are not auto-generated — define them as `analytics` marts first
(see `data-dictionary.md`), then point the agent at the mart.

## 8. Cost + rate control

Each reply returns `usage:{prompt_tokens, completion_tokens, model}`; Laravel logs it
per user for chargeback. Beat aggregates `agent_tokens_total` to Prometheus daily.
Caps: `LLM_MAX_TOKENS=4096` per reply, 60 msg/min analyst / 600 admin (Laravel throttle
middleware). On `429` from provider, ai-engine retries once on `LLM_FALLBACK_MODEL`,
then returns `503 {detail: llm_unavailable}` — UI shows "try again shortly".
