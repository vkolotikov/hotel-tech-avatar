from fastapi import FastAPI

app = FastAPI(title="WellnessAI ml-service", version="0.1.0")


@app.get("/health")
async def health() -> dict[str, bool | str]:
    return {"ok": True, "service": "ml-service"}
