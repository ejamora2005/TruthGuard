"""Synthetic calibration plumbing fixtures; these are not real accuracy labels."""
import hashlib
import unittest
from calibrate_evidence_verdicts import calibrate


def fixture(test_correct=80):
    labels, predictions = [], []
    for split in ('calibration', 'test'):
        for i in range(100):
            text = split + str(i)
            correct = i < (80 if split == 'calibration' else test_correct)
            labels.append(dict(case_id=text, task='factual', text=text, label='TRUE' if correct else 'FALSE',
                               event_group=text, split=split, human_reviewed=True, reviewer='synthetic-test', reviewed_at='2026-10-15',
                               evaluation_cutoff='2026-10-15T00:00:00Z', evidence_urls=['https://example.gov/fictional'],
                               review_basis='Synthetic test only', independent_of_development=True, label_source='independent_evidence_review'))
            predictions.append(dict(case_id=text, task='claim', verdict='TRUE', pipeline_identity='a'*64,
                                    evaluation_cutoff='2026-10-15T00:00:00Z', input_sha256=hashlib.sha256(text.encode()).hexdigest()))
    return labels, predictions, dict(scope='Synthetic software fixture only', representative_of_deployment=True, independent_review=True)


class VerdictCalibrationTests(unittest.TestCase):
    def test_fits_only_calibration_labels_and_evaluates_separate_test_labels(self):
        rows, predictions, population = fixture()
        result = calibrate(rows, predictions, population)
        self.assertEqual(result['status'], 'validated_on_supplied_population')
        self.assertAlmostEqual(result['cells']['claim|TRUE']['probability'], 81/102)
        rows, predictions, population = fixture(20)
        failed = calibrate(rows, predictions, population)
        self.assertEqual(failed['status'], 'fitted_not_validated')
        self.assertEqual(result['cells']['claim|TRUE']['probability'], failed['cells']['claim|TRUE']['probability'])

    def test_small_cells_cannot_be_deployed(self):
        rows, predictions, population = fixture()
        r = calibrate(rows[::2], predictions[::2], population)
        self.assertFalse(r['cells']['claim|TRUE']['passed'])

    def test_rejects_old_response_labels_input_mismatch_and_mixed_pipeline_versions(self):
        for mutation in ('labels', 'input', 'pipeline', 'unknown'):
            rows, predictions, population = fixture()
            if mutation == 'labels': rows[0]['label_source'] = 'previous_detector_response'
            elif mutation == 'input': predictions[0]['input_sha256'] = 'b'*64
            elif mutation == 'pipeline': predictions[0]['pipeline_identity'] = 'b'*64
            else: predictions[0]['verdict'] = 'DEFINITELY_TRUE'
            with self.assertRaises(ValueError): calibrate(rows, predictions, population)

    def test_related_events_cannot_inflate_sample_size_or_cross_splits(self):
        rows, predictions, population = fixture()
        rows[1]['event_group'] = rows[0]['event_group']
        with self.assertRaisesRegex(ValueError, 'independent event'): calibrate(rows, predictions, population)
        rows, predictions, population = fixture()
        rows[-1]['event_group'] = rows[0]['event_group']
        with self.assertRaisesRegex(ValueError, 'leakage'): calibrate(rows, predictions, population)

    def test_unresolved_outputs_remain_audited_without_confidence(self):
        rows, predictions, population = fixture()
        for row in predictions: row['verdict'] = 'UNVERIFIED'
        r = calibrate(rows, predictions, population)
        self.assertEqual(r['cells'], {})
        self.assertEqual(r['unscored_count'], 200)
        self.assertEqual(r['status'], 'fitted_not_validated')


if __name__ == '__main__': unittest.main()
