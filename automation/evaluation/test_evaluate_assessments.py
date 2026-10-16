"""Metric fixtures only. Passing these tests is not an accuracy measurement."""
import unittest
from evaluate_assessments import evaluate, fit_isotonic, validate_annotations


def case(i, label='AI_GENERATED', task='ai_generation', split='test'):
    return dict(case_id=str(i), task=task, text=f'Independent example {i}', label=label,
                event_group=f'event-{i}', media_group=f'media-{i}', split=split, reviewer='fixture-reviewer',
                reviewed_at='2026-10-14', human_reviewed=True, provenance='fixture provenance, not real data', evaluation_cutoff='2026-10-14')


def pred(i, verdict='LIKELY_AI_GENERATED', value=.8, task='ai_generation'):
    return dict(case_id=str(i),task=task,verdict=verdict,evaluation_cutoff='2026-10-14',model_version='fixture-v1',
                score_type='normalized_model_output', probabilities={'AI_GENERATED':value,'NOT_AI_GENERATED':1-value})


class AssessmentEvaluationTests(unittest.TestCase):
    def test_known_binary_metrics_and_reliability_bins(self):
        r=evaluate([case(1),case(2,'NOT_AI_GENERATED')],{'before':[pred(1),pred(2,'INCONCLUSIVE',.4)]})['systems']['before']['ai_generation']
        self.assertEqual(r['sample_size'],2)
        self.assertEqual(r['accuracy_including_abstentions'],.5)
        self.assertEqual(r['coverage'],.5)
        self.assertAlmostEqual(r['probability_metrics']['brier_multiclass_sum'],.2)
        self.assertEqual(sum(b['count'] for b in r['probability_metrics']['reliability_bins']),2)
        self.assertEqual(r['errors'][0]['case_id'],'2')

    def test_missing_predictions_invalid_percentages_and_heuristics_fail_closed(self):
        with self.assertRaises(ValueError):evaluate([case(1)],{'x':[]})
        p=pred(1);p['probabilities']['AI_GENERATED']=80
        with self.assertRaises(ValueError):evaluate([case(1)],{'x':[p]})
        p=pred(1);p['score_type']='evidence_strength'
        with self.assertRaises(ValueError):evaluate([case(1)],{'x':[p]})

    def test_group_leakage_human_review_and_provenance_are_enforced(self):
        a,b=case(1,split='calibration'),case(2)
        b['event_group']=a['event_group']
        with self.assertRaisesRegex(ValueError,'leakage'):validate_annotations([a,b])
        b=case(2);b['human_reviewed']=False
        with self.assertRaisesRegex(ValueError,'human review'):validate_annotations([b])
        b=case(2);b['provenance']=''
        with self.assertRaisesRegex(ValueError,'provenance'):validate_annotations([b])

    def test_tasks_and_technical_failures_are_separate(self):
        f=case(2,'TRUE','factual')
        r=evaluate([case(1),f],{'x':[pred(1),dict(case_id='2',task='factual',verdict='ANALYSIS_UNAVAILABLE',evaluation_cutoff='2026-10-14')]})
        self.assertEqual(r['systems']['x']['factual']['inconclusive_and_technical_counts'],{'ANALYSIS_UNAVAILABLE':1})
        self.assertEqual(r['systems']['x']['factual']['probability_metrics']['scored_count'],0)

    def test_isotonic_fit_uses_only_disjoint_calibration_cases(self):
        rows=[case(1,split='calibration'),case(2,'NOT_AI_GENERATED',split='calibration'),case(3)]
        fit=fit_isotonic(rows,[pred(1,value=.2),pred(2,value=.8)],'ai_generation')
        self.assertEqual(fit['status'],'fitted_not_validated')
        self.assertEqual(fit['sample_size'],2)
        self.assertEqual(len(fit['blocks']),1)
        with self.assertRaisesRegex(ValueError,'separate calibration'):fit_isotonic(rows,[pred(1),pred(2),pred(3)],'ai_generation')

    def test_empty_dataset_is_explicitly_unmeasured(self):
        self.assertEqual(evaluate([],{'before':[],'after':[]})['status'],'unmeasured')

    def test_native_capture_claim_task_is_evaluated_as_factual(self):
        row = dict(case_id='1', task='claim', verdict='TRUE', evaluation_cutoff='2026-10-14')
        r = evaluate([case(1, 'TRUE', 'factual')], {'captured': [row]})
        self.assertEqual(r['systems']['captured']['factual']['accuracy_including_abstentions'], 1)


if __name__=='__main__':unittest.main()
