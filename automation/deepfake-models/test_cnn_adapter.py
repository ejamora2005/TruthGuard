import copy
import importlib.util
import json
import unittest
from pathlib import Path
from types import SimpleNamespace

import numpy as np
from PIL import Image, ImageOps

spec = importlib.util.spec_from_file_location('cnn_adapter', Path(__file__).with_name('cnn_adapter.py'))
adapter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(adapter)


class AdapterContractTest(unittest.TestCase):
    def setUp(self):
        self.config = json.loads((Path(__file__).parents[1] / 'models/truthguard_image_model_config.json').read_text())
        self.model = SimpleNamespace(input_shape=(None, 224, 224, 3), output_shape=(None, 1),
                                     layers=[SimpleNamespace(activation=SimpleNamespace(__name__='sigmoid'))])

    def test_video_without_detected_faces_never_scores_full_frames(self):
        import cv2
        import tempfile
        from unittest.mock import patch
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'no-faces.avi'
            writer = cv2.VideoWriter(str(path), cv2.VideoWriter_fourcc(*'MJPG'), 10, (64, 64))
            for _ in range(20):
                writer.write(np.zeros((64, 64, 3), dtype='uint8'))
            writer.release()
            class NeverPredict:
                def predict(self, *args, **kwargs):
                    raise AssertionError('Out-of-scope full frames must not reach the face model')
            scope = {}
            with patch.object(adapter, 'crop_largest_face', side_effect=lambda image, *args: (image, False)):
                _, used, error = adapter.predict_video_probability(NeverPredict(), path, 224,
                    '0_to_1_float32', 8, 1, 'top5_mean', True, .2, np, Image, scope)
            self.assertIsNone(error)
            self.assertEqual(0, used)
            self.assertEqual(8, scope['sampled_frames'])
            self.assertEqual(0, scope['face_frames'])

    def test_deployed_mapping_is_positive_fake_and_reversed_config_fails_closed(self):
        self.assertTrue(adapter.valid_model_contract(self.model, self.config, 224, '0_to_255_float32'))
        altered = copy.deepcopy(self.config)
        altered['label_mapping'] = {'0': 'fake', '1': 'real'}
        self.assertFalse(adapter.valid_model_contract(self.model, altered, 224, '0_to_255_float32'))
        for score in (0.1, 0.474, 0.475, 0.9):
            returned = adapter.extract_fake_probability([[score]], np)
            self.assertAlmostEqual(score, returned, places=6)
            self.assertEqual(float(np.float32(score)) >= self.config['threshold'], returned >= self.config['threshold'])

    def test_invalid_scores_shape_and_double_normalization_are_rejected(self):
        for value in ([], [float('nan')], [float('inf')], [1.5], [-0.1], [0.1, 0.9]):
            with self.assertRaises(ValueError):
                adapter.extract_fake_probability(value, np)
        self.assertFalse(adapter.valid_model_contract(self.model, self.config, 224, '0_to_1_float32'))
        self.model.input_shape = (None, 128, 128, 3)
        self.assertFalse(adapter.valid_model_contract(self.model, self.config, 224, '0_to_255_float32'))

    def test_rgb_channels_dimensions_range_and_orientation(self):
        image = Image.new('RGB', (3, 5), (255, 32, 0))
        image.putpixel((0, 0), (0, 0, 255))
        image.getexif()[274] = 6
        batch = adapter.preprocess_pil_image(image, 224, '0_to_255_float32', np)
        expected = np.asarray(ImageOps.exif_transpose(image).convert('RGB').resize((224, 224), Image.Resampling.BICUBIC), dtype='float32')
        self.assertEqual((1, 224, 224, 3), batch.shape)
        self.assertEqual(np.float32, batch.dtype)
        np.testing.assert_array_equal(expected, batch[0])
        self.assertEqual(255, batch.max())
        self.assertEqual(0, batch.min())


if __name__ == '__main__':
    unittest.main()
