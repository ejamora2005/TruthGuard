"""Prepare a source-based review sheet without exposing model predictions."""
import argparse
import csv
import json
from pathlib import Path


def prepare(cases, output):
    # Review decisions, reviewer identity and event groups must be supplied by
    # the actual reviewer, never inferred from a publisher rating or a model.
    columns = ['case_id', 'submitted_text', 'reference_publisher', 'reference_rating', 'reference_urls',
               'label_status', 'review_decision', 'reviewed_text', 'reviewed_verdict', 'review_basis',
               'reviewer', 'reviewed_at', 'event_group', 'duplicate_group', 'media_group', 'near_claim_group',
               'proposed_split', 'independent_of_development', 'evaluation_cutoff']
    rows = []
    seen = set()
    for case in cases:
        if not case.get('case_id') or case['case_id'] in seen:
            raise ValueError('Missing/duplicate case_id')
        seen.add(case['case_id'])
        rows.append({'case_id': case['case_id'], 'submitted_text': case['text'],
                     'reference_publisher': case.get('reference_publisher', ''),
                     'reference_rating': case.get('reference_rating', ''),
                     'reference_urls': json.dumps(case.get('evidence_urls', []), ensure_ascii=False),
                     'label_status': case.get('label_status', 'needs_scope_review'),
                     'evaluation_cutoff': case['evaluation_cutoff']})
    with Path(output).open('x', encoding='utf-8-sig', newline='') as handle:
        writer = csv.DictWriter(handle, fieldnames=columns)
        writer.writeheader()
        writer.writerows(rows)
    return {'status': 'awaiting_independent_review', 'candidate_cases': len(rows), 'reviewed_cases': 0,
            'note': 'No TruthGuard predictions are included. Complete actual evidence review before accepting or relabeling a case.'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cases', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    cases = [json.loads(line) for line in args.cases.read_text(encoding='utf-8-sig').splitlines() if line.strip()]
    print(json.dumps(prepare(cases, args.output), indent=2))


if __name__ == '__main__':
    main()
