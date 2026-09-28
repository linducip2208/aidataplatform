# AIDataPlatform — Celery worker/beat image (wrapper around ai-engine/Dockerfile)
# NOTE: docker-compose.yml builds celery-worker/beat directly from ./ai-engine/Dockerfile.
# This file exists so `docker build -f infrastructure/docker/celery.Dockerfile .` also works
# (e.g. CI jobs that run from repo root) and to pin worker-specific defaults.
#
# Build from root:  docker build -f infrastructure/docker/celery.Dockerfile -t aidata-celery .
# Build (preferred): docker compose build celery-worker celery-beat

ARG AI_ENGINE_BASE=aidata-fastapi:latest

# Stage 1: ensure base exists by rebuilding ai-engine (ignored if base already built)
FROM python:3.13-slim AS base-builder
WORKDIR /app
COPY ai-engine/requirements.txt ./requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

FROM base-builder AS runtime
WORKDIR /app
COPY ai-engine/ ./

ENV PYTHONUNBUFFERED=1 \
    CELERY_QUEUES=default,imports,quality,ml,agent,rag \
    CELERY_CONCURRENCY=4

# Queues must match compose: default,imports,quality,ml,agent,rag
# Worker entrypoint overridden by compose `command:`; default below is informational.
CMD ["celery", "-A", "app.celery_app.celery_app", "worker", "--loglevel=info", "--concurrency=4", "-Q", "default,imports,quality,ml,agent,rag"]
