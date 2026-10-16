"""Offline temperature-calibration experiment on publisher-labeled benchmark images.

This accepts candidate dataset labels without claiming independent human review,
training exclusion, or deployment suitability. Its report is deliberately rejected
by TruthGuard's live confidence loader. The reviewed production fitter is unchanged.
Run with .venv-ai-origin/Scripts/python.exe; no API or model downloads are used.
"""
import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import sys
import time

from calibrate_image_scores import bootstrap_log_loss_delta, fit_temperature, metrics

ROOT = Path(__file__).resolve().parents[2]
LABELS = {"NOT_AI_GENERATED": 0, "AI_GENERATED": 1}
IDENTITY_FIELDS = ("model_version", "config_sha256", "processor_sha256", "adapter_version")


def sha256(path):
    digest = hashlib.sha256()
    with Path(path).open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_jsonl(path):
    return [json.loads(line) for line in Path(path).read_text(encoding="utf-8-sig").splitlines() if line.strip()]


def validate_candidates(rows):
    ids, hashes, groups = set(), set(), {}
    for row in rows:
        if not row.get("case_id") or row["case_id"] in ids:
            raise ValueError("Missing or duplicate case ID")
        ids.add(row["case_id"])
        digest = row.get("input_sha256", "")
        if len(digest) != 64 or any(char not in "0123456789abcdef" for char in digest) or digest in hashes:
            raise ValueError("Unique original image SHA-256 required")
        hashes.add(digest)
        if row.get("task") != "ai_generation" or row.get("label") not in LABELS or row.get("split") not in {"calibration", "test"}:
            raise ValueError("Known-origin binary benchmark labels and explicit splits required")
        if not row.get("event_group") or not row.get("media_path") or not row.get("provenance") or not row.get("evidence_urls"):
            raise ValueError("Original path, source provenance, and scene grouping required")
        for field in ("event_group", "media_group", "duplicate_group"):
            if value := row.get(field):
                key = (field, value)
                if key in groups and groups[key] != row["split"]:
                    raise ValueError("Related image group leaked between calibration and test")
                groups[key] = row["split"]
    if any({row["label"] for row in rows if row["split"] == split} != set(LABELS) for split in ("calibration", "test")):
        raise ValueError("Both image classes are required in each split")


def validate_capture(rows, predictions, identity, complete=False):
    if set(identity) != set(IDENTITY_FIELDS) or any(not isinstance(value, str) or not value for value in identity.values()):
        raise ValueError("Exact model identity required")
    mapped, cases = {}, {row["case_id"]: row for row in rows}
    for prediction in predictions:
        key = prediction.get("case_id")
        if key in mapped or key not in cases:
            raise ValueError("Duplicate or unknown captured case")
        if prediction.get("model_identity") != identity or prediction.get("input_sha256") != cases[key]["input_sha256"]:
            raise ValueError("Captured image or model identity changed")
        p = prediction.get("fake_probability")
        if isinstance(p, bool) or not isinstance(p, (float, int)) or not math.isfinite(p) or not 0 <= p <= 1:
            raise ValueError("Captured probability must be finite and normalized")
        mapped[key] = prediction
    if complete and set(mapped) != set(cases):
        raise ValueError("Cannot fit from an incomplete capture")
    return mapped


def fit_benchmark(rows, predictions, identity):
    validate_candidates(rows)
    mapped = validate_capture(rows, predictions, identity, complete=True)
    points = {split: [(mapped[row["case_id"]]["fake_probability"], LABELS[row["label"]])
                      for row in rows if row["split"] == split] for split in ("calibration", "test")}
    temperature = fit_temperature(points["calibration"])
    before, after = metrics(points["test"], 1), metrics(points["test"], temperature)
    interval = bootstrap_log_loss_delta(points["test"], temperature,
                                        [row["event_group"] for row in rows if row["split"] == "test"])
    sufficient = all(len({row["event_group"] for row in rows if row["split"] == split and row["label"] == label}) >= 100
                     for split in points for label in LABELS)
    improves = (after["log_loss"] < before["log_loss"] and interval[1] < 0
                and after["brier_positive_class"] <= before["brier_positive_class"]
                and after["positive_class_ece_10_bins"] <= before["positive_class_ece_10_bins"])
    by_source = {}
    for source in sorted({row.get("candidate_source", "unspecified") for row in rows}):
        selected = [row for row in rows if row["split"] == "test" and row.get("candidate_source", "unspecified") == source]
        correct = sum((mapped[row["case_id"]]["fake_probability"] > .5) == bool(LABELS[row["label"]]) for row in selected)
        by_source[source] = {"count": len(selected), "correct": correct, "accuracy": correct / len(selected) if selected else None}
    return {
        "schema_version": 1, "status": "experimental_benchmark_only", "method": "binary-temperature-v1",
        "temperature": temperature, "model_identity": identity,
        "counts": {split: dict(Counter(row["label"] for row in rows if row["split"] == split)) for split in points},
        "independent_scene_groups": {split: len({row["event_group"] for row in rows if row["split"] == split}) for split in points},
        "test_classification_at_raw_threshold_0_5": by_source,
        "benchmark_validation": {"sufficient_candidate_groups": sufficient,
            "finite_interior_fit": .0501 < temperature < 99.99,
            "held_out_improvement": improves, "before": before, "after": after,
            "paired_log_loss_delta_95pct_interval": interval, "fit_used_test_data": False},
        "validation": {"passed": False, "fit_used_test_data": False},
        "deployment_eligible": False,
        "review_status": {"independent_human_review_complete": all(row.get("human_reviewed") is True for row in rows),
                          "training_independence_confirmed": all(row.get("independent_of_training") is True for row in rows),
                          "deployment_population_reviewed": False},
        "fitted_at": datetime.now(timezone.utc).isoformat(),
        "limitations": ["Uses published benchmark labels; independent review and training-overlap exclusion remain unresolved.",
                        "Results apply to these original RAISE, FLUX and SD3.5 benchmark files at their observed class ratio.",
                        "Source grouping and exact deduplication do not rule out all near-duplicate relationships.",
                        "This report cannot enable live confidence, even if benchmark metrics improve.",
                        "Temperature scaling preserves the 0.5 class decision; it does not improve classification accuracy.",
                        "No test-label tuning or alternate method selection is permitted after this evaluation."],
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--annotations", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--threads", type=int, default=3)
    parser.add_argument("--device", choices=("cpu", "cuda"), default="cpu")
    args = parser.parse_args()
    if not 1 <= args.threads <= 8:
        parser.error("Use 1 to 8 CPU threads")
    rows = read_jsonl(args.annotations)
    validate_candidates(rows)
    args.output_dir.mkdir(parents=True, exist_ok=True)
    report_path = args.output_dir / "benchmark-calibration.json"
    if report_path.exists():
        parser.error("Completed experiments are immutable; use a new output directory")
    directory = ROOT / "automation/models/ai-origin-broad"
    identity = {"model_version": "sha256:" + sha256(directory / "pytorch_model.pt"),
                "config_sha256": sha256(directory / "config.json"),
                "processor_sha256": sha256(directory / "preprocessor_config.json"),
                "adapter_version": "broad-ai-origin-v1"}
    profile = None
    if args.device == "cuda":
        from cuda_benchmark_detector import ADAPTER_VERSION, execution_profile
        profile = execution_profile()
        identity["adapter_version"] = ADAPTER_VERSION
    plan = {"annotations_sha256": sha256(args.annotations), "model_identity": identity,
            "cases": len(rows), "method": "binary-temperature-v1", "cpu_threads": args.threads,
            "scope": "experimental_publisher_labeled_benchmark_not_deployment_validation"}
    if profile is not None:
        plan["execution_profile"] = profile
    plan_path, capture_path = args.output_dir / "run-plan.json", args.output_dir / "scores.jsonl"
    if args.resume:
        if not plan_path.exists() or json.loads(plan_path.read_text()) != plan:
            parser.error("Cannot resume after annotations, experiment, or model identity changed")
    else:
        with plan_path.open("x", encoding="utf-8") as stream:
            json.dump(plan, stream, indent=2)
        if capture_path.exists():
            parser.error("Capture already exists; use explicit --resume")
    predictions = read_jsonl(capture_path) if capture_path.exists() else []
    completed = validate_capture(rows, predictions, identity)
    progress_path = args.output_dir / "progress.json"

    def progress(state):
        temporary = progress_path.with_suffix(".json.tmp")
        state["updated_at"] = datetime.now(timezone.utc).isoformat()
        temporary.write_text(json.dumps(state, indent=2, allow_nan=False), encoding="utf-8")
        temporary.replace(progress_path)
        print(json.dumps(state, allow_nan=False), flush=True)

    progress({"phase": "loading_model", "captured": len(completed), "total": len(rows)})
    # No new model download or network access is needed or allowed in this run.
    os.environ.update(HF_HUB_OFFLINE="1", TRANSFORMERS_OFFLINE="1", HF_HUB_DISABLE_TELEMETRY="1",
                      AI_ORIGIN_CPU_THREADS=str(args.threads))
    import socket

    def deny_network(*_args, **_kwargs):
        raise RuntimeError("Network access disabled for offline calibration")

    socket.socket.connect = deny_network
    socket.socket.connect_ex = deny_network
    sys.path.insert(0, str(ROOT / "automation/deepfake-models"))
    print(f"Loading pinned local detector; {len(completed)}/{len(rows)} scores already captured", flush=True)
    if args.device == "cuda":
        from cuda_benchmark_detector import load_detector
        detector = load_detector(directory)
    else:
        from broad_ai_origin import BroadDetector
        detector = BroadDetector(directory)
    if "sha256:" + detector.weight_hash != identity["model_version"]:
        raise ValueError("Checkpoint changed during model loading")
    started, last_progress, added = time.monotonic(), time.monotonic(), 0
    ordered = sorted(rows, key=lambda row: (row["split"] != "calibration", row["case_id"]))
    with capture_path.open("a" if args.resume else "x", encoding="utf-8") as stream:
        for row in ordered:
            if row["case_id"] in completed:
                continue
            path = Path(row["media_path"])
            if not path.is_absolute():
                path = args.annotations.resolve().parent / path
            if sha256(path) != row["input_sha256"]:
                raise ValueError("Original image hash changed: " + row["case_id"])
            # Only original image pixels enter the detector; no labels or source strings.
            probability = detector.predict(path)
            if sha256(path) != row["input_sha256"]:
                raise ValueError("Image changed during prediction: " + row["case_id"])
            prediction = {"case_id": row["case_id"], "input_sha256": row["input_sha256"],
                          "model_identity": identity, "fake_probability": probability}
            validate_capture([row], [prediction], identity)
            stream.write(json.dumps(prediction, allow_nan=False) + "\n")
            stream.flush()
            predictions.append(prediction)
            added += 1
            now = time.monotonic()
            if added <= 3 or now - last_progress >= 30 or len(predictions) == len(rows):
                speed = (now - started) / added
                progress({"phase": "scoring", "captured": len(predictions), "total": len(rows),
                          "seconds_per_image": round(speed, 2),
                          "remaining_minutes_estimate": round(speed * (len(rows) - len(predictions)) / 60, 1)})
                last_progress = now
    progress({"phase": "fitting_and_testing", "captured": len(predictions), "total": len(rows)})
    result = fit_benchmark(rows, predictions, identity)
    if profile is not None:
        result["execution_profile"] = profile
        result["limitations"].append("GPU FP32 scores are not interchangeable with the application's CPU BF16 scores; no CPU/GPU score mixing or automatic deployment is allowed.")
    result["input_hashes"] = {"annotations": plan["annotations_sha256"], "predictions": sha256(capture_path)}
    with report_path.open("x", encoding="utf-8") as stream:
        json.dump(result, stream, indent=2, allow_nan=False)
    progress({"phase": "completed", "captured": len(predictions), "total": len(rows),
              "status": result["status"], "temperature": result["temperature"],
              "report": str(report_path), "deployment_eligible": False})


if __name__ == "__main__":
    main()
