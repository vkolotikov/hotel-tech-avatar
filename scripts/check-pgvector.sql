-- Phase-0 exit check: confirm pgvector is available on the target Postgres
-- instance. Run against the production database on Laravel Cloud:
--
--   psql "$DATABASE_URL" -f scripts/check-pgvector.sql
--
-- Idempotent: creates and drops a throwaway table. Safe to re-run.

BEGIN;

-- 1. Try to enable the extension (no-op if already installed).
CREATE EXTENSION IF NOT EXISTS vector;

-- 2. Create a tiny test table with a 3-dim vector column.
CREATE TABLE IF NOT EXISTS _pgvector_smoke (
  id   SERIAL PRIMARY KEY,
  name TEXT,
  embedding vector(3)
);

-- 3. Insert a sample row.
INSERT INTO _pgvector_smoke (name, embedding) VALUES ('smoke', '[0.1, 0.2, 0.3]');

-- 4. Query it with an L2 distance calc — if pgvector works, this returns a row.
SELECT id, name, embedding, embedding <-> '[0.0, 0.0, 0.0]' AS distance
  FROM _pgvector_smoke
 WHERE name = 'smoke';

-- 5. Clean up.
DROP TABLE _pgvector_smoke;

COMMIT;

-- Expected output: one row with distance ~0.374 and no errors. If the
-- CREATE EXTENSION line errors, the pgvector extension isn't available on
-- the current Postgres tier — raise with Laravel Cloud support.
