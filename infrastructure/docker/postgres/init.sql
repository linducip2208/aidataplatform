-- AIDataPlatform Postgres bootstrap. Runs once, on first initialisation of the
-- pgdata volume, from /docker-entrypoint-initdb.d.
--
-- Table ownership, so this file does not overstate what it sets up:
--   * Alembic (ai-engine/alembic/versions/0001_*) creates the engine tables.
--   * Laravel (application/database/migrations/) creates users, sessions,
--     cache, jobs, datasets, chat_*, audit_logs, personal_access_tokens.
--   Both declare no schema, so every table lives in `public`. Nothing is created
--   in the reserved schemas below and nothing here may be relied on to place a
--   table outside `public`.
--
-- This file installs extensions and creates the empty schemas that
-- docs/data-dictionary.md reserves for a future raw/staging/warehouse layout.
-- Existing volumes never re-run it; apply changes with
--   docker compose exec postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f <file>

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Reserved, intentionally empty. Dropped by nothing: a future data-lake loader
-- may already have objects in them.
CREATE SCHEMA IF NOT EXISTS raw;
CREATE SCHEMA IF NOT EXISTS staging;
CREATE SCHEMA IF NOT EXISTS warehouse;
CREATE SCHEMA IF NOT EXISTS analytics;
CREATE SCHEMA IF NOT EXISTS ml;
CREATE SCHEMA IF NOT EXISTS ai;

-- The app connects as POSTGRES_USER, which is the owner of this database and
-- therefore already owns public. The grants below only matter when
-- POSTGRES_USER is not the bootstrap superuser (see the fallback below).
DO $$
DECLARE
    app_user TEXT;
BEGIN
    app_user := COALESCE(
        NULLIF(current_setting('app.user', true), ''),
        current_user
    );

    EXECUTE format('GRANT ALL ON SCHEMA public TO %I', app_user);
    EXECUTE format('GRANT ALL ON SCHEMA raw, staging, warehouse, analytics, ml, ai TO %I', app_user);
    EXECUTE format(
        'ALTER DEFAULT PRIVILEGES IN SCHEMA public, raw, staging, warehouse, analytics, ml, ai '
        'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I', app_user
    );
    EXECUTE format(
        'ALTER DEFAULT PRIVILEGES IN SCHEMA public, raw, staging, warehouse, analytics, ml, ai '
        'GRANT USAGE, SELECT ON SEQUENCES TO %I', app_user
    );
EXCEPTION WHEN OTHERS THEN
    -- Never abort the first-boot bootstrap over a grant: a working database
    -- with a missing grant is recoverable, a refused entrypoint is not.
    RAISE NOTICE 'init.sql grants skipped: %', SQLERRM;
END $$;

-- Sanity check after first boot:
--   SELECT extname FROM pg_extension WHERE extname IN ('vector', 'pg_trgm', 'uuid-ossp');
--   SELECT nspname FROM pg_namespace WHERE nspname IN ('raw', 'staging', 'warehouse', 'analytics', 'ml', 'ai');
