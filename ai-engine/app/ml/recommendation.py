"""Recommendation: popularity + item-item co-occurrence + TF-IDF content fallback."""
from __future__ import annotations

from collections import Counter, defaultdict
from typing import Any, Dict, List

import pandas as pd


def recommend(transactions: pd.DataFrame | List[Dict], customer_id=None, product_id=None, top_k: int = 5) -> List[Dict[str, Any]]:
    if isinstance(transactions, list):
        df = pd.DataFrame(transactions)
    else:
        df = transactions.copy() if transactions is not None else pd.DataFrame()
    if df.empty:
        return []
    # normalize cols
    ren = {}
    for c in list(df.columns):
        lc = str(c).lower()
        if lc in ("nama customer", "pelanggan", "customer", "customer name", "nm customer", "customer_id"):
            ren[c] = "customer"
        if lc in ("nm brg", "nama barang", "nama produk", "barang", "product name", "product_id"):
            ren[c] = "product"
    df = df.rename(columns=ren)
    if "product" not in df.columns:
        return []
    if "customer" not in df.columns:
        df["customer"] = "ALL"
    pop = Counter(df["product"].astype(str))
    # co-occurrence: products bought together by same customer
    co: dict[str, Counter] = defaultdict(Counter)
    for _, g in df.groupby("customer"):
        prods = list(dict.fromkeys(g["product"].astype(str)))
        for i, a in enumerate(prods):
            for b in prods[i + 1:]:
                co[a][b] += 1
                co[b][a] += 1
    # content-based TF-IDF on product+category
    tfidf_sim: dict[str, dict] = {}
    try:
        from sklearn.feature_extraction.text import TfidfVectorizer
        from sklearn.metrics.pairwise import cosine_similarity

        texts, prods = [], []
        for p, g in df.groupby("product"):
            cat = str(g["category"].iloc[0]) if "category" in g.columns else ""
            texts.append(f"{p} {cat}")
            prods.append(str(p))
        if len(texts) >= 2:
            vec = TfidfVectorizer().fit_transform(texts)
            sim = cosine_similarity(vec)
            for i, p in enumerate(prods):
                tfidf_sim[p] = {prods[j]: float(sim[i][j]) for j in range(len(prods)) if j != i}
    except Exception:
        tfidf_sim = {}

    scores: Counter = Counter()
    if product_id:
        p = str(product_id)
        for other, c in co.get(p, {}).items():
            scores[other] += c * 2.0
        for other, s in tfidf_sim.get(p, {}).items():
            scores[other] += s * 1.5
    if customer_id:
        bought = set(df[df["customer"].astype(str) == str(customer_id)]["product"].astype(str))
        for b in bought:
            for other, c in co.get(b, {}).items():
                if other not in bought:
                    scores[other] += c
        # exclude already bought
        for b in bought:
            scores.pop(b, None)
    if not scores:
        # popularity fallback (small-data graceful)
        for p, c in pop.most_common(top_k * 2):
            if str(p) != str(product_id):
                scores[p] += c * 0.1
    ranked = scores.most_common(top_k)
    return [{"product": p, "score": round(float(s), 3)} for p, s in ranked]
