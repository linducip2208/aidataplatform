"""Production-grade import connectors (Agent 2 / A2 ownership).

Abstract base plus working implementations that only rely on dependencies the
engine already ships (SQLAlchemy, httpx, pandas). No fake data anywhere: where
a live external system is unavailable the connector raises
:class:`IntegrationBoundary` naming exactly what must be configured.
"""
from __future__ import annotations

import abc
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Dict, Iterator, List, Optional

import pandas as pd


class IntegrationBoundary(Exception):
    """A live external system or credential the connector needs is not
    configured. The message always names what must be provided."""


class BaseConnector(abc.ABC):
    """A chunked source of DataFrames. Implementations must be bounded-memory:
    ``iter_chunks`` yields at most ``chunksize`` rows per frame and never
    materialises the whole source."""

    name: str = "base"

    @abc.abstractmethod
    def iter_chunks(self, chunksize: int = 20000) -> Iterator[pd.DataFrame]:
        ...

    def read_full(self, limit_rows: int = 200000) -> pd.DataFrame:
        frames: List[pd.DataFrame] = []
        total = 0
        for chunk in self.iter_chunks():
            frames.append(chunk)
            total += len(chunk)
            if total >= limit_rows:
                break
        if not frames:
            return pd.DataFrame()
        return pd.concat(frames, ignore_index=True).head(limit_rows)

    def describe(self) -> Dict[str, Any]:
        return {"connector": self.name}


# ---------------------------------------------------------------------------
# Database connector: SQLAlchemy SELECT -> chunks
# ---------------------------------------------------------------------------
class DatabaseConnector(BaseConnector):
    """Streams ``SELECT`` results in ``chunksize`` windows.

    Two modes: ``query`` with ``{offset}``/``{limit}`` placeholders (portable
    keyset-style paging over any SQLAlchemy URL), or ``table`` + ``order_by``
    which builds the paging query automatically. The URL must be a *sync*
    driver — async drivers (``+asyncpg``, ``+aiosqlite``) cannot back the
    synchronous engine this service runs on.
    """

    name = "database"

    def __init__(self, url: str, query: Optional[str] = None, *,
                 table: Optional[str] = None, order_by: Optional[str] = None,
                 params: Optional[Dict[str, Any]] = None) -> None:
        if not url:
            raise IntegrationBoundary(
                "DatabaseConnector needs a SQLAlchemy URL: pass url= "
                "(e.g. 'postgresql+psycopg2://user:***@host/db' via the "
                "SYNC_DATABASE_URL / DATABASE_URL environment variable).")
        from app.core.config import is_async_url

        if is_async_url(url):
            raise IntegrationBoundary(
                f"DatabaseConnector got async-driver URL {url.split(':', 1)[0]}://...; "
                "provide the synchronous equivalent (postgresql+psycopg2://, "
                "mysql+pymysql://, sqlite://) — see SYNC_DATABASE_URL.")
        if not query and not table:
            raise IntegrationBoundary(
                "DatabaseConnector needs query='SELECT ... {offset} {limit}' "
                "or table='schema.table' with order_by='id'.")
        self.url = url
        self.query = query
        self.table = table
        self.order_by = order_by
        self.params = dict(params or {})

    def _engine(self):  # lazy import keeps module import side-effect free
        from sqlalchemy import create_engine

        kwargs: Dict[str, Any] = {"pool_pre_ping": True}
        if self.url.startswith("sqlite"):
            kwargs = {"connect_args": {"check_same_thread": False}}
        return create_engine(self.url, **kwargs)

    def _sql(self) -> str:
        if self.query:
            return self.query
        order = self.order_by or "1"
        return (f'SELECT * FROM {self.table} ORDER BY {order} '
                "LIMIT {limit} OFFSET {offset}")

    def iter_chunks(self, chunksize: int = 20000) -> Iterator[pd.DataFrame]:
        from sqlalchemy import text

        engine = self._engine()
        try:
            offset = 0
            while True:
                sql = self._sql().format(limit=int(chunksize), offset=int(offset))
                try:
                    with engine.connect() as conn:
                        frame = pd.read_sql(text(sql), conn, params=self.params or None)
                except Exception as exc:
                    raise IntegrationBoundary(
                        f"DatabaseConnector query failed against "
                        f"{self.url.split('@')[-1]}; check the URL, credentials, "
                        f"network and the query/table: {type(exc).__name__}") from exc
                if frame.empty:
                    return
                yield frame
                if len(frame) < chunksize:
                    return
                offset += len(frame)
        finally:
            try:
                engine.dispose()
            except Exception:
                pass

    def describe(self) -> Dict[str, Any]:
        host = self.url.split("@")[-1] if "@" in self.url else self.url.split("://")[-1][:64]
        return {"connector": self.name, "target": host,
                "mode": "query" if self.query else f"table:{self.table}"}


# ---------------------------------------------------------------------------
# REST API connector: httpx paginated GET -> chunks
# ---------------------------------------------------------------------------
class RestApiConnector(BaseConnector):
    """Streams a paginated JSON ``GET`` endpoint into chunks.

    The endpoint must answer ``{"data": [...]}`` (the engine envelope) or a
    bare JSON list; pagination is driven by ``page_param``/``page_size_param``
    starting at page 1 until an empty page arrives. A bearer token goes in
    ``Authorization`` when ``auth_token`` is set.
    """

    name = "rest_api"

    def __init__(self, base_url: str, endpoint: str = "", *,
                 params: Optional[Dict[str, Any]] = None,
                 headers: Optional[Dict[str, str]] = None,
                 auth_token: str = "",
                 page_param: str = "page", page_size_param: str = "per_page",
                 client: Optional[Any] = None, timeout: float = 30.0) -> None:
        if not base_url:
            raise IntegrationBoundary(
                "RestApiConnector needs base_url='https://host/api/v1' plus "
                "endpoint='/resource' (or a httpx client for tests).")
        self.base_url = base_url.rstrip("/")
        self.endpoint = endpoint
        self.params = dict(params or {})
        self.headers = dict(headers or {})
        if auth_token:
            self.headers.setdefault("Authorization", f"Bearer {auth_token}")
        self.page_param = page_param
        self.page_size_param = page_size_param
        self._client = client
        self.timeout = timeout

    def _get_client(self):  # pragma: no cover - trivial
        if self._client is not None:
            return self._client
        import httpx

        return httpx.Client(base_url=self.base_url, headers=self.headers, timeout=self.timeout)

    def _fetch_page(self, client: Any, page: int, page_size: int) -> List[Dict[str, Any]]:
        q = dict(self.params)
        q[self.page_param] = page
        q[self.page_size_param] = page_size
        try:
            resp = client.get(self.endpoint or "/", params=q)
            resp.raise_for_status()
            body = resp.json()
        except Exception as exc:
            raise IntegrationBoundary(
                f"RestApiConnector GET {self.base_url}{self.endpoint} failed "
                f"(page {page}); check the URL, auth token and network: "
                f"{type(exc).__name__}") from exc
        if isinstance(body, dict) and isinstance(body.get("data"), list):
            return body["data"]
        if isinstance(body, list):
            return body
        if isinstance(body, dict) and isinstance(body.get("items"), list):
            return body["items"]
        raise IntegrationBoundary(
            f"RestApiConnector GET {self.base_url}{self.endpoint} answered a "
            "payload without a list at 'data'/'items' — the endpoint must "
            "return {'data': [...]} or [...].")

    def iter_chunks(self, chunksize: int = 20000) -> Iterator[pd.DataFrame]:
        client = self._get_client()
        close = self._client is None and hasattr(client, "close")
        try:
            page = 1
            buf: List[Dict[str, Any]] = []
            while True:
                rows = self._fetch_page(client, page, chunksize)
                if not rows:
                    break
                buf.extend(rows)
                page += 1
                while len(buf) >= chunksize:
                    yield pd.DataFrame(buf[:chunksize])
                    buf = buf[chunksize:]
                if len(rows) < chunksize:
                    break
            if buf:
                yield pd.DataFrame(buf)
        finally:
            if close:
                try:
                    client.close()
                except Exception:
                    pass

    def describe(self) -> Dict[str, Any]:
        return {"connector": self.name, "target": f"{self.base_url}{self.endpoint}"}


# ---------------------------------------------------------------------------
# Scheduled imports: cron-spec record + due-check (no runner side effects)
# ---------------------------------------------------------------------------
@dataclass
class ScheduledImport:
    """A cron-spec import schedule. Storage-agnostic: the caller persists the
    dataclass dict and asks :meth:`is_due` whether to fire.

    ``cron`` is standard 5-field ``minute hour dom month dow`` (names and
    ``*/n`` steps supported). ``last_run_at`` is timezone-aware UTC; naive
    values are assumed UTC.
    """
    name: str
    cron: str
    connector: str = ""
    config: Dict[str, Any] = field(default_factory=dict)
    last_run_at: Optional[datetime] = None
    enabled: bool = True

    def to_dict(self) -> Dict[str, Any]:
        return {"name": self.name, "cron": self.cron, "connector": self.connector,
                "config": dict(self.config),
                "last_run_at": self.last_run_at.isoformat() if self.last_run_at else None,
                "enabled": self.enabled}

    @classmethod
    def from_dict(cls, payload: Dict[str, Any]) -> "ScheduledImport":
        raw = payload.get("last_run_at")
        last = None
        if raw:
            try:
                last = datetime.fromisoformat(str(raw))
                if last.tzinfo is None:
                    last = last.replace(tzinfo=timezone.utc)
            except Exception:
                last = None
        return cls(name=str(payload.get("name", "")), cron=str(payload.get("cron", "")),
                   connector=str(payload.get("connector", "")),
                   config=dict(payload.get("config") or {}),
                   last_run_at=last, enabled=bool(payload.get("enabled", True)))

    def is_due(self, now: Optional[datetime] = None) -> bool:
        """True when the schedule is enabled and at least one cron tick falls
        in (last_run_at, now]. Never raises: an invalid cron spec is not due."""
        if not self.enabled:
            return False
        now = now or datetime.now(timezone.utc)
        if now.tzinfo is None:
            now = now.replace(tzinfo=timezone.utc)
        try:
            ticks = _cron_ticks_between(self.cron, self.last_run_at, now)
        except Exception:
            return False
        return bool(ticks)


_CRON_RANGES = ((0, 59), (0, 23), (1, 31), (1, 12), (0, 6))
_CRON_MONTHS = {"jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
                "jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12}
_CRON_DOW = {"sun": 0, "mon": 1, "tue": 2, "wed": 3, "thu": 4, "fri": 5, "sat": 6}


def _parse_cron_field(token: str, lo: int, hi: int) -> set:
    token = token.strip().lower()
    if token in ("*", "?"):
        return set(range(lo, hi + 1))
    out: set = set()
    for part in token.split(","):
        step = 1
        if "/" in part:
            part, _, step_s = part.partition("/")
            try:
                step = max(1, int(step_s))
            except ValueError:
                raise ValueError(f"bad cron step: {token!r}")
        if part in ("*", ""):
            rng = range(lo, hi + 1)
        elif "-" in part:
            a_s, _, b_s = part.partition("-")
            rng = range(_cron_int(a_s, lo, hi), _cron_int(b_s, lo, hi) + 1)
        else:
            rng = range(_cron_int(part, lo, hi), _cron_int(part, lo, hi) + 1)
        out.update(v for v in rng if lo <= v <= hi and (v - lo) % step == 0)
    if not out:
        raise ValueError(f"empty cron field: {token!r}")
    return out


def _cron_int(token: str, lo: int, hi: int) -> int:
    token = token.strip().lower()
    if token in _CRON_MONTHS and hi == 12:
        return _CRON_MONTHS[token]
    if token in _CRON_DOW and hi == 6:
        return _CRON_DOW[token]
    return int(token)


def _parse_cron(spec: str) -> List[set]:
    parts = spec.strip().split()
    if len(parts) != 5:
        raise ValueError(f"cron needs 5 fields, got {len(parts)}: {spec!r}")
    return [_parse_cron_field(tok, lo, hi) for tok, (lo, hi) in zip(parts, _CRON_RANGES)]


def _cron_ticks_between(spec: str, since: Optional[datetime], until: datetime) -> List[datetime]:
    """Minute-granularity tick list in (since, until]. Scans back at most 32
    days so a never-run schedule does not iterate from the epoch."""
    fields = _parse_cron(spec)
    if since is not None and since.tzinfo is None:
        since = since.replace(tzinfo=timezone.utc)
    start = until.timestamp() if since is None else max(since.timestamp(), until.timestamp() - 32 * 86400)
    ticks: List[datetime] = []
    # Walk minute boundaries from start (exclusive) to until (inclusive).
    cursor = int(start // 60 * 60) + 60
    end = int(until.timestamp() // 60 * 60)
    minute, hour, dom, month, dow = fields
    while cursor <= end:
        dt = datetime.fromtimestamp(cursor, tz=timezone.utc)
        # cron DOW: 0 and 7 are both Sunday.
        if (dt.minute in minute and dt.hour in hour and dt.day in dom
                and dt.month in month and ((dt.isoweekday() % 7) in dow)):
            ticks.append(dt)
        cursor += 60
    return ticks
