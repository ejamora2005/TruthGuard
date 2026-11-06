import hashlib
import unittest

from audit_fact_checks import audit, metrics


def fixture(key, expected, actual, origin='research'):
    text = 'Synthetic metric fixture '+key
    case = {'case_id': key, 'task': 'factual', 'text': text, 'event_group': key,
            'expected_verdict': expected, 'evaluation_cutoff': '2026-11-01T00:00:00Z',
            'label_status': 'source_checked_development'}
    prediction = {'case_id': key, 'task': 'claim', 'verdict': actual, 'pipeline_identity': 'fixture',
                  'input_sha256': hashlib.sha256(text.encode()).hexdigest(),
                  'evaluation_cutoff': case['evaluation_cutoff'], 'answer_origin': origin,
                  'api_usage': {'total_tokens': 100}, 'api_budget': {'reported_tokens': 100},
                  'latency_ms': 1000, 'source_count': 1}
    archive = {'case_id': key, 'result': {'verdict': actual, 'summary': 'Fixture only.', 'sources': []}}
    return case, prediction, archive


def combine(*fixtures):
    return audit(*[list(rows) for rows in zip(*fixtures)])


class AuditTests(unittest.TestCase):
    def test_abstention_cannot_inflate_accuracy_or_hide_unresolved_answerable_cases(self):
        report = combine(fixture('one', 'TRUE', 'TRUE'), fixture('two', 'FALSE', 'UNVERIFIED'),
                         fixture('three', 'TRUE', 'FALSE'))
        m = report['overall']
        self.assertAlmostEqual(m['decisive_reference_agreement'], .5)
        self.assertAlmostEqual(m['coverage_on_answerable_reference_cases'], 2/3)
        self.assertAlmostEqual(m['wrong_false_rate_on_true_references'], .5)
        self.assertEqual(m['reported_tokens'], 300)  # Usage and budget are not double-counted.
        self.assertEqual(m['reported_tokens_per_correct_decisive_reference'], 300)
        self.assertFalse(report['release_ready'])
        self.assertEqual(report['status'], 'development_diagnostic_not_deployment_accuracy')

    def test_unknown_reference_does_not_silently_become_a_correct_or_wrong_label(self):
        rows = list(fixture('one', None, 'FALSE'))
        rows[0]['label_status'] = 'needs_scope_review'
        report = combine(rows)
        self.assertEqual(report['overall']['comparable_reference_cases'], 0)
        self.assertIsNone(report['overall']['decisive_reference_agreement'])
        self.assertEqual(report['overall']['coverage_all_inputs'], 1)

    def test_saved_answers_are_reported_separately_from_research(self):
        report = combine(fixture('one', 'FALSE', 'FALSE', 'saved_answer'), fixture('two', 'TRUE', 'UNVERIFIED'))
        self.assertEqual(report['by_answer_origin']['saved_answer']['decisive_reference_agreement'], 1)
        self.assertIsNone(report['by_answer_origin']['research']['decisive_reference_agreement'])

    def test_incomplete_and_tampered_runs_fail_closed(self):
        c, p, a = fixture('one', 'TRUE', 'TRUE')
        with self.assertRaisesRegex(ValueError, 'archive'):
            audit([c], [p], [])
        p['input_sha256'] = 'wrong'
        with self.assertRaisesRegex(ValueError, 'hash mismatch'):
            audit([c], [p], [a])

    def test_human_review_cannot_be_claimed_by_changing_only_a_status(self):
        c, p, a = fixture('one', 'TRUE', 'TRUE')
        c['label_status'] = 'independent_review'
        with self.assertRaisesRegex(ValueError, 'actual review records'):
            audit([c], [p], [a])

    def test_zero_denominators_and_missing_usage_remain_unmeasured(self):
        self.assertIsNone(metrics([])['decisive_reference_agreement'])
        c, p, a = fixture('one', 'TRUE', 'ANALYSIS_UNAVAILABLE')
        del p['api_usage'], p['api_budget']
        result = audit([c], [p], [a])['overall']
        self.assertEqual(result['technical_failures'], 1)
        self.assertEqual(result['cases_without_token_measurement'], 1)
        self.assertIsNone(result['reported_tokens_per_correct_decisive_reference'])

    def test_source_trace_distinguishes_retrieval_from_use_and_keeps_material_url_queries(self):
        c, p, a = fixture('one', 'FALSE', 'UNVERIFIED')
        c['evidence_urls'] = ['https://www.example.com/?p=123&utm_source=feed']
        a['result']['diagnostics'] = {'candidate_records': [
            {'url': 'https://example.com/?p=456', 'retrieval_status': 'retrieved'}]}
        report = audit([c], [p], [a])
        self.assertFalse(report['cases'][0]['reference_source_retrieved'])
        a['result']['diagnostics']['candidate_records'][0]['url'] = 'https://example.com/?p=123'
        report = audit([c], [p], [a])
        self.assertTrue(report['cases'][0]['reference_source_retrieved'])
        self.assertFalse(report['cases'][0]['reference_source_used'])
        self.assertEqual(report['overall']['reference_source_retrieved_but_unused'], 1)

    def test_live_cutoffs_require_explicit_policy_and_archive_binding(self):
        c, p, a = fixture('one', 'TRUE', 'TRUE')
        c['cutoff_policy'] = p['cutoff_policy'] = 'capture_time'
        p['declared_evaluation_cutoff'] = c['evaluation_cutoff']
        p['evaluation_cutoff'] = a['evaluation_cutoff'] = '2026-11-01T01:00:00Z'
        a['captured_at'] = '2026-11-01T01:01:00Z'
        report = audit([c], [p], [a])
        self.assertEqual(report['cases'][0]['evaluation_cutoff'], p['evaluation_cutoff'])
        a['evaluation_cutoff'] = '2026-11-01T00:59:59Z'
        with self.assertRaisesRegex(ValueError, 'Live cutoff/archive mismatch'):
            audit([c], [p], [a])


if __name__ == '__main__':
    unittest.main()
