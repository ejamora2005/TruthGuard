import json
import math
import os
import sys
from pathlib import Path
from typing import Any


DEFAULT_IMAGE_SIZE = 224
DEFAULT_THRESHOLD = 0.5
DEFAULT_VIDEO_FRAME_LIMIT = 8
DEFAULT_VIDEO_FRAME_STRIDE = 12


def nullable_string(value: object) -> str | None:
    normalized = str(value or "").strip()
    return normalized or None


def env_int(name: str, default: int, minimum: int | None = None) -> int:
    raw_value = os.getenv(name)

    try:
        parsed = int(raw_value) if raw_value is not None else default
    except ValueError:
        parsed = default

    if minimum is not None:
        return max(minimum, parsed)

    return parsed


def env_float(name: str, default: float, minimum: float | None = None, maximum: float | None = None) -> float:
    raw_value = os.getenv(name)

    try:
        parsed = float(raw_value) if raw_value is not None else default
    except ValueError:
        parsed = default

    if minimum is not None:
        parsed = max(minimum, parsed)

    if maximum is not None:
        parsed = min(maximum, parsed)

    return parsed


def build_response(
    *,
    status: str,
    screening_score: int,
    signals: list[dict[str, int | str]],
    summary: str,
    model_family: str = "cnn",
    requires_model_integration: bool = False,
) -> dict[str, Any]:
    return {
        "status": status,
        "model_family": model_family,
        "screening_score": max(0, min(100, int(screening_score))),
        "signals": signals,
        "summary": summary,
        "requires_model_integration": requires_model_integration,
    }


def load_request() -> dict[str, Any]:
    raw_payload = sys.stdin.read().strip()

    if not raw_payload:
        return {}

    try:
        parsed = json.loads(raw_payload)
    except json.JSONDecodeError:
        return {}

    return parsed if isinstance(parsed, dict) else {}


def load_numpy_and_pillow():
    try:
        import numpy as np
    except Exception as exc:  # pragma: no cover - import guard
        return None, None, str(exc)

    try:
        from PIL import Image
    except Exception as exc:  # pragma: no cover - import guard
        return None, None, str(exc)

    return np, Image, None


def load_tensorflow_model(model_path: Path):
    try:
        import tensorflow as tf
    except Exception as exc:  # pragma: no cover - import guard
        return None, str(exc)

    try:
        model = tf.keras.models.load_model(model_path)
    except Exception as exc:
        return None, str(exc)

    return model, None


def sigmoid(value: float) -> float:
    if value >= 0:
        exponent = math.exp(-value)
        return 1.0 / (1.0 + exponent)

    exponent = math.exp(value)
    return exponent / (1.0 + exponent)


def extract_fake_probability(prediction: Any, np) -> float:
    values = np.asarray(prediction, dtype="float32").reshape(-1)

    if values.size == 0:
        raise ValueError("CNN model returned an empty prediction.")

    if values.size == 1:
        raw_value = float(values[0])

        if 0.0 <= raw_value <= 1.0:
            return raw_value

        return sigmoid(raw_value)

    shifted = values - float(values.max())
    exponents = np.exp(shifted)
    denominator = float(exponents.sum())

    if denominator <= 0:
        raise ValueError("CNN model returned invalid logits.")

    probabilities = exponents / denominator
    return float(probabilities[-1])


def preprocess_pil_image(image, image_size: int, np):
    resized = image.convert("RGB").resize((image_size, image_size))
    pixels = np.asarray(resized, dtype="float32") / 255.0
    return np.expand_dims(pixels, axis=0)


def predict_image_probability(model, media_path: Path, image_size: int, np, Image) -> float:
    with Image.open(media_path) as image:
        batch = preprocess_pil_image(image, image_size, np)

    prediction = model.predict(batch, verbose=0)
    return extract_fake_probability(prediction, np)


def predict_video_probability(
    model,
    media_path: Path,
    image_size: int,
    frame_limit: int,
    frame_stride: int,
    np,
    Image,
):
    try:
        import cv2
    except Exception as exc:  # pragma: no cover - import guard
        return None, f"OpenCV is required for CNN video inference: {exc}"

    capture = cv2.VideoCapture(str(media_path))

    if not capture.isOpened():
        return None, "Video file could not be opened for CNN inference."

    probabilities: list[float] = []
    frame_index = 0

    try:
        while len(probabilities) < frame_limit:
            success, frame = capture.read()

            if not success:
                break

            if frame_index % frame_stride == 0:
                rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                pil_frame = Image.fromarray(rgb_frame)
                batch = preprocess_pil_image(pil_frame, image_size, np)
                prediction = model.predict(batch, verbose=0)
                probabilities.append(extract_fake_probability(prediction, np))

            frame_index += 1
    finally:
        capture.release()

    if not probabilities:
        return None, "Video inference did not extract any usable frames."

    return float(sum(probabilities) / len(probabilities)), None


def build_signals(fake_probability: float) -> list[dict[str, int | str]]:
    if fake_probability >= 0.85:
        return [
            {
                "label": "CNN model found very strong synthetic-media artifacts",
                "weight": 30,
            }
        ]

    if fake_probability >= 0.7:
        return [
            {
                "label": "CNN model found strong synthetic-media artifacts",
                "weight": 22,
            }
        ]

    if fake_probability >= 0.55:
        return [
            {
                "label": "CNN model found moderate synthetic-media artifacts",
                "weight": 14,
            }
        ]

    return [
        {
            "label": "CNN model found limited synthetic-media artifacts",
            "weight": 6,
        }
    ]


def main() -> int:
    request_payload = load_request()
    media_type = nullable_string(request_payload.get("media_type")) or "unknown"
    media_path_value = nullable_string(request_payload.get("media_path"))
    heuristic_score = int(request_payload.get("heuristic_screening_score", 0) or 0)

    if media_type not in {"image", "video"}:
        print(
            json.dumps(
                build_response(
                    status="not-applicable",
                    screening_score=0,
                    signals=[],
                    summary="CNN deepfake inference is only applicable to image or video inputs.",
                )
            )
        )
        return 0

    model_path_value = nullable_string(os.getenv("DEEPFAKE_CNN_MODEL_PATH"))

    if model_path_value is None:
        print(
            json.dumps(
                build_response(
                    status="not-configured",
                    screening_score=heuristic_score,
                    signals=[],
                    summary="Set DEEPFAKE_CNN_MODEL_PATH to a trained CNN model file before enabling CNN inference.",
                    requires_model_integration=True,
                )
            )
        )
        return 0

    if media_path_value is None:
        print(
            json.dumps(
                build_response(
                    status="missing-media",
                    screening_score=heuristic_score,
                    signals=[],
                    summary="CNN inference requires an uploaded media file.",
                    requires_model_integration=True,
                )
            )
        )
        return 0

    model_path = Path(model_path_value)
    media_path = Path(media_path_value)

    if not model_path.is_file():
        print(
            json.dumps(
                build_response(
                    status="configuration-error",
                    screening_score=heuristic_score,
                    signals=[],
                    summary="The configured CNN model file could not be found.",
                    requires_model_integration=True,
                )
            )
        )
        return 0

    if not media_path.is_file():
        print(
            json.dumps(
                build_response(
                    status="missing-media",
                    screening_score=heuristic_score,
                    signals=[],
                    summary="The uploaded media file could not be found for CNN inference.",
                    requires_model_integration=True,
                )
            )
        )
        return 0

    np, Image, dependency_error = load_numpy_and_pillow()

    if dependency_error is not None:
        print(
            json.dumps(
                build_response(
                    status="dependency-error",
                    screening_score=heuristic_score,
                    signals=[],
                    summary=(
                        "CNN inference requires numpy and Pillow. Install the deepfake-model dependencies first: "
                        f"{dependency_error}"
                    ),
                    requires_model_integration=True,
                )
            )
        )
        return 0

    model, model_error = load_tensorflow_model(model_path)

    if model_error is not None:
        print(
            json.dumps(
                build_response(
                    status="dependency-error",
                    screening_score=heuristic_score,
                    signals=[],
                    summary=(
                        "CNN inference requires TensorFlow and a loadable Keras model file. "
                        f"Model loading failed: {model_error}"
                    ),
                    requires_model_integration=True,
                )
            )
        )
        return 0

    image_size = env_int("DEEPFAKE_CNN_IMAGE_SIZE", DEFAULT_IMAGE_SIZE, 32)
    threshold = env_float("DEEPFAKE_CNN_THRESHOLD", DEFAULT_THRESHOLD, 0.0, 1.0)
    frame_limit = env_int("DEEPFAKE_CNN_VIDEO_FRAME_LIMIT", DEFAULT_VIDEO_FRAME_LIMIT, 1)
    frame_stride = env_int("DEEPFAKE_CNN_VIDEO_FRAME_STRIDE", DEFAULT_VIDEO_FRAME_STRIDE, 1)

    try:
        if media_type == "image":
            fake_probability = predict_image_probability(model, media_path, image_size, np, Image)
        else:
            fake_probability, video_error = predict_video_probability(
                model,
                media_path,
                image_size,
                frame_limit,
                frame_stride,
                np,
                Image,
            )

            if video_error is not None:
                print(
                    json.dumps(
                        build_response(
                            status="dependency-error",
                            screening_score=heuristic_score,
                            signals=[],
                            summary=video_error,
                            requires_model_integration=True,
                        )
                    )
                )
                return 0
    except Exception as exc:  # pragma: no cover - runtime guard
        print(
            json.dumps(
                build_response(
                    status="model-error",
                    screening_score=heuristic_score,
                    signals=[],
                    summary=f"CNN inference failed while processing the media: {exc}",
                    requires_model_integration=True,
                )
            )
        )
        return 0

    screening_score = round(fake_probability * 100)
    fake_label = nullable_string(os.getenv("DEEPFAKE_CNN_FAKE_LABEL")) or "fake"
    real_label = nullable_string(os.getenv("DEEPFAKE_CNN_REAL_LABEL")) or "real"
    label = fake_label if fake_probability >= threshold else real_label
    signals = build_signals(fake_probability)
    summary = (
        f"CNN inference completed with {screening_score}% synthetic-media probability. "
        f"The current threshold labels this upload as {label}."
    )

    print(
        json.dumps(
            build_response(
                status="completed",
                screening_score=screening_score,
                signals=signals,
                summary=summary,
            )
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
