"""Offline packaging and backend-separation tests; no CUDA accuracy claim."""
import hashlib
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import zipfile

from benchmark_image_calibration import validate_capture
from build_colab_calibration import FILES, build, portable_annotations
from colab_calibration_support import safe_extract, verify_bundle
from cuda_benchmark_detector import ADAPTER_VERSION, execution_profile
from test_benchmark_image_calibration import fixtures, IDENTITY


class ColabCalibrationTests(unittest.TestCase):
    def test_portability_preserves_split_group_labels_hashes_and_review_flags(self):
        rows, _ = fixtures()
        for row in rows:
            row["candidate_source"] = "flux" if row["label"] == "AI_GENERATED" else "raise_camera"
            row["media_path"] = "C:\\Downloads\\source\\" + row["case_id"] + ".png"
        portable = portable_annotations(rows)
        for original, new in zip(rows, portable):
            self.assertTrue(new["media_path"].startswith("../datasets/"))
            self.assertNotIn("C:", new["media_path"])
            self.assertEqual({k: v for k, v in original.items() if k != "media_path"},
                             {k: v for k, v in new.items() if k != "media_path"})
        self.assertTrue(rows[0]["media_path"].startswith("C:"))

    def test_bundle_has_only_whitelisted_code_configs_and_portable_annotations(self):
        rows, _ = fixtures()
        for row in rows:
            row["candidate_source"] = "flux" if row["label"] == "AI_GENERATED" else "raise_camera"
        with tempfile.TemporaryDirectory() as temporary:
            temporary = Path(temporary)
            annotations = temporary / "annotations.jsonl"
            annotations.write_text("".join(json.dumps(row) + "\n" for row in rows))
            notebook, bundle = build(annotations, temporary / "output")
            document = json.loads(notebook.read_text())
            self.assertIn(hashlib.sha256(bundle.read_bytes()).hexdigest(), notebook.read_text())
            self.assertEqual(document["nbformat"], 4)
            self.assertEqual(document["metadata"]["accelerator"], "GPU")
            for cell in document["cells"]:
                if cell["cell_type"] == "code" and not cell["source"].startswith("%pip"):
                    compile(cell["source"], cell["id"], "exec")
            with zipfile.ZipFile(bundle) as archive:
                self.assertEqual(set(archive.namelist()), set(FILES) | {"calibration/annotations.jsonl", "bundle-manifest.json"})
                self.assertNotIn(".env", archive.namelist())
                self.assertFalse(any(name.endswith("pytorch_model.pt") for name in archive.namelist()))
            extracted = temporary / "extracted"
            safe_extract(bundle, extracted)
            self.assertEqual(verify_bundle(extracted)["cases"], len(rows))
            (extracted / FILES[0]).write_text("tampered")
            with self.assertRaisesRegex(ValueError, "changed"):
                verify_bundle(extracted)

    def test_archive_traversal_and_symlinks_are_rejected_before_extraction(self):
        with tempfile.TemporaryDirectory() as temporary:
            temporary = Path(temporary)
            for name in ("../escape", "/absolute", "C:/escape", "folder\\..\\..\\escape"):
                archive = temporary / "unsafe.zip"
                with zipfile.ZipFile(archive, "w") as stream:
                    stream.writestr(name, "unsafe")
                with self.subTest(name=name), self.assertRaises(ValueError):
                    safe_extract(archive, temporary / "out")
            info = zipfile.ZipInfo("link")
            info.external_attr = 0o120777 << 16
            with zipfile.ZipFile(archive, "w") as stream:
                stream.writestr(info, "/outside")
            with self.assertRaisesRegex(ValueError, "symlinks"):
                safe_extract(archive, temporary / "out")

    def test_cuda_profile_fails_explicitly_without_gpu(self):
        fake_torch = SimpleNamespace(cuda=SimpleNamespace(is_available=lambda: False))
        with patch.dict("sys.modules", {"torch": fake_torch}), self.assertRaisesRegex(RuntimeError, "Change runtime type"):
            execution_profile()

    def test_cpu_scores_cannot_resume_in_cuda_capture(self):
        rows, predictions = fixtures()
        gpu_identity = dict(IDENTITY, adapter_version=ADAPTER_VERSION)
        with self.assertRaisesRegex(ValueError, "identity changed"):
            validate_capture(rows, predictions, gpu_identity)


if __name__ == "__main__":
    unittest.main()
