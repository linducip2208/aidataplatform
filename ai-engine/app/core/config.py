"""Application settings (pydantic-settings)."""
from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import List

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_env: str = Field(default="dev")
    app_name: str = Field(default="ai-engine")
    log_level: str = Field(default="INFO")

    api_host: str = Field(default="0.0.0.0")
    api_port: int = Field(default=8000)

    service_api_key: str = Field(default="change-me-service-key")
    jwt_secret: str = Field(default="change-me-jwt-secret")
    jwt_algorithm: str = Field(default="HS256")
    jwt_expire_minutes: int = Field(default=60)

    database_url: str = Field(default="sqlite:///./dev.db")
    redis_url: str = Field(default="redis://localhost:6379/0")

    llm_provider: str = Field(default="openai")
    llm_base_url: str = Field(default="https://api.openai.com/v1")
    llm_api_key: str = Field(default="")
    llm_model: str = Field(default="gpt-4o-mini")
    llm_embedding_model: str = Field(default="text-embedding-3-small")
    llm_timeout_seconds: int = Field(default=60)
    llm_max_retries: int = Field(default=3)

    openrouter_base_url: str = Field(default="https://openrouter.ai/api/v1")
    openrouter_api_key: str = Field(default="")
    openai_base_url: str = Field(default="https://api.openai.com/v1")
    openai_api_key: str = Field(default="")

    storage_path: Path = Field(default=Path("./datasets"))
    model_path: Path = Field(default=Path("./models"))
    upload_max_mb: int = Field(default=200)
    chunk_rows: int = Field(default=20000)

    quality_min_score: float = Field(default=0.6)

    cors_origins: str = Field(default="http://localhost:3000,http://localhost:8000")

    @field_validator("app_env")
    @classmethod
    def _env_valid(cls, v: str) -> str:
        allowed = {"demo", "dev", "staging", "prod"}
        v = (v or "dev").lower()
        return v if v in allowed else "dev"

    @property
    def cors_origin_list(self) -> List[str]:
        return [o.strip() for o in (self.cors_origins or "").split(",") if o.strip()]

    @property
    def is_prod(self) -> bool:
        return self.app_env == "prod"

    def effective_llm_base_url(self) -> str:
        if self.llm_provider.lower() == "openrouter":
            return self.openrouter_base_url
        return self.llm_base_url or self.openai_base_url

    def effective_llm_api_key(self) -> str:
        if self.llm_provider.lower() == "openrouter":
            return self.openrouter_api_key or self.llm_api_key
        return self.llm_api_key or self.openai_api_key


@lru_cache
def get_settings() -> Settings:
    s = Settings()
    try:
        s.storage_path.mkdir(parents=True, exist_ok=True)
    except Exception:
        pass
    try:
        s.model_path.mkdir(parents=True, exist_ok=True)
    except Exception:
        pass
    return s


settings = get_settings()
