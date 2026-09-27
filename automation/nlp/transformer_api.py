"""FastAPI service for the TruthGuard Filipino transformer classifier."""
from __future__ import annotations

import os
from pathlib import Path

import uvicorn
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from transformer_inference import PredictionSettings, TruthGuardFilipinoClassifier, default_model_dir


app = FastAPI(title="TruthGuard Filipino NLP API", version="1.0.0")
classifier: TruthGuardFilipinoClassifier | None = None


class PredictRequest(BaseModel):
    article: str = Field(..., min_length=1)
    uncertain_threshold: float = Field(default=0.70, ge=0.0, le=1.0)
    min_words: int = Field(default=40, ge=1)
    max_length: int = Field(default=256, ge=32, le=512)


def configured_model_dir() -> Path:
    configured = os.getenv("TRUTHGUARD_NLP_MODEL_DIR")

    return Path(configured) if configured else default_model_dir()


def get_classifier() -> TruthGuardFilipinoClassifier:
    global classifier

    if classifier is None:
        try:
            classifier = TruthGuardFilipinoClassifier(configured_model_dir())
        except Exception as exc:
            raise HTTPException(status_code=503, detail=str(exc)) from exc

    return classifier


@app.get("/health")
def health() -> dict[str, object]:
    try:
        get_classifier()
    except HTTPException:
        return {
            "ok": False,
            "loaded": False,
        }

    return {
        "ok": True,
        "loaded": True,
    }


@app.post("/predict")
def predict(request: PredictRequest) -> dict[str, object]:
    settings = PredictionSettings(
        uncertain_threshold=request.uncertain_threshold,
        min_words=request.min_words,
        max_length=request.max_length,
    )

    try:
        return get_classifier().predict(request.article, settings)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


if __name__ == "__main__":
    host = os.getenv("TRUTHGUARD_NLP_HOST", "127.0.0.1")
    port = int(os.getenv("TRUTHGUARD_NLP_PORT", "8765"))
    uvicorn.run("transformer_api:app", host=host, port=port, reload=False)
