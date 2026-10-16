"""Synthetic unit fixtures test the metric code only, not TruthGuard accuracy."""
import csv
import tempfile
import unittest
from pathlib import Path

from evaluate import FIELDS, evaluate, metrics, read_annotations


class EvaluationTests(unittest.TestCase):
    def test_abstentions_are_in_the_denominator(self):
        labels = [{'claim_id': '1', 'label': 'supported', 'evaluation_cutoff': '2026-01-01'},
                  {'claim_id': '2', 'label': 'refuted', 'evaluation_cutoff': '2026-01-01'}]
        predictions = {'1': {'label': 'supported', 'evaluation_cutoff': '2026-01-01'},
                       '2': {'label': 'insufficient', 'evaluation_cutoff': '2026-01-01'}}
        report = metrics(labels, predictions)
        self.assertEqual(report['accuracy'], .5)
        self.assertEqual(report['abstention_rate'], .5)
        self.assertEqual(report['accuracy_among_answered'], 1)
        self.assertEqual(report['confusion_matrix']['refuted']['insufficient'], 1)
        self.assertIsNone(report['citation_correctness']['fraction_correct'])

    def test_missing_predictions_are_not_silently_dropped(self):
        with self.assertRaises(ValueError):
            metrics([{'claim_id': '1', 'label': 'supported'}], {})

    def test_unreviewed_labels_and_group_leakage_are_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'annotations.csv'
            rows = [{'claim_id': '1', 'claim': 'one', 'event_group': 'event-a', 'evaluation_cutoff': '2026-01-01', 'split': 'train', 'label': 'supported', 'reviewer': 'unit-test-fixture', 'reviewed_at': '2026-01-01', 'human_reviewed': 'true'},
                    {'claim_id': '2', 'claim': 'two', 'event_group': 'event-a', 'evaluation_cutoff': '2026-01-01', 'split': 'test', 'label': 'supported', 'reviewer': 'unit-test-fixture', 'reviewed_at': '2026-01-01', 'human_reviewed': 'true'}]
            def write():
                with path.open('w', newline='') as handle:
                    writer = csv.DictWriter(handle, fieldnames=FIELDS)
                    writer.writeheader()
                    writer.writerows(rows)
            write()
            with self.assertRaisesRegex(ValueError, 'crosses'): read_annotations(path)
            rows[1]['event_group'] = 'event-b'
            rows[1]['human_reviewed'] = 'false'
            write()
            with self.assertRaisesRegex(ValueError, 'human review'): read_annotations(path)

    def test_empty_annotation_template_reports_unmeasured(self):
        template = Path(__file__).with_name('annotations.template.csv')
        self.assertEqual(evaluate(template, [])['status'], 'unmeasured')


if __name__ == '__main__': unittest.main()
