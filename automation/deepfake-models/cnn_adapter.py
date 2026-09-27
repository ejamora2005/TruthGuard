import json
import math
import os
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Any


DEFAULT_IMAGE_SIZE = 224
DEFAULT_THRESHOLD = 0.5
DEFAULT_INPUT_RANGE = "0_to_1_float32"
DEFAULT_VIDEO_FRAME_LIMIT = 8
DEFAULT_VIDEO_FRAME_STRIDE = 12
DEFAULT_VIDEO_AGGREGATION_METHOD = "mean"
DEFAULT_VIDEO_FACE_CROP = False
DEFAULT_VIDEO_FACE_MARGIN = 0.45


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


def env_bool(name: str, default: bool) -> bool:
    raw_value = os.getenv(name)

    if raw_value is None:
        return default

    normalized = raw_value.strip().lower()

    if normalized in {"1", "true", "yes", "on"}:
        return True

    if normalized in {"0", "false", "no", "off"}:
        return False

    return default


def build_response(
    *,
    status: str,
    screening_score: int,
    signals: list[dict[str, int | str]],
    summary: str,
    model_family: str = "cnn",
    requires_model_integration: bool = False,
    fake_probability: float | None = None,
    threshold: float | None = None,
    label: str | None = None,
    image_size: int | None = None,
    media_type: str | None = None,
    frames_used: int | None = None,
    aggregation_method: str | None = None,
) -> dict[str, Any]:
    response = {
        "status": status,
        "model_family": model_family,
        "screening_score": max(0, min(100, int(screening_score))),
        "signals": signals,
        "summary": summary,
        "requires_model_integration": requires_model_integration,
    }

    if fake_probability is not None:
        response["fake_probability"] = max(0.0, min(1.0, float(fake_probability)))

    if threshold is not None:
        response["threshold"] = max(0.0, min(1.0, float(threshold)))

    if label is not None:
        response["label"] = label

    if image_size is not None:
        response["image_size"] = max(1, int(image_size))

    if media_type is not None:
        response["media_type"] = media_type

    if frames_used is not None:
        response["frames_used"] = max(0, int(frames_used))

    if aggregation_method is not None:
        response["aggregation_method"] = aggregation_method

    return response


def load_request() -> dict[str, Any]:
    raw_payload = sys.stdin.read().lstrip("\ufeff").lstrip("ï»¿").strip()

    if not raw_payload:
        return {}

    try:
        parsed = json.loads(raw_payload)
    except json.JSONDecodeError:
        return {}

    return parsed if isinstance(parsed, dict) else {}


def load_model_config(model_path: Path) -> dict[str, Any]:
    config_path_value = nullable_string(os.getenv("DEEPFAKE_CNN_CONFIG_PATH"))
    candidates = []

    if config_path_value is not None:
        candidates.append(Path(config_path_value))

    candidates.extend(
        [
            model_path.with_name(f"{model_path.stem}_config.json"),
            model_path.parent / "truthguard_image_model_config.json",
        ]
    )

    for candidate in candidates:
        if not candidate.is_file():
            continue

        try:
            with candidate.open("r", encoding="utf-8") as config_file:
                parsed = json.load(config_file)
        except Exception:
            continue

        if isinstance(parsed, dict):
            return parsed

    return {}


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
        model = tf.keras.models.load_model(model_path, compile=False, safe_mode=False)
    except Exception as exc:
        try:
            with sanitized_keras_archive(model_path) as sanitized_model_path:
                model = tf.keras.models.load_model(sanitized_model_path, compile=False, safe_mode=False)
        except Exception:
            return None, str(exc)

    return model, None


class sanitized_keras_archive:
    def __init__(self, model_path: Path):
        self.model_path = model_path
        self.temporary_directory: tempfile.TemporaryDirectory[str] | None = None
        self.sanitized_path: Path | None = None

    def __enter__(self) -> Path:
        if self.model_path.suffix.lower() != ".keras":
            raise ValueError("Only .keras archives can be sanitized.")

        self.temporary_directory = tempfile.TemporaryDirectory()
        self.sanitized_path = Path(self.temporary_directory.name) / self.model_path.name

        with zipfile.ZipFile(self.model_path, "r") as source_archive:
            with zipfile.ZipFile(self.sanitized_path, "w", compression=zipfile.ZIP_DEFLATED) as target_archive:
                for source_info in source_archive.infolist():
                    payload = source_archive.read(source_info.filename)

                    if source_info.filename == "config.json":
                        config = json.loads(payload.decode("utf-8"))
                        strip_null_quantization_config(config)
                        payload = json.dumps(config, separators=(",", ":")).encode("utf-8")

                    target_archive.writestr(source_info, payload)

        return self.sanitized_path

    def __exit__(self, exc_type, exc, traceback) -> None:
        if self.temporary_directory is not None:
            self.temporary_directory.cleanup()


def strip_null_quantization_config(value: Any) -> None:
    if isinstance(value, dict):
        if value.get("quantization_config") is None:
            value.pop("quantization_config", None)

        for child in value.values():
            strip_null_quantization_config(child)

    if isinstance(value, list):
        for child in value:
            strip_null_quantization_config(child)


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


def configured_input_range(model_config: dict[str, Any]) -> str:
    env_value = nullable_string(os.getenv("DEEPFAKE_CNN_INPUT_RANGE"))

    if env_value is not None:
        return env_value

    preprocessing = model_config.get("preprocessing")

    if isinstance(preprocessing, dict):
        config_value = nullable_string(preprocessing.get("input_range"))

        if config_value is not None:
            return config_value

    return DEFAULT_INPUT_RANGE


def uses_zero_to_one_input(input_range: str) -> bool:
    normalized = input_range.lower().replace("-", "_").replace(" ", "_")
    return normalized in {"0_to_1", "0_to_1_float32", "0_1", "normalized", "normalized_float32"}


def preprocess_pil_image(image, image_size: int, input_range: str, np):
    resized = image.convert("RGB").resize((image_size, image_size))
    pixels = np.asarray(resized, dtype="float32")

    if uses_zero_to_one_input(input_range):
        pixels = pixels / 255.0

    return np.expand_dims(pixels, axis=0)


def predict_image_probability(model, media_path: Path, image_size: int, input_range: str, np, Image) -> float:
    with Image.open(media_path) as image:
        batch = preprocess_pil_image(image, image_size, input_range, np)

    prediction = model.predict(batch, verbose=0)
    return extract_fake_probability(prediction, np)


def sample_video_frame_indices(capture, frame_limit: int) -> list[int]:
    total_frames = int(capture.get(7) or 0)

    if total_frames <= 0:
        return []

    if total_frames <= frame_limit:
        return list(range(total_frames))

    return [int(index * (total_frames - 1) / (frame_limit - 1)) for index in range(frame_limit)]


def crop_largest_face(image_rgb, cv2, face_cascade, margin: float):
    if face_cascade is None:
        return image_rgb, False

    gray = cv2.cvtColor(image_rgb, cv2.COLOR_RGB2GRAY)
    faces = face_cascade.detectMultiScale(
        gray,
        scaleFactor=1.08,
        minNeighbors=4,
        minSize=(35, 35),
    )

    if len(faces) == 0:
        return image_rgb, False

    x, y, width, height = max(faces, key=lambda box: box[2] * box[3])
    image_height, image_width = image_rgb.shape[:2]

    center_x = x + width // 2
    center_y = y + height // 2
    side = int(max(width, height) * (1 + margin))

    x1 = max(0, center_x - side // 2)
    y1 = max(0, center_y - side // 2)
    x2 = min(image_width, center_x + side // 2)
    y2 = min(image_height, center_y + side // 2)

    crop = image_rgb[y1:y2, x1:x2]

    if getattr(crop, "size", 0) == 0:
        return image_rgb, False

    return crop, True


def load_face_cascade(cv2):
    try:
        cascade_path = cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
        face_cascade = cv2.CascadeClassifier(cascade_path)
    except Exception:
        return None

    if face_cascade.empty():
        return None

    return face_cascade


def top_k_mean(values, k: int) -> float:
    sorted_values = sorted(float(value) for value in values)
    if not sorted_values:
        raise ValueError("Cannot aggregate an empty probability list.")

    selected_values = sorted_values[-max(1, min(k, len(sorted_values))):]

    return float(sum(selected_values) / len(selected_values))


def aggregate_probabilities(probabilities: list[float], method: str) -> float:
    normalized_method = method.lower().strip()

    if normalized_method == "mean":
        return float(sum(probabilities) / len(probabilities))

    if normalized_method == "median":
        sorted_values = sorted(probabilities)
        midpoint = len(sorted_values) // 2

        if len(sorted_values) % 2 == 1:
            return float(sorted_values[midpoint])

        return float((sorted_values[midpoint - 1] + sorted_values[midpoint]) / 2)

    if normalized_method == "max":
        return float(max(probabilities))

    if normalized_method.startswith("top") and normalized_method.endswith("_mean"):
        raw_k = normalized_method.removeprefix("top").removesuffix("_mean")

        try:
            return top_k_mean(probabilities, int(raw_k))
        except ValueError:
            pass

    return float(sum(probabilities) / len(probabilities))


def configured_video_aggregation(model_config: dict[str, Any]) -> str:
    env_value = nullable_string(os.getenv("DEEPFAKE_CNN_VIDEO_AGGREGATION_METHOD"))

    if env_value is not None:
        return env_value

    config_value = nullable_string(model_config.get("aggregation_method"))

    if config_value is not None:
        return config_value

    return DEFAULT_VIDEO_AGGREGATION_METHOD


def configured_video_face_crop(model_config: dict[str, Any]) -> bool:
    config_value = bool(model_config.get("face_crop", DEFAULT_VIDEO_FACE_CROP))

    return env_bool("DEEPFAKE_CNN_VIDEO_FACE_CROP", config_value)


def predict_video_probability(
    model,
    media_path: Path,
    image_size: int,
    input_range: str,
    frame_limit: int,
    frame_stride: int,
    aggregation_method: str,
    face_crop: bool,
    face_margin: float,
    np,
    Image,
):
    try:
        import cv2
    except Exception as exc:  # pragma: no cover - import guard
        return None, f"OpenCV is required for CNN video inference: {exc}"

    capture = cv2.VideoCapture(str(media_path))

    if not capture.isOpened():
        return None, 0, "Video file could not be opened for CNN inference."

    probabilities: list[float] = []
    batches = []
    face_cascade = load_face_cascade(cv2) if face_crop else None
    frame_indices = sample_video_frame_indices(capture, frame_limit)

    try:
        if frame_indices:
            for frame_index in frame_indices:
                capture.set(cv2.CAP_PROP_POS_FRAMES, frame_index)
                success, frame = capture.read()

                if not success:
                    continue

                rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)

                if face_crop:
                    rgb_frame, _ = crop_largest_face(rgb_frame, cv2, face_cascade, face_margin)

                pil_frame = Image.fromarray(rgb_frame)
                batches.append(preprocess_pil_image(pil_frame, image_size, input_range, np)[0])
        else:
            frame_index = 0

            while len(batches) < frame_limit:
                success, frame = capture.read()

                if not success:
                    break

                if frame_index % frame_stride == 0:
                    rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)

                    if face_crop:
                        rgb_frame, _ = crop_largest_face(rgb_frame, cv2, face_cascade, face_margin)

                    pil_frame = Image.fromarray(rgb_frame)
                    batches.append(preprocess_pil_image(pil_frame, image_size, input_range, np)[0])

                frame_index += 1
    finally:
        capture.release()

    if not batches:
        return None, 0, "Video inference did not extract any usable frames."

    prediction_batch = np.asarray(batches, dtype="float32")
    predictions = model.predict(prediction_batch, verbose=0)

    for prediction in predictions:
        probabilities.append(extract_fake_probability(prediction, np))

    return aggregate_probabilities(probabilities, aggregation_method), len(probabilities), None


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

    model_config = load_model_config(model_path)

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

    config_image_size = int(model_config.get("image_size", DEFAULT_IMAGE_SIZE) or DEFAULT_IMAGE_SIZE)
    config_threshold = float(model_config.get("threshold", DEFAULT_THRESHOLD) or DEFAULT_THRESHOLD)
    config_frame_limit = int(
        model_config.get("frames_per_video", model_config.get("frame_limit", DEFAULT_VIDEO_FRAME_LIMIT))
        or DEFAULT_VIDEO_FRAME_LIMIT
    )

    image_size = env_int("DEEPFAKE_CNN_IMAGE_SIZE", config_image_size, 32)
    threshold = env_float("DEEPFAKE_CNN_THRESHOLD", config_threshold, 0.0, 1.0)
    input_range = configured_input_range(model_config)
    frame_limit = env_int("DEEPFAKE_CNN_VIDEO_FRAME_LIMIT", config_frame_limit, 1)
    frame_stride = env_int("DEEPFAKE_CNN_VIDEO_FRAME_STRIDE", DEFAULT_VIDEO_FRAME_STRIDE, 1)
    aggregation_method = configured_video_aggregation(model_config)
    face_crop = configured_video_face_crop(model_config)
    face_margin = env_float("DEEPFAKE_CNN_VIDEO_FACE_MARGIN", DEFAULT_VIDEO_FACE_MARGIN, 0.0, 2.0)
    frames_used: int | None = None

    try:
        if media_type == "image":
            fake_probability = predict_image_probability(model, media_path, image_size, input_range, np, Image)
        else:
            fake_probability, frames_used, video_error = predict_video_probability(
                model,
                media_path,
                image_size,
                input_range,
                frame_limit,
                frame_stride,
                aggregation_method,
                face_crop,
                face_margin,
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

    if media_type == "video":
        summary = (
            f"CNN video inference completed with {screening_score}% synthetic-media probability "
            f"using {aggregation_method} aggregation across {frames_used or 0} sampled frame(s). "
            f"The current threshold labels this upload as {label}."
        )

    print(
        json.dumps(
            build_response(
                status="completed",
                screening_score=screening_score,
                signals=signals,
                summary=summary,
                fake_probability=fake_probability,
                threshold=threshold,
                label=label,
                image_size=image_size,
                media_type=media_type,
                frames_used=frames_used,
                aggregation_method=aggregation_method if media_type == "video" else None,
            )
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
