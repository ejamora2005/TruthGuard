"""Prepare a reproducible publisher-labeled AI-origin benchmark.

The supported public bundle is the University of Naples Federico II B-Free
extended Synthbuster package: RAISE camera photographs plus FLUX and Stable
Diffusion 3 generated images. Labels come from the publisher's directory
layout. They are suitable for an experimental benchmark, not an assertion of
independent human review or guaranteed exclusion from a model's training data.
"""
import argparse
import hashlib
import json
from pathlib import Path
import random
import re

IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".webp", ".tif", ".tiff", ".bmp"}
SOURCES = {
    "raise_camera": {
        "label": "NOT_AI_GENERATED",
        "tokens": ("real_raise_1k", "raise"),
        "evidence_url": "https://www.grip.unina.it/download/prog/B-Free/extended_synthbuster/real_RAISE_1k.zip",
        "provenance": "RAISE camera photograph distributed in the B-Free extended Synthbuster benchmark.",
    },
    "flux": {
        "label": "AI_GENERATED",
        "tokens": ("flux",),
        "evidence_url": "https://www.grip.unina.it/download/prog/B-Free/extended_synthbuster/sd3_flux.zip",
        "provenance": "FLUX-generated image distributed in the B-Free extended Synthbuster benchmark.",
    },
    "sd3_large": {
        "label": "AI_GENERATED",
        "tokens": ("sd3", "stable_diffusion_3", "stable-diffusion-3"),
        "evidence_url": "https://www.grip.unina.it/download/prog/B-Free/extended_synthbuster/sd3_flux.zip",
        "provenance": "Stable Diffusion 3-generated image distributed in the B-Free extended Synthbuster benchmark.",
    },
}


def digest(path):
    value = hashlib.sha256()
    with Path(path).open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            value.update(block)
    return value.hexdigest()


def source_for(path, root):
    relative = path.relative_to(root).as_posix().lower()
    matches = []
    for source, details in SOURCES.items():
        if any(token in relative for token in details["tokens"]):
            matches.append(source)
    # "sd3_flux" is an archive/container name, so a FLUX filename must not
    # cause an SD3 image to match both sources (or vice versa).
    if "flux" in matches and "sd3_large" in matches:
        parts = {part.lower() for part in path.relative_to(root).parts}
        if any("sd3" in part or "stable" in part for part in parts):
            # Ignore the shared archive/container name `sd3_flux`.
            if any(part != "sd3_flux" and ("sd3" in part or "stable" in part) for part in parts):
                return "sd3_large"
        if any(part != "sd3_flux" and "flux" in part for part in parts):
            return "flux"
    if len(matches) != 1:
        raise ValueError(f"Cannot determine one publisher source for {path}")
    return matches[0]


def stable_split(group, seed):
    value = hashlib.sha256(f"{seed}:{group}".encode()).digest()
    return "calibration" if int.from_bytes(value[:8], "big") % 2 == 0 else "test"


def prepare(dataset_dir, output, seed=42173, maximum_per_source=None):
    dataset_dir = Path(dataset_dir).resolve()
    output = Path(output).resolve()
    candidates = [path for path in dataset_dir.rglob("*")
                  if path.is_file() and path.suffix.lower() in IMAGE_SUFFIXES]
    grouped = {source: [] for source in SOURCES}
    for path in candidates:
        grouped[source_for(path, dataset_dir)].append(path)
    if any(not paths for paths in grouped.values()):
        missing = [source for source, paths in grouped.items() if not paths]
        raise ValueError("Missing required publisher folders: " + ", ".join(missing))

    rng = random.Random(seed)
    rows = []
    seen_hashes = set()
    for source, paths in grouped.items():
        paths = sorted(paths, key=lambda path: path.as_posix().lower())
        rng.shuffle(paths)
        if maximum_per_source:
            paths = paths[:maximum_per_source]
        details = SOURCES[source]
        for index, path in enumerate(paths, 1):
            sha = digest(path)
            if sha in seen_hashes:
                continue
            seen_hashes.add(sha)
            try:
                relative = path.relative_to(output.parent).as_posix()
            except ValueError:
                # A manifest may be stored outside the dataset tree. Absolute
                # paths remain explicit and are rewritten by portable bundles.
                relative = path.as_posix()
            original_stem = re.sub(r"--tg-(?:jpeg55|resized|screenshot|text_overlay|filtered)$", "", path.stem.lower())
            media_group = f"{source}:{original_stem}"
            condition_match = re.search(r"--tg-([a-z0-9_]+)$", path.stem.lower())
            condition = condition_match.group(1) if condition_match else "publisher_original"
            rows.append({
                "case_id": f"{source}-{index:05d}-{sha[:10]}",
                "task": "ai_generation",
                "text": "",
                "label": details["label"],
                "candidate_source": source,
                "condition": condition,
                "event_group": media_group,
                "media_group": media_group,
                "duplicate_group": sha,
                "split": stable_split(media_group, seed),
                "reviewer": None,
                "reviewed_at": None,
                "human_reviewed": False,
                "evidence_urls": [details["evidence_url"]],
                "provenance": details["provenance"] + (" TruthGuard created a controlled " + condition.replace("_", " ") + " derivative for robustness evaluation." if condition_match else ""),
                "evaluation_cutoff": None,
                "media_path": relative,
                "input_sha256": sha,
                "independent_of_training": False,
            })

    counts = {(split, label): sum(row["split"] == split and row["label"] == label for row in rows)
              for split in ("calibration", "test")
              for label in ("NOT_AI_GENERATED", "AI_GENERATED")}
    if any(count < 100 for count in counts.values()):
        raise ValueError(f"At least 100 independent cases per class and split are required; got {counts}")
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists():
        raise FileExistsError("Refusing to overwrite immutable benchmark annotations: " + str(output))
    with output.open("x", encoding="utf-8", newline="\n") as stream:
        for row in sorted(rows, key=lambda item: item["case_id"]):
            stream.write(json.dumps(row, ensure_ascii=False) + "\n")
    return {"annotations": str(output), "cases": len(rows),
            "counts": {f"{split}:{label}": count for (split, label), count in counts.items()},
            "publisher_labels_only": True, "live_settings_changed": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset-dir", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--seed", type=int, default=42173)
    parser.add_argument("--maximum-per-source", type=int)
    args = parser.parse_args()
    print(json.dumps(prepare(args.dataset_dir, args.output, args.seed, args.maximum_per_source), indent=2))


if __name__ == "__main__":
    raise SystemExit(main())
