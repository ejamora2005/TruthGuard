import json
from pathlib import Path
import sys
import tempfile
import unittest

from PIL import Image

sys.path.insert(0, str(Path(__file__).resolve().parent))
from prepare_public_image_benchmark import prepare


class PreparePublicImageBenchmarkTest(unittest.TestCase):
    def test_builds_balanced_reproducible_publisher_manifest(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "datasets"
            for folder in ("real_RAISE_1k", "sd3_flux/flux", "sd3_flux/sd3_large"):
                (root / folder).mkdir(parents=True)
            for index in range(250):
                for folder, color in (("real_RAISE_1k", (index % 255, 10, 20)),
                                      ("sd3_flux/flux", (20, index % 255, 30)),
                                      ("sd3_flux/sd3_large", (30, 40, index % 255))):
                    Image.new("RGB", (3, 3), color).save(root / folder / f"case-{index:03d}.png")
            output = Path(temporary) / "annotations.jsonl"
            report = prepare(root, output)
            rows = [json.loads(line) for line in output.read_text(encoding="utf-8").splitlines()]
            self.assertEqual(report["cases"], len(rows))
            self.assertTrue(all(row["human_reviewed"] is False for row in rows))
            self.assertTrue(all(row["independent_of_training"] is False for row in rows))
            self.assertTrue(all((output.parent / row["media_path"]).is_file() for row in rows))
            self.assertEqual(len(rows), len({row["input_sha256"] for row in rows}))
            for split in ("calibration", "test"):
                for label in ("NOT_AI_GENERATED", "AI_GENERATED"):
                    self.assertGreaterEqual(sum(row["split"] == split and row["label"] == label for row in rows), 100)


if __name__ == "__main__":
    unittest.main()
