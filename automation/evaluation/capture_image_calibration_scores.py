"""Blind, offline inference on reviewed original images; no labels enter the detector.

Run using .venv-ai-origin/Scripts/python.exe. Each JSONL annotation needs media_path
(absolute or relative to its manifest) and the exact original input_sha256. This
command never changes media files or model weights and refuses output overwrite.
"""
import argparse
import hashlib
import json
from pathlib import Path
import sys

from evaluate_assessments import read_jsonl, validate_annotations

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'automation' / 'deepfake-models'))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--annotations', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    if args.output.exists():
        parser.error('Use a new prediction filename; existing captures are immutable')
    rows = validate_annotations(read_jsonl(args.annotations))
    cases = [r for r in rows if r['split'] in {'calibration', 'test'}]
    if not cases or any(r['task'] != 'ai_generation' or r.get('independent_of_training') is not True for r in cases):
        parser.error('Reviewed, training-independent AI-origin calibration/test cases required')
    resolved = []
    for row in cases:
        path = Path(row['media_path'])
        if not path.is_absolute():
            path = args.annotations.resolve().parent / path
        if hashlib.sha256(path.read_bytes()).hexdigest() != row.get('input_sha256'):
            raise ValueError('Original image hash mismatch: '+row['case_id'])
        resolved.append((row, path))
    from broad_ai_origin import BroadDetector
    directory = ROOT / 'automation' / 'models' / 'ai-origin-broad'
    detector = BroadDetector(directory)
    identity = {'model_version': 'sha256:'+detector.weight_hash,
                'config_sha256': hashlib.sha256((directory/'config.json').read_bytes()).hexdigest(),
                'processor_sha256': hashlib.sha256((directory/'preprocessor_config.json').read_bytes()).hexdigest(),
                'adapter_version': 'broad-ai-origin-v1'}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    # Exclusive creation preserves evidence. Interrupted output cannot pass the
    # fitter's exact-case coverage check; resume into a new capture, not a merge.
    with args.output.open('x', encoding='utf-8') as stream:
        for index, (row, path) in enumerate(resolved, 1):
            p = detector.predict(path)
            if hashlib.sha256(path.read_bytes()).hexdigest() != row['input_sha256']:
                raise ValueError('Original image changed during inference')
            result = {'case_id': row['case_id'], 'task': 'ai_generation',
                      'evaluation_cutoff': row['evaluation_cutoff'], 'input_sha256': row['input_sha256'],
                      'verdict': 'AI_GENERATED' if p > .5 else 'NOT_AI_GENERATED',
                      'score_type': 'normalized_model_output', 'model_identity': identity,
                      'probabilities': {'AI_GENERATED': p, 'NOT_AI_GENERATED': 1-p}}
            stream.write(json.dumps(result, allow_nan=False)+'\n')
            stream.flush()
            print(f'Captured {index}/{len(resolved)}', flush=True)


if __name__ == '__main__':
    main()
