-- AIDataPlatform Postgres init (runs once on first volume init)
-- Extensions: vector (pgvector), pg_trgm (fuzzy search for RAG/quality dedup)
-- Schemas mirror docs/data-dictionary.md: raw, staging, warehouse, analytics, ml, ai

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- App role is created by POSTGRES_USER env; ensure schema privileges.
-- (Runs as superuser during docker-entrypoint-initdb.d.)

CREATE SCHEMA IF NOT EXISTS raw;
CREATE SCHEMA IF NOT EXISTS staging;
CREATE SCHEMA IF NOT EXISTS warehouse;
CREATE SCHEMA IF NOT EXISTS analytics;
CREATE SCHEMA IF NOT EXISTS ml;
CREATE SCHEMA IF NOT EXISTS ai;

-- Grant to the app user (also PUBLIC read guard: revoke later in prod hardening, see security.md)
DO $$
DECLARE
  app_user TEXT := COALESCE(current_setting('app.user', true), 'aidata');
BEGIN
  -- When run via entrypoint, POSTGRES_USER already owns the DB; grants are belt & braces.
  EXECUTE format('GRANT ALL ON SCHEMA raw, staging, warehouse, analytics, ml, ai TO %I', app_user);
  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA raw, staging, warehouse, analytics, ml, ai GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I', app_user);
  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA raw, staging, warehouse, analytics, ml, ai GRANT USAGE, SELECT ON SEQUENCES TO %I', app_user);
EXCEPTION WHEN OTHERS THEN
  RAISE NOTICE 'init.sql grant skipped: %', SQLERRM;
END $$;

-- Alembic/Laravel migrations create tables later; this is only a schema+extension bootstrap.
-- Verify after boot: SELECT * FROM pg_extension WHERE extname IN ('vector','pg_trgm');
