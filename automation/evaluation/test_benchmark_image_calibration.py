"""Synthetic tests of experimental calibration safeguards, never accuracy evidence."""
import copy
import hashlib
import math
import unittest

from benchmark_image_calibration import fit_benchmark, validate_candidates, validate_capture

IDENTITY = {"model_version": "fixture-weights", "config_sha256": "fixture-config",
            "processor_sha256": "fixture-processor", "adapter_version": "fixture-adapter"}


def fixtures():
    rows, predictions = [], []
    for split in ("calibration", "test"):
        for index in range(32):
            key = f"{split}-{index}"
            digest = hashlib.sha256(key.encode()).hexdigest()
            y = index % 8 < 3 or index % 8 == 7
            rows.append({"case_id": key, "task": "ai_generation", "label": "AI_GENERATED" if y else "NOT_AI_GENERATED",
                         "split": split, "event_group": key, "media_group": key, "media_path": key + ".png",
                         "input_sha256": digest, "provenance": "synthetic fixture only", "evidence_urls": ["fixture"],
                         "human_reviewed": False, "independent_of_training": False,
                         "candidate_source": "fixture-ai" if y else "fixture-real"})
            predictions.append({"case_id": key, "input_sha256": digest, "model_identity": IDENTITY.copy(),
                                "fake_probability": .99 if index % 8 < 4 else .01})
    return rows, predictions


class ExperimentalCalibrationTests(unittest.TestCase):
    def test_fits_known_temperature_without_claiming_review_or_deployment_validation(self):
        rows, predictions = fixtures()
        original = copy.deepcopy(rows)
        report = fit_benchmark(rows, predictions, IDENTITY)
        self.assertAlmostEqual(report["temperature"], math.log(99) / math.log(3), places=5)
        self.assertEqual(report["status"], "experimental_benchmark_only")
        self.assertFalse(report["deployment_eligible"])
        self.assertFalse(report["validation"]["passed"])
        self.assertFalse(report["review_status"]["independent_human_review_complete"])
        self.assertFalse(report["review_status"]["training_independence_confirmed"])
        self.assertEqual(rows, original)
        self.assertLess(report["benchmark_validation"]["after"]["log_loss"], report["benchmark_validation"]["before"]["log_loss"])
        self.assertFalse(report["benchmark_validation"]["sufficient_candidate_groups"])

    def test_test_labels_cannot_change_fitted_temperature(self):
        rows, predictions = fixtures()
        before = fit_benchmark(rows, predictions, IDENTITY)
        for row in rows:
            if row["split"] == "test":
                row["label"] = "AI_GENERATED" if row["label"] == "NOT_AI_GENERATED" else "NOT_AI_GENERATED"
        after = fit_benchmark(rows, predictions, IDENTITY)
        self.assertEqual(before["temperature"], after["temperature"])
        self.assertNotEqual(before["benchmark_validation"]["after"]["log_loss"], after["benchmark_validation"]["after"]["log_loss"])
        for row, prediction in zip(rows, predictions):
            if row["split"] == "test":
                row["label"] = "AI_GENERATED" if prediction["fake_probability"] > .5 else "NOT_AI_GENERATED"
        rejected = fit_benchmark(rows, predictions, IDENTITY)
        self.assertEqual(before["temperature"], rejected["temperature"])
        self.assertFalse(rejected["benchmark_validation"]["held_out_improvement"])

    def test_duplicate_originals_and_related_groups_cannot_cross_splits(self):
        for field in ("case_id", "input_sha256", "event_group", "media_group"):
            rows, _ = fixtures()
            rows[-1][field] = rows[0][field]
            with self.subTest(field=field), self.assertRaises(ValueError):
                validate_candidates(rows)

    def test_rejects_missing_provenance_or_one_class_split(self):
        for field in ("evidence_urls", "provenance", "media_path"):
            rows, _ = fixtures()
            rows[0][field] = None
            with self.subTest(field=field), self.assertRaises(ValueError):
                validate_candidates(rows)
        rows, _ = fixtures()
        for row in rows:
            if row["split"] == "test":
                row["label"] = "AI_GENERATED"
        with self.assertRaises(ValueError):
            validate_candidates(rows)

    def test_resume_cannot_reuse_changed_model_images_invalid_or_duplicate_scores(self):
        for change in ("model", "image", "nan", "out_of_range", "boolean", "duplicate", "unknown"):
            rows, predictions = fixtures()
            if change == "model":
                predictions[0]["model_identity"]["adapter_version"] = "changed"
            elif change == "image":
                predictions[0]["input_sha256"] = "0" * 64
            elif change == "duplicate":
                predictions.append(predictions[0])
            elif change == "unknown":
                predictions[0]["case_id"] = "unknown"
            else:
                predictions[0]["fake_probability"] = {"nan": float("nan"), "out_of_range": 2, "boolean": True}[change]
            with self.subTest(change=change), self.assertRaises(ValueError):
                validate_capture(rows, predictions, IDENTITY)

    def test_partial_capture_can_resume_but_cannot_be_fitted(self):
        rows, predictions = fixtures()
        self.assertEqual(len(validate_capture(rows, predictions[:3], IDENTITY)), 3)
        with self.assertRaisesRegex(ValueError, "incomplete"):
            fit_benchmark(rows, predictions[:3], IDENTITY)


if __name__ == "__main__":
    unittest.main()
