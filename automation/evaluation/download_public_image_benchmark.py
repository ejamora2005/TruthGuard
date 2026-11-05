"""Download and verify the public B-Free/Synthbuster benchmark archives."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import urllib.error

from colab_calibration_support import ARCHIVES, BASE, digest, download_datasets
from prepare_public_image_benchmark import prepare
from create_robustness_variants import build as build_variants


def download_with_system_tls(output_dir):
    """Use the operating-system trust store without disabling TLS checks."""
    curl = shutil.which("curl.exe") or shutil.which("curl")
    if not curl:
        raise RuntimeError("Python rejected the server certificate and system curl is unavailable")
    cache = Path(output_dir) / "archives"
    cache.mkdir(parents=True, exist_ok=True)
    for name, expected in ARCHIVES.items():
        target = cache / name
        if target.exists() and digest(target, "md5") == expected:
            continue
        temporary = target.with_suffix(".zip.part")
        print("Downloading with operating-system TLS:", name, flush=True)
        result = subprocess.run([curl, "--fail", "--location", "--continue-at", "-", "--proto", "=https", "--tlsv1.2",
                                 "--silent", "--show-error", "--output", str(temporary), BASE + name])
        if result.returncode:
            raise RuntimeError(f"Secure system download failed for {name}")
        if digest(temporary, "md5") != expected:
            raise ValueError("Official archive checksum mismatch: " + name)
        temporary.replace(target)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", required=True, type=Path)
    args = parser.parse_args()
    try:
        download_datasets(args.output_dir)
    except urllib.error.URLError as error:
        if "CERTIFICATE_VERIFY_FAILED" not in str(error):
            raise
        download_with_system_tls(args.output_dir)
        # Re-enter the standard path: it re-verifies every checksum and performs
        # the existing traversal-safe extraction.
        download_datasets(args.output_dir)
    annotations = args.output_dir.resolve() / "annotations.jsonl"
    manifest = {"annotations": str(annotations), "status": "already_prepared"}
    if not annotations.exists():
        variants = build_variants(args.output_dir.resolve() / "datasets")
        manifest = prepare(args.output_dir.resolve() / "datasets", annotations)
        manifest["robustness_variants"] = variants
    print(json.dumps({"status": "downloaded_verified_extracted_and_annotated",
                      "dataset_dir": str(args.output_dir.resolve() / "datasets"),
                      "manifest": manifest, "live_settings_changed": False}))


if __name__ == "__main__":
    raise SystemExit(main())
