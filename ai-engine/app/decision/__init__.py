"""Decision/scenario engine (Agent 7).

In-process decision support over warehouse evidence. Every number returned
here was computed from data handed to the function; an unavailable source is
reported as ``unavailable`` with a reason, never imputed or zero-filled.

Submodules (all new, Agent 7 owned):

* :mod:`app.decision.evidence` — evidence aggregation (KPI, anomaly,
  forecast, ML prediction contexts).
* :mod:`app.decision.rules` — versioned threshold rules + scoring formula.
* :mod:`app.decision.scenarios` — what-if simulation with explicit
  unsupported paths.
* :mod:`app.decision.explain` — explanation builder.
* :mod:`app.decision.recommendations` — orchestration + persistence.
* :mod:`app.decision.models` — SQLAlchemy tables on the shared ``Base``.
"""
from __future__ import annotations
