# ml-service

Stateless Python microservice for tasks PHP has no ecosystem fit for:
PDF layout parsing, biomedical embeddings, reranker calls.

Phase 0 ships the scaffold only — one `/health` endpoint. No business
logic lives here; all state is in Laravel + Postgres.

## Requirements

- Python 3.12+

## Run locally

```bash
cd ml-service
python -m venv .venv
# Windows PowerShell:  .venv\Scripts\Activate.ps1
# Windows bash / Git Bash:  source .venv/Scripts/activate
# macOS / Linux:  source .venv/bin/activate
pip install -r requirements-dev.txt
uvicorn app.main:app --reload --port 8000
```

Verify:

```bash
curl http://localhost:8000/health
# => {"ok":true,"service":"ml-service"}
```

## Test

```bash
pytest
```

## Docker

```bash
docker build -t ml-service .
docker run --rm -p 8000:8000 ml-service
```

## Design notes

See [`docs/adr/2026-04-20-phase-0-ml-service-fastapi.md`](../docs/adr/2026-04-20-phase-0-ml-service-fastapi.md).
