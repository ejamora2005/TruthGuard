"""Calibrate task/verdict reliability from independent review, never old model labels.

Each fitted cell estimates P(independent review agrees | task, assigned verdict).
It does not estimate P(claim is true). No evidence heuristic is treated as a
probability. Separate event groups and untouched test labels are mandatory.
"""
import argparse
from collections import defaultdict
import hashlib
import json
import math
from pathlib import Path

from evaluate_assessments import read_jsonl, validate_annotations, TASKS, ABSTAIN

METHOD = 'reviewed-verdict-reliability-v1'
ELIGIBLE = {
    'claim': {'TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING'},
    'context': {'OUTDATED', 'OUT_OF_CONTEXT', 'MISLEADING', 'SATIRE_PARODY', 'OPINION_NOT_FACT_CHECKABLE'},
}


def wilson(correct, n):
    p, z = correct / n, 1.96
    center = (p + z*z/(2*n)) / (1 + z*z/n)
    radius = z * math.sqrt(p*(1-p)/n + z*z/(4*n*n)) / (1 + z*z/n)
    return [center-radius, center+radius]


def calibrate(annotations, predictions, population):
    validate_annotations(annotations)
    if (population.get('representative_of_deployment') is not True
            or population.get('independent_review') is not True
            or not isinstance(population.get('scope'), str)
            or not 1 <= len(population['scope'].strip()) <= 300):
        raise ValueError('Reviewed population scope and independent review are required')
    cases = [r for r in annotations if r['split'] in {'calibration', 'test'}]
    mapped = {r['case_id']: r for r in predictions}
    if not cases or len(mapped) != len(predictions) or set(mapped) != {r['case_id'] for r in cases}:
        raise ValueError('Predictions must match exactly the separate calibration and test cases')
    cells, identities, events = defaultdict(lambda: {'calibration': [], 'test': []}), set(), set()
    for case in cases:
        row = mapped[case['case_id']]
        task = 'claim' if case['task'] == 'factual' else case['task']
        if task not in ELIGIBLE or row.get('task') != task or row.get('evaluation_cutoff') != case['evaluation_cutoff']:
            raise ValueError('Factual/context task or cutoff mismatch')
        if (case.get('independent_of_development') is not True
                or not case.get('evidence_urls') or not case.get('review_basis')
                or case.get('label_source') != 'independent_evidence_review'):
            raise ValueError('Labels require independent evidence review, not saved detector responses')
        expected_sha = hashlib.sha256(case['text'].encode('utf-8')).hexdigest()
        if row.get('input_sha256') != expected_sha or row.get('source_url') != case.get('source_url') or row.get('media_sha256') != case.get('media_sha256'):
            raise ValueError('Prediction does not belong to the exact reviewed input')
        # One event per observation. Related paraphrases/crops cannot inflate n.
        if case['event_group'] in events:
            raise ValueError('One independent event per calibration observation is required')
        events.add(case['event_group'])
        identity = row.get('pipeline_identity', '')
        if not isinstance(identity, str) or len(identity) != 64 or any(c not in '0123456789abcdef' for c in identity):
            raise ValueError('Frozen pipeline identity is required')
        identities.add(identity)
        # Unresolved and technical outputs remain in the audit, with no badge.
        verdict = row.get('verdict')
        if verdict not in set(TASKS['factual'] + TASKS['context']) | ABSTAIN:
            raise ValueError('Unknown factual/context prediction verdict')
        if verdict not in ELIGIBLE[task]:
            continue
        cells[task+'|'+verdict][case['split']].append(int(verdict == case['label']))
    if len(identities) != 1:
        raise ValueError('Only one frozen pipeline may be calibrated at a time')
    result = {}
    for key, splits in sorted(cells.items()):
        counts = {s: {'count': len(ys), 'correct': sum(ys)} for s, ys in splits.items()}
        fit, test = counts['calibration'], counts['test']
        p = (fit['correct']+1)/(fit['count']+2) if fit['count'] else None
        observed = test['correct']/test['count'] if test['count'] else None
        interval = wilson(test['correct'], test['count']) if test['count'] else None
        sufficient = all(v['count'] >= 100 and v['correct'] >= 10 and v['count']-v['correct'] >= 10 for v in counts.values())
        passed = bool(sufficient and abs(p-observed) <= .05 and interval[0] <= p <= interval[1] and interval[1]-interval[0] <= .2)
        test_points = splits['test']
        result[key] = dict(counts, probability=p, held_out_agreement=observed, held_out_wilson_95=interval,
                           absolute_calibration_error=abs(p-observed) if p is not None and observed is not None else None,
                           held_out_brier=sum((p-y)**2 for y in test_points)/len(test_points) if p is not None and test_points else None,
                           sufficient_data=sufficient, passed=passed)
    return {'schema_version': 1, 'method': METHOD,
            'status': 'validated_on_supplied_population' if any(c['passed'] for c in result.values()) else 'fitted_not_validated',
            'pipeline_identity': identities.pop(), 'population': population, 'fit_used_test_data': False,
            'cells': result, 'sample_size': len(cases), 'unscored_count': len(cases)-sum(len(v) for splits in cells.values() for v in splits.values()),
            'limitations': ['Reliability is conditional on the task and assigned verdict, not individual claim truth.',
                            'Only passing cells may be enabled. Technical and unresolved results never acquire confidence.',
                            'Minimum counts and Wilson intervals do not establish universal calibration.',
                            'Review independence, event grouping and population suitability need genuine human audit.',
                            'Do not reuse the test set to choose another method after inspecting a failed validation.']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('annotations', 'predictions', 'population', 'output'):
        parser.add_argument('--'+name, type=Path, required=True)
    args = parser.parse_args()
    if args.output.exists():
        parser.error('Refusing to overwrite a calibration artifact')
    result = calibrate(read_jsonl(args.annotations), read_jsonl(args.predictions),
                       json.loads(args.population.read_text(encoding='utf-8-sig')))
    result['input_hashes'] = {name: hashlib.sha256(getattr(args, name).read_bytes()).hexdigest()
                              for name in ('annotations', 'predictions', 'population')}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, allow_nan=False)+'\n', encoding='utf-8')
    print(json.dumps({'status': result['status'], 'output': str(args.output),
                      'sha256': hashlib.sha256(args.output.read_bytes()).hexdigest()}))


if __name__ == '__main__':
    main()
