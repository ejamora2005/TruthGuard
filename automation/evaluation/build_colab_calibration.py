"""Build a small portable notebook bundle; exclude weights, images and credentials."""
import argparse
import hashlib
import json
from pathlib import Path, PureWindowsPath
import textwrap
import zipfile

from benchmark_image_calibration import validate_candidates

ROOT = Path(__file__).resolve().parents[2]
FILES = [
    "automation/evaluation/benchmark_image_calibration.py",
    "automation/evaluation/cuda_benchmark_detector.py",
    "automation/evaluation/colab_calibration_support.py",
    "automation/evaluation/calibrate_image_scores.py",
    "automation/evaluation/evaluate_assessments.py",
    "automation/deepfake-models/broad_ai_origin.py",
] + ["automation/models/ai-origin-broad/" + name for name in
     ("config.json", "preprocessor_config.json", "siglip_config.json", "source.json", "README.md")]


def portable_annotations(rows):
    validate_candidates(rows)
    folders = {"raise_camera": "real_RAISE_1k", "flux": "flux", "sd3_large": "sd3_large"}
    result = []
    for row in rows:
        row = dict(row)
        source = row.get("candidate_source")
        if source not in folders:
            raise ValueError("Unknown dataset source")
        row["media_path"] = "../datasets/" + folders[source] + "/" + PureWindowsPath(row["media_path"]).name
        result.append(row)
    validate_candidates(result)
    return result


def notebook(bundle_sha):
    cells = []

    def markdown(value):
        cells.append({"cell_type": "markdown", "metadata": {}, "source": textwrap.dedent(value).strip() + "\n"})

    def code(value):
        cells.append({"cell_type": "code", "metadata": {}, "source": textwrap.dedent(value).strip() + "\n",
                      "execution_count": None, "outputs": []})

    markdown("""
    # TruthGuard: GPU calibration experiment

    1. Select **Runtime → Change runtime type → GPU** (T4 is suitable if offered).
    2. Run these cells in order. Upload only **TruthGuard-calibration-bundle.zip** when asked.
    3. Authorize your own Google Drive to preserve scores across runtime interruptions.
    4. The original dataset archives (~4.7 GB) and pinned model (~2.1 GB) download directly
       into this temporary Colab runtime. No need to upload your image folders or `.env`.
    5. Keep the notebook running. Read its measured ETA rather than assuming a completion time.
       If interrupted, reconnect with a GPU and rerun the cells. Compatible saved captures resume.
    6. Download the completed result ZIP in the last cell.

    No OpenAI key, API calls, tokens, retraining, or application deployment are involved.
    The 2,994 unique originals keep the prepared scene groups and calibration/test split.
    Calibration is fitted on calibration labels only; test metrics are evaluated once.
    Training exclusion and independent review remain unresolved, so the report stays experimental.
    GPU FP32 scores are captured separately from the laptop's CPU BF16 scores. The GPU
    artifact cannot automatically calibrate the local CPU runtime.
    """)
    code("""
    %pip install -q transformers==4.57.6 timm==1.0.30 peft==0.17.1 accelerate==1.15.0 numpy==2.2.6 Pillow==12.3.0 huggingface-hub==0.36.2
    """)
    code("""
    import os, sys, json, hashlib, shutil, zipfile, subprocess
    from pathlib import Path
    os.environ.update(USE_TF="0", USE_FLAX="0", HF_HUB_DISABLE_TELEMETRY="1")
    import torch
    if not torch.cuda.is_available():
        raise RuntimeError("Choose Runtime > Change runtime type > GPU, then reconnect.")
    if tuple(int(x) for x in torch.__version__.split("+")[0].split(".")[:2]) < (2, 6):
        raise RuntimeError("This notebook requires PyTorch 2.6 or newer with CUDA.")
    print("GPU:", torch.cuda.get_device_name(0))
    print("PyTorch:", torch.__version__)
    print("Free runtime disk (GiB):", round(shutil.disk_usage('/content').free / 1024**3, 1))
    ROOT = Path('/content/TruthGuardCalibration')
    ROOT.mkdir(exist_ok=True)
    """)
    code(f"""
    from google.colab import files
    EXPECTED_BUNDLE_SHA256 = {bundle_sha!r}
    print("Choose TruthGuard-calibration-bundle.zip (the small prepared ZIP).")
    uploaded = files.upload()
    if len(uploaded) != 1:
        raise ValueError("Upload exactly the one prepared bundle ZIP.")
    bundle = next(iter(uploaded.values()))
    if hashlib.sha256(bundle).hexdigest() != EXPECTED_BUNDLE_SHA256:
        raise ValueError("This notebook and bundle do not match. Use the matching pair.")
    import io
    with zipfile.ZipFile(io.BytesIO(bundle)) as archive:
        for entry in archive.infolist():
            target = (ROOT / entry.filename).resolve()
            if not target.is_relative_to(ROOT) or '\\\\' in entry.filename or ':' in entry.filename:
                raise ValueError("Unsafe bundle path.")
        archive.extractall(ROOT)
    sys.path.insert(0, str(ROOT / 'automation/evaluation'))
    from colab_calibration_support import verify_bundle, digest, download_datasets
    metadata = verify_bundle(ROOT)
    print("Bundle verified. Unique images:", metadata['cases'])
    """)
    code("""
    # Save only small progress/scores/results to Drive, not thousands of images.
    from google.colab import drive
    drive.mount('/content/drive')
    from cuda_benchmark_detector import execution_profile
    profile = execution_profile()
    run_identity = {'profile': profile, 'bundle': EXPECTED_BUNDLE_SHA256}
    profile_id = hashlib.sha256(json.dumps(run_identity, sort_keys=True).encode()).hexdigest()[:16]
    OUTPUT = Path('/content/drive/MyDrive/TruthGuard-calibration') / ('gpu-' + profile_id)
    OUTPUT.mkdir(parents=True, exist_ok=True)
    print("Saved checkpoints:", OUTPUT)
    print("CPU results will not be mixed into this GPU experiment.")
    """)
    code("""
    # Direct public downloads; original archive checksums are verified before extraction.
    download_datasets(ROOT)
    from huggingface_hub import hf_hub_download
    model_dir = ROOT / 'automation/models/ai-origin-broad'
    source = json.loads((model_dir / 'source.json').read_text())
    weights = model_dir / 'pytorch_model.pt'
    if not weights.exists() or digest(weights) != source['sha256']:
        cached = hf_hub_download(repo_id=source['repository'], revision=source['revision'],
                                 filename='pytorch_model.pt', token=False, cache_dir='/content/hf-cache')
        if digest(cached) != source['sha256']:
            raise ValueError('Pinned model checksum mismatch.')
        shutil.copyfile(cached, weights)
    print('Model and dataset ready. No OpenAI credentials were used.')
    """)
    code("""
    report = OUTPUT / 'benchmark-calibration.json'
    if report.exists():
        print('This experiment is already complete. Continue to the results cell.')
    else:
        command = [sys.executable, '-u', str(ROOT / 'automation/evaluation/benchmark_image_calibration.py'),
                   '--annotations', str(ROOT / 'calibration/annotations.jsonl'),
                   '--output-dir', str(OUTPUT), '--threads', '2', '--device', 'cuda']
        if (OUTPUT / 'run-plan.json').exists():
            command.append('--resume')
        with (OUTPUT / 'colab-run.log').open('a', encoding='utf-8') as log:
            process = subprocess.Popen(command, cwd=ROOT, stdout=subprocess.PIPE,
                                       stderr=subprocess.STDOUT, text=True, bufsize=1)
            for line in process.stdout:
                print(line, end='', flush=True)
                log.write(line)
                log.flush()
            result_code = process.wait()
        if result_code:
            raise RuntimeError('Run stopped. Read the last error above; saved scores remain in Drive.')
    """)
    code("""
    result = json.loads((OUTPUT / 'benchmark-calibration.json').read_text())
    metrics = result['benchmark_validation']
    print(json.dumps({'status': result['status'], 'temperature': result['temperature'],
                      'before': {k:v for k,v in metrics['before'].items() if k != 'reliability_bins'},
                      'after': {k:v for k,v in metrics['after'].items() if k != 'reliability_bins'},
                      'held_out_improvement': metrics['held_out_improvement'],
                      'deployment_eligible': result['deployment_eligible']}, indent=2))
    result_zip = Path('/content/TruthGuard-GPU-calibration-results.zip')
    with zipfile.ZipFile(result_zip, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name in ('benchmark-calibration.json', 'scores.jsonl', 'run-plan.json', 'progress.json', 'colab-run.log'):
            if (OUTPUT / name).exists():
                archive.write(OUTPUT / name, name)
        archive.write(ROOT / 'calibration/annotations.jsonl', 'annotations.jsonl')
    files.download(str(result_zip))
    print('Keep this ZIP for review. Do not enable a GPU artifact on the CPU detector.')
    """)
    for index, cell in enumerate(cells):
        cell["id"] = "truthguard-cell-" + str(index)
    return {"nbformat": 4, "nbformat_minor": 5, "cells": cells,
            "metadata": {"accelerator": "GPU", "colab": {"name": "TruthGuard-GPU-calibration.ipynb"},
                         "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"}}}


def build(annotations, output):
    rows = portable_annotations([json.loads(line) for line in Path(annotations).read_text(encoding="utf-8").splitlines() if line.strip()])
    contents = {name: (ROOT / name).read_bytes() for name in FILES}
    contents['calibration/annotations.jsonl'] = ''.join(json.dumps(row) + '\n' for row in rows).encode()
    contents['bundle-manifest.json'] = json.dumps({'cases': len(rows), 'files': {
        name: hashlib.sha256(value).hexdigest() for name, value in contents.items()}}, indent=2).encode()
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    bundle = output / 'TruthGuard-calibration-bundle.zip'
    with zipfile.ZipFile(bundle, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, value in contents.items():
            archive.writestr(name, value)
    sha = hashlib.sha256(bundle.read_bytes()).hexdigest()
    path = output / 'TruthGuard-GPU-calibration.ipynb'
    path.write_text(json.dumps(notebook(sha), indent=2), encoding='utf-8')
    print(json.dumps({'notebook': str(path), 'bundle': str(bundle), 'bundle_bytes': bundle.stat().st_size, 'cases': len(rows)}))
    return path, bundle


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--annotations', type=Path, required=True)
    parser.add_argument('--output-dir', type=Path, required=True)
    args = parser.parse_args()
    build(args.annotations, args.output_dir)
