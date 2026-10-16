"""Compare frozen fact-check predictions with independently reviewed held-out labels.

No labels or accuracy numbers are generated. Unknown labels and split leakage fail closed.
Only standard-library dependencies are used.
"""
from __future__ import annotations

import argparse
from collections import Counter
import csv
import hashlib
import json
from pathlib import Path
import statistics

LABELS = ('supported', 'refuted', 'mixed', 'insufficient', 'needs_context', 'not_fact_checkable')
ABSTENTIONS = {'insufficient', 'needs_context'}
FIELDS = ['claim_id', 'claim', 'original_language', 'claim_date', 'evaluation_cutoff', 'event_group',
          'duplicate_group', 'media_group', 'split', 'label', 'reviewer', 'reviewed_at',
          'adjudicator', 'human_reviewed', 'evidence_urls', 'evidence_quotes', 'annotation_notes']


def read_annotations(path: Path) -> list[dict]:
    with path.open(encoding='utf-8-sig', newline='') as handle:
        rows = list(csv.DictReader(handle))
    ids, groups, text_splits = set(), {}, {}
    for row in rows:
        if not row.get('claim_id') or row['claim_id'] in ids:
            raise ValueError('Missing or duplicate claim_id')
        ids.add(row['claim_id'])
        if row.get('human_reviewed', '').lower() != 'true' or not row.get('reviewer') or not row.get('reviewed_at'):
            raise ValueError('Every label must have explicit human review, reviewer, and date')
        if row.get('label') not in LABELS or row.get('split') not in {'train', 'validation', 'test'}:
            raise ValueError('Invalid label or split')
        if not row.get('evaluation_cutoff') or not row.get('event_group') or not row.get('claim', '').strip():
            raise ValueError('Claim, event group, and evaluation cutoff are required')
        for field in ('event_group', 'duplicate_group', 'media_group'):
            if group := row.get(field):
                identity = (field, group)
                if identity in groups and groups[identity] != row['split']:
                    raise ValueError(f'Related {field} crosses dataset splits: {group}')
                groups[identity] = row['split']
        normalized = ' '.join(row['claim'].lower().split())
        if normalized in text_splits and text_splits[normalized] != row['split']:
            raise ValueError('An exact duplicate claim crosses dataset splits')
        text_splits[normalized] = row['split']
    return [row for row in rows if row['split'] == 'test']


def prediction_rows(path: Path) -> dict[str, dict]:
    rows = [json.loads(line) for line in path.read_text(encoding='utf-8').splitlines() if line.strip()]
    mapped = {row['claim_id']: row for row in rows}
    if len(mapped) != len(rows):
        raise ValueError('Duplicate prediction claim_id')
    return mapped


def metrics(labels: list[dict], predictions: dict[str, dict]) -> dict:
    expected = {row['claim_id'] for row in labels}
    if set(predictions) != expected:
        raise ValueError('Every system must predict exactly the same held-out claim IDs; missing predictions must be explicit abstentions')
    matrix = {truth: {pred: 0 for pred in LABELS} for truth in LABELS}
    for row in labels:
        prediction = predictions[row['claim_id']]
        if prediction.get('evaluation_cutoff') != row['evaluation_cutoff']:
            raise ValueError('Prediction and annotation evaluation cutoffs differ')
        if prediction.get('label') not in LABELS:
            raise ValueError('Unmapped prediction label; article classifier labels are not factual-verification labels')
        matrix[row['label']][prediction['label']] += 1
    n = len(labels)
    per_class = {}
    for label in LABELS:
        tp = matrix[label][label]
        gold = sum(matrix[label].values())
        predicted = sum(matrix[other][label] for other in LABELS)
        precision = tp / predicted if predicted else 0.0
        recall = tp / gold if gold else 0.0
        per_class[label] = {'precision': precision, 'recall': recall,
                            'f1': 2 * precision * recall / (precision + recall) if precision + recall else 0.0,
                            'support': gold}
    answered = [row for row in labels if predictions[row['claim_id']]['label'] not in ABSTENTIONS]
    result = {'sample_size': n, 'class_distribution': dict(Counter(row['label'] for row in labels)),
              'accuracy': sum(matrix[l][l] for l in LABELS) / n if n else None,
              'macro_precision': statistics.mean(x['precision'] for x in per_class.values()),
              'macro_recall': statistics.mean(x['recall'] for x in per_class.values()),
              'macro_f1': statistics.mean(x['f1'] for x in per_class.values()),
              'per_class': per_class, 'confusion_matrix': matrix,
              'abstention_rate': (n - len(answered)) / n if n else None,
              'answered_count': len(answered),
              'accuracy_among_answered': sum(predictions[r['claim_id']]['label'] == r['label'] for r in answered) / len(answered) if answered else None}
    for field in ('latency_ms', 'search_requests', 'page_requests', 'input_tokens', 'output_tokens'):
        values = [p[field] for p in predictions.values() if isinstance(p.get(field), (int, float)) and p[field] >= 0]
        result[field] = {'measured_count': len(values), 'mean': statistics.mean(values) if values else None, 'total': sum(values) if values else None}
    for field in ('retrieval_relevance', 'citation_correctness'):
        # These are human-reviewed per-item judgments, never inferred from the model's own score.
        judged = [v for p in predictions.values() if p.get('evidence_reviewed_by') for v in p.get(field, []) if isinstance(v, bool)]
        result[field] = {'judged_items': len(judged), 'fraction_correct': sum(judged) / len(judged) if judged else None}
    return result


def evaluate(annotation_path: Path, paths: list[Path]) -> dict:
    labels = read_annotations(annotation_path)
    if not labels:
        return {'status': 'unmeasured', 'reason': 'No independently reviewed held-out claims are available.', 'sample_size': 0}
    if len({path.stem for path in paths}) != len(paths):
        raise ValueError('Each system prediction file needs a unique name')
    systems = {path.stem: metrics(labels, prediction_rows(path)) for path in paths}
    return {'status': 'measured_on_supplied_labels', 'annotation_sha256': hashlib.sha256(annotation_path.read_bytes()).hexdigest(),
            'labels': LABELS, 'abstentions': sorted(ABSTENTIONS), 'systems': systems,
            'limitations': ['Label quality and independence require human audit.', 'No thresholds are tuned by this script.',
                           'Macro metrics include all six predefined classes (zero when undefined).',
                           'Accuracy includes abstentions; answered accuracy must be reported with coverage.',
                           'Article-style real/fake classes are not automatically mapped to factual truth.']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--annotations', type=Path)
    parser.add_argument('--predictions', type=Path, nargs='+')
    parser.add_argument('--output', type=Path)
    parser.add_argument('--template', type=Path)
    args = parser.parse_args()
    if args.template:
        with args.template.open('x', encoding='utf-8', newline='') as handle:
            csv.writer(handle).writerow(FIELDS)
        return
    if not args.annotations or not args.predictions or not args.output:
        parser.error('--annotations, --predictions, and --output are required')
    report = evaluate(args.annotations, args.predictions)
    args.output.write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps({'status': report['status'], 'output': str(args.output)}))


if __name__ == '__main__':
    main()
