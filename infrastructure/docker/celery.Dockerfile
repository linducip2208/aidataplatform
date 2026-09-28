# AIDataPlatform — Celery worker/beat image.
#
# docker-compose.yml does NOT use this file: celery-worker and celery-beat are
# built from `context: ./ai-engine` + `dockerfile: Dockerfile`, because they
# share that image with fastapi and only differ by `command:`. This file exists
# for standalone builds and for pinning worker-specific defaults.
#
# Build from the repository root (the COPY paths below are root-relative):
#   docker build -f infrastructure/docker/celery.Dockerfile -t aidata-celery .
#
# The working directory is /code, matching ai-engine/Dockerfile and the
# APP_DIR default in ai-engine/docker-entrypoint.sh, so the same volume paths
# used by docker-compose.yml resolve here too.

FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    APP_DIR=/code \
    CELERY_QUEUES=default,imports,quality,ml,agent,rag \
    CELERY_CONCURRENCY=4

WORKDIR /code

RUN apt-get update && apt-get install -y --no-install-recommends \
      build-essential libpq-dev libmagic1 curl \
    && rm -rf /var/lib/apt/lists/*

COPY ai-engine/requirements.txt ./requirements.txt
RUN pip install --upgrade pip && pip install -r ./requirements.txt

COPY ai-engine/app ./app
COPY ai-engine/alembic.ini ./alembic.ini
COPY ai-engine/alembic ./alembic
COPY ai-engine/docker-entrypoint.sh ./docker-entrypoint.sh

RUN mkdir -p /code/data/storage /code/data/models /code/data/datasets

EXPOSE 8000

# The queues below must stay identical to CELERY_QUEUES and to the `-Q` flag in
# the docker-compose command, or tasks are published to queues nobody consumes.
ENTRYPOINT ["sh", "/code/docker-entrypoint.sh"]
CMD ["celery", "-A", "app.celery_app.celery_app", "worker", "--loglevel=info", "--concurrency=4", "-Q", "default,imports,quality,ml,agent,rag"]
