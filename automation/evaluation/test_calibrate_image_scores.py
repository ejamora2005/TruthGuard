"""Synthetic algorithm fixtures only; these are not image accuracy measurements."""
import copy
import hashlib
import math
import unittest

from calibrate_image_scores import calibrate, fit_temperature, prepare, transform
from test_evaluate_assessments import case, pred


POPULATION = {'scope': 'Synthetic unit-test population only', 'representative_of_deployment': True,
              'training_overlap_checked': True, 'reviewer': 'synthetic-fixture'}
IDENTITY = {'model_version': 'fixture-weights', 'config_sha256': 'fixture-config',
            'processor_sha256': 'fixture-processor', 'adapter_version': 'fixture-adapter'}


def fixtures(n=400):
    annotations, predictions = [], []
    for split in ('calibration', 'test'):
        for i in range(n):
            key = f'{split}-{i}'
            p = .99 if i % 8 < 4 else .01
            # 75% correct at either score: perfect target temperature is known.
            y = (i % 8 < 3) or (i % 8 == 7)
            a = case(key, 'AI_GENERATED' if y else 'NOT_AI_GENERATED', split=split)
            a.update(input_sha256=hashlib.sha256(key.encode()).hexdigest(), independent_of_training=True)
            r = pred(key, value=p)
            r.update(input_sha256=a['input_sha256'], model_identity=IDENTITY.copy())
            annotations.append(a)
            predictions.append(r)
    return annotations, predictions


class ImageCalibrationTests(unittest.TestCase):
    def test_temperature_matches_known_analytical_solution_and_preserves_class_order(self):
        points = [(.99, 1)]*3+[(.99, 0)]+[(.01, 0)]*3+[(.01, 1)]
        t = fit_temperature(points)
        self.assertAlmostEqual(t, math.log(99)/math.log(3), places=5)
        self.assertAlmostEqual(transform(.99, t), .75, places=6)
        self.assertAlmostEqual(transform(.01, t), .25, places=6)
        for p in (0, .01, .4, .5, .8, .99, 1):
            self.assertEqual(transform(p, t) > .5, p > .5)
        self.assertAlmostEqual(transform(.9, 2), .75, places=12)

    def test_fit_never_reads_test_labels_and_evaluation_records_improvement(self):
        annotations, predictions = fixtures()
        result = calibrate(annotations, predictions, POPULATION)
        self.assertEqual(result['status'], 'validated_on_supplied_population')
        self.assertFalse(result['validation']['fit_used_test_data'])
        self.assertLess(result['validation']['after']['log_loss'], result['validation']['before']['log_loss'])
        self.assertLess(result['validation']['paired_log_loss_delta_95pct_interval'][1], 0)
        changed = copy.deepcopy(annotations)
        for a in changed:
            if a['split'] == 'test':
                a['label'] = 'NOT_AI_GENERATED' if a['label'] == 'AI_GENERATED' else 'AI_GENERATED'
        second = calibrate(changed, predictions, POPULATION)
        self.assertEqual(result['temperature'], second['temperature'])
        self.assertNotEqual(result['validation']['after']['log_loss'], second['validation']['after']['log_loss'])

    def test_tiny_smoke_sets_cannot_be_exported_as_validated(self):
        annotations, predictions = fixtures(8)
        result = calibrate(annotations, predictions, POPULATION)
        self.assertEqual(result['status'], 'fitted_not_validated')
        self.assertFalse(result['validation']['sufficient_data'])

    def test_test_performance_can_reject_a_fitted_temperature(self):
        annotations, predictions = fixtures()
        for a, r in zip(annotations, predictions):
            if a['split'] == 'test':
                a['label'] = 'AI_GENERATED' if r['probabilities']['AI_GENERATED'] > .5 else 'NOT_AI_GENERATED'
        result = calibrate(annotations, predictions, POPULATION)
        self.assertEqual(result['status'], 'fitted_not_validated')
        self.assertFalse(result['validation']['held_out_improvement'])

    def test_original_hash_group_model_and_training_leakage_are_rejected(self):
        for mutate in ('hash', 'group', 'model', 'training', 'probability', 'missing'):
            annotations, predictions = fixtures(8)
            if mutate == 'hash':
                annotations[-1]['input_sha256'] = annotations[0]['input_sha256']
            elif mutate == 'group':
                annotations[-1]['media_group'] = annotations[0]['media_group']
            elif mutate == 'model':
                predictions[-1]['model_identity']['model_version'] = 'another-model'
            elif mutate == 'training':
                annotations[-1]['independent_of_training'] = False
            elif mutate == 'probability':
                predictions[-1]['probabilities']['AI_GENERATED'] = float('nan')
            else:
                predictions.pop()
            with self.subTest(mutate=mutate), self.assertRaises(ValueError):
                prepare(annotations, predictions)

    def test_repeated_event_images_cannot_inflate_independent_sample_count(self):
        annotations, predictions = fixtures()
        for a in annotations:
            a['event_group'] = a['split']+'-single-event'
        result = calibrate(annotations, predictions, POPULATION)
        self.assertFalse(result['validation']['sufficient_data'])

    def test_unknown_population_invalid_scores_or_temperatures_are_rejected(self):
        annotations, predictions = fixtures(8)
        with self.assertRaises(ValueError):
            calibrate(annotations, predictions, {})
        for p in (float('nan'), -1, 2, True):
            with self.assertRaises(ValueError):
                transform(p, 1)
        for t in (0, -1, float('inf'), True):
            with self.assertRaises(ValueError):
                transform(.8, t)


if __name__ == '__main__':
    unittest.main()
