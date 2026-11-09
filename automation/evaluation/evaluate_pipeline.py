"""Review six fact-check stages using frozen reports and explicit human judgments.

Prepare an unreviewed JSONL sheet, then evaluate the completed sheet. No API calls,
automatic labels, training, or changes to published reports occur in this workflow.
"""
import argparse
from collections import Counter
from datetime import datetime
import hashlib
import html
import json
from pathlib import Path

from audit_fact_checks import indexed, load


STAGES = {
    'extraction': 'Readable text and visible subject were captured faithfully; no invented identity or price.',
    'claims': 'Material factual assertions, questions, estimates and opinions were separated with correct scope.',
    'retrieval': 'Relevant accessible evidence was found for the exact subject, date and claim; record known missed sources.',
    'evidence': 'Quoted passages support the claimed relationship and match identities, dates, numbers and qualifiers; conflicts were preserved.',
    'verdict': 'Each claim outcome and the overall verdict follow the documented evidence and appropriate uncertainty.',
    'explanation_citations': 'The explanation answers the question accurately and its citations establish its material statements.',
}
STATUSES = {'pass', 'fail', 'unreviewed', 'not_applicable'}


def digest(archive):
    return hashlib.sha256(json.dumps(archive, sort_keys=True, ensure_ascii=False,
                                     separators=(',', ':'), allow_nan=False).encode('utf-8')).hexdigest()


def observations(archive):
    result = archive.get('result')
    if not isinstance(result, dict):
        raise ValueError('Every archive needs a result object: ' + archive['case_id'])
    task = result.get('primary_task')
    if task not in {None, 'claim', 'context'}:
        raise ValueError('This workflow reviews factual/context stages, not media detection accuracy')
    return {
        'input': archive.get('input', {}),
        'extraction': result.get('extraction', {}),
        'claims': result.get('claims', []),
        'candidate_records': result.get('diagnostics', {}).get('candidate_records', []),
        'search_attempts': result.get('diagnostics', {}).get('search_attempts', []),
        'verdict': result.get('verdict'),
        'summary': result.get('summary', ''),
        'sources': result.get('sources', []),
        'answer_segments': result.get('answer_segments', []),
        'generated_wording': result.get('generated_wording', {}),
        'answer_validation': result.get('answer_validation', {}),
        'limitations': result.get('limitations', []),
        'answer_origin': archive.get('answer_origin', 'see_original_archive'),
        'unverified_feedback': archive.get('feedback', []),
    }


def prepare(archives):
    indexed(archives, 'archives')
    return [{
        'case_id': archive['case_id'], 'archive_sha256': digest(archive),
        'reviewer': None, 'reviewed_at': None, 'human_reviewed': False,
        'independent_of_development': False,
        'stages': {stage: {'status': 'unreviewed', 'note': '', 'evidence_urls': []} for stage in STAGES},
        'observed': observations(archive),
    } for archive in archives]


def valid_review(review):
    stages = review.get('stages')
    if not isinstance(stages, dict) or set(stages) != set(STAGES):
        raise ValueError('Every case needs exactly the six documented stages')
    completed = False
    for stage, judgment in stages.items():
        if not isinstance(judgment, dict) or judgment.get('status') not in STATUSES:
            raise ValueError('Unknown review status for ' + stage)
        if not isinstance(judgment.get('note'), str) or not isinstance(judgment.get('evidence_urls'), list):
            raise ValueError('Stage notes and evidence_urls must be supplied')
        if any(not isinstance(url, str) or not url.startswith(('https://', 'http://')) for url in judgment['evidence_urls']):
            raise ValueError('Evidence references must be HTTP(S) URLs')
        if judgment['status'] != 'unreviewed':
            completed = True
            if not judgment['note'].strip():
                raise ValueError('Each reviewed stage needs a reason or evidence note')
    if not isinstance(review.get('independent_of_development'), bool):
        raise ValueError('Record independence explicitly as true or false')
    if completed:
        if review.get('human_reviewed') is not True or not isinstance(review.get('reviewer'), str) or not review['reviewer'].strip():
            raise ValueError('Completed stages require actual human review and reviewer identity')
        try:
            datetime.fromisoformat(review['reviewed_at'].replace('Z', '+00:00'))
        except (KeyError, ValueError, TypeError, AttributeError):
            raise ValueError('Completed stages need an ISO review date') from None


def stage_metrics(reviews):
    metrics = {}
    for stage in STAGES:
        counts = Counter(r['stages'][stage]['status'] for r in reviews)
        assessed = counts['pass'] + counts['fail']
        metrics[stage] = {
            'counts': {status: counts[status] for status in sorted(STATUSES)},
            'assessed_count': assessed,
            'reviewer_assessed_pass_rate': counts['pass'] / assessed if assessed else None,
            'assessment_coverage': assessed / len(reviews) if reviews else None,
        }
    return metrics


def evaluate(archives, reviews):
    frozen, judgments = indexed(archives, 'archives'), indexed(reviews, 'reviews')
    if set(frozen) != set(judgments):
        raise ValueError('Reviews must cover exactly the frozen report IDs; keep unreviewed rows')
    failures = []
    for key, review in judgments.items():
        if review.get('archive_sha256') != digest(frozen[key]):
            raise ValueError('Review belongs to a different report snapshot: ' + key)
        observations(frozen[key])
        valid_review(review)
        failed = [stage for stage in STAGES if review['stages'][stage]['status'] == 'fail']
        if failed:
            failures.append({
                'case_id': key,
                'earliest_observed_failed_stage': failed[0],
                'failed_stages': failed,
                'unreviewed_stages': [s for s in STAGES if review['stages'][s]['status'] == 'unreviewed'],
                'reviewer': review['reviewer'],
                'findings': {s: review['stages'][s] for s in failed},
            })
    rows = list(judgments.values())
    assessed = sum(r['stages'][s]['status'] in {'pass', 'fail'} for r in rows for s in STAGES)
    return {
        'status': 'human_reviewed_stage_diagnostic' if assessed else 'unmeasured',
        'sample_size': len(rows), 'assessed_stage_count': assessed,
        'stages': stage_metrics(rows),
        'by_review_origin': {
            'independent': stage_metrics([r for r in rows if r['independent_of_development']]),
            'development': stage_metrics([r for r in rows if not r['independent_of_development']]),
        },
        'failures': failures,
        'earliest_observed_failure_counts': dict(Counter(r['earliest_observed_failed_stage'] for r in failures)),
        'rubric': STAGES,
        'limitations': [
            'Stage pass rates are human diagnostic judgments, not measured deployment or video-model accuracy.',
            'Unreviewed and not-applicable stages never count as passes; denominators and coverage are explicit.',
            'The earliest observed failure is a triage hint, not proof of root cause or independence between errors.',
            'User feedback is unverified and cannot supply a gold label or automatically change a verdict.',
            'Missing telemetry is not evidence of stage success. Explain unavailable or inapplicable review in the notes.',
            'Use independent labels, event/media-group splits and the held-out evaluator for performance claims.',
            'Freeze new runs and review sheets to compare fixes; these files never train or modify the live pipeline.',
        ],
    }


def write_report(report, destination):
    destination.mkdir(parents=True, exist_ok=False)
    (destination / 'report.json').write_text(json.dumps(report, indent=2, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    esc = lambda value: html.escape(str(value))
    rows = ''
    for stage, metric in report['stages'].items():
        rate = metric['reviewer_assessed_pass_rate']
        rows += '<tr>' + ''.join('<td>' + esc(v) + '</td>' for v in [
            stage, metric['counts']['pass'], metric['counts']['fail'],
            metric['counts']['unreviewed'], metric['counts']['not_applicable'],
            'unmeasured' if rate is None else f'{rate:.1%}',
        ]) + '</tr>'
    failures = ''.join('<li><strong>' + esc(r['case_id']) + '</strong><pre>'
                       + esc(json.dumps(r['findings'], indent=2, ensure_ascii=False)) + '</pre></li>' for r in report['failures'])
    page = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TruthGuard stage review</title>'
    page += '<style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#172033}td,th{border:1px solid #ccd3df;padding:.6rem;text-align:left}table{border-collapse:collapse}pre{white-space:pre-wrap;overflow-wrap:anywhere}.scroll{overflow:auto}</style>'
    page += '<h1>TruthGuard stage review</h1><p>' + esc(report['status']) + ' | Cases: ' + str(report['sample_size']) + '</p>'
    page += '<p>Diagnostic review only. Unreviewed stages are not successful stages.</p><div class="scroll"><table><tr><th>Stage</th><th>Pass</th><th>Fail</th><th>Unreviewed</th><th>N/A</th><th>Reviewed pass rate</th></tr>' + rows + '</table></div>'
    page += '<h2>Failures to investigate</h2><ul>' + failures + '</ul><h2>Limitations</h2><ul>'
    page += ''.join('<li>' + esc(note) + '</li>' for note in report['limitations']) + '</ul></html>'
    (destination / 'report.html').write_text(page, encoding='utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--reports', required=True, type=Path)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--prepare', type=Path, help='New blank review JSONL with frozen observations')
    mode.add_argument('--reviews', type=Path, help='Completed review JSONL')
    parser.add_argument('--output-dir', type=Path)
    args = parser.parse_args()
    if args.reviews and not args.output_dir:
        parser.error('--reviews requires --output-dir')
    archives = load(args.reports)
    if args.prepare:
        rows = prepare(archives)
        with args.prepare.open('x', encoding='utf-8') as handle:
            for row in rows:
                handle.write(json.dumps(row, ensure_ascii=False, allow_nan=False) + '\n')
        print(json.dumps({'status': 'awaiting_human_review', 'cases': len(rows), 'output': str(args.prepare)}))
        return
    report = evaluate(archives, load(args.reviews))
    report['artifacts'] = {key: {'path': str(path), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
                           for key, path in [('reports', args.reports), ('reviews', args.reviews)]}
    write_report(report, args.output_dir)
    print(json.dumps({'status': report['status'], 'cases': report['sample_size'], 'output': str(args.output_dir)}))


if __name__ == '__main__':
    main()
