"""Synthetic review fixtures test bookkeeping, never model accuracy."""
import copy
import json
from pathlib import Path
import tempfile
import unittest

from evaluate_pipeline import evaluate, prepare, write_report


def archive(case_id='one'):
    return {'case_id': case_id, 'captured_at': '2026-11-09T01:00:00Z',
            'result': {'primary_task': 'claim', 'verdict': 'UNVERIFIED',
                       'extraction': {'original_text': '10 million each?', 'visual_context': 'Red vehicles.'},
                       'summary': '<script>fixture</script>', 'sources': [], 'claims': []},
            'feedback': [{'category': 'verdict_review', 'details': {'review_status': 'unverified_user_report'}}]}


def reviewed(row, statuses, independent=False):
    row = copy.deepcopy(row)
    row.update(human_reviewed=True, reviewer='fixture reviewer', reviewed_at='2026-11-09',
               independent_of_development=independent)
    for stage, status in statuses.items():
        row['stages'][stage].update(status=status, note='Synthetic review reason <script>fixture</script>')
    return row


class PipelineEvaluationTests(unittest.TestCase):
    def test_preparation_preserves_context_and_feedback_but_never_infers_labels(self):
        rows = prepare([archive()])
        self.assertEqual(rows[0]['observed']['extraction']['visual_context'], 'Red vehicles.')
        self.assertEqual(rows[0]['observed']['unverified_feedback'][0]['category'], 'verdict_review')
        self.assertFalse(rows[0]['human_reviewed'])
        result = evaluate([archive()], rows)
        self.assertEqual(result['status'], 'unmeasured')
        self.assertEqual(result['assessed_stage_count'], 0)
        self.assertIsNone(result['stages']['verdict']['reviewer_assessed_pass_rate'])
        self.assertEqual(result['stages']['verdict']['counts']['unreviewed'], 1)

    def test_partial_reviews_keep_denominators_and_origins_separate(self):
        archives = [archive('one'), archive('two'), archive('three')]
        sheets = prepare(archives)
        reviews = [reviewed(sheets[0], {'extraction': 'fail', 'claims': 'fail', 'verdict': 'pass'}, True),
                   reviewed(sheets[1], {'extraction': 'pass', 'claims': 'not_applicable'}), sheets[2]]
        result = evaluate(archives, reviews)
        extraction = result['stages']['extraction']
        self.assertEqual(extraction['reviewer_assessed_pass_rate'], .5)
        self.assertEqual(extraction['assessment_coverage'], 2/3)
        self.assertEqual(result['stages']['claims']['assessed_count'], 1)
        self.assertEqual(result['stages']['claims']['reviewer_assessed_pass_rate'], 0)
        self.assertEqual(result['by_review_origin']['independent']['extraction']['counts']['fail'], 1)
        self.assertEqual(result['by_review_origin']['development']['extraction']['counts']['fail'], 0)
        self.assertEqual(result['failures'][0]['earliest_observed_failed_stage'], 'extraction')
        self.assertEqual(result['failures'][0]['failed_stages'], ['extraction', 'claims'])

    def test_changed_snapshot_missing_case_and_duplicate_case_fail(self):
        original = archive()
        rows = prepare([original])
        changed = copy.deepcopy(original)
        changed['result']['verdict'] = 'TRUE'
        with self.assertRaisesRegex(ValueError, 'different report snapshot'):
            evaluate([changed], rows)
        with self.assertRaisesRegex(ValueError, 'exactly'):
            evaluate([original], [])
        with self.assertRaisesRegex(ValueError, 'duplicate'):
            evaluate([original], rows + rows)

    def test_reviewed_stages_require_person_date_and_reason(self):
        sheet = prepare([archive()])[0]
        sheet['stages']['verdict']['status'] = 'pass'
        with self.assertRaisesRegex(ValueError, 'reason'):
            evaluate([archive()], [sheet])
        sheet['stages']['verdict']['note'] = 'A reason'
        with self.assertRaisesRegex(ValueError, 'human review'):
            evaluate([archive()], [sheet])
        sheet.update(human_reviewed=True, reviewer='fixture', reviewed_at='yesterday')
        with self.assertRaisesRegex(ValueError, 'ISO'):
            evaluate([archive()], [sheet])

    def test_unknown_stage_status_and_bad_evidence_url_fail(self):
        sheet = prepare([archive()])[0]
        sheet['stages']['extra_stage'] = {}
        with self.assertRaisesRegex(ValueError, 'six'):
            evaluate([archive()], [sheet])
        sheet = prepare([archive()])[0]
        sheet['stages']['claims']['status'] = 'probably_ok'
        with self.assertRaisesRegex(ValueError, 'status'):
            evaluate([archive()], [sheet])
        sheet = prepare([archive()])[0]
        sheet['stages']['claims']['evidence_urls'] = ['javascript:fixture']
        with self.assertRaisesRegex(ValueError, 'HTTP'):
            evaluate([archive()], [sheet])

    def test_media_accuracy_is_not_mixed_with_fact_check_stage_reviews(self):
        row = archive()
        row['result']['primary_task'] = 'media'
        with self.assertRaisesRegex(ValueError, 'not media'):
            prepare([row])

    def test_report_escapes_findings_and_refuses_overwrite(self):
        reviews = [reviewed(prepare([archive()])[0], {'evidence': 'fail'})]
        result = evaluate([archive()], reviews)
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder) / 'report'
            write_report(result, target)
            self.assertNotIn('<script>', (target / 'report.html').read_text(encoding='utf-8'))
            self.assertIn('&lt;script&gt;', (target / 'report.html').read_text(encoding='utf-8'))
            self.assertEqual(json.loads((target / 'report.json').read_text())['sample_size'], 1)
            with self.assertRaises(FileExistsError):
                write_report(result, target)

    def test_empty_input_is_unmeasured(self):
        result = evaluate([], [])
        self.assertEqual(result['status'], 'unmeasured')
        self.assertIsNone(result['stages']['retrieval']['assessment_coverage'])


if __name__ == '__main__':
    unittest.main()
