"""Product display columns: description + image_url on dim_product.

Revision ID: 0003
Revises: 0002
Create Date: 2026-09-30

Why these columns
-----------------
Product analytics (ABC, recommendations, the AI sales frame) only ever had a
name to show: ``dim_product`` carried codes, prices and a category, so every
storefront-like surface rendered bare text. ``description`` is the one-line
Indonesian retail description shown under the name; ``image_url`` is the
Laravel-public path (``/images/demo-products/<slug>.svg``) rendered as the
thumbnail. Both are presentation metadata, never inputs to pricing, margin or
model features, so nothing downstream can mistake them for business facts.

Guarded exactly as 0002 is: the container runs ``alembic upgrade head`` on
every boot, so an unguarded ADD COLUMN raises "duplicate column" on the
second boot and takes the container down with it. NOT NULL with an empty
default on purpose -- existing rows read back "" and the API reports them
imageless rather than failing the row.

The two ADD COLUMN calls are written out individually (no loop over a tuple):
``tests/test_schema_parity.py`` statically evaluates revisions and only
understands literal calls.
"""
from __future__ import annotations

import logging

import sqlalchemy as sa
from alembic import op

revision = "0003"
down_revision = "0002"
branch_labels = None
depends_on = None

log = logging.getLogger("alembic.runtime.migration")


def _has_table(table: str) -> bool:
    return sa.inspect(op.get_bind()).has_table(table)


def _has_column(table: str, column: str) -> bool:
    return column in {c["name"] for c in sa.inspect(op.get_bind()).get_columns(table)}


def upgrade() -> None:
    if not _has_table("dim_product"):
        log.info("skip: dim_product not present")
        return
    if _has_column("dim_product", "description"):
        log.info("skip description: already present")
    else:
        op.add_column("dim_product", sa.Column("description", sa.String(1024), nullable=False, server_default=""))
        log.info("added description to dim_product")
    if _has_column("dim_product", "image_url"):
        log.info("skip image_url: already present")
    else:
        op.add_column("dim_product", sa.Column("image_url", sa.String(1024), nullable=False, server_default=""))
        log.info("added image_url to dim_product")


def downgrade() -> None:
    if not _has_table("dim_product"):
        return
    if _has_column("dim_product", "image_url"):
        op.drop_column("dim_product", "image_url")
    if _has_column("dim_product", "description"):
        op.drop_column("dim_product", "description")
