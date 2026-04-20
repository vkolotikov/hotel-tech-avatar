# ADR — Phase 0 ml-service scaffold

**Date:** 2026-04-20
**Status:** Accepted
**Authors:** platform

## Context

CLAUDE.md §Phase 0 names "Python ml-service directory scaffolded with a
healthcheck endpoint only" as exit criteria. CLAUDE.md §Existing stack
describes the service as a "small Python microservice only where PHP has no
ecosystem fit: PDF layout parsing, biomedical embeddings, reranker calls.
Keep it stateless, tiny, and without business logic."

Nothing in the service talks to the database, holds session state, or owns
product rules. Laravel is the sole state owner; ml-service is a pure
function that Laravel calls and forgets.

## Decisions

1. **FastAPI over Flask.** Native async, typed request/response models via
   Pydantic, and an auto-generated OpenAPI surface Laravel can use to drive
   typed HTTP clients later. Flask would need ~three extensions to match,
   and async matters once PDF parsing and reranker calls run in parallel.

2. **`requirements.txt`, not `pyproject.toml`.** Phase 0 is two files and
   zero published wheels. A flat `requirements.txt` + `requirements-dev.txt`
   split keeps the Dockerfile one line and the review diff small. Switch to
   Poetry/uv if and when a real dependency graph emerges.

3. **Stateless, no business logic — enforced by absence.** No database
   driver, no ORM, no cache client, no queue client, no auth middleware in
   the dependency list. A contributor who wants to add state has to add a
   dependency first, which forces the conversation.

4. **One `/health` endpoint this phase.** Returns `{"ok": true, "service":
   "ml-service"}`. Matches the Laravel health-check convention and gives
   infra something to probe. Real endpoints arrive with the first task that
   needs them (PDF ingestion, Phase 1).

5. **Python 3.12 pinned in the Dockerfile.** Matches the version tested
   locally and the version FastAPI 0.115 targets. Pin bumps become a
   deliberate ADR rather than a silent base-image drift.

6. **pytest with `TestClient`, no mocks.** FastAPI's `TestClient` drives the
   real app through Starlette — no extra abstraction, no mock layer to
   rot. Phase 0 has one test; the pattern scales.

## Consequences

- Deploying the service is a `docker build && docker run` away — no
  application server config, no WSGI adapter.
- Adding a real endpoint is: write the route, write the test, update
  `requirements.txt` if a new library is needed, commit. No framework
  scaffolding to regenerate.
- Laravel will call ml-service over plain HTTP from a queued job. Retry,
  timeout, and error classification live on the Laravel side; the service
  stays thin.
- If scale ever demands it, the service shards horizontally because it is
  stateless by design.

## Alternatives considered

- **Flask + Gunicorn.** Rejected — no async, no typed models, and the
  ecosystem fit is worse for the specific jobs this service will own
  (PDF parsing libraries and reranker SDKs are async-friendly).
- **Laravel-side Python via `symfony/process`.** Rejected — blocks a PHP
  worker for the duration of a multi-second parse, and puts Python
  dependency management inside the Laravel image.
- **Node.js service.** Rejected — the specific libraries needed (PDF
  layout parsing, biomedical embeddings, Cohere reranker) have stronger
  Python coverage.

## Implementation notes

- Local run: `python -m venv .venv`, activate, `pip install -r
  requirements-dev.txt`, `uvicorn app.main:app --reload --port 8000`.
- Tests: `pytest` from the `ml-service/` directory. `pythonpath = .` in
  `pytest.ini` so `from app.main import app` resolves without editable
  installs.
- The Docker image installs only `requirements.txt` (runtime) — test
  dependencies are dev-only and never reach production.
