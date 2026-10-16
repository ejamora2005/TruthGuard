"""Synthetic archive integrity tests; no images, model inference or network calls."""
import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

from benchmark_image_calibration import fit_benchmark
from build_colab_calibration import FILES
from review_colab_results import ADAPTER_VERSION, MEMBERS, ROOT, read_archive, require_close, verify
from test_benchmark_image_calibration import fixtures


class ColabResultReviewTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.model = self.folder / "model"
        self.model.mkdir()
        for name in ("pytorch_model.pt", "config.json", "preprocessor_config.json"):
            (self.model / name).write_bytes(b"synthetic unit fixture, not model weights")
        sha = lambda name: hashlib.sha256((self.model / name).read_bytes()).hexdigest()
        self.identity = {"model_version": "sha256:" + sha("pytorch_model.pt"),
                         "config_sha256": sha("config.json"), "processor_sha256": sha("preprocessor_config.json"),
                         "adapter_version": ADAPTER_VERSION}
        (self.model / "source.json").write_text(json.dumps({"sha256": sha("pytorch_model.pt")}))
        rows, scores = fixtures()
        for row in scores:
            row["model_identity"] = self.identity.copy()
        self.contents = {"annotations.jsonl": self.jsonl(rows), "scores.jsonl": self.jsonl(scores), "colab-run.log": b"fixture"}
        self.report = fit_benchmark(rows, scores, self.identity)
        self.report["execution_profile"] = {"device": "cuda", "precision": "float32", "gpu_name": "synthetic"}
        self.report["input_hashes"] = {"annotations": hashlib.sha256(self.contents["annotations.jsonl"]).hexdigest(),
                                       "predictions": hashlib.sha256(self.contents["scores.jsonl"]).hexdigest()}
        self.contents["benchmark-calibration.json"] = json.dumps(self.report).encode()
        self.contents["run-plan.json"] = json.dumps({"model_identity": self.identity, "cases": len(rows),
            "execution_profile": self.report["execution_profile"],
            "annotations_sha256": self.report["input_hashes"]["annotations"]}).encode()
        self.contents["progress.json"] = json.dumps({"phase": "completed", "captured": len(rows), "total": len(rows),
            "deployment_eligible": False, "status": self.report["status"], "temperature": self.report["temperature"]}).encode()
        bundle_contents = {name: (ROOT / name).read_bytes() for name in FILES}
        bundle_contents["calibration/annotations.jsonl"] = self.contents["annotations.jsonl"]
        manifest = {"cases": len(rows), "files": {name: hashlib.sha256(data).hexdigest() for name, data in bundle_contents.items()}}
        bundle_contents["bundle-manifest.json"] = json.dumps(manifest).encode()
        self.bundle = self.folder / "bundle.zip"
        self.zip(self.bundle, bundle_contents)

    @staticmethod
    def jsonl(rows):
        return "".join(json.dumps(row) + "\n" for row in rows).encode()

    @staticmethod
    def zip(path, contents):
        with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as archive:
            for name, data in contents.items():
                archive.writestr(name, data)

    def test_complete_capture_is_verified_without_becoming_live_confidence(self):
        archive = self.folder / "result.zip"
        self.zip(archive, self.contents)
        review = verify(read_archive(archive), self.bundle, self.model)
        self.assertEqual(review["images"], 64)
        self.assertTrue(all(review["checks"].values()))
        self.assertFalse(review["deployment_eligible"])
        self.assertFalse(review["live_settings_changed"])
        self.assertEqual(review["paid_api_calls"], 0)
        self.assertEqual(review["new_inference_runs"], 0)

    def test_unsafe_unexpected_and_duplicate_archive_entries_are_rejected(self):
        for name in ("../outside.txt", "subdir/progress.json", "unexpected.py"):
            contents = dict(self.contents)
            contents[name] = contents.pop("progress.json")
            archive = self.folder / "result.zip"
            self.zip(archive, contents)
            with self.subTest(name=name), self.assertRaises(ValueError):
                read_archive(archive)
        self.zip(archive, self.contents)
        with zipfile.ZipFile(archive, "a") as stream:
            import warnings
            with warnings.catch_warnings():
                warnings.simplefilter("ignore", UserWarning)
                stream.writestr("progress.json", b"{}")
        with self.assertRaises(ValueError):
            read_archive(archive)

    def test_partial_or_changed_scores_cannot_be_imported(self):
        for change in ("partial", "checksum", "model", "probability"):
            contents = dict(self.contents)
            scores = [json.loads(line) for line in contents["scores.jsonl"].decode().splitlines()]
            if change == "partial":
                scores.pop()
            elif change == "model":
                scores[0]["model_identity"]["adapter_version"] = "cpu"
            elif change == "probability":
                scores[0]["fake_probability"] = 2
            else:
                scores[0]["fake_probability"] = .5
            contents["scores.jsonl"] = self.jsonl(scores)
            with self.subTest(change=change), self.assertRaises(ValueError):
                verify(contents, self.bundle, self.model)

    def test_report_edits_and_incomplete_progress_cannot_be_imported(self):
        for change in ("metric", "live", "progress"):
            contents = dict(self.contents)
            report = copy.deepcopy(self.report)
            if change == "metric":
                report["benchmark_validation"]["after"]["log_loss"] = .00001
            elif change == "live":
                report["deployment_eligible"] = True
            else:
                progress = json.loads(contents["progress.json"])
                progress["phase"] = "scoring"
                contents["progress.json"] = json.dumps(progress).encode()
            contents["benchmark-calibration.json"] = json.dumps(report).encode()
            with self.subTest(change=change), self.assertRaises(ValueError):
                verify(contents, self.bundle, self.model)

    def test_changed_checkpoint_and_changed_bundle_are_rejected(self):
        (self.model / "pytorch_model.pt").write_bytes(b"changed")
        with self.assertRaisesRegex(ValueError, "checkpoint"):
            verify(self.contents, self.bundle, self.model)
        with zipfile.ZipFile(self.bundle) as archive:
            contents = {name: archive.read(name) for name in archive.namelist()}
        contents["calibration/annotations.jsonl"] += b"\n"
        self.zip(self.bundle, contents)
        with self.assertRaisesRegex(ValueError, "bundle changed"):
            verify(self.contents, self.bundle, self.model)

    def test_numeric_noise_is_tolerated_but_counts_and_material_changes_are_not(self):
        require_close(.757461938, .757461929)
        for actual, expected in ((.75, .7), (100.0, 100), (True, 1), (float("nan"), .5)):
            with self.subTest(actual=actual), self.assertRaises(ValueError):
                require_close(actual, expected)


if __name__ == "__main__":
    unittest.main()
