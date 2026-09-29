"""Named quality profiles: curated rule bundles with validation.

A profile is ``{"name": ..., "dataset_type": ..., "rules": [...],
"description": ...}``. :data:`PROFILES` ships the built-ins; callers may pass
their own profile dicts through :func:`validate_profile`.
"""
from __future__ import annotations

from typing import Any, Dict, List

from app.quality.rules import RULE_TYPES, validate_rule

PROFILES: Dict[str, Dict[str, Any]] = {
    "sales_strict": {
        "name": "sales_strict",
        "dataset_type": "sales",
        "description": "Strict sales import gate: required keys, sane measures, fresh dates.",
        "rules": [
            {"id": "customer_required", "column": "customer", "type": "required",
             "params": {}, "severity": "error"},
            {"id": "product_required", "column": "product", "type": "required",
             "params": {}, "severity": "error"},
            {"id": "quantity_valid", "column": "quantity", "type": "validity",
             "params": {"check": "no_negative"}, "severity": "error"},
            {"id": "price_valid", "column": "price", "type": "validity",
             "params": {"check": "parse_number"}, "severity": "error"},
            {"id": "date_fresh", "column": "date", "type": "freshness",
             "params": {"max_age_days": 90}, "severity": "warn"},
            {"id": "no_exact_duplicates", "column": None, "type": "duplicate",
             "params": {}, "severity": "warn"},
            {"id": "branch_known", "column": "branch", "type": "referential",
             "params": {"allowed_values": ["Jakarta", "Bandung", "Surabaya"]},
             "severity": "warn"},
        ],
    },
    "inventory_standard": {
        "name": "inventory_standard",
        "dataset_type": "inventory",
        "description": "Standard inventory gate: keys present, stock non-negative, no dupes.",
        "rules": [
            {"id": "product_required", "column": "product", "type": "required",
             "params": {}, "severity": "error"},
            {"id": "warehouse_required", "column": "warehouse", "type": "required",
             "params": {}, "severity": "error"},
            {"id": "stock_valid", "column": "stock_qty", "type": "validity",
             "params": {"check": "no_negative"}, "severity": "error"},
            {"id": "stock_range", "column": "stock_qty", "type": "range",
             "params": {"min": 0, "max": 1000000}, "severity": "warn"},
            {"id": "no_exact_duplicates", "column": None, "type": "duplicate",
             "params": {}, "severity": "error"},
        ],
    },
    "customers_pii_aware": {
        "name": "customers_pii_aware",
        "dataset_type": "customers",
        "description": "Customer gate: identity present, well-formed email/phone, sane segment.",
        "rules": [
            {"id": "customer_required", "column": "customer", "type": "required",
             "params": {}, "severity": "error"},
            {"id": "email_format", "column": "email", "type": "regex",
             "params": {"pattern": r"^[^@\s]+@[^@\s]+\.[^@\s]+$"}, "severity": "error"},
            {"id": "phone_type", "column": "phone", "type": "datatype",
             "params": {"dtype": "string"}, "severity": "warn"},
            {"id": "segment_known", "column": "segment", "type": "enum",
             "params": {"allowed": ["new", "regular", "vip", "churned"]}, "severity": "warn"},
            {"id": "customer_unique", "column": "customer", "type": "unique",
             "params": {}, "severity": "error"},
        ],
    },
}


def list_profiles() -> List[Dict[str, Any]]:
    """Return deep copies of the built-in profiles (callers may mutate freely)."""
    import copy

    return [copy.deepcopy(profile) for profile in PROFILES.values()]


def get_profile(name: str) -> Dict[str, Any]:
    """Return a copy of the named built-in profile; raises ``KeyError``."""
    import copy

    key = (name or "").strip()
    if key not in PROFILES:
        raise KeyError(f"unknown quality profile {name!r}; "
                       f"expected one of: {', '.join(sorted(PROFILES))}")
    return copy.deepcopy(PROFILES[key])


def validate_profile(profile: Dict[str, Any]) -> Dict[str, Any]:
    """Validate a profile dict and return its normalised form.

    Checks the name, the rules list (non-empty, unique rule ids, every rule
    valid via :func:`validate_rule`), and that every rule type is known.
    Raises ``ValueError`` with the field and reason.
    """
    if not isinstance(profile, dict):
        raise ValueError("invalid profile: expected an object")
    name = profile.get("name", "")
    if not isinstance(name, str) or not name.strip():
        raise ValueError("invalid profile: name: a non-empty string name is required")
    rules = profile.get("rules")
    if not isinstance(rules, list) or not rules:
        raise ValueError(f"invalid profile {name!r}: rules: a non-empty rules list is required")
    normalised = [validate_rule(rule) for rule in rules]
    seen: Dict[str, int] = {}
    for rule in normalised:
        if rule["id"] in seen:
            raise ValueError(f"invalid profile {name!r}: rules: duplicate rule id {rule['id']!r}")
        seen[rule["id"]] = 1
    unknown = [r["type"] for r in normalised if r["type"] not in RULE_TYPES]
    if unknown:  # pragma: no cover - validate_rule already rejects these
        raise ValueError(f"invalid profile {name!r}: unknown rule types: {unknown}")
    out = {
        "name": name.strip(),
        "dataset_type": str(profile.get("dataset_type", "sales")),
        "description": str(profile.get("description", "")),
        "rules": normalised,
    }
    return out
