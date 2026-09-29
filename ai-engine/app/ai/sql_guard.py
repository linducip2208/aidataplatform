"""Read-only SQL guardrails for AI-generated queries.

Every statement the assistant executes goes through :func:`validate_sql` first;
anything that is not a single ``SELECT`` (or a ``WITH ... SELECT`` CTE) over the
allowlisted warehouse tables is refused with an explicit machine-readable
``reason`` — never executed, never rewritten into something executable.

Rules, in the order they are applied:

1. **Single statement.** Comments are stripped, then any ``;`` outside a quoted
   literal refuses the text. ``SELECT 1; DROP TABLE x`` is two statements, not
   one query with a comment.
2. **SELECT-only.** After stripping leading comments/whitespace the text must
   start with ``SELECT`` or ``WITH`` (a CTE whose body is still scanned by the
   rules below). Anything else — including ``EXPLAIN``, ``SHOW`` and ``PRAGMA``
   — is refused.
3. **Blocked keywords.** ``INSERT/UPDATE/DELETE/DROP/ALTER/CREATE/TRUNCATE/
   GRANT/REVOKE/EXEC/EXECUTE/CALL/MERGE/COPY/VACUUM/INTO OUTFILE/LOAD DATA/
   ATTACH/DETACH/PRAGMA`` anywhere outside a quoted literal refuses the text.
   ``UNION`` stays allowed: it is still a read.
4. **Table allowlist.** Names after ``FROM``/``JOIN`` must all be warehouse
   tables (:data:`ALLOWED_TABLES`). System tables, ops tables (``ai_messages``,
   ``rag_chunks``, ``users`` ...) and unknown names are refused. Schema
   prefixes (``public.fact_sales``) and aliases are unwrapped before the check.
5. **LIMIT enforcement.** A missing ``LIMIT`` is appended
   (``DEFAULT_ROW_LIMIT``); a ``LIMIT`` above :data:`MAX_ROW_LIMIT` is clamped.
   The rewrite is reported via ``limit_enforced`` so callers can tell the user
   the result is a sample, not a total.

Read-only connection note: the guardrail is a parser, not a privilege. The
execute path (``app.ai.tools.execute_sql``) additionally runs the statement
inside a transaction it never commits and, on PostgreSQL, issues
``SET TRANSACTION READ ONLY`` first (best-effort, warned on failure). The
database role serving the engine should additionally hold only ``SELECT``
grants; this module documents that requirement but cannot create the role.
"""
from __future__ import annotations

import re
from typing import Any, Dict, List, Tuple

MAX_SQL_CHARS = 8000
DEFAULT_ROW_LIMIT = 200
MAX_ROW_LIMIT = 5000

# Warehouse tables the assistant may read. Everything else — ops tables,
# auth tables, RAG corpus tables — is refused even when the statement is a
# plain SELECT, because the assistant has no need to see credentials,
# other users' conversations or the raw corpus.
ALLOWED_TABLES = frozenset({
    "fact_sales",
    "fact_inventory",
    "fact_purchases",
    "fact_expenses",
    "dim_customer",
    "dim_product",
    "dim_branch",
    "dim_supplier",
    "dim_warehouse",
    "dim_date",
    "dim_department",
})

# Refused anywhere outside a quoted literal. Word-boundary matched so a
# column named "updated_at" does not trip the "UPDATE" rule.
BLOCKED_KEYWORDS = (
    "insert", "update", "delete", "drop", "alter", "create", "truncate",
    "grant", "revoke", "exec", "execute", "call", "merge", "copy", "vacuum",
    "attach", "detach", "pragma",
)
_BLOCKED_RE = re.compile(
    r"\b(" + "|".join(BLOCKED_KEYWORDS) + r")\b", re.IGNORECASE)
_INTO_OUTFILE_RE = re.compile(r"\binto\s+(outfile|dumpfile)\b", re.IGNORECASE)
_LOAD_DATA_RE = re.compile(r"\bload\s+data\b", re.IGNORECASE)
_FROM_JOIN_RE = re.compile(
    r"\b(?:from|join)\s+([A-Za-z_][\w.]*|\".*?\"|'.*?')", re.IGNORECASE)
_LIMIT_RE = re.compile(r"\blimit\s+(\d+)", re.IGNORECASE)
_LEADING_COMMENT_RE = re.compile(
    r"\A(?:\s|--[^\n]*\n|/\*.*?\*/)*", re.DOTALL)
_STRING_RE = re.compile(r"'(?:[^'\\]|\\.|'')*'|\"(?:[^\"\\]|\\.|\"\")*\"")
_LINE_COMMENT_RE = re.compile(r"--[^\n]*")
_BLOCK_COMMENT_RE = re.compile(r"/\*.*?\*/", re.DOTALL)

READ_ONLY_ROLE_NOTE = (
    "Execute only on a connection/role with SELECT grants on the warehouse "
    "tables (ideally SET TRANSACTION READ ONLY); this validator is a parser, "
    "not a privilege boundary."
)


def _strip_literals(text: str) -> str:
    """Replace quoted literals with spaces so keywords inside them are inert."""
    return _STRING_RE.sub(lambda m: " " * len(m.group(0)), text)


def _strip_comments(text: str) -> str:
    """Remove ``--`` line comments and ``/* */`` block comments.

    Must run on text whose quoted literals were already stripped (see
    :func:`validate_sql`): a ``--`` inside a string is data, not a comment,
    and stripping it first would unbalance the quote and expose the string
    contents to the keyword scan.
    """
    return _BLOCK_COMMENT_RE.sub(" ", _LINE_COMMENT_RE.sub(" ", text))


def _split_statements(text: str) -> List[str]:
    """Split on ``;`` outside quoted literals and comments.

    Single-pass state machine: quotes protect ``;`` (``'; --'`` is data),
    and comments are skipped without touching string contents (a ``--``
    inside a literal must not eat the closing quote). Returns the raw
    statement parts, comments included — validation strips them later, after
    literals are blanked.
    """
    parts: List[str] = []
    current: List[str] = []
    i, n = 0, len(text)
    in_single = in_double = False
    while i < n:
        ch = text[i]
        nxt = text[i + 1] if i + 1 < n else ""
        if in_single:
            current.append(ch)
            if ch == "\\" and nxt:
                current.append(nxt)
                i += 2
                continue
            if ch == "'":
                if nxt == "'":  # escaped '' inside literal
                    current.append(nxt)
                    i += 2
                    continue
                in_single = False
            i += 1
            continue
        if in_double:
            current.append(ch)
            if ch == '"':
                if nxt == '"':
                    current.append(nxt)
                    i += 2
                    continue
                in_double = False
            i += 1
            continue
        if ch == "'":
            in_single = True
            current.append(ch)
            i += 1
            continue
        if ch == '"':
            in_double = True
            current.append(ch)
            i += 1
            continue
        if ch == "-" and nxt == "-":
            while i < n and text[i] != "\n":
                current.append(text[i])
                i += 1
            continue
        if ch == "/" and nxt == "*":
            i += 2
            while i < n and not (text[i] == "*" and i + 1 < n and text[i + 1] == "/"):
                i += 1
            i += 2
            current.append(" ")
            continue
        if ch == ";":
            parts.append("".join(current))
            current = []
            i += 1
            continue
        current.append(ch)
        i += 1
    parts.append("".join(current))
    return parts


def _table_names(sql: str) -> List[str]:
    """Return the raw table references after FROM/JOIN, unwrapped and lowered."""
    names: List[str] = []
    for match in _FROM_JOIN_RE.finditer(sql):
        raw = match.group(1).strip().strip("\"'")
        # "public.fact_sales" -> "fact_sales"; skip subselects/CTE parens.
        base = raw.split(".")[-1].strip().lower()
        if base and base not in ("select", "lateral"):
            names.append(base)
    return names


def warehouse_schema() -> Dict[str, List[str]]:
    """Return the allowlisted warehouse schema for NL-to-SQL generation prompts.

    Deterministic snapshot of the tables :data:`ALLOWED_TABLES` covers, with
    the join columns the assistant needs. The generator prompt embeds exactly
    this, so the model cannot invent tables the guardrail would then refuse.
    """
    return {
        "fact_sales": ["id", "transaction_date", "customer_id", "product_id",
                       "branch_id", "quantity", "selling_price", "discount",
                       "revenue"],
        "fact_inventory": ["id", "snapshot_date", "product_id", "warehouse_id",
                           "stock_qty"],
        "fact_purchases": ["id", "purchase_date", "supplier_id", "product_id",
                           "quantity", "cost"],
        "fact_expenses": ["id", "expense_date", "department_id", "amount",
                          "category"],
        "dim_customer": ["id", "customer_code", "customer_name", "segment",
                         "city"],
        "dim_product": ["id", "product_code", "product_name", "category",
                         "unit", "cost_price", "selling_price",
                         "description", "image_url"],
        "dim_branch": ["id", "branch_code", "branch_name", "city"],
        "dim_supplier": ["id", "supplier_code", "supplier_name"],
        "dim_warehouse": ["id", "warehouse_code", "warehouse_name"],
        "dim_date": ["date_key", "full_date", "year", "month", "day",
                     "weekday"],
        "dim_department": ["id", "dept_code", "dept_name"],
    }


def validate_sql(sql: Any) -> Dict[str, Any]:
    """Validate one SQL statement without executing it.

    Returns ``{"allowed": bool, "reason": str, "normalized_sql": str,
    "limit": int, "limit_enforced": bool, "tables": [str], "note": str}``.
    ``reason`` is ``"ok"`` when allowed, otherwise a stable snake_case code
    with a human suffix (``"refused:not_select — ..."``) so callers can match
    on the prefix and show the whole string to the user.
    """
    result: Dict[str, Any] = {
        "allowed": False, "reason": "", "normalized_sql": "",
        "limit": DEFAULT_ROW_LIMIT, "limit_enforced": False,
        "tables": [], "note": READ_ONLY_ROLE_NOTE,
    }
    text = sql if isinstance(sql, str) else str(sql or "")
    if not text.strip():
        result["reason"] = "refused:empty — no SQL statement was provided"
        return result
    if len(text) > MAX_SQL_CHARS:
        result["reason"] = (
            f"refused:too_long — statement is {len(text)} characters, "
            f"limit is {MAX_SQL_CHARS}")
        return result

    statements = [p for p in _split_statements(text) if p.strip()]
    if len(statements) != 1:
        result["reason"] = (
            "refused:multi_statement — exactly one SELECT statement is "
            "allowed, no stacked queries")
        return result
    single = statements[0].strip()

    # Literals are blanked BEFORE comments are stripped: a "--" or "/*" inside
    # a quoted string is data, and stripping comments first would unbalance
    # the quote and expose the string contents to every scan below.
    bare = _strip_comments(_strip_literals(single))
    head = _LEADING_COMMENT_RE.sub("", single).strip()[:64].upper()
    if not (head.startswith("SELECT") or head.startswith("WITH")):
        result["reason"] = (
            "refused:not_select — only a single SELECT (or WITH ... SELECT) "
            "statement is allowed")
        return result
    if _BLOCKED_RE.search(bare):
        result["reason"] = (
            "refused:blocked_keyword — statement contains a write/DDL "
            "keyword; only reads are allowed")
        return result
    if _INTO_OUTFILE_RE.search(bare) or _LOAD_DATA_RE.search(bare):
        result["reason"] = (
            "refused:file_access — SELECT ... INTO OUTFILE and LOAD DATA "
            "are not allowed")
        return result

    tables = _table_names(bare)
    result["tables"] = tables
    if not tables:
        result["reason"] = (
            "refused:no_table — could not find a FROM/JOIN table reference")
        return result
    blocked = [t for t in tables if t not in ALLOWED_TABLES]
    if blocked:
        result["reason"] = (
            "refused:blocked_table — table(s) not in the read allowlist: "
            + ", ".join(sorted(set(blocked))))
        return result

    match = _LIMIT_RE.search(bare)
    normalized = single.rstrip().rstrip(";")
    if match:
        try:
            asked = int(match.group(1))
        except ValueError:
            asked = DEFAULT_ROW_LIMIT
        if asked < 1:
            result["reason"] = "refused:bad_limit — LIMIT must be >= 1"
            return result
        if asked > MAX_ROW_LIMIT:
            normalized = _LIMIT_RE.sub(f"LIMIT {MAX_ROW_LIMIT}", normalized, count=1)
            result["limit"] = MAX_ROW_LIMIT
            result["limit_enforced"] = True
        else:
            result["limit"] = asked
    else:
        normalized = f"{normalized} LIMIT {DEFAULT_ROW_LIMIT}"
        result["limit"] = DEFAULT_ROW_LIMIT
        result["limit_enforced"] = True

    result["allowed"] = True
    result["reason"] = "ok"
    result["normalized_sql"] = normalized
    return result


def refusal_message(validation: Dict[str, Any]) -> str:
    """Render a user-facing refusal for a failed validation (Indonesian)."""
    reason = str(validation.get("reason") or "refused")
    return (
        "Kueri SQL ditolak oleh guardrail dan TIDAK dijalankan "
        f"({reason}). Hanya satu pernyataan SELECT atas tabel gudang "
        "yang diizinkan; sebutkan tabel dan kolom yang Anda butuhkan.")
