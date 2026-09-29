"""Static parity between the engine's two independent declarations of its schema.

The warehouse/ops schema is declared twice: once as SQLAlchemy models in
``app/database/models.py`` and once as hand-written Alembic revisions in
``alembic/versions/``. Nothing compares them, so a drift between the two is
silent -- the container boots, the app serves traffic, and every query against
the drifting column fails at runtime. ``0001`` was generated from the models so
it matched once; the models have changed since, and ``0002`` adds indexes by
hand.

This module closes that gap with a purely static comparison:

* no database, no connection, no fixtures;
* no import of ``app.database.models`` -- that would pull in SQLAlchemy and
  build an engine from ``DATABASE_URL`` at import time. Both sources are parsed
  with :mod:`ast` and never executed.

What is asserted
----------------
1. the same set of tables in the models and in the migrations;
2. the same columns per table, in both directions (a column with no model and a
   column with no migration are both failures);
3. the same nullability wherever it is declared on both sides;
4. the same primary key and the same foreign-key targets per table;
5. the same rendered column type signature wherever it is statically resolvable;
6. no table is created by both the engine and the Laravel application;
7. every table named in ``docs/data-dictionary.md`` is created by exactly one of
   the two DDL owners;
8. no revision declares a table it never creates, and every revision that
   creates tables also drops them, so ``downgrade()`` is real.

Ownership is one-way by design: the engine owns the warehouse/ML/AI/ops tables
via Alembic, Laravel owns the app tables, and ``audit_logs`` is declared exactly
once, by Laravel. See ``docs/data-dictionary.md`` §7.

Tolerated divergence
--------------------
Indexes are deliberately out of scope. ``0002_query_indexes.py`` adds three
``import_job_id`` indexes the models do not declare, because the ETL purge
delete needs them; the models describe the ORM, the migrations describe the
queries. Only tables, columns, keys and types are compared here.

``rag_chunks`` is the one table declared by two model classes (the pgvector and
the no-pgvector fallback, selected by the ``_HAS_PGVECTOR`` import guard). Its
two declarations must agree on the column *names*; the migration must match at
least one of them column for column, which is what lets ``0001`` pick the
pgvector branch's ``nullable=True`` for ``embedding`` while a pgvector-less
local stack still gets a working model.
"""
from __future__ import annotations

import ast
import re
from pathlib import Path
from typing import Any, NamedTuple

import pytest

ENGINE_ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = ENGINE_ROOT.parent
MODELS_PY = ENGINE_ROOT / "app" / "database" / "models.py"
VERSIONS_DIR = ENGINE_ROOT / "alembic" / "versions"
DATA_DICTIONARY = REPO_ROOT / "docs" / "data-dictionary.md"
LARAVEL_MIGRATIONS = REPO_ROOT / "application" / "database" / "migrations"

KNOWN_TYPE_NAMES = {
    "BigInteger", "Boolean", "Date", "DateTime", "Float", "Integer", "JSON",
    "LargeBinary", "Numeric", "SmallInteger", "String", "Text",
}


# --------------------------------------------------------------------------
# shared value types
# --------------------------------------------------------------------------
class _Opaque:
    """A value the static evaluator cannot resolve (an unbound name, a type object)."""

    __slots__ = ()

    def __repr__(self) -> str:
        return "<opaque>"


OPAQUE = _Opaque()


class Column(NamedTuple):
    name: str
    type: str | None
    nullable: bool | None
    primary_key: bool
    foreign_key: str | None


class TableSpec(NamedTuple):
    name: str
    columns: dict[str, Column]
    origin: str


class _ForeignKey(NamedTuple):
    target: Any


class _DeclaredIndex(NamedTuple):
    name: str
    table: str | None
    columns: tuple[str, ...]


def _describe(obj: object) -> str:
    return repr(obj)


# --------------------------------------------------------------------------
# small AST helpers
# --------------------------------------------------------------------------
def _func_name(node: ast.AST) -> str:
    if isinstance(node, ast.Name):
        return node.id
    if isinstance(node, ast.Attribute):
        return node.attr
    return ""


def _kwarg(call: ast.Call, name: str) -> ast.AST | None:
    for keyword in call.keywords:
        if keyword.arg == name:
            return keyword.value
    return None


def _kwarg_bool(call: ast.Call, name: str) -> bool:
    node = _kwarg(call, name)
    if node is None:
        return False
    try:
        return bool(ast.literal_eval(node))
    except (ValueError, SyntaxError):
        return False


def _is_call_to(node: ast.AST, name: str) -> bool:
    return isinstance(node, ast.Call) and _func_name(node.func) == name


def _render_type(node: ast.AST) -> str | None:
    """Render a type expression as a comparable signature, e.g. ``String(512)``.

    Returns ``None`` when the type is not statically resolvable (an alias bound
    inside a ``try``/``except``, a locally computed object), which tells the
    caller to skip the comparison rather than guess.
    """
    if isinstance(node, ast.Name):
        return node.id if node.id in KNOWN_TYPE_NAMES else None
    if isinstance(node, ast.Attribute):
        return node.attr if node.attr in KNOWN_TYPE_NAMES else None
    if isinstance(node, ast.Call):
        name = _func_name(node.func)
        if name not in KNOWN_TYPE_NAMES and name != "Vector":
            return None
        parts = [ast.unparse(a) for a in node.args]
        parts += [
            f"{kw.arg}={ast.unparse(kw.value)}"
            for kw in node.keywords
            if kw.arg is not None
        ]
        return f"{name}({', '.join(parts)})" if parts else name
    return None


# --------------------------------------------------------------------------
# models.py
# --------------------------------------------------------------------------
def _annotation_nullable(annotation: ast.AST | None) -> bool | None:
    """Resolve ``Mapped[...]`` nullability: ``Mapped[int | None]`` -> True."""
    if not isinstance(annotation, ast.Subscript):
        return None
    name = _func_name(annotation.value)
    if name not in {"Mapped", "mapped_column"}:
        return None
    inner = annotation.slice
    parts: list[str] = []
    if isinstance(inner, ast.BinOp) and isinstance(inner.op, ast.BitOr):
        parts = [ast.unparse(inner.left), ast.unparse(inner.right)]
    elif isinstance(inner, ast.Subscript) and _func_name(inner.value) in {"Optional", "Union"}:
        parts = [ast.unparse(e) for e in inner.slice.elts]
    else:
        return False
    return "None" in parts


def _model_column(name: str, value: ast.AST, annotation: ast.AST | None, origin: str) -> Column:
    if not isinstance(value, ast.Call):
        return Column(name, None, None, False, None)
    type_node = value.args[0] if value.args else None
    foreign_key = None
    for arg in value.args[1:]:
        if _is_call_to(arg, "ForeignKey"):
            foreign_key = _foreign_key_target(arg)
    if _is_call_to(type_node, "ForeignKey"):
        foreign_key = _foreign_key_target(type_node)
    nullable_node = _kwarg(value, "nullable")
    if nullable_node is not None:
        nullable: bool | None = bool(ast.literal_eval(nullable_node))
    else:
        nullable = _annotation_nullable(annotation)
    return Column(
        name=name,
        type=None if foreign_key is not None else _render_type(type_node),
        nullable=nullable,
        primary_key=_kwarg_bool(value, "primary_key"),
        foreign_key=foreign_key,
    )


def _foreign_key_target(node: ast.AST) -> str | None:
    if isinstance(node, ast.Call) and node.args:
        try:
            return str(ast.literal_eval(node.args[0]))
        except (ValueError, SyntaxError):
            return None
    return None


def _is_mapped_column(node: ast.AST) -> bool:
    return isinstance(node, ast.Call) and _func_name(node.func) == "mapped_column"


def _model_columns(cls: ast.ClassDef) -> dict[str, Column]:
    columns: dict[str, Column] = {}
    origin = f"{MODELS_PY.name}:{cls.lineno} {cls.name}"
    for stmt in cls.body:
        if isinstance(stmt, ast.AnnAssign) and isinstance(stmt.target, ast.Name):
            if stmt.value is not None and _is_mapped_column(stmt.value):
                columns[stmt.target.id] = _model_column(
                    stmt.target.id, stmt.value, stmt.annotation, origin
                )
        elif isinstance(stmt, ast.Assign) and len(stmt.targets) == 1:
            target = stmt.targets[0]
            if isinstance(target, ast.Name) and _is_mapped_column(stmt.value):
                columns[target.id] = _model_column(target.id, stmt.value, None, origin)
    return columns


def _class_tablename(cls: ast.ClassDef) -> str | None:
    for stmt in cls.body:
        if not isinstance(stmt, ast.Assign) or len(stmt.targets) != 1:
            continue
        target = stmt.targets[0]
        if isinstance(target, ast.Name) and target.id == "__tablename__":
            try:
                return str(ast.literal_eval(stmt.value))
            except (ValueError, SyntaxError):
                return None
    return None


def _parse_models() -> dict[str, list[TableSpec]]:
    """Return ``{table: [spec per declaring class]}``.

    A table with more than one spec is declared by more than one class (the
    pgvector fallback); every other table has exactly one.
    """
    tree = ast.parse(MODELS_PY.read_text(encoding="utf-8"), filename=str(MODELS_PY))
    classes = [n for n in ast.walk(tree) if isinstance(n, ast.ClassDef)]
    mixins: dict[str, list[dict[str, Column]]] = {}
    for cls in classes:
        if _class_tablename(cls) is None:
            mixins[cls.name] = [_model_columns(cls)]

    resolved: dict[str, dict[str, Column]] = {}

    def resolve(cls: ast.ClassDef, seen: frozenset[str] = frozenset()) -> dict[str, Column]:
        if cls.name in resolved:
            return resolved[cls.name]
        columns: dict[str, Column] = {}
        for base in cls.bases:
            if isinstance(base, ast.Name) and base.id in mixins and base.id not in seen:
                for variant in mixins[base.id]:
                    columns.update(variant)
        columns.update(_model_columns(cls))
        resolved[cls.name] = columns
        return columns

    tables: dict[str, list[TableSpec]] = {}
    for cls in classes:
        tablename = _class_tablename(cls)
        if tablename is None:
            continue
        origin = f"{MODELS_PY.name}:{cls.lineno} {cls.name}"
        tables.setdefault(tablename, []).append(
            TableSpec(tablename, resolve(cls, frozenset({cls.name})), origin)
        )
    return tables


# --------------------------------------------------------------------------
# alembic revisions
# --------------------------------------------------------------------------
def _module_env(tree: ast.Module) -> dict[str, ast.AST]:
    """Collect module-level functions and assignments.

    Bindings inside ``if``/``try`` blocks are deliberately not collected, so a
    name such as ``_EMBEDDING_TYPE`` (assigned in a ``try``) resolves to
    :data:`OPAQUE` and the affected column type is compared as unknown.
    """
    env: dict[str, ast.AST] = {}
    for node in tree.body:
        if isinstance(node, (ast.FunctionDef, ast.ClassDef)):
            env[node.name] = node
        elif isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            env[node.targets[0].id] = node.value
        elif isinstance(node, ast.AnnAssign) and isinstance(node.target, ast.Name) and node.value is not None:
            env[node.target.id] = node.value
    return env


class _Evaluator:
    """Evaluate the literal subset of Python that the revisions are written in.

    It resolves the ``TABLES`` tuple in ``0001`` -- including its ``*_pk()`` and
    ``*_timestamps()`` helpers -- without importing SQLAlchemy, Alembic or the
    module itself.
    """

    def __init__(self, env: dict[str, ast.AST], origin: str) -> None:
        self.env = env
        self.origin = origin
        self.memo: dict[str, Any] = {}
        self.in_progress: set[str] = set()

    def eval(self, node: ast.AST | None) -> Any:
        if node is None:
            return None
        if isinstance(node, ast.Constant):
            return node.value
        if isinstance(node, (ast.Tuple, ast.List)):
            out: list[Any] = []
            for element in node.elts:
                value = self.eval(element.value if isinstance(element, ast.Starred) else element)
                if isinstance(value, list):
                    out.extend(value)
                else:
                    out.append(value)
            return out
        if isinstance(node, ast.Name):
            if node.id in self.memo:
                return self.memo[node.id]
            target = self.env.get(node.id)
            if target is None or node.id in self.in_progress:
                return OPAQUE
            self.in_progress.add(node.id)
            value = self.eval(target)
            self.in_progress.discard(node.id)
            self.memo[node.id] = value
            return value
        if isinstance(node, ast.Call):
            return self._call(node)
        return OPAQUE

    def _call(self, node: ast.Call) -> Any:
        name = _func_name(node.func)
        if isinstance(node.func, ast.Name) and isinstance(self.env.get(node.func.id), ast.FunctionDef):
            return self._call_function(self.env[node.func.id])
        if name == "Column":
            return self._column(node)
        if name == "ForeignKey":
            return _ForeignKey(self.eval(node.args[0]) if node.args else None)
        if name == "Index":
            return self._index(node)
        if name == "Table" and isinstance(self.env.get("Table"), ast.ClassDef):
            return self._table(node)
        return OPAQUE

    def _call_function(self, func: ast.FunctionDef) -> Any:
        returns = [stmt for stmt in func.body if isinstance(stmt, ast.Return)]
        if len(returns) != 1 or returns[0].value is None:
            return OPAQUE
        return self.eval(returns[0].value)

    def _column(self, node: ast.Call) -> Any:
        if not node.args or not isinstance(node.args[0], ast.Constant):
            return OPAQUE
        name = str(node.args[0].value)
        type_node = node.args[1] if len(node.args) > 1 else None
        foreign_key = None
        for arg in node.args[1:]:
            if _is_call_to(arg, "ForeignKey"):
                foreign_key = _foreign_key_target(arg)
        nullable_node = _kwarg(node, "nullable")
        nullable = bool(ast.literal_eval(nullable_node)) if nullable_node is not None else None
        return Column(
            name=name,
            type=_render_type(type_node),
            nullable=nullable,
            primary_key=_kwarg_bool(node, "primary_key"),
            foreign_key=foreign_key,
        )

    def _index(self, node: ast.Call) -> Any:
        if not node.args or not isinstance(node.args[0], ast.Constant):
            return OPAQUE
        columns = self.eval(node.args[1]) if len(node.args) > 1 else []
        return _DeclaredIndex(
            name=str(node.args[0].value),
            table=None,
            columns=tuple(str(c) for c in columns if isinstance(c, str)),
        )

    def _table(self, node: ast.Call) -> Any:
        if not node.args or not isinstance(node.args[0], ast.Constant):
            return OPAQUE
        raw_columns = self.eval(node.args[1]) if len(node.args) > 1 else []
        columns = {c.name: c for c in raw_columns if isinstance(c, Column)}
        indexes = [c for c in raw_columns if isinstance(c, _DeclaredIndex)]
        if len(node.args) > 2:
            indexes += [c for c in self.eval(node.args[2]) if isinstance(c, _DeclaredIndex)]
        return TableSpec(
            name=str(node.args[0].value),
            columns=columns,
            origin=f"{self.origin}:{node.lineno} Table()",
        ), tuple(indexes)


def _op_calls(tree: ast.Module, op_name: str) -> list[ast.Call]:
    return [
        node
        for node in ast.walk(tree)
        if isinstance(node, ast.Call)
        and _func_name(node.func) == op_name
        and isinstance(node.func, ast.Attribute)
        and isinstance(node.func.value, ast.Name)
        and node.func.value.id == "op"
    ]


def _parse_revision(path: Path) -> tuple[dict[str, TableSpec], dict[str, TableSpec], int]:
    """Return (declared tables, tables created by ``op.create_table``, create calls)."""
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=path.name)
    env = _module_env(tree)
    evaluator = _Evaluator(env, path.name)
    declared: dict[str, TableSpec] = {}
    for node in tree.body:
        if not isinstance(node, (ast.Assign, ast.AnnAssign)):
            continue
        for item in _flatten(evaluator.eval(node.value)):
            if isinstance(item, tuple) and len(item) == 2 and isinstance(item[0], TableSpec):
                spec = item[0]
                declared[spec.name] = spec

    created: dict[str, TableSpec] = dict(declared)
    for call in _op_calls(tree, "create_table"):
        if not call.args or not isinstance(call.args[0], ast.Constant):
            continue
        literal = _Evaluator(env, path.name)._table(
            ast.Call(func=ast.Name(id="Table", ctx=ast.Load()), args=list(call.args), keywords=[])
        )
        if isinstance(literal, tuple) and isinstance(literal[0], TableSpec):
            created[literal[0].name] = literal[0]
    return declared, created, len(_op_calls(tree, "create_table"))


def _apply_column_additions(
    tables: dict[str, TableSpec],
) -> None:
    """Merge ``op.add_column`` calls from later revisions into the table specs.

    Revisions after 0001 alter tables instead of creating them (e.g. 0003
    adds display columns to dim_product), so the per-revision ``created``
    maps never see those columns. Only literal calls are understood; anything
    fancier resolves to OPAQUE and is skipped rather than misread.
    """
    for path in REVISION_FILES:
        tree = ast.parse(path.read_text(encoding="utf-8"), filename=path.name)
        env = _module_env(tree)
        for call in _op_calls(tree, "add_column"):
            if len(call.args) < 2 or not isinstance(call.args[0], ast.Constant):
                continue
            table = str(call.args[0].value)
            column = _Evaluator(env, path.name).eval(call.args[1])
            if table not in tables or not isinstance(column, Column):
                continue
            spec = tables[table]
            tables[table] = TableSpec(
                name=spec.name,
                columns={**spec.columns, column.name: column},
                origin=f"{spec.origin}+{path.name}:{call.lineno}",
            )


def _flatten(value: Any) -> list[Any]:
    if isinstance(value, list):
        out: list[Any] = []
        for item in value:
            out.extend(_flatten(item))
        return out
    return [value]


# --------------------------------------------------------------------------
# application/database/migrations (PHP)
# --------------------------------------------------------------------------
_LARAVEL_CREATE = re.compile(r"""Schema::create\(\s*['"]([a-z0-9_]+)['"]""")


def _laravel_tables() -> dict[str, str]:
    tables: dict[str, str] = {}
    for path in sorted(LARAVEL_MIGRATIONS.glob("*.php")):
        text = path.read_text(encoding="utf-8", errors="replace")
        for name in _LARAVEL_CREATE.findall(text):
            tables[name] = path.name
    return tables


# --------------------------------------------------------------------------
# docs/data-dictionary.md
# --------------------------------------------------------------------------
_BACKTICK = re.compile(r"`([^`]+)`", re.DOTALL)
_SIGNATURE = re.compile(r"^([a-z][a-z0-9_]*)\(([\s\S]*)\)$")
_HEADING = re.compile(r"^#{2,6}\s+`([a-z][a-z0-9_]*)`", re.MULTILINE)
_TABLE_ROW = re.compile(r"^\|\s*`([a-z][a-z0-9_]*)`\s*\|", re.MULTILINE)
_COLUMNS = re.compile(r"^[A-Za-z_][A-Za-z0-9_.]*")


def _doc_tables() -> set[str]:
    """Extract the table names the data dictionary declares.

    Two shapes count: a backticked signature (`` `import_jobs(id, ...)` ``) and
    a backticked standalone name in a heading or a table row (``### `users` ``,
    ``| `dim_customer` |``). Everything else backticked in the document is prose
    -- column names, enum values, type words, dotted references -- and is not a
    table, so a broad identifier scan is not usable here.
    """
    text = DATA_DICTIONARY.read_text(encoding="utf-8")
    found: set[str] = set()
    found.update(_HEADING.findall(text))
    found.update(_TABLE_ROW.findall(text))
    for span in _BACKTICK.findall(text):
        match = _SIGNATURE.match(span)
        if not match:
            continue
        name, body = match.group(1), match.group(2)
        parts = [part.strip() for part in body.split(",") if part.strip()]
        if parts and all(_COLUMNS.match(part) for part in parts):
            found.add(name)
    return found


# --------------------------------------------------------------------------
# module-level facts
# --------------------------------------------------------------------------
MODEL_TABLES = _parse_models()
REVISION_FILES = sorted(VERSIONS_DIR.glob("*.py"))
REVISIONS = {path.name: _parse_revision(path) for path in REVISION_FILES}
MIGRATION_TABLES: dict[str, TableSpec] = {}
for _declared, _created, _calls in REVISIONS.values():
    MIGRATION_TABLES.update(_created)
_apply_column_additions(MIGRATION_TABLES)
LARAVEL_TABLES = _laravel_tables()
DOC_TABLES = _doc_tables()

MODEL_ONLY = sorted(set(MODEL_TABLES) - set(MIGRATION_TABLES))
MIGRATION_ONLY = sorted(set(MIGRATION_TABLES) - set(MODEL_TABLES))
ALL_TABLES = sorted(set(MODEL_TABLES) | set(MIGRATION_TABLES))


def _format(columns: dict[str, Column]) -> str:    return ", ".join(
        f"{c.name}"
        + ("" if c.nullable is None else ("" if c.nullable else " NOT NULL"))
        + ("" if not c.foreign_key else f" -> {c.foreign_key}")
        for c in columns.values()
    )


def _pick_model_spec(table: str, migration: TableSpec) -> TableSpec:
    """Return the declaring model class that the migration matches best."""
    specs = MODEL_TABLES[table]
    return min(
        specs,
        key=lambda spec: len(set(spec.columns) ^ set(migration.columns)),
    )


def _spec_failures(table: str, check: Any) -> list[tuple[str, list[str]]]:
    """Run ``check(model_spec)`` against every declaring model class.

    A table declared by more than one class (the pgvector fallback) is allowed
    to satisfy the check on any one of them; the returned list holds one entry
    per class that fails, so an empty list means at least one class matched.
    """
    failures: list[tuple[str, list[str]]] = []
    for spec in MODEL_TABLES[table]:
        items = check(spec)
        if items:
            failures.append((spec.origin, items))
    return failures


# --------------------------------------------------------------------------
# 1. the same set of tables
# --------------------------------------------------------------------------
def test_model_and_migration_table_sets_are_identical() -> None:
    model_only = sorted(set(MODEL_TABLES) - set(MIGRATION_TABLES))
    migration_only = sorted(set(MIGRATION_TABLES) - set(MODEL_TABLES))
    assert model_only == [], (
        f"tables declared in {MODELS_PY.name} but never created by any revision "
        f"(every query against them fails at runtime): {model_only}"
    )
    assert migration_only == [], (
        "tables created by a revision with no model behind them (dead DDL that "
        f"no ORM will ever use): {migration_only}"
    )


def test_every_revision_was_parsed() -> None:
    assert [path.name for path in REVISION_FILES] == list(REVISIONS)
    assert MIGRATION_TABLES, "no table parsed from the revisions -- parser regression"


# --------------------------------------------------------------------------
# 2/3. same columns, same nullability
# --------------------------------------------------------------------------
@pytest.mark.parametrize("table", ALL_TABLES)
def test_columns_match(table: str) -> None:
    migration = MIGRATION_TABLES[table]
    model = _pick_model_spec(table, migration)
    in_migration = set(migration.columns)
    in_model = set(model.columns)
    assert in_model - in_migration == set(), (
        f"{table}: columns in {model.origin} with no column in the migration: "
        f"{sorted(in_model - in_migration)}"
    )
    assert in_migration - in_model == set(), (
        f"{table}: columns created by the migration with no model attribute: "
        f"{sorted(in_migration - in_model)}"
    )


@pytest.mark.parametrize("table", ALL_TABLES)
def test_nullability_matches_where_declared(table: str) -> None:
    migration = MIGRATION_TABLES[table]

    def check(spec: TableSpec) -> list[str]:
        conflicting = []
        for name, column in spec.columns.items():
            migrated = migration.columns[name]
            if column.nullable is None or migrated.nullable is None:
                continue
            if column.nullable != migrated.nullable:
                conflicting.append(
                    f"{name}: model nullable={column.nullable} vs migration "
                    f"nullable={migrated.nullable}"
                )
        return conflicting

    failures = _spec_failures(table, check)
    assert failures == [], (
        f"{table}: nullability differs and no model class matches the migration. "
        + "; ".join(f"[{origin}] {', '.join(items)}" for origin, items in failures)
    )


# --------------------------------------------------------------------------
# 4. same keys
# --------------------------------------------------------------------------
@pytest.mark.parametrize("table", ALL_TABLES)
def test_primary_keys_match(table: str) -> None:
    migration = MIGRATION_TABLES[table]
    migration_pk = sorted(c.name for c in migration.columns.values() if c.primary_key)

    def check(spec: TableSpec) -> list[str]:
        model_pk = sorted(c.name for c in spec.columns.values() if c.primary_key)
        return [] if model_pk == migration_pk else [
            f"models {model_pk} vs migration {migration_pk}"
        ]

    failures = _spec_failures(table, check)
    assert failures == [], (
        f"{table}: primary key columns differ in {migration.origin}. "
        + "; ".join(f"[{origin}] {items[0]}" for origin, items in failures)
    )


@pytest.mark.parametrize("table", ALL_TABLES)
def test_foreign_keys_match(table: str) -> None:
    migration = MIGRATION_TABLES[table]
    model = _pick_model_spec(table, migration)
    migration_fks = {c.name: c.foreign_key for c in migration.columns.values() if c.foreign_key}
    model_fks = {c.name: c.foreign_key for c in model.columns.values() if c.foreign_key}
    assert model_fks == migration_fks, (
        f"{table}: foreign keys differ -- models {model_fks} vs migration {migration_fks}"
    )


# --------------------------------------------------------------------------
# 5. same types where statically resolvable
# --------------------------------------------------------------------------
@pytest.mark.parametrize("table", ALL_TABLES)
def test_column_types_match_where_resolvable(table: str) -> None:
    migration = MIGRATION_TABLES[table]

    def check(spec: TableSpec) -> list[str]:
        conflicting = []
        for name, column in spec.columns.items():
            migrated = migration.columns[name]
            if column.type is None or migrated.type is None:
                continue
            if column.type != migrated.type:
                conflicting.append(f"{name}: model {column.type} vs migration {migrated.type}")
        return conflicting

    failures = _spec_failures(table, check)
    assert failures == [], (
        f"{table}: column types differ. "
        + "; ".join(f"[{origin}] {', '.join(items)}" for origin, items in failures)
    )


# --------------------------------------------------------------------------
# 6/7. ownership
# --------------------------------------------------------------------------
def test_no_table_is_created_by_both_owners() -> None:
    collisions = sorted(set(MIGRATION_TABLES) & set(LARAVEL_TABLES))
    assert collisions == [], (
        "created by both Alembic and a Laravel migration; whichever container "
        f"migrates second fails on 'relation already exists': {collisions}"
    )


def test_audit_logs_is_owned_by_laravel_only() -> None:
    assert "audit_logs" in LARAVEL_TABLES, (
        "audit_logs must be created by the Laravel application, see "
        "docs/data-dictionary.md section 7"
    )
    assert "audit_logs" not in MIGRATION_TABLES, (
        "a revision creates audit_logs, which collides with "
        + LARAVEL_TABLES.get("audit_logs", "the Laravel migration")
    )
    assert "audit_logs" not in MODEL_TABLES, (
        "a model declares audit_logs; the engine has no audit writer and would "
        "race the Laravel migration"
    )


# --------------------------------------------------------------------------
# 8. documentation
# --------------------------------------------------------------------------
def test_doc_table_extraction_found_the_documented_tables() -> None:
    assert len(DOC_TABLES) >= 30, (
        f"only {len(DOC_TABLES)} table names extracted from the data dictionary: "
        f"{sorted(DOC_TABLES)} -- the extractor has probably stopped matching"
    )
    assert {"raw_uploads", "dim_customer", "fact_sales", "audit_logs", "users"} <= DOC_TABLES


@pytest.mark.parametrize("table", sorted(DOC_TABLES))
def test_documented_table_has_exactly_one_ddl_owner(table: str) -> None:
    owners = []
    if table in MIGRATION_TABLES:
        owners.append("ai-engine alembic")
    if table in LARAVEL_TABLES:
        owners.append("laravel migration " + LARAVEL_TABLES[table])
    assert len(owners) == 1, (
        f"{table} is documented in docs/data-dictionary.md but is created by "
        f"{owners or 'no source at all'}"
    )


def test_documented_engine_tables_match_the_models() -> None:
    documented = DOC_TABLES & set(MIGRATION_TABLES)
    assert documented <= set(MODEL_TABLES), (
        f"documented as engine tables with no model: {sorted(documented - set(MODEL_TABLES))}"
    )


# --------------------------------------------------------------------------
# revision hygiene
# --------------------------------------------------------------------------
@pytest.mark.parametrize("name", sorted(REVISIONS))
def test_declared_tables_are_actually_created(name: str) -> None:
    declared, _created, create_calls = REVISIONS[name]
    if not declared:
        return
    assert create_calls > 0, (
        f"{name} declares {sorted(declared)} but never calls op.create_table: "
        "the tables would not exist in a fresh database"
    )


@pytest.mark.parametrize("name", sorted(REVISIONS))
def test_created_tables_are_dropped_on_downgrade(name: str) -> None:
    _declared, created, create_calls = REVISIONS[name]
    if not create_calls or not created:
        return
    path = next(p for p in REVISION_FILES if p.name == name)
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=path.name)
    assert _op_calls(tree, "drop_table"), (
        f"{name} creates {sorted(created)} but never calls op.drop_table: "
        "downgrade() cannot restore the previous state"
    )


# --------------------------------------------------------------------------
# human-readable parity table
# --------------------------------------------------------------------------
def test_parity_table() -> None:
    revisions_creating = {
        name for name, (_d, created, calls) in REVISIONS.items() if created and calls
    }
    rows = []
    for table in sorted(set(MODEL_TABLES) | set(MIGRATION_TABLES) | DOC_TABLES):
        in_models = table in MODEL_TABLES
        in_migrations = table in MIGRATION_TABLES
        origin = sorted(
            name for name, (_d, created, _c) in REVISIONS.items() if table in created
        )
        if in_models and in_migrations:
            verdict = "parity"
        elif in_models:
            verdict = "MODEL WITHOUT DDL"
        else:
            verdict = "DDL WITHOUT MODEL"
        if table in LARAVEL_TABLES and in_migrations:
            verdict = "OWNERSHIP COLLISION"
        rows.append(
            f"{table:24} {'yes' if in_models else '-':4} "
            f"{','.join(origin) or '-':26} {'-':3} {verdict}"
        )
    for table in sorted(DOC_TABLES - set(MODEL_TABLES) - set(MIGRATION_TABLES)):
        if table in LARAVEL_TABLES:
            rows.append(f"{table:24} {'-':4} {'-':26} {'-':3} laravel-only (documented)")
    print("\ntable                     models  in migrations                 0003  verdict")
    print("\n".join(rows))
    assert revisions_creating, "no revision was recognised as creating tables"
