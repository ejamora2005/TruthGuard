"""Fit temperature scaling on calibration images; evaluate once on separate test images.

Nothing is fitted from saved TruthGuard verdicts. Requires independently reviewed
known-origin labels and frozen predictions from the exact deployed image model.
Synthetic unit fixtures test the implementation only, never deployment accuracy.

python automation/evaluation/calibrate_image_scores.py --annotations labels.jsonl \
    --predictions scores.jsonl --population population.json --output calibration.json

Prediction rows use evaluate_assessments.py's binary probability schema plus
input_sha256 and model_identity. Annotation rows additionally require input_sha256
and independent_of_training=true. Exporting a validated artifact requires at least
100 examples of EACH class in EACH split. This is a conservative engineering
minimum, not a statistical guarantee. Population suitability still needs review.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import math
from pathlib import Path
import random
import statistics

from evaluate_assessments import read_jsonl, validate_annotations, probabilities, reliability

METHOD = 'binary-temperature-v1'
EPSILON = 1e-6
MINIMUM_PER_CLASS = 100
IDENTITY_FIELDS = ('model_version', 'config_sha256', 'processor_sha256', 'adapter_version')


def logit(p):
    if isinstance(p, bool) or not isinstance(p, (float, int)) or not math.isfinite(p) or not 0 <= p <= 1:
        raise ValueError('Expected a finite normalized model score')
    p = min(1 - EPSILON, max(EPSILON, p))
    return math.log(p) - math.log1p(-p)


def transform(p, temperature):
    if isinstance(temperature, bool) or not math.isfinite(temperature) or temperature <= 0:
        raise ValueError('Temperature must be finite and positive')
    z = logit(p) / temperature
    return 1 / (1 + math.exp(-z)) if z >= 0 else math.exp(z) / (1 + math.exp(z))


def loss(points, temperature):
    # Stable Bernoulli negative log likelihood; no rounded percentages.
    values = []
    for p, y in points:
        z = logit(p) / temperature
        values.append(max(z, 0) - y*z + math.log1p(math.exp(-abs(z))))
    return statistics.mean(values)


def fit_temperature(points):
    if not points or {y for _, y in points} != {0, 1}:
        raise ValueError('Both known-origin classes are required for fitting')
    # Optimize log(T) on calibration data ONLY; positive T preserves class order.
    lo, hi = math.log(.05), math.log(100)
    ratio = (math.sqrt(5)-1)/2
    left, right = hi-ratio*(hi-lo), lo+ratio*(hi-lo)
    for _ in range(100):
        if loss(points, math.exp(left)) < loss(points, math.exp(right)):
            hi, right = right, left
            left = hi-ratio*(hi-lo)
        else:
            lo, left = left, right
            right = lo+ratio*(hi-lo)
    return math.exp((lo+hi)/2)


def metrics(points, temperature):
    fitted = [(transform(p, temperature), y) for p, y in points]
    bins = reliability(fitted)
    return {'sample_size': len(points), 'log_loss': loss(points, temperature),
            'brier_positive_class': statistics.mean((p-y)**2 for p, y in fitted),
            'positive_class_ece_10_bins': sum(b['count']*abs(b['mean_probability']-b['observed_frequency'])
                                            for b in bins if b['count'])/len(points),
            'reliability_bins': bins}


def bootstrap_log_loss_delta(points, temperature, groups):
    # Paired resampling of untouched test predictions, not refitting on test data.
    # Group leakage is rejected below; dependence beyond supplied groups is a limit.
    grouped = {}
    for point, group in zip(points, groups):
        grouped.setdefault(group, []).append(loss([point], temperature)-loss([point], 1))
    clusters = list(grouped.values())
    rng = random.Random(42173)
    estimates = sorted(statistics.mean(v for cluster in rng.choices(clusters, k=len(clusters)) for v in cluster)
                       for _ in range(1000))
    return [estimates[24], estimates[974]]


def prepare(annotations, predictions):
    validate_annotations(annotations)
    cases = [r for r in annotations if r['split'] in {'calibration', 'test'}]
    if not cases or any(r['task'] != 'ai_generation' for r in cases):
        raise ValueError('Supply AI-generation calibration and test labels')
    all_hashes = {}
    for row in annotations:
        if sha := row.get('input_sha256'):
            if sha in all_hashes and all_hashes[sha] != row['split']:
                raise ValueError('Original image leaked across training/calibration/test splits')
            all_hashes[sha] = row['split']
    mapped = {r['case_id']: r for r in predictions}
    if len(mapped) != len(predictions) or set(mapped) != {r['case_id'] for r in cases}:
        raise ValueError('Predictions must match exactly the separate calibration and test cases')
    identity = None
    hashes = {}
    splits = {'calibration': [], 'test': []}
    for case in cases:
        row = mapped[case['case_id']]
        sha = case.get('input_sha256', '')
        if len(sha) != 64 or any(c not in '0123456789abcdef' for c in sha):
            raise ValueError('Original image SHA-256 required')
        if sha in hashes:
            raise ValueError('Duplicate original image in calibration/test data')
        hashes[sha] = case['split']
        if case.get('independent_of_training') is not True:
            raise ValueError('Training independence must be established for every original image')
        if row.get('task') != 'ai_generation' or row.get('evaluation_cutoff') != case['evaluation_cutoff']:
            raise ValueError('Prediction task or cutoff mismatch')
        if row.get('input_sha256') != sha or row.get('score_type') != 'normalized_model_output':
            raise ValueError('Prediction must record the original image and raw model output')
        current = row.get('model_identity', {})
        if set(current) != set(IDENTITY_FIELDS) or any(not isinstance(current[k], str) or not current[k] for k in IDENTITY_FIELDS):
            raise ValueError('Exact model, processor, config and adapter identity required')
        if identity is not None and current != identity:
            raise ValueError('Cannot mix model or preprocessing versions in a calibrator')
        identity = current
        vector = probabilities(row, 'ai_generation')
        if vector is None:
            raise ValueError('Missing normalized model output')
        p = vector['AI_GENERATED']
        logit(p)
        splits[case['split']].append((p, int(case['label'] == 'AI_GENERATED')))
    if any({y for _, y in values} != {0, 1} for values in splits.values()):
        raise ValueError('Both known-origin classes are required in each split')
    return splits, identity


def calibrate(annotations, predictions, population):
    if (not isinstance(population.get('scope'), str) or not population['scope'].strip()
            or len(population['scope']) > 300 or population.get('representative_of_deployment') is not True
            or population.get('training_overlap_checked') is not True or not population.get('reviewer')):
        raise ValueError('Reviewed population scope, suitability and training-overlap check required')
    splits, identity = prepare(annotations, predictions)
    temperature = fit_temperature(splits['calibration'])
    before, after = metrics(splits['test'], 1), metrics(splits['test'], temperature)
    sizes = {split: {'real': sum(y == 0 for _, y in rows), 'fake': sum(y == 1 for _, y in rows)}
             for split, rows in splits.items()}
    groups = [r['event_group'] for r in annotations if r['split'] == 'test']
    interval = bootstrap_log_loss_delta(splits['test'], temperature, groups)
    independent = {split: {label: len({r['event_group'] for r in annotations
                                      if r['split'] == split and r['label'] == gold})
                            for label, gold in [('real', 'NOT_AI_GENERATED'), ('fake', 'AI_GENERATED')]}
                   for split in splits}
    sufficient = all(n >= MINIMUM_PER_CLASS for counts in independent.values() for n in counts.values())
    bounded = .0501 < temperature < 99.99
    improves = (after['log_loss'] < before['log_loss'] and interval[1] < 0
                and after['brier_positive_class'] <= before['brier_positive_class']
                and after['positive_class_ece_10_bins'] <= before['positive_class_ece_10_bins'])
    passed = sufficient and bounded and improves
    return {'schema_version': 1, 'method': METHOD,
            'status': 'validated_on_supplied_population' if passed else 'fitted_not_validated',
            'temperature': temperature, 'input_transform': 'logit-clipped-1e-6-v1',
            'epsilon': EPSILON, 'positive_class': 'fake', 'label_mapping': ['real', 'fake'],
            'model_identity': identity, 'population': population, 'counts': sizes, 'independent_group_counts': independent,
            'validation': {'passed': passed, 'minimum_per_class_per_split': MINIMUM_PER_CLASS,
                           'sufficient_data': sufficient, 'finite_interior_fit': bounded,
                           'held_out_improvement': improves, 'before': before, 'after': after,
                           'paired_log_loss_delta_95pct_interval': interval,
                           'fit_used_test_data': False},
            'fitted_at': datetime.now(timezone.utc).isoformat(),
            'limitations': ['Validation applies only to the reviewed supplied population and prevalence.',
                           'Minimum sample counts and bootstrap intervals do not prove universal calibration.',
                           'Labels, population suitability and training independence require genuine review; fields alone cannot prove them.',
                           'Temperature scaling changes probabilities, not model weights, raw class labels or accuracy.',
                           'The test set must not be reused to select methods or tune a failed calibrator.']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--annotations', type=Path, required=True)
    parser.add_argument('--predictions', type=Path, required=True)
    parser.add_argument('--population', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if args.output.exists():
        parser.error('Refusing to overwrite a calibration artifact; use a new versioned filename')
    result = calibrate(read_jsonl(args.annotations), read_jsonl(args.predictions),
                       json.loads(args.population.read_text(encoding='utf-8-sig')))
    result['input_hashes'] = {k: hashlib.sha256(p.read_bytes()).hexdigest() for k, p in
                              [('annotations', args.annotations), ('predictions', args.predictions), ('population', args.population)]}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, allow_nan=False)+'\n', encoding='utf-8')
    print(json.dumps({'status': result['status'], 'artifact': str(args.output),
                      'sha256': hashlib.sha256(args.output.read_bytes()).hexdigest()}))


if __name__ == '__main__':
    main()
