"""Shared inference helpers for the TruthGuard Filipino transformer model."""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer


LABEL_MAP = {
    "0": "Likely Real News",
    "1": "Likely Fake News",
}


def default_model_dir() -> Path:
    repo_root = Path(__file__).resolve().parents[2]

    return repo_root / "automation" / "models" / "filipino-transformer" / "best_model"


@dataclass(frozen=True)
class PredictionSettings:
    uncertain_threshold: float = 0.70
    min_words: int = 40
    max_length: int = 256


class TruthGuardFilipinoClassifier:
    def __init__(self, model_dir: Path, device: str | None = None):
        self.model_dir = model_dir.expanduser().resolve()
        self._assert_model_dir()
        self.device = device or ("cuda" if torch.cuda.is_available() else "cpu")
        self.tokenizer = self._load_tokenizer()
        self.model = AutoModelForSequenceClassification.from_pretrained(str(self.model_dir), local_files_only=True)
        self.model.to(self.device)
        self.model.eval()

    def _load_tokenizer(self):
        try:
            return AutoTokenizer.from_pretrained(
                str(self.model_dir),
                local_files_only=True,
                use_fast=False,
            )
        except TypeError:
            return AutoTokenizer.from_pretrained(str(self.model_dir), local_files_only=True)

    def predict(self, article: str, settings: PredictionSettings | None = None) -> dict[str, object]:
        settings = settings or PredictionSettings()
        text = " ".join(article.split())

        if text == "":
            raise ValueError("Article text is empty.")

        inputs = self.tokenizer(
            text,
            return_tensors="pt",
            truncation=True,
            max_length=settings.max_length,
            padding=True,
        ).to(self.device)

        with torch.no_grad():
            outputs = self.model(**inputs)
            probabilities = torch.softmax(outputs.logits, dim=-1)[0]

        predicted_id = int(torch.argmax(probabilities).item())
        predicted_label = str(predicted_id)
        confidence = float(probabilities[predicted_id])
        real_probability = float(probabilities[0])
        fake_probability = float(probabilities[1])
        word_count = len(text.split())

        if word_count < settings.min_words:
            verdict = "Uncertain"
            reason = "Text is too short for this article-level model."
        elif confidence < settings.uncertain_threshold:
            verdict = "Uncertain"
            reason = "Model confidence is below the threshold."
        else:
            verdict = LABEL_MAP[predicted_label]
            reason = "Prediction passed length and confidence checks."

        return {
            "verdict": verdict,
            "predicted_label": predicted_label,
            "confidence": round(confidence, 4),
            "real_probability": round(real_probability, 4),
            "fake_probability": round(fake_probability, 4),
            "word_count": word_count,
            "reason": reason,
            "model_dir": str(self.model_dir),
        }

    def _assert_model_dir(self) -> None:
        if not self.model_dir.is_dir():
            raise FileNotFoundError(f"Model directory does not exist: {self.model_dir}")

        if not (self.model_dir / "config.json").is_file():
            raise FileNotFoundError(f"Missing config.json in model directory: {self.model_dir}")

        has_weights = (self.model_dir / "model.safetensors").is_file() or (self.model_dir / "pytorch_model.bin").is_file()

        if not has_weights:
            raise FileNotFoundError(f"Missing model weights in model directory: {self.model_dir}")
