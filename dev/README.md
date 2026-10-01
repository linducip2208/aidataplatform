# Native Windows Service Manager (dev, Laragon-based)

No Docker required. Laragon owns Apache/Nginx + MySQL; these scripts manage
everything else and never touch Laragon services.

```powershell
powershell -ExecutionPolicy Bypass -File .\dev\status.ps1
powershell -ExecutionPolicy Bypass -File .\dev\start-all.ps1
powershell -ExecutionPolicy Bypass -File .\dev\stop-all.ps1
powershell -ExecutionPolicy Bypass -File .\dev\restart-all.ps1
```

Managed (PID-tracked in `dev/runtime/`, logs in `ai-engine/logs/`):
redis (only if the manager started it), ai-engine (uvicorn),
celery-worker, celery-beat, laravel-queue, laravel-schedule.

Checked, never managed: Apache/Nginx, MySQL, Ollama (report-only).

Configuration comes from `application/.env` (`APP_URL`, `AI_ENGINE_URL`),
`ai-engine/.env`, and optional `CELERY_QUEUES` / `CELERY_CONCURRENCY` /
`REDIS_SERVER` / `PHP_BIN` environment overrides — nothing is hardcoded.

`dev/stub-engine.py` is a UI-development aid only and is never started by
these scripts; the real engine is `uvicorn app.main:app` on the root `.venv`.
