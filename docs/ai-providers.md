# AI Providers (BYOK)

Bring your own key. The platform never ships a working provider credential:
without a configured key the AI answers from retrieved data and says so
(`offline` degraded path), it never invents provider output.

## Concepts

- **Provider registry** (`ai_providers` table, Admin > Provider AI):
  metadata only — name, type, base URL, model, capabilities, pricing,
  priority, enabled flag. **API keys are never stored**: not in the table,
  not in logs, not in audit detail.
- **Active provider**: the enabled row with the lowest priority number.
- **Test** (`Test koneksi`): one tiny `chat/completions` call with a
  caller-supplied key. The key travels in that request only.
- **Publish** (`Terbitkan`): writes the managed env block and tells the
  operator which restart to run.

## Key flow

1. Admin pastes the key into the test/publish form (password input,
   never re-displayed, `max:512`).
2. Native/aaPanel: the service writes these names into `ai-engine/.env`
   inside `# >>> aidata-managed-providers` markers (previous file backed
   up as `.env.bak.<timestamp>`, mode `0600`), preserving every other
   line, then asks for
   `supervisorctl restart aidata-fastapi aidata-celery-worker aidata-celery-beat`.
3. Docker Compose: containers receive env by interpolation at creation and
   the root `.env` is not mounted into them, so no write from the UI could
   take effect. Publish instead returns the exact block to paste into the
   root `.env` plus `docker compose up -d fastapi celery-worker celery-beat`.
   This is stated in the UI, not hidden.

| Provider type | Key slot | Notes |
|---|---|---|
| OpenAI-compatible | `LLM_API_KEY` | any `/v1`-dialect endpoint |
| OpenRouter | `OPENROUTER_API_KEY` | `LLM_PROVIDER=openrouter` |
| Ollama (local) | none | key optional; stays on your network |
| Custom | `LLM_API_KEY` | any OpenAI-compatible base URL |

## Rotation

Paste the new key and publish again: the managed block is replaced (never
duplicated), the old file is backed up first, and every step is audit-logged
(`ai_provider.*`). To revoke, publish an empty key field is refused — disable
the provider instead, then rotate at the provider dashboard.

## Cost note

Per-model pricing on the registry is informational for the operator today;
spend enforcement reads the measured ledger (`AI_MONTHLY_BUDGET_USD`), never
these fields.
