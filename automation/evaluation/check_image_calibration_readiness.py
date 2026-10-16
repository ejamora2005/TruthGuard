"""Read-only preflight for image calibration; never changes labels or live settings.

Checks all prerequisites together, before model loading or another inference run.
Recorded review declarations are checked, not manufactured or independently proved.
Exit 0 means ready to run the existing fitter, NOT validated deployment calibration.
"""
import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re

from benchmark_image_calibration import validate_candidates, validate_capture
from calibrate_image_scores import MINIMUM_PER_CLASS, prepare
from evaluate_assessments import read_jsonl

ROOT = Path(__file__).resolve().parents[2]


def digest(path):
    sha = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            sha.update(chunk)
    return sha.hexdigest()


def inspect(annotations_path, predictions_path, population_path, model_directory, adapter):
    checks, blockers = {}, []

    def check(name, passed, detail):
        checks[name] = {'passed': bool(passed), 'detail': detail}
        if not passed:
            blockers.append(name)

    annotations_path = Path(annotations_path).resolve()
    rows, scores = read_jsonl(annotations_path), read_jsonl(predictions_path)
    population = json.loads(Path(population_path).read_text(encoding='utf-8-sig'))
    try:
        validate_candidates(rows)
        check('candidate_structure', True, 'Unique cases, original hashes and separate groups checked.')
    except (ValueError, KeyError, TypeError) as error:
        check('candidate_structure', False, str(error))

    missing, changed = [], []
    for row in rows:
        path = Path(row.get('media_path', ''))
        if not path.is_absolute():
            path = annotations_path.parent / path
        if not path.is_file():
            missing.append(row.get('case_id'))
        elif digest(path) != row.get('input_sha256'):
            changed.append(row.get('case_id'))
    check('original_files', not missing and not changed,
          {'checked': len(rows), 'missing': len(missing), 'hash_mismatches': len(changed),
           'missing_examples': missing[:5], 'mismatch_examples': changed[:5]})

    review_fields = ('human_reviewed', 'reviewer', 'reviewed_at', 'evaluation_cutoff', 'independent_of_training')
    counts = {field: sum(row.get(field) is True if field in ('human_reviewed', 'independent_of_training')
                         else bool(row.get(field)) for row in rows) for field in review_fields}
    check('recorded_review', bool(rows) and all(count == len(rows) for count in counts.values()),
          {'cases': len(rows), 'completed_fields': counts,
           'note': 'Approval to run checks does not supply image provenance or human review.'})
    groups = {split: {label: len({r['event_group'] for r in rows
                                  if r.get('split') == split and r.get('label') == label and r.get('event_group')})
                      for label in ('NOT_AI_GENERATED', 'AI_GENERATED')}
              for split in ('calibration', 'test')}
    check('independent_group_counts', all(n >= MINIMUM_PER_CLASS for values in groups.values() for n in values.values()),
          {'minimum_per_class_per_split': MINIMUM_PER_CLASS, 'groups': groups})
    suitable = (isinstance(population.get('scope'), str) and 0 < len(population['scope'].strip()) <= 300
                and not re.search(r'replace|template', population['scope'], re.I)
                and population.get('representative_of_deployment') is True
                and population.get('training_overlap_checked') is True and bool(population.get('reviewer')))
    check('reviewed_population', suitable,
          'A reviewed scope, deployment suitability, training-overlap check and reviewer must be recorded.')

    directory = Path(model_directory)
    try:
        source = json.loads((directory / 'source.json').read_text(encoding='utf-8'))
        actual = {name: digest(directory / name) for name in
                  ('pytorch_model.pt', 'config.json', 'preprocessor_config.json', 'siglip_config.json')}
        pinned = (actual['pytorch_model.pt'] == source['sha256']
                  and set(source['configuration_sha256']) == {'config.json', 'preprocessor_config.json', 'siglip_config.json'}
                  and all(actual.get(name) == sha for name, sha in source['configuration_sha256'].items()))
        identity = {'model_version': 'sha256:' + actual['pytorch_model.pt'],
                    'config_sha256': actual['config.json'], 'processor_sha256': actual['preprocessor_config.json'],
                    'adapter_version': adapter}
        check('pinned_model_files', pinned, 'Checkpoint and bundled configuration hashes checked against source.json.')
    except (OSError, KeyError, TypeError, ValueError) as error:
        identity = None
        check('pinned_model_files', False, str(error))

    if identity is not None:
        try:
            # The reviewed CPU capture records a full class vector. Adapt its
            # shape for integrity checking only; never rewrite the saved scores.
            capture = [dict(score, fake_probability=score.get('probabilities', {}).get('AI_GENERATED'))
                       if score.get('score_type') == 'normalized_model_output' else score for score in scores]
            validate_capture(rows, capture, identity, complete=True)
            check('deployment_capture', True, 'Every case matches the original image and exact deployment model/adapter.')
        except (ValueError, KeyError, TypeError) as error:
            check('deployment_capture', False, str(error))
    else:
        check('deployment_capture', False, 'Cannot verify capture identity without verified local model files.')

    # Use the existing production fitter's schema as well. Experimental scores
    # deliberately do not satisfy it and must not be silently relabeled here.
    if checks['recorded_review']['passed'] and checks['deployment_capture']['passed']:
        try:
            prepare(rows, scores)
            check('production_prediction_schema', True, 'Existing fitter accepted task, cutoff and raw score records.')
        except (ValueError, KeyError, TypeError) as error:
            check('production_prediction_schema', False, str(error))
    else:
        check('production_prediction_schema', False, 'Reviewed annotations and matching deployment capture required first.')

    return {'status': 'ready_for_fit' if not blockers else 'blocked',
            'checked_at': datetime.now(timezone.utc).isoformat(), 'checks': checks, 'blockers': blockers,
            'case_counts': dict(Counter(row.get('split') for row in rows)),
            'inputs_sha256': {name: digest(path) for name, path in
                             [('annotations', annotations_path), ('predictions', predictions_path), ('population', population_path)]},
            'deployment_eligible': False, 'live_settings_changed': False, 'labels_changed': False,
            'model_inference_runs': 0, 'paid_api_calls': 0,
            'limitation': 'This preflight checks supplied records and files. It cannot invent independent review, prove an undocumented training history, or validate population suitability from approval alone. A passing fit and applicable artifact are still required.'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('annotations', 'predictions', 'population', 'output'):
        parser.add_argument('--' + name, required=True, type=Path)
    parser.add_argument('--model-directory', type=Path, default=ROOT / 'automation/models/ai-origin-broad')
    args = parser.parse_args()
    if args.output.exists():
        parser.error('Use a new output filename; existing audit records are immutable.')
    # Read the application's version instead of allowing a caller to bless GPU
    # scores by supplying their adapter name as the deployment identity.
    source = (ROOT / 'app/Services/Detections/AiOriginClassifier.php').read_text(encoding='utf-8')
    adapter = re.search(r"public const VERSION = '([^']+)';", source).group(1)
    report = inspect(args.annotations, args.predictions, args.population, args.model_directory, adapter)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open('x', encoding='utf-8') as stream:
        stream.write(json.dumps(report, indent=2, allow_nan=False) + '\n')
    print(json.dumps({'status': report['status'], 'blockers': report['blockers'], 'report': str(args.output),
                      'paid_api_calls': 0, 'live_settings_changed': False}))
    return 0 if report['status'] == 'ready_for_fit' else 2


if __name__ == '__main__':
    raise SystemExit(main())
