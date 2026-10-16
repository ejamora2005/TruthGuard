"""Verify and archive completed GPU benchmark results without inference or deployment.

Reproduces the already selected calculation from frozen scores. It never tunes a
new method, changes labels, enables calibration, or executes code from the ZIP.
"""
import argparse
from datetime import datetime, timezone
import hashlib
from html import escape
import json
import math
from pathlib import Path
import shutil
import zipfile

from benchmark_image_calibration import fit_benchmark, validate_candidates, validate_capture
from build_colab_calibration import FILES
from colab_calibration_support import digest
from cuda_benchmark_detector import ADAPTER_VERSION

MEMBERS = {"benchmark-calibration.json", "scores.jsonl", "run-plan.json",
           "progress.json", "colab-run.log", "annotations.jsonl"}
ROOT = Path(__file__).resolve().parents[2]


def read_archive(path):
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        if len(entries) != len(MEMBERS) or {entry.filename for entry in entries} != MEMBERS:
            raise ValueError("Expected exactly the six completed Colab result files")
        if any(entry.file_size > 25_000_000 or entry.external_attr >> 16 & 0o170000 == 0o120000
               for entry in entries):
            raise ValueError("Oversized result or archive symlink")
        # Reading checks ZIP CRC as well; no paths from the archive are extracted.
        return {name: archive.read(name) for name in MEMBERS}


def jsonl(data):
    return [json.loads(line) for line in data.decode("utf-8-sig").splitlines() if line.strip()]


def require_close(actual, expected, location="report"):
    if isinstance(expected, bool) or expected is None or isinstance(expected, str):
        equal = type(actual) is type(expected) and actual == expected
    elif isinstance(expected, int):
        equal = type(actual) is int and actual == expected
    elif isinstance(expected, float):
        # Linux/Windows libm can pick adjacent points on the flat optimum.
        equal = (not isinstance(actual, bool) and isinstance(actual, (int, float))
                 and math.isfinite(actual) and math.isclose(actual, expected, rel_tol=1e-7, abs_tol=1e-9))
    elif isinstance(expected, dict):
        equal = isinstance(actual, dict) and actual.keys() == expected.keys()
        if equal:
            for key in expected:
                require_close(actual[key], expected[key], location + "." + key)
    elif isinstance(expected, list):
        equal = isinstance(actual, list) and len(actual) == len(expected)
        if equal:
            for index, value in enumerate(expected):
                require_close(actual[index], value, location + f"[{index}]")
    else:
        equal = False
    if not equal:
        raise ValueError("Stored calculation does not reproduce: " + location)


def verify(contents, bundle_path, model_dir):
    report, plan, progress = (json.loads(contents[name]) for name in
                              ("benchmark-calibration.json", "run-plan.json", "progress.json"))
    annotations, predictions = jsonl(contents["annotations.jsonl"]), jsonl(contents["scores.jsonl"])
    validate_candidates(annotations)
    identity = report["model_identity"]
    validate_capture(annotations, predictions, identity, complete=True)
    if (identity != plan["model_identity"] or identity["adapter_version"] != ADAPTER_VERSION
            or report.get("execution_profile") != plan.get("execution_profile")
            or report["execution_profile"].get("device") != "cuda"
            or report["execution_profile"].get("precision") != "float32"):
        raise ValueError("GPU execution profile or model identity mismatch")
    if (progress.get("phase") != "completed" or progress.get("captured") != len(annotations)
            or progress.get("total") != len(annotations) or plan.get("cases") != len(annotations)):
        raise ValueError("Capture did not complete every planned image")
    for key, filename in (("annotations", "annotations.jsonl"), ("predictions", "scores.jsonl")):
        expected = hashlib.sha256(contents[filename]).hexdigest()
        if report["input_hashes"].get(key) != expected:
            raise ValueError("Report input checksum mismatch: " + key)
    if plan.get("annotations_sha256") != report["input_hashes"]["annotations"]:
        raise ValueError("Run plan annotation checksum mismatch")
    with zipfile.ZipFile(bundle_path) as bundle:
        manifest = json.loads(bundle.read("bundle-manifest.json"))
        if set(manifest["files"]) != set(FILES) | {"calibration/annotations.jsonl"}:
            raise ValueError("Original notebook bundle is incomplete")
        for name, expected in manifest["files"].items():
            data = bundle.read(name)
            if hashlib.sha256(data).hexdigest() != expected:
                raise ValueError("Original notebook bundle changed: " + name)
            if name.startswith("automation/") and (ROOT / name).read_bytes() != data:
                raise ValueError("Local evaluation/model code differs from notebook bundle: " + name)
        if contents["annotations.jsonl"] != bundle.read("calibration/annotations.jsonl"):
            raise ValueError("Returned labels or split differ from the original notebook bundle")
        if manifest["cases"] != len(annotations):
            raise ValueError("Notebook case count mismatch")
    model_dir = Path(model_dir)
    source = json.loads((model_dir / "source.json").read_text())
    if identity["model_version"] != "sha256:" + digest(model_dir / "pytorch_model.pt"):
        raise ValueError("Results do not match the local checkpoint")
    if identity["model_version"] != "sha256:" + source["sha256"]:
        raise ValueError("Results do not match the pinned model release")
    for field, name in (("config_sha256", "config.json"), ("processor_sha256", "preprocessor_config.json")):
        if identity[field] != digest(model_dir / name):
            raise ValueError("Local preprocessing/configuration mismatch")
    reproduced = fit_benchmark(annotations, predictions, identity)
    for field in ("schema_version", "status", "method", "temperature", "counts", "independent_scene_groups",
                  "test_classification_at_raw_threshold_0_5", "benchmark_validation", "validation",
                  "deployment_eligible", "review_status"):
        require_close(report[field], reproduced[field], field)
    if progress.get("deployment_eligible") is not False or progress.get("status") != report["status"]:
        raise ValueError("Progress and report validation status disagree")
    require_close(progress["temperature"], report["temperature"], "progress.temperature")
    per_source = report["test_classification_at_raw_threshold_0_5"]
    total = sum(row["count"] for row in per_source.values())
    correct = sum(row["correct"] for row in per_source.values())
    mapped = {row["case_id"]: row for row in predictions}
    # Report the application's EXISTING frozen 0.9 selection rule separately.
    # It is evaluated on GPU scores here, not claimed as measured CPU behavior.
    selected = [row for row in annotations if row["split"] == "test"
                and max(mapped[row["case_id"]]["fake_probability"],
                        1 - mapped[row["case_id"]]["fake_probability"]) >= .9]
    selected_correct = sum((mapped[row["case_id"]]["fake_probability"] > .5)
                           == (row["label"] == "AI_GENERATED") for row in selected)
    return {"status": "verified_experimental_gpu_benchmark", "verified_at": datetime.now(timezone.utc).isoformat(),
            "checks": {name: True for name in ("archive_crc", "complete_capture", "input_hashes", "frozen_labels_and_splits",
                        "no_cross_split_groups_or_exact_duplicates", "local_pinned_checkpoint", "code_matches_notebook_bundle",
                        "calculations_reproduced")},
            "images": len(annotations), "test_images": total, "test_correct_at_raw_threshold_0_5": correct,
            "numeric_reproduction_tolerance": {"relative": 1e-7, "absolute": 1e-9, "integer_counts": "exact"},
            "test_accuracy_at_raw_threshold_0_5": correct / total,
            "test_by_source": per_source, "temperature": report["temperature"],
            "before": report["benchmark_validation"]["before"], "after": report["benchmark_validation"]["after"],
            "paired_log_loss_delta_95pct_interval": report["benchmark_validation"]["paired_log_loss_delta_95pct_interval"],
            "existing_0_9_rule_on_gpu_scores": {"selected": len(selected), "total": total,
                "correct": selected_correct, "coverage": len(selected) / total,
                "accuracy_when_selected": selected_correct / len(selected) if selected else None,
                "is_cpu_runtime_measurement": False},
            "execution_profile": report["execution_profile"], "review_status": report["review_status"],
            "deployment_eligible": False, "live_settings_changed": False,
            "blockers": ["GPU FP32 adapter differs from the application's CPU BF16 adapter.",
                         "Independent label review and training-overlap exclusion remain unresolved.",
                         "RAISE/FLUX/SD3 originals and their class ratio do not establish suitability for user uploads."],
            "next_steps": ["Keep this final test split sealed; do not tune another method against its results.",
                           "Review known-origin provenance, training overlap and the intended upload population.",
                           "Capture scores from the exact intended deployment adapter on a suitable separately reserved calibration/test cohort.",
                           "Run the reviewed fitter and enable only an artifact that passes its checks and matches the deployed adapter."],
            "paid_api_calls": 0, "new_inference_runs": 0}


def html_report(review):
    sources = "".join(f"<tr><td>{escape(name)}</td><td>{row['correct']}/{row['count']}</td>"
                      f"<td>{row['accuracy']:.2%}</td></tr>" for name, row in review["test_by_source"].items())
    blockers = "".join(f"<li>{escape(value)}</li>" for value in review["blockers"])
    steps = "".join(f"<li>{escape(value)}</li>" for value in review["next_steps"])
    before, after = review["before"], review["after"]
    return f"""<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>TruthGuard GPU calibration review</title><style>body{{font:17px/1.65 system-ui;margin:40px auto;padding:0 24px;max-width:900px;color:#182235;background:#f8fafc}}h1,h2{{line-height:1.25}}table{{border-collapse:collapse;width:100%;background:white}}td,th{{text-align:left;padding:10px;border-bottom:1px solid #cbd5e1}}.note{{background:#fff7ed;border-left:4px solid #c2410c;padding:16px}}code{{overflow-wrap:anywhere}}</style>
<h1>GPU experiment completed and verified</h1><p class="note">This is an experimental benchmark, not an approved live confidence profile. TruthGuard's live model, verdict rules, saved results and calibration settings were not changed.</p>
<p>All {review['images']:,} images completed. The frozen test split contains {review['test_images']:,} images. At the original 0.5 threshold, {review['test_correct_at_raw_threshold_0_5']:,} were classified correctly ({review['test_accuracy_at_raw_threshold_0_5']:.2%}) on this benchmark. This is not measured accuracy on user uploads.</p>
<table><tr><th>Test source</th><th>Correct / total</th><th>Correctly classified</th></tr>{sources}</table>
<h2>Calibration result</h2><p>Temperature: {review['temperature']:.9f}. Fitted only from the calibration split. Temperature scaling changes the scores, preserving the original 0.5 classification decision.</p>
<table><tr><th>Held-out metric (lower is better)</th><th>Before</th><th>After</th></tr>
<tr><td>Log loss</td><td>{before['log_loss']:.6f}</td><td>{after['log_loss']:.6f}</td></tr>
<tr><td>Brier score</td><td>{before['brier_positive_class']:.6f}</td><td>{after['brier_positive_class']:.6f}</td></tr>
<tr><td>10-bin calibration error</td><td>{before['positive_class_ece_10_bins']:.6f}</td><td>{after['positive_class_ece_10_bins']:.6f}</td></tr></table>
<h2>Why live confidence stays unchanged</h2><ul>{blockers}</ul><h2>Remaining validation</h2><ol>{steps}</ol>
<p>The archive, original bundle, labels/splits, exact model, 2,994 saved scores and reported calculations were checked locally. No OpenAI calls or new model inference were used.</p>
<p>Machine-readable details: <a href="review.json">review.json</a>. Original report: <a href="benchmark-calibration.json">benchmark-calibration.json</a>.</p></html>"""


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--bundle", type=Path, required=True)
    parser.add_argument("--model-dir", type=Path, default=ROOT / "automation/models/ai-origin-broad")
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()
    if args.output_dir.exists():
        parser.error("Refusing to overwrite a result archive; choose a new directory")
    contents = read_archive(args.archive)
    review = verify(contents, args.bundle, args.model_dir)
    review["archive_sha256"], review["bundle_sha256"] = digest(args.archive), digest(args.bundle)
    args.output_dir.mkdir(parents=True)
    for name, data in contents.items():
        (args.output_dir / name).write_bytes(data)
    shutil.copyfile(args.archive, args.output_dir / "original-results.zip")
    (args.output_dir / "review.json").write_text(json.dumps(review, indent=2, allow_nan=False) + "\n", encoding="utf-8")
    (args.output_dir / "review.html").write_text(html_report(review), encoding="utf-8")
    print(json.dumps({"status": review["status"], "images": review["images"],
                      "test_accuracy": review["test_accuracy_at_raw_threshold_0_5"],
                      "deployment_eligible": False, "output": str(args.output_dir)}))


if __name__ == "__main__":
    main()
