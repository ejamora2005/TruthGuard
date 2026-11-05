"""Create controlled social-media variants without changing origin labels."""
import argparse
import hashlib
import json
from pathlib import Path

from PIL import Image, ImageDraw, ImageEnhance, ImageOps

from prepare_public_image_benchmark import IMAGE_SUFFIXES, source_for

VARIANTS = ("jpeg55", "resized", "screenshot", "text_overlay", "filtered")


def selected(paths, limit):
    return sorted(paths, key=lambda path: hashlib.sha256(path.as_posix().encode()).hexdigest())[:limit]


def normalized(image):
    image = ImageOps.exif_transpose(image).convert("RGB")
    image.thumbnail((1600, 1600), Image.Resampling.LANCZOS)
    return image


def build(dataset_dir, per_source=100):
    root = Path(dataset_dir).resolve()
    originals = [path for path in root.rglob("*") if path.is_file()
                 and path.suffix.lower() in IMAGE_SUFFIXES and "truthguard-derived" not in path.parts]
    by_source = {name: [] for name in ("raise_camera", "flux", "sd3_large")}
    for path in originals:
        by_source[source_for(path, root)].append(path)
    output_root = root / "truthguard-derived"
    created = 0
    for source, paths in by_source.items():
        if not paths:
            raise ValueError("No originals found for " + source)
        destination = output_root / source
        destination.mkdir(parents=True, exist_ok=True)
        for path in selected(paths, per_source):
            with Image.open(path) as opened:
                image = normalized(opened)
            base = path.stem
            targets = {kind: destination / f"{base}--tg-{kind}.jpg" for kind in VARIANTS}
            if not targets["jpeg55"].exists():
                image.save(targets["jpeg55"], "JPEG", quality=55, optimize=True)
                created += 1
            if not targets["resized"].exists():
                small = image.resize((max(32, image.width // 2), max(32, image.height // 2)), Image.Resampling.BILINEAR)
                small.resize(image.size, Image.Resampling.BILINEAR).save(targets["resized"], "JPEG", quality=82)
                created += 1
            if not targets["screenshot"].exists():
                canvas = Image.new("RGB", (image.width + 48, image.height + 112), "#f1f5f9")
                draw = ImageDraw.Draw(canvas)
                draw.rectangle((0, 0, canvas.width, 64), fill="#1e293b")
                draw.ellipse((18, 19, 42, 43), fill="#3b82f6")
                draw.text((54, 24), "Public post", fill="white")
                canvas.paste(image, (24, 80))
                canvas.save(targets["screenshot"], "JPEG", quality=80)
                created += 1
            if not targets["text_overlay"].exists():
                overlay = image.copy()
                draw = ImageDraw.Draw(overlay)
                height = max(42, overlay.height // 7)
                draw.rectangle((0, overlay.height - height, overlay.width, overlay.height), fill="#101010")
                draw.text((16, overlay.height - height + 12), "SAMPLE SOCIAL MEDIA CAPTION", fill="#ffffff")
                overlay.save(targets["text_overlay"], "JPEG", quality=84)
                created += 1
            if not targets["filtered"].exists():
                filtered = ImageEnhance.Contrast(ImageEnhance.Color(image).enhance(1.25)).enhance(1.15)
                filtered.save(targets["filtered"], "JPEG", quality=86)
                created += 1
    return {"created": created, "variants_per_original": len(VARIANTS),
            "originals_selected_per_source": per_source, "output": str(output_root)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset-dir", required=True, type=Path)
    parser.add_argument("--per-source", type=int, default=100)
    args = parser.parse_args()
    if not 1 <= args.per_source <= 500:
        parser.error("--per-source must be between 1 and 500")
    print(json.dumps(build(args.dataset_dir, args.per_source), indent=2))


if __name__ == "__main__":
    raise SystemExit(main())
