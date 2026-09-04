import argparse
import json
import random
import re
import subprocess
from dataclasses import dataclass
from dataclasses import field
from pathlib import Path
from typing import Any


IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".bmp"}
VIDEO_EXTENSIONS = {".mp4", ".mov", ".avi", ".mkv", ".webm", ".m4v"}


@dataclass(slots=True)
class SourceSpec:
    name: str
    path: Path
    label: str
    kind: str
    pattern: str
    group_mode: str = "auto"
    max_items: int | None = None


@dataclass(slots=True)
class DatasetItem:
    source_name: str
    path: Path
    label: str
    kind: str
    group_key: str
    relative_key: str


@dataclass(slots=True)
class PreparationStats:
    collected_files: int = 0
    written_images: int = 0
    skipped_files: int = 0
    skipped_reasons: dict[str, int] = field(default_factory=dict)

    def add_skip(self, reason: str) -> None:
        self.skipped_files += 1
        self.skipped_reasons[reason] = self.skipped_reasons.get(reason, 0) + 1


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "Prepare a deepfake dataset for TruthGuard CNN training. "
            "The script reads a JSON manifest of image/video sources and writes train/val/test/real/fake folders."
        )
    )
    parser.add_argument("--manifest", required=True, help="Path to a JSON manifest describing source folders.")
    parser.add_argument("--output-dir", required=True, help="Output root to create train/val/test/real/fake folders in.")
    parser.add_argument("--image-size", type=int, default=224, help="Output image size for copied images and extracted frames.")
    parser.add_argument("--train-ratio", type=float, default=0.7, help="Train split ratio.")
    parser.add_argument("--val-ratio", type=float, default=0.15, help="Validation split ratio.")
    parser.add_argument("--test-ratio", type=float, default=0.15, help="Test split ratio.")
    parser.add_argument("--seed", type=int, default=42, help="Shuffle seed used for group-level splitting.")
    parser.add_argument("--ffmpeg-binary", default="ffmpeg", help="FFmpeg binary used for video frame extraction.")
    parser.add_argument("--video-frame-limit", type=int, default=8, help="Maximum frames to extract per video.")
    parser.add_argument("--video-frame-stride", type=int, default=12, help="Sample every Nth frame from each video.")
    parser.add_argument("--summary-file", default="dataset_summary.json", help="Summary JSON file name written under the output dir.")
    return parser


def parser_error(parser: argparse.ArgumentParser, message: str) -> int:
    parser.error(message)
    return 2


def load_manifest(manifest_path: Path) -> list[SourceSpec]:
    raw_payload = json.loads(manifest_path.read_text(encoding="utf-8"))

    if isinstance(raw_payload, dict):
        raw_sources = raw_payload.get("sources")
    else:
        raw_sources = raw_payload

    if not isinstance(raw_sources, list):
        raise ValueError("Manifest must be a JSON list or an object with a 'sources' array.")

    sources: list[SourceSpec] = []

    for index, raw_source in enumerate(raw_sources, start=1):
        if not isinstance(raw_source, dict):
            raise ValueError(f"Manifest source #{index} must be an object.")

        name = normalize_slug(str(raw_source.get("name") or f"source_{index}"))
        label = str(raw_source.get("label") or "").strip().lower()
        kind = str(raw_source.get("kind") or "").strip().lower()
        path_value = raw_source.get("path")
        pattern = str(raw_source.get("pattern") or "").strip()
        group_mode = str(raw_source.get("group_mode") or "auto").strip().lower()
        max_items_value = raw_source.get("max_items")

        if label not in {"real", "fake"}:
            raise ValueError(f"Manifest source '{name}' must use label 'real' or 'fake'.")

        if kind not in {"image", "video"}:
            raise ValueError(f"Manifest source '{name}' must use kind 'image' or 'video'.")

        if not isinstance(path_value, str) or not path_value.strip():
            raise ValueError(f"Manifest source '{name}' must define a non-empty path.")

        if group_mode not in {"auto", "file", "parent"}:
            raise ValueError(f"Manifest source '{name}' uses unsupported group_mode '{group_mode}'.")

        path = Path(path_value).expanduser().resolve()

        if not pattern:
            pattern = "**/*" if kind == "image" else "**/*"

        max_items = None

        if max_items_value is not None:
            max_items = max(1, int(max_items_value))

        sources.append(
            SourceSpec(
                name=name,
                path=path,
                label=label,
                kind=kind,
                pattern=pattern,
                group_mode=group_mode,
                max_items=max_items,
            )
        )

    return sources


def normalize_slug(value: str) -> str:
    normalized = re.sub(r"[^a-zA-Z0-9._-]+", "-", value.strip())
    normalized = normalized.strip("-_.")
    return normalized or "item"


def ensure_supported_suffix(path: Path, kind: str) -> bool:
    suffix = path.suffix.lower()

    if kind == "image":
        return suffix in IMAGE_EXTENSIONS

    return suffix in VIDEO_EXTENSIONS


def derive_group_key(source: SourceSpec, file_path: Path) -> tuple[str, str]:
    relative = file_path.relative_to(source.path)
    relative_without_suffix = relative.with_suffix("").as_posix().lower()
    stem = file_path.stem.lower()

    if source.kind == "video":
        group_key = relative_without_suffix
    elif source.group_mode == "file":
        group_key = relative_without_suffix
    elif source.group_mode == "parent":
        parent_key = relative.parent.as_posix().lower().strip("./")
        group_key = parent_key or stem
    else:
        normalized_stem = re.sub(r"([_-](frame|img|image))[_-]?\d+$", "", stem)
        normalized_stem = normalized_stem.strip("-_.") or stem
        parent_key = relative.parent.as_posix().lower().strip("./")
        group_key = "/".join(part for part in [parent_key, normalized_stem] if part)

    return f"{source.name}:{group_key}", relative_without_suffix


def collect_source_items(source: SourceSpec, rng: random.Random, stats: PreparationStats) -> list[DatasetItem]:
    if not source.path.is_dir():
        raise ValueError(f"Source directory does not exist: {source.path}")

    candidate_paths = sorted(path for path in source.path.glob(source.pattern) if path.is_file())
    filtered_paths = [path for path in candidate_paths if ensure_supported_suffix(path, source.kind)]

    if source.max_items is not None and len(filtered_paths) > source.max_items:
        filtered_paths = rng.sample(filtered_paths, source.max_items)
        filtered_paths.sort()

    items: list[DatasetItem] = []

    for file_path in filtered_paths:
        group_key, relative_key = derive_group_key(source, file_path)
        items.append(
            DatasetItem(
                source_name=source.name,
                path=file_path,
                label=source.label,
                kind=source.kind,
                group_key=group_key,
                relative_key=relative_key,
            )
        )

    stats.collected_files += len(items)
    return items


def allocate_split_counts(total_groups: int, ratios: dict[str, float]) -> dict[str, int]:
    raw_counts = {split: total_groups * ratio for split, ratio in ratios.items()}
    counts = {split: int(value) for split, value in raw_counts.items()}
    remainder = total_groups - sum(counts.values())
    positive_splits = [split for split, ratio in ratios.items() if ratio > 0]

    if total_groups >= len(positive_splits):
        for split in positive_splits:
            if counts[split] == 0:
                counts[split] = 1
                remainder -= 1

        while remainder < 0:
            donor = max(
                (split for split in positive_splits if counts[split] > 1),
                key=lambda split: (counts[split], ratios[split]),
                default=None,
            )

            if donor is None:
                break

            counts[donor] -= 1
            remainder += 1

    ordered_splits = sorted(
        raw_counts.keys(),
        key=lambda split: (raw_counts[split] - counts[split], ratios[split]),
        reverse=True,
    )

    for split in ordered_splits:
        if remainder <= 0:
            break

        counts[split] += 1
        remainder -= 1

    return counts


def split_items_by_group(items: list[DatasetItem], ratios: dict[str, float], rng: random.Random) -> dict[str, list[DatasetItem]]:
    grouped_by_label: dict[str, dict[str, list[DatasetItem]]] = {"real": {}, "fake": {}}

    for item in items:
        grouped_by_label[item.label].setdefault(item.group_key, []).append(item)

    split_items = {"train": [], "val": [], "test": []}

    for label, groups in grouped_by_label.items():
        group_keys = list(groups.keys())
        rng.shuffle(group_keys)
        counts = allocate_split_counts(len(group_keys), ratios)
        cursor = 0

        for split_name in ["train", "val", "test"]:
            split_group_keys = group_keys[cursor:cursor + counts[split_name]]
            cursor += counts[split_name]

            for group_key in split_group_keys:
                split_items[split_name].extend(groups[group_key])

    return split_items


def make_output_name(item: DatasetItem, index: int) -> str:
    relative_slug = normalize_slug(item.relative_key.replace("/", "__"))
    return f"{item.source_name}__{relative_slug}__{index:05d}.jpg"


def write_image_item(item: DatasetItem, destination: Path, image_size: int, stats: PreparationStats) -> None:
    try:
        from PIL import Image
    except Exception as exc:  # pragma: no cover - import guard
        raise RuntimeError(f"Pillow is required for image preparation: {exc}") from exc

    destination.parent.mkdir(parents=True, exist_ok=True)

    try:
        with Image.open(item.path) as image:
            image = image.convert("RGB")
            image = image.resize((image_size, image_size))
            image.save(destination, format="JPEG", quality=92)
    except Exception:
        stats.add_skip("invalid-image")
        return

    stats.written_images += 1


def write_video_item(
    item: DatasetItem,
    destination_dir: Path,
    output_prefix: str,
    image_size: int,
    frame_limit: int,
    frame_stride: int,
    ffmpeg_binary: str,
    stats: PreparationStats,
) -> None:
    destination_dir.mkdir(parents=True, exist_ok=True)
    output_pattern = str(destination_dir / f"{output_prefix}__%03d.jpg")
    filter_chain = f"select=not(mod(n\\,{frame_stride})),scale={image_size}:{image_size}"
    command = [
        ffmpeg_binary,
        "-hide_banner",
        "-loglevel",
        "error",
        "-y",
        "-i",
        str(item.path),
        "-vf",
        filter_chain,
        "-vsync",
        "vfr",
        "-frames:v",
        str(frame_limit),
        output_pattern,
    ]

    try:
        completed = subprocess.run(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
    except FileNotFoundError:
        stats.add_skip("ffmpeg-missing")
        return
    except OSError:
        stats.add_skip("ffmpeg-error")
        return

    if completed.returncode != 0:
        stats.add_skip("video-extraction-failed")
        return

    written_frames = len(list(destination_dir.glob(f"{output_prefix}__*.jpg")))

    if written_frames == 0:
        stats.add_skip("video-no-frames")
        return

    stats.written_images += written_frames


def write_split_items(
    split_name: str,
    items: list[DatasetItem],
    output_dir: Path,
    image_size: int,
    frame_limit: int,
    frame_stride: int,
    ffmpeg_binary: str,
    stats: PreparationStats,
) -> dict[str, int]:
    per_label_counts = {"real": 0, "fake": 0}

    for index, item in enumerate(items, start=1):
        label_dir = output_dir / split_name / item.label
        output_name = make_output_name(item, index)
        before_count = stats.written_images

        if item.kind == "image":
            write_image_item(item, label_dir / output_name, image_size, stats)
            per_label_counts[item.label] += max(0, stats.written_images - before_count)
            continue

        write_video_item(
            item,
            label_dir,
            output_name.removesuffix(".jpg"),
            image_size,
            frame_limit,
            frame_stride,
            ffmpeg_binary,
            stats,
        )
        per_label_counts[item.label] += max(0, stats.written_images - before_count)

    return per_label_counts


def summarize_sources(items: list[DatasetItem]) -> dict[str, dict[str, int]]:
    summary: dict[str, dict[str, int]] = {}

    for item in items:
        source_summary = summary.setdefault(item.source_name, {"real": 0, "fake": 0})
        source_summary[item.label] += 1

    return summary


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    ratios = {
        "train": args.train_ratio,
        "val": args.val_ratio,
        "test": args.test_ratio,
    }

    if any(ratio < 0 for ratio in ratios.values()):
        return parser_error(parser, "Split ratios must be zero or greater.")

    ratio_sum = sum(ratios.values())

    if abs(ratio_sum - 1.0) > 0.001:
        return parser_error(parser, "Train/val/test ratios must add up to 1.0.")

    if args.image_size < 32:
        return parser_error(parser, "image-size must be at least 32.")

    manifest_path = Path(args.manifest).expanduser().resolve()
    output_dir = Path(args.output_dir).expanduser().resolve()

    if not manifest_path.is_file():
        return parser_error(parser, f"Manifest file does not exist: {manifest_path}")

    try:
        sources = load_manifest(manifest_path)
    except Exception as exc:
        return parser_error(parser, f"Could not load manifest: {exc}")

    rng = random.Random(args.seed)
    stats = PreparationStats()
    items: list[DatasetItem] = []

    try:
        for source in sources:
            items.extend(collect_source_items(source, rng, stats))
    except Exception as exc:
        return parser_error(parser, str(exc))

    if not items:
        return parser_error(parser, "No supported files were found in the provided sources.")

    split_items = split_items_by_group(items, ratios, rng)

    for split_name in ["train", "val", "test"]:
        for label in ["real", "fake"]:
            (output_dir / split_name / label).mkdir(parents=True, exist_ok=True)

    split_summary: dict[str, dict[str, int]] = {}

    for split_name in ["train", "val", "test"]:
        split_summary[split_name] = write_split_items(
            split_name,
            split_items[split_name],
            output_dir,
            args.image_size,
            args.video_frame_limit,
            args.video_frame_stride,
            args.ffmpeg_binary,
            stats,
        )

    summary_payload = {
        "status": "completed",
        "manifest": str(manifest_path),
        "output_dir": str(output_dir),
        "image_size": args.image_size,
        "ratios": ratios,
        "sources": summarize_sources(items),
        "splits": split_summary,
        "stats": {
            "collected_files": stats.collected_files,
            "written_images": stats.written_images,
            "skipped_files": stats.skipped_files,
            "skipped_reasons": stats.skipped_reasons,
        },
    }

    summary_path = output_dir / args.summary_file
    summary_path.write_text(json.dumps(summary_payload, indent=2), encoding="utf-8")
    print(json.dumps(summary_payload))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
