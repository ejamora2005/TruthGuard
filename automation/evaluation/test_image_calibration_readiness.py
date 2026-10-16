"""Preflight guard tests with tiny synthetic files; no model or network calls."""
import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from check_image_calibration_readiness import inspect


class CalibrationReadinessTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.model = self.root / 'model'
        self.model.mkdir()
        names = ('pytorch_model.pt', 'config.json', 'preprocessor_config.json', 'siglip_config.json')
        hashes = {}
        for name in names:
            data = ('synthetic fixture only: ' + name).encode()
            (self.model / name).write_bytes(data)
            hashes[name] = hashlib.sha256(data).hexdigest()
        (self.model / 'source.json').write_text(json.dumps({'sha256': hashes['pytorch_model.pt'],
            'configuration_sha256': {name: hashes[name] for name in names if name != 'pytorch_model.pt'}}))
        self.identity = {'model_version': 'sha256:' + hashes['pytorch_model.pt'], 'config_sha256': hashes['config.json'],
                         'processor_sha256': hashes['preprocessor_config.json'], 'adapter_version': 'fixture-cpu'}
        self.rows, self.scores = [], []
        for split in ('calibration', 'test'):
            for label in ('AI_GENERATED', 'NOT_AI_GENERATED'):
                for index in range(100):
                    key = f'{split}-{label}-{index}'
                    data = key.encode()
                    (self.root / key).write_bytes(data)
                    sha = hashlib.sha256(data).hexdigest()
                    self.rows.append({'case_id': key, 'task': 'ai_generation', 'label': label, 'split': split,
                        'event_group': key, 'media_path': key, 'input_sha256': sha, 'provenance': 'synthetic fixture',
                        'evidence_urls': ['fixture'], 'human_reviewed': True, 'independent_of_training': True,
                        'reviewer': 'synthetic reviewer', 'reviewed_at': '2026-01-01T00:00:00Z',
                        'evaluation_cutoff': '2026-01-01T00:00:00Z'})
                    self.scores.append({'case_id': key, 'task': 'ai_generation', 'input_sha256': sha,
                        'model_identity': self.identity.copy(), 'evaluation_cutoff': '2026-01-01T00:00:00Z',
                        'score_type': 'normalized_model_output', 'probabilities': {'AI_GENERATED': .8, 'NOT_AI_GENERATED': .2}})
        self.population = {'scope': 'Synthetic test population only', 'representative_of_deployment': True,
                           'training_overlap_checked': True, 'reviewer': 'synthetic reviewer'}

    def run_check(self):
        for name, rows in (('annotations.jsonl', self.rows), ('scores.jsonl', self.scores)):
            (self.root / name).write_text(''.join(json.dumps(row) + '\n' for row in rows), encoding='utf-8')
        (self.root / 'population.json').write_text(json.dumps(self.population), encoding='utf-8')
        return inspect(self.root / 'annotations.jsonl', self.root / 'scores.jsonl', self.root / 'population.json',
                       self.model, 'fixture-cpu')

    def test_ready_records_only_allow_fitting_and_never_enable_deployment(self):
        before = copy.deepcopy((self.rows, self.scores, self.population))
        result = self.run_check()
        self.assertEqual(result['status'], 'ready_for_fit')
        self.assertFalse(result['deployment_eligible'])
        self.assertFalse(result['live_settings_changed'])
        self.assertEqual(result['paid_api_calls'], 0)
        self.assertEqual((self.rows, self.scores, self.population), before)

    def test_reports_missing_files_unreviewed_data_and_runtime_mismatch_together(self):
        (self.root / self.rows[0]['media_path']).unlink()
        self.rows[0]['human_reviewed'] = False
        self.scores[0]['model_identity']['adapter_version'] = 'fixture-cuda'
        self.population['representative_of_deployment'] = False
        result = self.run_check()
        self.assertEqual(result['status'], 'blocked')
        self.assertTrue({'original_files', 'recorded_review', 'deployment_capture', 'reviewed_population'} <= set(result['blockers']))

    def test_changed_original_and_changed_pinned_configuration_are_detected(self):
        (self.root / self.rows[0]['media_path']).write_bytes(b'changed')
        (self.model / 'siglip_config.json').write_bytes(b'changed')
        result = self.run_check()
        self.assertEqual(result['checks']['original_files']['detail']['hash_mismatches'], 1)
        self.assertIn('pinned_model_files', result['blockers'])

    def test_incomplete_or_wrong_task_prediction_cannot_pass(self):
        missing = self.scores.pop()
        self.assertIn('deployment_capture', self.run_check()['blockers'])
        self.scores.append(missing)
        self.scores[0]['task'] = 'factual'
        self.assertIn('production_prediction_schema', self.run_check()['blockers'])

    def test_experimental_gpu_score_shape_does_not_become_a_production_record(self):
        self.scores = [{'case_id': row['case_id'], 'input_sha256': row['input_sha256'],
                        'model_identity': self.identity.copy(), 'fake_probability': .8} for row in self.rows]
        result = self.run_check()
        self.assertTrue(result['checks']['deployment_capture']['passed'])
        self.assertIn('production_prediction_schema', result['blockers'])


if __name__ == '__main__':
    unittest.main()
