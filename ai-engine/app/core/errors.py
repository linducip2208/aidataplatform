"""Structured application errors."""
from __future__ import annotations

from typing import Any, Dict, Optional


class AppError(Exception):
    def __init__(
        self,
        module: str,
        operation: str,
        error_type: str,
        message: str,
        technical: str = "",
        resolution: str = "",
        status_code: int = 400,
        code: str = "APP_ERROR",
    ) -> None:
        super().__init__(message)
        self.module = module
        self.operation = operation
        self.error_type = error_type
        self.message = message
        self.technical = technical or message
        self.resolution = resolution
        self.status_code = status_code
        self.code = code


def build_error_response(
    *,
    module: str,
    operation: str,
    error_type: str,
    message: str,
    technical: str = "",
    request_id: str = "-",
    resolution: str = "",
    code: str = "APP_ERROR",
    details: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    return {
        "success": False,
        "error": {
            "module": module,
            "operation": operation,
            "error_type": error_type,
            "code": code,
            "message": message,
            "technical": technical or message,
            "request_id": request_id,
            "resolution": resolution,
            "details": details or {},
        },
    }


COMMON_RESOLUTIONS = {
    "validation": "Periksa kembali payload / format file, lalu ulangi permintaan.",
    "auth": "Pastikan X-Service-Key atau Bearer token valid.",
    "not_found": "Pastikan ID / nama resource benar.",
    "db": "Periksa koneksi DATABASE_URL dan migrasi alembic.",
    "llm": "Periksa LLM_API_KEY / LLM_BASE_URL lalu coba lagi.",
}
