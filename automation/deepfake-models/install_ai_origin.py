"""Download the pinned optional AI-origin weights; never execute remote source.

Setup (PowerShell, from the repository root):
  python -m venv .venv-ai-origin
  .venv-ai-origin/Scripts/python.exe -m pip install --extra-index-url https://download.pytorch.org/whl/cpu -r automation/deepfake-models/requirements-ai-origin.txt
  .venv-ai-origin/Scripts/python.exe automation/deepfake-models/install_ai_origin.py

Then enable AI_ORIGIN_ENABLED=true in .env and clear Laravel config/restart its
queue workers. The original TensorFlow environment and weights are independent.
The separate checkpoint needs about 2.1 GB disk space, plus the Python runtime.
"""
import hashlib
import json
from pathlib import Path
from urllib.request import Request, urlopen

DIRECTORY = Path(__file__).resolve().parents[1] / 'models' / 'ai-origin-broad'
REVISION = '6455cf791436ee914c9556ab71578cce9761fef7'
SHA256 = 'caae0c005d8e37e7aa086aa241d1c9445d296ef77649004655c14f5c81130d4b'
SIZE = 2105483083


def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        while chunk := stream.read(1024 * 1024):
            result.update(chunk)
    return result.hexdigest()


def main():
    manifest = json.loads((DIRECTORY / 'source.json').read_text(encoding='utf-8'))
    if manifest['revision'] != REVISION or manifest['sha256'] != SHA256:
        raise ValueError('The bundled manifest does not match the pinned release')
    for name, expected in manifest['configuration_sha256'].items():
        if digest(DIRECTORY / name) != expected:
            raise ValueError('Bundled configuration changed: ' + name)
    target = DIRECTORY / 'pytorch_model.pt'
    if target.exists():
        if target.stat().st_size != SIZE or digest(target) != SHA256:
            raise ValueError('Existing checkpoint failed verification; investigate before replacing it')
        print('Pinned checkpoint and bundled configurations verified. No download needed.')
        return
    partial = target.with_suffix('.pt.tmp')
    offset = partial.stat().st_size if partial.exists() else 0
    if offset > SIZE:
        raise ValueError('Partial download exceeds expected size')
    if offset < SIZE:
        url = f'https://huggingface.co/Bombek1/ai-image-detector-siglip-dinov2/resolve/{REVISION}/pytorch_model.pt'
        headers = {'Range': f'bytes={offset}-'} if offset else {}
        with urlopen(Request(url, headers=headers), timeout=120) as response:
            if offset and response.status == 206:
                if not response.headers.get('Content-Range', '').startswith(f'bytes {offset}-'):
                    raise ValueError('Server returned an unexpected download range')
                mode = 'ab'
            else:
                mode, offset = 'wb', 0
            announced = offset // (64 * 1024 * 1024)
            with partial.open(mode) as stream:
                while chunk := response.read(1024 * 1024):
                    stream.write(chunk)
                    offset += len(chunk)
                    if offset > SIZE:
                        raise ValueError('Download exceeds expected size')
                    if offset // (64 * 1024 * 1024) != announced:
                        announced = offset // (64 * 1024 * 1024)
                        print(f'Downloaded {offset:,} / {SIZE:,} bytes', flush=True)
    if partial.stat().st_size != SIZE or digest(partial) != SHA256:
        raise ValueError('Downloaded checkpoint failed size or SHA-256 verification')
    partial.replace(target)
    print('Pinned checkpoint downloaded and verified. Weights unchanged.')


if __name__ == '__main__':
    main()
