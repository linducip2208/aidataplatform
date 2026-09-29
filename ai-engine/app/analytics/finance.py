"""Finance analytics: revenue, COGS, expenses, margins.

Accounting definitions implemented here (this module is the single source of
truth for ``GET /analytics/finance``, the ``finance.*`` alert metrics and the
``query_finance`` tool):

``total_revenue``
    Net sales. The stated ``revenue`` measure wins when the frame carries one;
    a row whose revenue is null falls back to ``quantity * selling_price -
    discount``. Revenue is never derived pre-discount: the ETL stores
    ``revenue = max(0, quantity * selling_price - discount)`` (``etl.write_facts``)
    and :mod:`app.analytics.sales` applies the same rule, so a frame that
    arrives without a ``revenue`` column must not disagree with ``/analytics/kpi``
    for the same rows.
``total_cogs``
    Cost of the goods **sold**, priced from the sales frame as
    ``quantity * unit_cost`` whenever that frame carries a product cost. This
    is deliberately *not* the sum of ``fact_purchases``: purchases are a
    cash-basis proxy that drifts from the goods actually sold across a period
    boundary, so summing it would misstate COGS. A purchases frame is used only
    as a fallback when the sales frame cannot price what was sold.
``total_expenses``
    Sum of ``fact_expenses.amount`` over the same window, signed, so an expense
    credit (a negative amount) reduces the total instead of being silently
    dropped.
``margin_pct``
    **Net margin on revenue**: ``net_profit / total_revenue * 100``. The alerts
    registry documents ``finance.margin_pct`` as "net margin percentage for the
    window" (``app/alerts/rules.py``), so net — not gross — is the contract, and
    the denominator is revenue. Zero revenue yields ``0.0`` rather than a
    division error.

Cost and revenue are floored at zero because the ETL floors revenue at zero on
write; a negative COGS is not a meaningful number. Expenses are left signed.
"""
from __future__ import annotations

import math
from typing import Any, Dict, Iterable, Optional

import pandas as pd

# Column aliases in *priority* order. Matching is by priority, never by the
# frame's column order: a frame carrying both ``total`` and ``revenue`` must
# resolve to ``revenue`` regardless of which column the producer emitted first.
_REVENUE_COLUMNS: tuple[str, ...] = ("revenue", "omzet", "nilai", "total", "subtotal")
_QUANTITY_COLUMNS: tuple[str, ...] = ("quantity", "qty", "jumlah", "jml", "volume")
_PRICE_COLUMNS: tuple[str, ...] = ("selling_price", "harga jual", "price")
_DISCOUNT_COLUMNS: tuple[str, ...] = ("discount", "diskon", "disc")
_UNIT_COST_COLUMNS: tuple[str, ...] = ("cost_price", "unit_cost", "cost", "harga beli", "harga pokok")
_LINE_TOTAL_COLUMNS: tuple[str, ...] = (
    "cogs", "cost_of_goods_sold", "total_cost", "line_total", "total",
)
_AMOUNT_COLUMNS: tuple[str, ...] = ("amount", "nominal", "biaya", "pengeluaran")
_SALES_DATE_COLUMNS: tuple[str, ...] = (
    "transaction_date", "tanggal transaksi", "tgl", "date", "order date",
)


def _column(d: pd.DataFrame, names: Iterable[str]) -> Optional[pd.Series]:
    """Return the highest-priority column in ``names`` present on ``d`` as a
    Series, or None. A duplicated label yields its first column instead of a
    DataFrame, so a de-duplicated import cannot turn a sum into a concat."""
    for name in names:
        for col in d.columns:
            if str(col).strip().lower() == name:
                series = d[col]
                return series.iloc[:, 0] if isinstance(series, pd.DataFrame) else series
    return None


def _num(series: Optional[pd.Series]) -> Optional[pd.Series]:
    """Coerce a column to float64, mapping every null and non-numeric cell to
    0.0. Never mutates the caller's frame."""
    if series is None:
        return None
    return pd.to_numeric(series, errors="coerce").fillna(0.0).astype("float64")


def _zero_floored(series: Optional[pd.Series]) -> float:
    """Sum a column after flooring negatives at zero (costs and revenue are
    never negative in this warehouse). Returns 0.0 for a missing column."""
    if series is None:
        return 0.0
    return float(series.clip(lower=0.0).sum())


def _revenue(d: pd.DataFrame) -> float:
    """Net revenue for a sales frame. The stated ``revenue`` measure wins; a row
    with a null revenue falls back to ``quantity * selling_price - discount``,
    and a frame with no revenue column at all is derived the same way. Never
    pre-discount."""
    stated = _column(d, _REVENUE_COLUMNS)
    qty = _num(_column(d, _QUANTITY_COLUMNS))
    price = _num(_column(d, _PRICE_COLUMNS))
    disc = _num(_column(d, _DISCOUNT_COLUMNS))
    derived: Optional[pd.Series] = None
    if qty is not None and price is not None:
        derived = qty * price
        if disc is not None:
            derived = derived - disc
        derived = derived.clip(lower=0.0)
    if stated is None:
        return _zero_floored(derived)
    stated_num = pd.to_numeric(stated, errors="coerce")
    if derived is not None:
        stated_num = stated_num.fillna(derived)
    else:
        stated_num = stated_num.fillna(0.0)
    return _zero_floored(stated_num.astype("float64"))


def _cogs_from_sales(d: pd.DataFrame) -> float:
    """Cost of the goods sold, priced off the sales rows themselves as
    ``quantity * unit_cost``. Returns 0.0 when the frame carries no cost basis,
    which is the honest answer rather than a number borrowed from purchases."""
    qty = _num(_column(d, _QUANTITY_COLUMNS))
    if qty is None:
        return 0.0
    unit = _num(_column(d, _UNIT_COST_COLUMNS))
    if unit is not None:
        return _zero_floored(qty * unit)
    return _zero_floored(_num(_column(d, _LINE_TOTAL_COLUMNS)))


def _cogs_from_purchases(d: pd.DataFrame) -> float:
    """Fallback COGS from a purchases frame. An explicit line total wins; a
    per-unit cost is multiplied by quantity, because the ETL writes the same
    ``cost`` field into both ``fact_purchases.cost`` and ``dim_product.cost_price``
    where the latter is unambiguously per-unit. A quantity is never summed as
    money, and a frame with no monetary column contributes 0.0."""
    line = _num(_column(d, _LINE_TOTAL_COLUMNS))
    if line is not None:
        return _zero_floored(line)
    qty = _num(_column(d, _QUANTITY_COLUMNS))
    unit = _num(_column(d, _UNIT_COST_COLUMNS))
    if qty is not None and unit is not None:
        return _zero_floored(qty * unit)
    return 0.0


def _expenses(d: pd.DataFrame) -> float:
    """Sum of the expense amounts, left signed so a credit offsets a cost."""
    amount = _num(_column(d, _AMOUNT_COLUMNS))
    if amount is None:
        return 0.0
    return float(amount.sum())


def _db_window(
    sales_df: Optional[pd.DataFrame], date_from: Any, date_to: Any
) -> tuple[Any, Any]:
    """Resolve the (from, to) window every fact query is filtered by.

    Explicit bounds win. Otherwise the window is taken from the *data actually
    in the sales frame* — its own min/max transaction date — never from a
    calendar year: a warehouse whose data straddles a year boundary, or a
    fiscal year that is not the calendar year, then still gets purchases and
    expenses drawn from exactly the period its revenue covers. A frame with no
    usable dates means "no window", and every table is queried unfiltered.
    """
    if date_from is not None or date_to is not None:
        return date_from, date_to
    if sales_df is None or getattr(sales_df, "empty", True):
        return None, None
    col = _column(sales_df, _SALES_DATE_COLUMNS)
    if col is None:
        return None, None
    ts = pd.to_datetime(col, errors="coerce")
    if not bool(ts.notna().any()):
        return None, None
    return ts.min(), ts.max()


def _db_sum(db_session: Any, column: Any, date_column: Any,
            date_from: Any, date_to: Any) -> float:
    """``COALESCE(SUM(col), 0)`` over one fact table, windowed identically to
    the sales query.

    ``coalesce`` is the Postgres-correct zero-row idiom: ``SUM`` over an empty
    set is NULL, so a month with no rows would otherwise poison the arithmetic
    downstream. The bound is a bound — no value is ever interpolated into SQL.
    """
    from sqlalchemy import func

    q = db_session.query(func.coalesce(func.sum(column), 0.0))
    if date_from is not None:
        q = q.filter(date_column >= date_from)
    if date_to is not None:
        q = q.filter(date_column <= date_to)
    return float(q.scalar() or 0.0)


def _db_cogs(db_session: Any, date_from: Any, date_to: Any) -> float:
    """COGS straight from the warehouse: ``SUM(fact_sales.quantity *
    dim_product.cost_price)`` over the same window as the revenue.

    The join is many-to-one on ``dim_product.id``, the dimension's primary key,
    so it cannot fan out and so cannot double count. A sales row whose product
    has no cost basis multiplies to NULL and is excluded by ``SUM`` rather than
    being counted as free goods.
    """
    from app.database.models import DimProduct, FactSales
    from sqlalchemy import func

    q = (db_session.query(func.coalesce(func.sum(FactSales.quantity * DimProduct.cost_price), 0.0))
         .outerjoin(DimProduct, FactSales.product_id == DimProduct.id))
    if date_from is not None:
        q = q.filter(FactSales.transaction_date >= date_from)
    if date_to is not None:
        q = q.filter(FactSales.transaction_date <= date_to)
    return float(q.scalar() or 0.0)


def _round(value: float) -> float:
    """Round to 2dp and guarantee a plain, finite Python float: ``round`` on a
    numpy scalar would return a numpy float, and a NaN or inf leaking into the
    envelope would serialise as invalid JSON."""
    number = float(value)
    if not math.isfinite(number):
        return 0.0
    return round(number, 2)


def finance_summary(
    sales_df: Optional[pd.DataFrame] = None,
    purchases_df: Optional[pd.DataFrame] = None,
    expenses_df: Optional[pd.DataFrame] = None,
    *,
    db_session: Any = None,
    date_from: Any = None,
    date_to: Any = None,
) -> Dict[str, Any]:
    """Return ``{total_revenue, total_cogs, total_expenses, gross_profit,
    net_profit, margin_pct}``, all plain floats rounded to 2dp.

    Any argument may be None, empty or entirely absent, in which case that
    component contributes 0.0 and the envelope is still fully populated — an
    empty warehouse and a month with no rows both return all-zero floats, never
    None, NaN or a missing key. The module docstring states the accounting
    definitions; ``margin_pct`` is net margin on revenue.

    ``db_session`` loads the components that are not in the frames straight from
    the warehouse (revenue when no sales frame is given, COGS from
    ``fact_sales`` joined to ``dim_product``, expenses from ``fact_expenses``).
    ``date_from``/``date_to`` window that query; without them the window is
    taken from the sales frame's own dates, so all three facts are always read
    over the same period. A frame argument always wins over the database for the
    component it supplies, so an explicitly filtered frame cannot be silently
    replaced by a wider database aggregate.
    """
    lo, hi = _db_window(sales_df, date_from, date_to)

    if sales_df is not None and not sales_df.empty:
        revenue = _revenue(sales_df)
    elif db_session is not None:
        from app.database.models import FactSales

        revenue = _db_sum(db_session, FactSales.revenue,
                          FactSales.transaction_date, lo, hi)
    else:
        revenue = 0.0

    if sales_df is not None and not sales_df.empty:
        cogs = _cogs_from_sales(sales_df)
    elif db_session is not None:
        cogs = _db_cogs(db_session, lo, hi)
    else:
        cogs = 0.0
    if cogs == 0.0 and purchases_df is not None and not purchases_df.empty:
        cogs = _cogs_from_purchases(purchases_df)

    if expenses_df is not None and not expenses_df.empty:
        expenses = _expenses(expenses_df)
    elif db_session is not None:
        from app.database.models import FactExpense

        expenses = _db_sum(db_session, FactExpense.amount,
                           FactExpense.expense_date, lo, hi)
    else:
        expenses = 0.0

    gross = revenue - cogs
    net = gross - expenses
    margin = (net / revenue * 100.0) if revenue else 0.0
    return {"total_revenue": _round(revenue), "total_cogs": _round(cogs),
            "total_expenses": _round(expenses), "gross_profit": _round(gross),
            "net_profit": _round(net), "margin_pct": _round(margin)}
