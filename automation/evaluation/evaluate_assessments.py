"""Auditable, task-separated evaluation. No training or threshold tuning on test cases.

Input: human-reviewed JSONL annotations plus frozen JSONL predictions. Use --template
for a blank schema; --fit-calibration fits binary isotonic calibration on the named
calibration split only and does NOT enable confidence in the application.
"""
import argparse
from collections import Counter
from difflib import SequenceMatcher
import hashlib
import json
import math
from pathlib import Path
import statistics

TASKS = {
    'factual': ['TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING', 'UNVERIFIED', 'CONFLICTING_EVIDENCE'],
    'ai_generation': ['AI_GENERATED', 'NOT_AI_GENERATED'],
    'manipulation': ['MANIPULATED', 'NOT_MANIPULATED'],
    'context': ['OUTDATED', 'OUT_OF_CONTEXT', 'MISSING_CONTEXT', 'SATIRE_PARODY', 'OPINION_NOT_FACT_CHECKABLE', 'CONTEXT_SUPPORTED', 'MISLEADING'],
}
TECHNICAL = {'ANALYSIS_UNAVAILABLE', 'UNSUPPORTED_ANALYSIS', 'UNREADABLE_CONTENT'}
ABSTAIN = TECHNICAL | {'INCONCLUSIVE', 'UNVERIFIED', 'CONFLICTING_EVIDENCE', 'MISSING_CONTEXT'}
ALIASES = {'LIKELY_AI_GENERATED': 'AI_GENERATED', 'LIKELY_NOT_AI_GENERATED': 'NOT_AI_GENERATED',
           'LIKELY_MANIPULATED': 'MANIPULATED', 'NO_MANIPULATION_DETECTED': 'NOT_MANIPULATED'}
FIELDS = ['case_id', 'task', 'text', 'label', 'event_group', 'duplicate_group', 'media_group', 'near_claim_group',
          'split', 'reviewer', 'reviewed_at', 'human_reviewed', 'evidence_urls', 'provenance', 'evaluation_cutoff']


def read_jsonl(path):
    return [json.loads(line) for line in Path(path).read_text(encoding='utf-8-sig').splitlines() if line.strip()]


def validate_annotations(rows):
    ids, groups = set(), {}
    for row in rows:
        if not row.get('case_id') or row['case_id'] in ids:
            raise ValueError('Missing or duplicate case ID')
        ids.add(row['case_id'])
        if row.get('human_reviewed') is not True or not row.get('reviewer') or not row.get('reviewed_at'):
            raise ValueError('Independent human review, reviewer, and review date are required')
        if row.get('task') not in TASKS or row.get('label') not in TASKS[row['task']]:
            raise ValueError('Unknown task/ground-truth label; abstentions are not media provenance labels')
        if row.get('split') not in {'train', 'validation', 'calibration', 'test'} or not row.get('event_group') or not row.get('evaluation_cutoff'):
            raise ValueError('Explicit split, event group, and evaluation cutoff are required')
        if row['task'] in {'ai_generation', 'manipulation'} and not row.get('provenance'):
            raise ValueError('Media evaluation requires a documented provenance/original comparison record')
        for field in ('event_group', 'duplicate_group', 'media_group', 'near_claim_group'):
            if group := row.get(field):
                key = (field, group)
                if key in groups and groups[key] != row['split']:
                    raise ValueError('Group leakage across splits: ' + field)
                groups[key] = row['split']
    # Conservative diagnostic; reviewers must also supply event/media/near-claim groups.
    for i, first in enumerate(rows):
        for second in rows[i+1:]:
            a, b = (' '.join(r.get('text', '').lower().split()) for r in (first, second))
            if a and b and first['split'] != second['split'] and (a == b or (min(len(a), len(b)) >= 40 and SequenceMatcher(None, a, b).ratio() >= .92)):
                raise ValueError('Exact/near-identical claim leakage across splits')
    return rows


def normalized_prediction(row, task):
    label = ALIASES.get(row['verdict'], row['verdict'])
    if label not in TASKS[task] and label not in ABSTAIN:
        raise ValueError('Unmapped prediction label for task: ' + task)
    return label


def probabilities(row, task):
    values = row.get('probabilities')
    if values is None:
        return None
    if row.get('score_type') not in {'normalized_model_output', 'calibrated_probability'}:
        raise ValueError('Evidence heuristics and arbitrary decision values are not probabilities')
    if set(values) != set(TASKS[task]) or any(isinstance(v, bool) or not isinstance(v, (int, float)) or not math.isfinite(v) or not 0 <= v <= 1 for v in values.values()) or abs(sum(values.values()) - 1) > 1e-6:
        raise ValueError('Invalid normalized probability vector')
    return values


def reliability(points):
    bins = []
    for i in range(10):
        values = [(p, y) for p, y in points if min(9, int(p * 10)) == i]
        bins.append({'lower': i / 10, 'upper': (i+1) / 10, 'count': len(values),
                     'mean_probability': statistics.mean(p for p, _ in values) if values else None,
                     'observed_frequency': statistics.mean(y for _, y in values) if values else None})
    return bins


def task_metrics(annotations, predictions, task):
    labels = TASKS[task]
    columns = labels + sorted(ABSTAIN - set(labels))
    matrix = {label: {p: 0 for p in columns} for label in labels}
    errors, scored, points = [], [], []
    for case in annotations:
        row = predictions[case['case_id']]
        prediction_task = 'factual' if row.get('task') == 'claim' else row.get('task')
        if prediction_task != task or row.get('evaluation_cutoff') != case['evaluation_cutoff']:
            raise ValueError('Task or cutoff mismatch')
        predicted = normalized_prediction(row, task)
        matrix[case['label']][predicted] += 1
        if predicted != case['label']:
            errors.append({'case_id': case['case_id'], 'expected': case['label'], 'predicted': predicted})
        vector = probabilities(row, task)
        if vector is not None:
            scored.append((case['label'], vector))
            chosen = max(vector, key=vector.get)
            points.append((vector[chosen], int(chosen == case['label'])))
    per_class = {}
    for label in labels:
        tp = matrix[label][label]
        support = sum(matrix[label].values())
        predicted = sum(matrix[truth][label] for truth in labels)
        precision, recall = tp / predicted if predicted else 0, tp / support if support else 0
        per_class[label] = {'precision': precision, 'recall': recall, 'f1': 2*precision*recall/(precision+recall) if precision+recall else 0, 'support': support}
    n = len(annotations)
    abstentions = Counter(normalized_prediction(predictions[r['case_id']], task) for r in annotations if normalized_prediction(predictions[r['case_id']], task) in ABSTAIN)
    curve = reliability(points)
    return {'sample_size': n, 'per_class': per_class, 'macro_f1': statistics.mean(x['f1'] for x in per_class.values()),
            'accuracy_including_abstentions': (n-len(errors))/n, 'confusion_matrix': matrix, 'errors': errors,
            'inconclusive_and_technical_counts': dict(abstentions), 'coverage': (n-sum(abstentions.values()))/n,
            'probability_metrics': {'scored_count': len(scored),
                'brier_multiclass_sum': statistics.mean(sum((v[l]-int(y == l))**2 for l in labels) for y,v in scored) if scored else None,
                'log_loss': statistics.mean(-math.log(max(1e-15, v[y])) for y,v in scored) if scored else None,
                'top_label_ece_10_bins': sum(b['count']*abs(b['mean_probability']-b['observed_frequency']) for b in curve if b['count'])/len(points) if points else None,
                'reliability_bins': curve, 'classwise_reliability': {l: reliability([(v[l],int(y == l)) for y,v in scored]) for l in labels}}}


def evaluate(annotations, systems):
    validate_annotations(annotations)
    cases = [r for r in annotations if r['split'] == 'test']
    if not cases:
        return {'status': 'unmeasured', 'sample_size': 0, 'reason': 'No human-reviewed held-out examples supplied.'}
    result = {}
    for name, predictions in systems.items():
        mapped = {r['case_id']: r for r in predictions}
        if len(mapped) != len(predictions) or set(mapped) != {r['case_id'] for r in cases}:
            raise ValueError('Each system must predict exactly the same held-out cases; record failures explicitly')
        result[name] = {task: task_metrics([r for r in cases if r['task'] == task], mapped, task) for task in TASKS if any(r['task'] == task for r in cases)}
    return {'status': 'measured_on_supplied_human_labels', 'sample_size': len(cases), 'systems': result,
            'limitations': ['No threshold tuning or calibration fitting uses the test split.', 'Probability metrics evaluate the supplied vectors, not heuristic evidence scores.',
                'Brier is the unscaled sum over classes; binary values are twice the positive-class convention. Log loss clips at 1e-15.',
                'Near-duplicate text detection is a conservative guard, not a substitute for human grouping of events and media.',
                'Macro F1 includes all task classes, with zero for undefined classes. Small diagnostic samples cannot establish deployment accuracy.']}


def fit_isotonic(annotations, predictions, task):
    validate_annotations(annotations)
    if task not in {'ai_generation', 'manipulation'}:
        raise ValueError('Only binary media calibration is implemented')
    cases = [r for r in annotations if r['split'] == 'calibration' and r['task'] == task]
    mapped = {r['case_id']:r for r in predictions}
    if not cases or len(mapped) != len(predictions) or set(mapped) != {r['case_id'] for r in cases}:
        raise ValueError('Fit predictions must match only the separate calibration split')
    positive = TASKS[task][0]
    if len({r['label'] for r in cases}) != 2:
        raise ValueError('Both ground-truth classes are required for fitting')
    grouped = {}
    for case in cases:
        row = mapped[case['case_id']]
        if row.get('task') != task or row.get('evaluation_cutoff') != case['evaluation_cutoff']:
            raise ValueError('Calibration task/cutoff mismatch')
        vector = probabilities(row, task)
        if vector is None: raise ValueError('Missing calibration score')
        grouped.setdefault(vector[positive], []).append(int(case['label'] == positive))
    blocks = []
    for p, ys in sorted(grouped.items()):
        blocks.append({'lower':p, 'upper':p, 'sum':sum(ys), 'n':len(ys)})
        while len(blocks)>1 and blocks[-2]['sum']/blocks[-2]['n'] > blocks[-1]['sum']/blocks[-1]['n']:
            b=blocks.pop(); a=blocks.pop()
            blocks.append({'lower':a['lower'], 'upper':b['upper'], 'sum':a['sum']+b['sum'], 'n':a['n']+b['n']})
    return {'status':'fitted_not_validated', 'method':'binary-isotonic-pav-v1', 'task':task, 'positive_class':positive,
            'sample_size':len(cases), 'fit_case_ids': sorted(mapped), 'blocks':blocks,
            'inference_rule':'Use block mean; between blocks linearly interpolate boundary means; clamp outside observed score range. Validate on untouched test data before deployment.',
            'model_versions':sorted({r.get('model_version','not_recorded') for r in predictions})}


def diagrams(report, directory):
    import matplotlib
    matplotlib.use('Agg')
    import matplotlib.pyplot as plt
    directory.mkdir(parents=True, exist_ok=True)
    for name, tasks in report.get('systems', {}).items():
        for task, scores in tasks.items():
            bins = [b for b in scores['probability_metrics']['reliability_bins'] if b['count']]
            if not bins: continue
            fig, ax = plt.subplots(figsize=(5,5), layout='constrained')
            ax.plot([0,1],[0,1],'--',color='gray',label='Ideal')
            ax.plot([b['mean_probability'] for b in bins],[b['observed_frequency'] for b in bins],'o-',label=f"n={scores['probability_metrics']['scored_count']}")
            ax.set(xlim=(0,1),ylim=(0,1),xlabel='Mean top-label probability',ylabel='Observed correctness',title=f'{name}: {task}')
            ax.legend();fig.savefig(directory/f'{name}-{task}-reliability.svg');plt.close(fig)


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--annotations',type=Path);p.add_argument('--predictions',type=Path,nargs='+');p.add_argument('--output',type=Path)
    p.add_argument('--template',type=Path);p.add_argument('--diagrams',type=Path);p.add_argument('--fit-calibration',choices=['ai_generation','manipulation'])
    a=p.parse_args()
    if a.template:
        a.template.write_text(json.dumps({k:None for k in FIELDS})+'\n',encoding='utf-8');return
    if not a.annotations or not a.predictions or not a.output: p.error('annotations, predictions and output required')
    rows=read_jsonl(a.annotations)
    systems={path.stem:read_jsonl(path) for path in a.predictions}
    if len(systems)!=len(a.predictions):p.error('prediction files need unique names')
    if a.fit_calibration and len(systems)!=1:p.error('fit one frozen model at a time')
    report=fit_isotonic(rows,next(iter(systems.values())),a.fit_calibration) if a.fit_calibration else evaluate(rows,systems)
    report['annotation_sha256']=hashlib.sha256(a.annotations.read_bytes()).hexdigest()
    a.output.write_text(json.dumps(report,indent=2,allow_nan=False),encoding='utf-8')
    if a.diagrams:diagrams(report,a.diagrams)
    print(json.dumps({'status':report['status'],'output':str(a.output)}))


if __name__=='__main__':main()
