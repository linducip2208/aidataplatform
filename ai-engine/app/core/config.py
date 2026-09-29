"""Application settings (pydantic-settings).

Single source of truth for every environment variable the engine reads.

Two rules this module enforces:

1. **One engine flavour.** The service is synchronous end to end
   (``Session.query`` in the routers, sync Celery tasks, sync Alembic), so
   ``database_url`` is normalised to a *sync* driver here. Compose passes
   ``mysql+aiomysql://``; a sync ``create_engine`` cannot use that, so the
   async driver is rewritten before any engine is built. ``SYNC_DATABASE_URL``
   wins when it is set, which is the same precedence ``alembic/env.py`` uses.
2. **The caller's name wins.** Compose, ``application/config/ai_engine.php``,
   the root ``.env.example`` and ``docs/api.md`` all use the names in
   :data:`CANONICAL_ENV_NAMES`. The names the engine used to read are still
   accepted as deprecated fallbacks so an existing deployment does not break.

Every alias below logs a deprecation warning when the old name is the one that
supplied the value.
"""
from __future__ import annotations

import logging
import os
from functools import lru_cache
from pathlib import Path
from typing import List, Tuple

from pydantic import AliasChoices, Field, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

log = logging.getLogger("app.core.config")

# Async driver -> sync driver. The app is synchronous, so an async driver must
# never reach create_engine(). Kept in sync with alembic/env.py::_ASYNC_TO_SYNC.
ASYNC_TO_SYNC_DRIVERS = {
    "postgresql+asyncpg": "postgresql+psycopg2",
    "postgres+asyncpg": "postgresql+psycopg2",
    "postgresql+psycopg": "postgresql+psycopg2",
    "mysql+aiomysql": "mysql+pymysql",
    "mysql+asyncmy": "mysql+pymysql",
    "sqlite+aiosqlite": "sqlite",
}

# APP_ENV accepts Laravel's vocabulary. "production" is compose's own default,
# so rejecting it would break every container; an unknown value is an operator
# error and is raised instead of being silently rewritten to "dev".
ALLOWED_APP_ENVS = frozenset(
    {"dev", "demo", "local", "test", "staging", "stage", "prod", "production"}
)
PROD_APP_ENVS = frozenset({"prod", "production"})

# field -> (canonical env var, deprecated fallback env vars)
DEPRECATED_ALIASES: dict[str, Tuple[str, ...]] = {
    "upload_max_mb": ("MAX_UPLOAD_MB", "UPLOAD_MAX_MB"),
    "quality_min_score": ("QUALITY_THRESHOLD", "QUALITY_MIN_SCORE"),
    "llm_embedding_model": ("LLM_EMBEDDING_MODEL", "EMBED_MODEL"),
}


def to_sync_url(url: str) -> str:
    """Rewrite an async-driver SQLAlchemy URL to its synchronous equivalent."""
    url = (url or "").strip()
    for async_driver, sync_driver in ASYNC_TO_SYNC_DRIVERS.items():
        prefix = f"{async_driver}://"
        if url.startswith(prefix):
            return f"{sync_driver}{url[len(async_driver):]}"
    return url


def is_async_url(url: str) -> bool:
    url = (url or "").strip()
    return any(url.startswith(f"{d}://") for d in ASYNC_TO_SYNC_DRIVERS)


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    app_env: str = Field(default="dev")
    app_name: str = Field(default="ai-engine")
    log_level: str = Field(default="INFO")

    api_host: str = Field(default="0.0.0.0")
    api_port: int = Field(default=8000)

    service_api_key: str = Field(default="change-me-service-key")
    # Header Laravel sends the key in (application/config/ai_engine.php reads the
    # same variable). security.py and the rate-limit middleware in main.py both
    # resolve it from here so there is exactly one resolution path.
    service_api_key_header: str = Field(default="X-Service-Key")
    rate_limit_per_minute: int = Field(default=120)
    jwt_secret: str = Field(default="change-me-jwt-secret")
    jwt_algorithm: str = Field(default="HS256")
    jwt_expire_minutes: int = Field(default=60)

    # SYNC_DATABASE_URL is the Alembic convention; it is also the authoritative
    # value for this synchronous service when DATABASE_URL carries an async
    # driver. See _normalise_database_url.
    sync_database_url: str = Field(default="")
    database_url: str = Field(default="sqlite:///./dev.db")
    redis_url: str = Field(default="redis://localhost:6379/0")

    llm_provider: str = Field(default="openai")
    llm_base_url: str = Field(default="https://api.openai.com/v1")
    llm_api_key: str = Field(default="")
    llm_model: str = Field(default="gpt-4o-mini")
    llm_embedding_model: str = Field(
        default="text-embedding-3-small",
        validation_alias=AliasChoices("LLM_EMBEDDING_MODEL", "EMBED_MODEL"),
    )
    llm_timeout_seconds: int = Field(default=60)
    llm_max_retries: int = Field(default=3)

    openrouter_base_url: str = Field(default="https://openrouter.ai/api/v1")
    openrouter_api_key: str = Field(default="")
    openai_base_url: str = Field(default="https://api.openai.com/v1")
    openai_api_key: str = Field(default="")

    storage_path: Path = Field(default=Path("./datasets"))
    model_path: Path = Field(default=Path("./models"))
    # Compose and application/.env.example send MAX_UPLOAD_MB; UPLOAD_MAX_MB is
    # the deprecated engine-local name kept as a fallback.
    upload_max_mb: int = Field(
        default=200,
        validation_alias=AliasChoices("MAX_UPLOAD_MB", "UPLOAD_MAX_MB"),
    )
    chunk_rows: int = Field(default=20000)

    # Compose and application/.env.example send QUALITY_THRESHOLD;
    # QUALITY_MIN_SCORE is the deprecated engine-local name.
    quality_min_score: float = Field(
        default=0.6,
        validation_alias=AliasChoices("QUALITY_THRESHOLD", "QUALITY_MIN_SCORE"),
    )

    cors_origins: str = Field(default="http://localhost:3000,http://localhost:8000")

    # /metrics must never be reachable from a public network by accident;
    # Prometheus scrapes it over the compose network, which is private.
    metrics_enabled: bool = Field(default=True)
    metrics_allow_public: bool = Field(default=False)
    # Swagger UI. Left on so the documented /docs, /redoc and /openapi.json
    # routes keep working; set DOCS_ENABLED=false to close them.
    docs_enabled: bool = Field(default=True)

    @field_validator("app_env")
    @classmethod
    def _env_valid(cls, v: str) -> str:
        value = (v or "").strip().lower()
        if not value:
            raise ValueError(
                "APP_ENV is empty; set it to one of: " + ", ".join(sorted(ALLOWED_APP_ENVS))
            )
        if value not in ALLOWED_APP_ENVS:
            # Fail loudly: silently downgrading an unknown value to "dev" used
            # to be how a production deployment looked like a dev box.
            raise ValueError(
                f"APP_ENV={v!r} is not a known environment; "
                f"expected one of: {', '.join(sorted(ALLOWED_APP_ENVS))}"
            )
        return value

    @field_validator("service_api_key_header")
    @classmethod
    def _header_valid(cls, v: str) -> str:
        value = (v or "").strip()
        if not value:
            return "X-Service-Key"
        return value

    @field_validator("log_level")
    @classmethod
    def _log_level_valid(cls, v: str) -> str:
        value = (v or "INFO").strip().upper()
        if value not in {"CRITICAL", "ERROR", "WARNING", "INFO", "DEBUG", "NOTSET"}:
            return "INFO"
        return value

    @model_validator(mode="after")
    def _normalise_database_url(self) -> "Settings":
        """Guarantee database_url is usable by the synchronous create_engine."""
        raw = (self.database_url or "").strip()
        if not raw:
            raise ValueError("DATABASE_URL is empty; the engine cannot open a database.")
        explicit_sync = (self.sync_database_url or "").strip()
        if is_async_url(raw) and explicit_sync:
            # Same precedence as alembic/env.py::get_url: an explicit
            # SYNC_DATABASE_URL is the operator's sync URL, so use it verbatim
            # (still normalised, in case it is itself async).
            resolved = to_sync_url(explicit_sync)
            log.info(
                "DATABASE_URL uses an async driver; using SYNC_DATABASE_URL for the "
                "synchronous engine."
            )
        else:
            resolved = to_sync_url(raw)
        if is_async_url(resolved):  # pragma: no cover - defensive
            raise ValueError(
                f"DATABASE_URL={raw!r} could not be resolved to a synchronous driver."
            )
        self.database_url = resolved
        return self

    @model_validator(mode="after")
    def _warn_deprecated_aliases(self) -> "Settings":
        present = _present_env_names()
        for field, (canonical, *deprecated) in DEPRECATED_ALIASES.items():
            used = [name for name in deprecated if name in present]
            if not used or canonical in present:
                continue
            log.warning(
                "%s is deprecated; rename it to %s. It still works for now.",
                used[0],
                canonical,
            )
        return self

    @property
    def cors_origin_list(self) -> List[str]:
        return [o.strip() for o in (self.cors_origins or "").split(",") if o.strip()]

    @property
    def is_prod(self) -> bool:
        return self.app_env in PROD_APP_ENVS

    def effective_llm_base_url(self) -> str:
        if self.llm_provider.lower() == "openrouter":
            return self.openrouter_base_url
        return self.llm_base_url or self.openai_base_url

    def effective_llm_api_key(self) -> str:
        if self.llm_provider.lower() == "openrouter":
            return self.openrouter_api_key or self.llm_api_key
        return self.llm_api_key or self.openai_api_key


def _present_env_names() -> set[str]:
    """Upper-case names available to this process, from env and from .env files.

    pydantic-settings reads the dotenv file without exporting it, so the
    deprecation notice has to look at the file too.
    """
    names = {key.upper() for key in os.environ.keys()}
    for candidate in (".env", "ai-engine/.env"):
        try:
            path = Path(candidate)
            if not path.is_file():
                continue
            for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                names.add(line.split("=", 1)[0].strip().removeprefix("export ").upper())
        except OSError:  # pragma: no cover - unreadable dotenv is not fatal
            continue
    return names


@lru_cache
def get_settings() -> Settings:
    s = Settings()
    for path in (s.storage_path, s.model_path):
        try:
            path.mkdir(parents=True, exist_ok=True)
        except OSError as exc:
            log.warning("could not create %s: %s", path, type(exc).__name__)
    return s


settings = get_settings()
