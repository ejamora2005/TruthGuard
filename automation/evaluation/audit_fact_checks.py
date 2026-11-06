"""Audit frozen fact-check runs without treating draft labels as measured accuracy.

This development report is deliberately not a calibration/release certificate.
No API calls, model fitting, label inference or live configuration changes occur.
"""
import argparse
import csv
import hashlib
import html
import json
from pathlib import Path
from datetime import datetime
import statistics
from urllib.parse import parse_qsl, urlencode, urlsplit

LABELS = {'TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING', 'UNVERIFIED',
          'CONFLICTING_EVIDENCE', 'MISSING_CONTEXT', 'OPINION_NOT_FACT_CHECKABLE',
          'OUTDATED', 'OUT_OF_CONTEXT', 'SATIRE_PARODY', 'CONTEXT_SUPPORTED',
          'ANALYSIS_UNAVAILABLE', 'UNREADABLE_CONTENT', 'UNSUPPORTED_ANALYSIS', 'INCONCLUSIVE'}
DECISIVE = {'TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING', 'OUTDATED',
            'OUT_OF_CONTEXT', 'SATIRE_PARODY', 'CONTEXT_SUPPORTED'}
TECHNICAL = {'ANALYSIS_UNAVAILABLE', 'UNREADABLE_CONTENT', 'UNSUPPORTED_ANALYSIS'}
COMPARABLE = {'publisher_claim_review', 'stored_answer', 'source_checked_development', 'independent_review'}


def load(path):
    return [json.loads(line) for line in Path(path).read_text(encoding='utf-8-sig').splitlines() if line.strip()]


def indexed(rows, name):
    out = {}
    for row in rows:
        key = row.get('case_id')
        if not isinstance(key, str) or not key or key in out:
            raise ValueError(f'{name}: missing/duplicate case_id')
        out[key] = row
    return out


def ratio(a, b):
    return a / b if b else None


def wilson(correct, count):
    if not count:
        return None
    z = 1.959963984540054
    p = correct / count
    denominator = 1 + z*z/count
    center = (p + z*z/(2*count)) / denominator
    margin = z * ((p*(1-p)/count + z*z/(4*count*count))**.5) / denominator
    return [max(0, center-margin), min(1, center+margin)]


def canonical_url(value):
    if not isinstance(value, str):
        return None
    parsed = urlsplit(value)
    if parsed.scheme not in {'http', 'https'} or not parsed.hostname:
        return None
    query = sorted((k, v) for k, v in parse_qsl(parsed.query, keep_blank_values=True)
                   if not k.lower().startswith('utm_') and k.lower() not in {'fbclid', 'gclid'})
    return (parsed.hostname.lower().removeprefix('www.'), parsed.port, parsed.path.rstrip('/'), urlencode(query))


def metrics(rows):
    comparable = [r for r in rows if r['comparable']]
    decisive = [r for r in comparable if r['actual'] in DECISIVE]
    correct_decisive = sum(r['matches_reference'] for r in decisive)
    true_cases = [r for r in comparable if r['expected'] == 'TRUE']
    answerable = [r for r in comparable if r['expected'] in DECISIVE]
    tokens = [r['reported_tokens'] for r in rows if r['reported_tokens'] is not None]
    return {
        'cases': len(rows), 'recorded_event_groups': len({r['event_group'] for r in rows}),
        'comparable_reference_cases': len(comparable),
        'reference_agreement_including_abstentions': ratio(sum(r['matches_reference'] for r in comparable), len(comparable)),
        'decisive_cases_with_reference': len(decisive),
        'correct_decisive_cases': correct_decisive,
        'decisive_reference_agreement': ratio(correct_decisive, len(decisive)),
        'decisive_agreement_wilson_95': wilson(correct_decisive, len(decisive)),
        'coverage_all_inputs': ratio(sum(r['actual'] in DECISIVE for r in rows), len(rows)),
        'coverage_on_answerable_reference_cases': ratio(sum(r['actual'] in DECISIVE for r in answerable), len(answerable)),
        'wrong_false_rate_on_true_references': ratio(sum(r['actual'] == 'FALSE' for r in true_cases), len(true_cases)),
        'technical_failures': sum(r['actual'] in TECHNICAL for r in rows),
        'decisive_without_citations': sum(r['actual'] in DECISIVE and not r['source_count'] for r in rows),
        'reference_source_retrieved_but_unused': sum(r.get('reference_source_retrieved') is True
                                                    and r.get('reference_source_used') is False for r in rows),
        'reported_tokens': sum(tokens), 'cases_without_token_measurement': len(rows)-len(tokens),
        'reported_tokens_per_correct_decisive_reference': ratio(sum(tokens), correct_decisive),
        'median_latency_ms': statistics.median(r['latency_ms'] for r in rows) if rows else None,
        'citation_entailment_accuracy': None,
        'billing_cost_usd': None,
    }


def audit(cases, predictions, archives):
    cases = indexed(cases, 'cases')
    predictions = indexed(predictions, 'predictions')
    archives = indexed(archives, 'reports')
    if set(predictions) != set(archives) or not set(predictions) <= set(cases):
        raise ValueError('Every captured case needs exactly one archive and a supplied input')
    identities = {p.get('pipeline_identity') for p in predictions.values()}
    if len(identities) != 1 or None in identities:
        raise ValueError('A run must have one recorded pipeline identity')
    rows = []
    for key, p in predictions.items():
        c, archive = cases[key], archives[key]['result']
        if p.get('input_sha256') != hashlib.sha256(c['text'].encode()).hexdigest():
            raise ValueError('Input text hash mismatch: '+key)
        if p.get('media_sha256') != c.get('media_sha256') or p.get('source_url') != c.get('source_url'):
            raise ValueError('Media/source binding mismatch: '+key)
        task = lambda value: 'claim' if value == 'factual' else value
        if task(p.get('task')) != task(c.get('task')):
            raise ValueError('Task/cutoff mismatch: '+key)
        policy = c.get('cutoff_policy', 'fixed')
        if p.get('cutoff_policy', 'fixed') != policy:
            raise ValueError('Cutoff policy mismatch: '+key)
        if policy == 'fixed':
            if p.get('evaluation_cutoff') != c.get('evaluation_cutoff'):
                raise ValueError('Task/cutoff mismatch: '+key)
        elif policy == 'capture_time':
            stamp = lambda value: datetime.fromisoformat(value.replace('Z', '+00:00'))
            if (p.get('declared_evaluation_cutoff') != c.get('evaluation_cutoff')
                    or archives[key].get('evaluation_cutoff') != p.get('evaluation_cutoff')
                    or not stamp(c['evaluation_cutoff']) <= stamp(p['evaluation_cutoff']) <= stamp(archives[key]['captured_at'])):
                raise ValueError('Live cutoff/archive mismatch: '+key)
        else:
            raise ValueError('Unknown cutoff policy: '+key)
        verdict = p.get('verdict')
        if verdict not in LABELS:
            raise ValueError('Unknown factual/context verdict: '+str(verdict))
        expected = c.get('expected_verdict', c.get('label'))
        status = c.get('label_status', 'needs_scope_review')
        if expected is not None and expected not in LABELS:
            raise ValueError('Unknown reference verdict: '+str(expected))
        if status == 'independent_review' and not (c.get('human_reviewed') is True and c.get('reviewer')
                and c.get('reviewed_at') and c.get('review_basis') and c.get('independent_of_development') is True):
            raise ValueError('Independent review requires actual review records: '+key)
        comparable = expected is not None and status in COMPARABLE
        budget_tokens = p.get('api_budget', {}).get('reported_tokens')
        usage_tokens = (p.get('api_usage') or {}).get('total_tokens')
        token_values = [t for t in [budget_tokens, usage_tokens] if isinstance(t, int) and not isinstance(t, bool) and t >= 0]
        sources = archive.get('sources') or []
        references = {canonical_url(u) for u in c.get('evidence_urls', [])} - {None}
        records = archive.get('diagnostics', {}).get('candidate_records')
        reference_retrieved = any(s.get('retrieval_status') == 'retrieved'
            and (canonical_url(s.get('url')) in references or canonical_url(s.get('final_url')) in references)
            for s in records) if references and isinstance(records, list) else None
        reference_used = any(canonical_url(s.get('url')) in references for s in sources) if references else None
        rows.append({'case_id': key, 'submitted_text': c['text'], 'event_group': c['event_group'], 'expected': expected,
            'cutoff_policy': policy, 'evaluation_cutoff': p['evaluation_cutoff'],
            'label_status': status, 'actual': verdict, 'comparable': comparable,
            'matches_reference': verdict == expected if comparable else None,
            'answer_origin': p.get('answer_origin', 'unrecorded'),
            'reported_tokens': max(token_values) if token_values else None,
            'latency_ms': p['latency_ms'], 'source_count': p.get('source_count', len(sources)),
            'outcome_reason': p.get('outcome_reason'),
            'source_urls': [s['url'] for s in sources if s.get('url')],
            'reference_source_retrieved': reference_retrieved, 'reference_source_used': reference_used,
            'summary': archive.get('summary', ''),
            'evidence_review_status': 'not_independently_audited'})
    origins = sorted({r['answer_origin'] for r in rows})
    return {'status': 'development_diagnostic_not_deployment_accuracy', 'release_ready': False,
        'pipeline_identity': next(iter(identities)),
        'captured_cases': len(rows), 'uncaptured_cases': sorted(set(cases)-set(predictions)),
        'overall': metrics(rows),
        'by_answer_origin': {o: metrics([r for r in rows if r['answer_origin'] == o]) for o in origins},
        'by_label_status': {s: metrics([r for r in rows if r['label_status'] == s]) for s in sorted({r['label_status'] for r in rows})},
        'by_cutoff_policy': {s: metrics([r for r in rows if r['cutoff_policy'] == s]) for s in sorted({r['cutoff_policy'] for r in rows})},
        'cases': rows,
        'limitations': [
            'Publisher metadata can misstate claim scope; stored answers are reuse tests, not independent labels.',
            'Development cases and related variants are not independent held-out events.',
            'Wilson intervals summarize these observations and do not establish representativeness.',
            'A citation count does not measure source entailment or independence; human evidence review is outstanding.',
            'Reference URL discovery/use is a mechanical diagnostic, not proof that a source establishes the exact claim.',
            'Token counts are provider-reported observations, not a complete monetary invoice.',
            'No live production deployment, HTTP submission flow or browser behavior is certified by this service capture.',
            'Live capture uses each case start time; fixed-cutoff runs can exclude undated pages and must be compared separately.',
        ]}


def write_report(report, destination):
    destination = Path(destination)
    destination.mkdir(parents=True, exist_ok=False)
    (destination/'report.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    fields = ['case_id', 'submitted_text', 'event_group', 'expected', 'actual', 'label_status', 'matches_reference',
              'answer_origin', 'source_count', 'reported_tokens', 'latency_ms', 'outcome_reason',
              'reference_source_retrieved', 'reference_source_used', 'evidence_review_status']
    with (destination/'review.csv').open('w', encoding='utf-8', newline='') as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, extrasaction='ignore')
        writer.writeheader()
        writer.writerows(report['cases'])
    esc = lambda text: html.escape(str(text))
    visible = ['case_id', 'submitted_text', 'expected', 'actual', 'matches_reference', 'answer_origin',
               'source_count', 'reported_tokens', 'latency_ms', 'reference_source_retrieved', 'reference_source_used']
    rows = ''.join('<tr>'+''.join('<td>'+esc(r.get(f, ''))+'</td>' for f in visible)+'</tr>' for r in report['cases'])
    page = '<!doctype html><html lang="en"><meta charset="utf-8"><title>TruthGuard fact-check diagnostic</title>'
    page += '<style>body{font:16px system-ui;margin:2rem;color:#172033}table{border-collapse:collapse}td,th{padding:.6rem;border:1px solid #ccd3df;text-align:left}td:nth-child(2){min-width:18rem;max-width:32rem;white-space:pre-line}pre{white-space:pre-wrap}.notice{padding:1rem;background:#fff4ce}tr:nth-child(even){background:#f3f6fa}</style>'
    page += '<h1>TruthGuard fact-check diagnostic</h1><p class="notice">Development results. Release ready: false. These figures do not establish deployment accuracy.</p>'
    page += '<pre>'+esc(json.dumps(report['overall'], indent=2))+'</pre><h2>Individual results</h2><table><thead><tr>'
    page += ''.join('<th>'+esc(f)+'</th>' for f in visible)+'</tr></thead><tbody>'+rows+'</tbody></table>'
    page += '<h2>Limits of this run</h2><ul>'+''.join('<li>'+esc(t)+'</li>' for t in report['limitations'])+'</ul></html>'
    (destination/'report.html').write_text(page, encoding='utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cases', required=True, type=Path)
    parser.add_argument('--predictions', required=True, type=Path)
    parser.add_argument('--reports', required=True, type=Path)
    parser.add_argument('--output-dir', required=True, type=Path)
    args = parser.parse_args()
    report = audit(load(args.cases), load(args.predictions), load(args.reports))
    report['artifacts'] = {name: {'path': str(path), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
        for name, path in [('cases', args.cases), ('predictions', args.predictions), ('reports', args.reports)]}
    write_report(report, args.output_dir)
    print(json.dumps({'status': report['status'], 'release_ready': False, 'overall': report['overall']}, indent=2))


if __name__ == '__main__':
    main()
