FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /code

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential libpq-dev libmagic1 curl \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt /code/requirements.txt
RUN pip install --upgrade pip && pip install -r /code/requirements.txt

COPY app /code/app
COPY alembic.ini /code/alembic.ini
COPY alembic /code/alembic
COPY docker-entrypoint.sh /code/docker-entrypoint.sh

# Compose mounts the datasets and model-cache volumes here (STORAGE_PATH and
# MODEL_PATH both resolve under /code). They are created in the image so the
# container also works standalone, without a volume.
RUN mkdir -p /code/data/datasets /code/data/models

EXPOSE 8000

ENTRYPOINT ["sh", "/code/docker-entrypoint.sh"]
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "2"]
