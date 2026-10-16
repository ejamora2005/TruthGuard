"""Real local decoder checks; these do not measure OCR or factual accuracy."""
import base64
import hashlib
import io
from pathlib import Path
import unittest

from PIL import Image

from prepare_media import encode_image, prepare

FIXTURES = Path(__file__).resolve().parents[2] / 'tests/Fixtures/evidence/media'


class MediaPreparationTests(unittest.TestCase):
    def test_all_video_containers_produce_bounded_first_middle_last_frames(self):
        for suffix in ('webm', 'mp4', 'mov', 'm4v'):
            with self.subTest(suffix=suffix):
                path = FIXTURES / f'notice.{suffix}'
                original = hashlib.sha256(path.read_bytes()).hexdigest()
                result = prepare(path, 'video', 4_194_304)
                self.assertEqual(result['status'], 'completed')
                self.assertEqual(len(result['frames']), 3)
                self.assertEqual(result['sample_times_seconds'], [0, 1.4, 2.8])
                for frame in result['frames']:
                    data = base64.b64decode(frame['data'], validate=True)
                    self.assertLessEqual(len(data), 4_194_304)
                    with Image.open(io.BytesIO(data)) as image:
                        self.assertLessEqual(max(image.size), 1280)
                self.assertEqual(original, hashlib.sha256(path.read_bytes()).hexdigest())

    def test_large_image_is_reduced_with_aspect_ratio_preserved(self):
        image = Image.new('RGB', (4000, 2400), 'white')
        result = encode_image(image, 2000, 4_194_304)
        self.assertEqual(result['original_dimensions'], [4000, 2400])
        self.assertEqual(result['prepared_dimensions'], [2000, 1200])
        self.assertEqual(image.size, (4000, 2400))

    def test_missing_and_unsupported_media_are_explicit(self):
        self.assertEqual(prepare(FIXTURES / 'missing.mp4', 'video', 4096)['status'], 'missing_media')
        self.assertEqual(prepare(FIXTURES / 'notice.pdf', 'audio', 4096)['status'], 'unsupported_media')


if __name__ == '__main__':
    unittest.main()
