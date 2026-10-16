"""Portable setup helpers for the GPU calibration notebook; no application secrets."""
import hashlib
import json
from pathlib import Path, PurePosixPath
import urllib.request
import zipfile

ARCHIVES = {
    "real_RAISE_1k.zip": "a6aad7728226218f22a28b9c9aacaa2c",
    "sd3_flux.zip": "5a255c18fa99eb3115c7ed39d2840796",
}
BASE = "https://www.grip.unina.it/download/prog/B-Free/extended_synthbuster/"


def digest(path, algorithm="sha256"):
    value = hashlib.new(algorithm)
    with Path(path).open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            value.update(block)
    return value.hexdigest()


def safe_extract(archive, destination):
    destination = Path(destination).resolve()
    with zipfile.ZipFile(archive) as stream:
        for entry in stream.infolist():
            name = PurePosixPath(entry.filename)
            if name.is_absolute() or ".." in name.parts or "\\" in entry.filename or ":" in entry.filename:
                raise ValueError("Unsafe archive path")
            target = (destination / entry.filename).resolve()
            if not target.is_relative_to(destination):
                raise ValueError("Archive member escapes destination")
            if entry.external_attr >> 16 & 0o170000 == 0o120000:
                raise ValueError("Archive symlinks are not permitted")
        stream.extractall(destination)


def download_datasets(root):
    root = Path(root)
    cache, images = root / "archives", root / "datasets"
    cache.mkdir(parents=True, exist_ok=True)
    images.mkdir(parents=True, exist_ok=True)
    for name, expected in ARCHIVES.items():
        target = cache / name
        if not target.exists() or digest(target, "md5") != expected:
            temporary = target.with_suffix(".zip.part")
            print("Downloading", name, flush=True)
            count, last = 0, 0
            with urllib.request.urlopen(BASE + name, timeout=120) as response, temporary.open("wb") as stream:
                while block := response.read(4 * 1024 * 1024):
                    stream.write(block)
                    count += len(block)
                    if count - last >= 256 * 1024 * 1024:
                        print(f"  {count / 1024**3:.2f} GiB downloaded", flush=True)
                        last = count
            if digest(temporary, "md5") != expected:
                raise ValueError("Official archive checksum mismatch: " + name)
            temporary.replace(target)
        print("Checksum verified; extracting", name, flush=True)
        safe_extract(target, images)


def verify_bundle(root):
    root = Path(root)
    manifest = json.loads((root / "bundle-manifest.json").read_text())
    for name, expected in manifest["files"].items():
        path = (root / name).resolve()
        if not path.is_relative_to(root.resolve()) or digest(path) != expected:
            raise ValueError("Bundle file changed: " + name)
    return manifest
