import pandas as pd


def _df():
    return pd.DataFrame([
        {"transaction_date": "2024-01-01", "customer_name": "A", "product_name": "P1", "revenue": 100, "quantity": 1},
        {"transaction_date": "2024-01-02", "customer_name": "B", "product_name": "P2", "revenue": 200, "quantity": 2},
        {"transaction_date": "2024-02-01", "customer_name": "A", "product_name": "P1", "revenue": 150, "quantity": 1},
    ])


def test_sales_kpi():
    from app.analytics.sales import sales_kpi, sales_trend

    k = sales_kpi(_df())
    assert k["revenue"] == 450
    assert k["orders"] == 3
    assert isinstance(sales_trend(_df()), list)


def test_rfm_abc_cohort():
    from app.analytics.customers import cohort_retention, rfm
    from app.analytics.products import abc_analysis

    assert len(rfm(_df())) == 2
    assert abc_analysis(_df())[0]["grade"] == "A"
    assert isinstance(cohort_retention(_df()), list)


def test_inventory_finance_branches():
    import pandas as pd

    from app.analytics.branches import branch_kpi
    from app.analytics.finance import finance_summary
    from app.analytics.inventory import inventory_health

    stock = pd.DataFrame([{"product_name": "P1", "stock_qty": 10}])
    inv = inventory_health(stock, _df())
    assert inv[0]["product"] == "P1"
    f = finance_summary(_df())
    assert f["total_revenue"] == 450
    df = _df()
    df["branch_name"] = "JKT"
    assert branch_kpi(df)[0]["branch"] == "JKT"
