# AI Agent

Conversational analyst over the warehouse. One engine endpoint, `POST /api/v1/ai/chat`, proxied
by Laravel as `POST /api/agent/chat`. The agent is stateless per request; conversation history
lives in `ai_conversations` / `ai_messages`, mirrored into `chat_threads` /
`chat_messages` by Laravel.

## 1. Chat

Through Laravel, with a bearer token:

```bash
curl -s -X POST http://localhost:8080/api/agent/chat \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"message":"Top 3 penyebab churn kuartal terakhir?"}'
```

```json
{"data": {"reply": "...", "answer": "...", "conversation_id": 12, "evidence": [...], "steps": 4}}
```

`reply` and `answer` carry the same string; `reply` is an alias kept for older clients. The
response also logs an `agent.chat` audit row with the message length and the step count.

`message` is required, max 4000 characters. `conversation_id` is optional and must be an
**integer** — the engine's `ai_conversations.id`. Omit it to start a new conversation; the
engine creates one and returns its id. Continue an existing thread by passing that id back.

Straight at the engine, with the service key:

```bash
curl -s -X POST http://localhost:8001/api/v1/ai/chat \
  -H "X-Service-Key: $SERVICE_API_KEY" -H 'Content-Type: application/json' \
  -d '{"message":"How is revenue trending?","conversation_id":12,"context":{}}'
```

`context` is accepted and currently unused. The engine returns
`{answer, conversation_id, evidence, steps}` with no `reply` alias.

There is no rate limiting on this endpoint in Laravel and no per-user quota: every
authenticated role, `viewer` included, may ask questions. The engine applies a fail-closed
sliding window of `RATE_LIMIT_PER_MINUTE` (default 120) per credential and three times that per
path, so an assistant tab left open on a dashboard can be throttled by a single busy page. A
`429` from the provider or from that limiter surfaces to the browser as `502`, with
`code: RATE_LIMITED` in the body when the limiter is the cause.

## 2. How a turn is executed

1. `parse_intent` maps Indonesian and English keywords to at most 8 tool names. It appends
   `get_kpi` and `query_sales` when nothing matches, so every question gets an answer.
2. Each tool runs against the database and returns `{source, data}`. They are collected into
   `evidence[]`; a tool that raises contributes `{"source": <name>, "data": {"error": "..."}}`
   instead of aborting the turn.
3. The evidence is serialised (truncated to 6000 characters) and passed to the LLM with a
   system prompt that forbids inventing numbers, requires Indonesian, and requires saying the
   data is unavailable when the evidence is empty.
4. On LLM failure or an empty completion, `_fallback_answer` assembles the answer directly
   from the evidence and labels it `LLM offline`. This is why the assistant returns something
   plausible with no API key configured.
5. The user message and the answer are appended to `ai_conversations` /
   `ai_messages` (`role` `user` then `assistant`, with the evidence on the assistant row).

`steps` is the number of tools in the plan, so it is a reliable signal that the intent parser
understood the question. `steps: 2` on a complex question means it fell back to the default
plan.

## 3. Tools

All ten are read-only. There is no write path: the agent never creates a dataset, trains a
model or changes a status.

| Tool | Triggered by | Reads | Evidence `source` |
|---|---|---|---|
| `get_kpi` | kpi, ringkas, summary, pendapatan, revenue, omzet | `fact_sales` | `fact_sales` |
| `query_sales` | tren, trend, penjualan, sales, grafik | `fact_sales` | `fact_sales` |
| `query_inventory` | stok, stock, inventory, gudang | `fact_inventory` | `fact_inventory` |
| `query_customer` | customer, pelanggan, rfm, churn | `fact_sales` | `fact_sales:rfm` |
| `query_product` | produk, product, abc, kategori | `fact_sales` | `fact_sales:abc` |
| `query_finance` | keuangan, finance, margin, laba, profit | `fact_sales` | `finance` |
| `get_forecast` | forecast, prediksi, ramal, proyeksi | `fact_sales` | `ml.forecast` |
| `get_anomaly` | anomali, anomaly, janggal, outlier | `fact_sales` | `ml.anomaly` |
| `get_customer_segment` | segmen, segment, cluster | `fact_sales` | `ml.segment` |
| `generate_report` | laporan, report, eksekutif, mingguan, bulanan | all of the above | `reporting` |

Two behaviours to know when interpreting an answer:

- **The tools are not dataset-scoped.** They query the warehouse directly through
  `_load_frame`, which joins `fact_sales` to the dimensions with a `LIMIT 5000`. There is no
  `dataset_id` filter, so the assistant always sees everything committed. A question about
  "the Q1 dataset" is answered from all sales rows.
- **Truncation is silent and per tool.** `_load_frame` caps at 5000 joined rows before any
  aggregation, and each tool then truncates its result (`data[:60]`, `data[:50]`). KPIs
  computed over a truncated frame understate totals on a large warehouse. Prefer the
  analytics endpoints in `api.md` for exact numbers, and use the assistant for orientation.

## 4. Configuration

Only the `app/ai/llm.py` settings matter, all in `ai-engine/.env` (compose injects the
OpenRouter group):

| Variable | Default | Notes |
|---|---|---|
| `LLM_PROVIDER` | `openai` | Compose and the root `.env.example` both ship `openrouter`, so that is what a Docker run uses |
| `LLM_BASE_URL` | `https://api.openai.com/v1` | Used for any non-OpenRouter provider; the root `.env` also ships `https://openrouter.ai/api/v1` |
| `LLM_API_KEY` | empty | Empty means the offline fallback, not an error |
| `LLM_MODEL` | `gpt-4o-mini` | The only model id the engine reads; the root `.env` ships `anthropic/claude-3.5-sonnet` |
| `OPENROUTER_API_KEY` | empty | Used when `LLM_PROVIDER=openrouter`, else falls back to `LLM_API_KEY` |
| `OPENROUTER_BASE_URL` | `https://openrouter.ai/api/v1` | |
| `LLM_TIMEOUT_SECONDS` | `60` | Per attempt; `LLM_MAX_RETRIES` (default 3, hard-capped at 5) attempts, sleeping `0.5 × (attempt + 1)` between them |
| `LLM_EMBEDDING_MODEL` | `text-embedding-3-small` | Compose forwards the deprecated alias `EMBED_MODEL` instead, so under Docker that value is what takes effect. See `rag.md` §4 |

There is no `LLM_TEMPERATURE`, `LLM_MAX_TOKENS` or `LLM_FALLBACK_MODEL` setting, and no
per-provider model variable: `OPENROUTER_MODEL` is forwarded into the container and ignored.

`chat()` hardcodes `temperature=0.2` and `max_tokens=1500`, and on exhausted retries it
returns the offline summary. The Laravel budget for the call is `AI_ENGINE_LLM_TIMEOUT`
(120 s).

Switching providers needs no code change: `effective_llm_base_url()` and
`effective_llm_api_key()` in `app/core/config.py` resolve the pair from `LLM_PROVIDER`.

## 5. Safety

- **Grounding.** The system prompt forbids new numbers and the answer is generated only from
  the tool evidence. The fallback path cannot invent anything, because it prints the evidence.
- **Prompt injection.** Dataset content reaches the prompt as evidence text. There is no
  output filter and no exfiltration scrubber, so treat the assistant as able to echo
  whatever is in the warehouse. Restrict who can ask questions: `POST /api/agent/chat`
  requires only authentication, so a viewer can query every committed fact.
- **No writes.** Nothing in the tool set mutates state.
- **Secrets.** API keys stay in the engine's environment. Laravel never forwards its own
  credentials and never returns the key to a client.

## 6. Memory

`ai_conversations` + `ai_messages` are the engine's memory; `conversation_id` is the thread
key and is an integer. Laravel's `chat_threads.ai_conversation_id` is a soft reference to it
(no foreign key), and `chat_messages.chat_thread_id` is a normal Laravel foreign key to
`chat_threads`.

Passing a `conversation_id` does **not** replay history into the prompt: `run_agent` reads no
prior messages, it only appends the new turn. "Follow-up" questions are answered from the
warehouse, not from what was said before. Deleting a thread in the UI removes only the Laravel
copy; the engine's `ai_conversations` rows and their messages stay.

## 7. Debugging

- `evidence[]` in the response is the fastest diagnostic: it shows which tools ran, what they
  read, and any per-tool error. An empty `data` means the frame was empty, not that the tool
  failed.
- The engine logs the structured error when the LLM is unreachable and the answer carries the
  `(note: LLM unreachable: …)` suffix. Check `docker compose logs -f fastapi`.
- Slow answers are a symptom of the LLM budget: three attempts at
  `LLM_TIMEOUT_SECONDS` (60) each, against Laravel's `AI_ENGINE_LLM_TIMEOUT` (120), so the
  Laravel side gives up first and returns `502`.
- `POST /api/v1/ai/report` (service key) renders an executive summary with
  `{period, branch, format}`; `format: "html"` returns raw HTML instead of the JSON envelope.
  Laravel has no route for it — the **Reports** UI page calls the engine's tool path instead.

## 8. Response contract

`POST /api/v1/ai/chat` returns the legacy keys plus additive enterprise keys:

```json
{"answer": "...", "conversation_id": 12, "evidence": [...], "steps": 4,
 "data_sources": ["fact_sales"], "metrics": {"steps": 4, "degraded": false,
 "provider": "openrouter", "model": "anthropic/claude-3.5-sonnet",
 "template": "assistant.v2", "template_fallback": false, "fallback_steps": []},
 "confidence": 1.0, "limitations": [], "usage": {"recorded": true, "...": "..."},
 "template": "assistant.v2"}
```

ANSWER / EVIDENCE / SOURCES (`data_sources`) / METRICS / CONFIDENCE / LIMITATIONS:
`confidence` is the share of evidence rows with real data; `limitations` names every
empty/failed source, unverified number, template fallback and offline degradation. Laravel's
`POST /api/agent/chat` keeps the documented `{reply, answer, conversation_id, evidence,
steps}` shape byte-identical; the extra keys are consumed server-side (usage is persisted
on the assistant message, see §11).

## 9. Prompt templates

System prompts live in a code-side versioned registry (`PROMPT_TEMPLATES` in
`ai-engine/app/ai/agent.py`): `assistant.v1` (the historical prompt, default),
`assistant.v2` (stricter: per-number source citation plus a closing confidence line), and
`sql.v1` (JSON-only SELECT generation). Select via `POST /api/v1/ai/chat?template=<key>`
or `{"context": {"template": "<key>"}}`; Laravel accepts `template` on both
`POST /api/agent/chat` and the assistant web form and forwards it inside `context`.
Unknown keys fall back to `assistant.v1` and report `metrics.template_fallback: true`
instead of failing the turn.

## 10. SQL guardrails

The agent runs user-supplied or generated SELECTs through `app/ai/sql_guard.py`
(`validate_sql`) before the new `query_sql` tool executes them — and the execute path
re-validates, so a refused statement is never run:

1. exactly one statement (no stacked queries; `;` inside quotes/comments is data);
2. SELECT-only (`SELECT` or `WITH ... SELECT`; `EXPLAIN`/`PRAGMA`/writes refused);
3. no write/DDL keywords (`INSERT/UPDATE/DELETE/DROP/...`, `INTO OUTFILE`, `LOAD DATA`);
4. table allowlist — `fact_*`/`dim_*` warehouse tables only (`users`, `ai_messages`,
   `rag_chunks`, unknown names refused);
5. LIMIT enforcement — missing LIMIT gets 200, anything above 5000 is clamped, and the
   rewrite is flagged via `limit_enforced`.

Execution runs read-only (rollback-only transaction, best-effort `SET TRANSACTION READ
ONLY` on PostgreSQL); the serving role should additionally hold only SELECT grants.
`POST /api/v1/ai/sql/validate` checks a statement without executing it
(`{allowed, reason, normalized_sql, limit, limit_enforced, tables, note}`). NL-to-SQL
generation (`generate_sql`) sees only the allowlisted schema snapshot and its candidate is
validated before return — generation never executes.

## 11. Providers, fallback, cost tracking

**Providers.** `llm.resolve_provider()` maps `LLM_PROVIDER` onto the registry
(`openai-compatible` / `openrouter` / `offline`); unknown values degrade to offline.
`chat_with_fallback(messages, primary, fallback)` tries primary → fallback → offline,
logging each step and recording `raw.fallback_steps`. Structured outputs pass
`validate_structured` (JSON-shape check for required keys); a miss degrades instead of
returning a half-shaped object.

**Hallucination mitigation.** A model synthesis over zero real evidence rows is replaced
by the canonical insufficient-data answer (`Data belum tersedia: tidak ada evidence ...`),
never invented numbers; numbers in a grounded answer are checked against the evidence
text and stragglers surface in `limitations`.

**Cost tracking.** Every turn appends one `ai_usage` ledger row (engine DB): per-model
prompt/completion tokens (provider `usage` block when available, else a labelled
`chars/4` heuristic) and estimated USD cost from the `MODEL_RATES` snapshot. Unknown
models record `estimated_cost: null` with an explicit note instead of a guessed price.
`GET /api/v1/ai/usage?conversation_id=` returns rows plus totals (unpriced rows counted,
never zero-filled); Laravel proxies it as `GET /api/ai/usage` and persists each turn's
`usage` summary on the assistant message's `meta` JSON column (never merged into
`evidence`).
