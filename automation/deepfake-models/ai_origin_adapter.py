"""Pinned, offline SigLIP inference; never substitutes an LLM or a filename label."""
import hashlib
import json
import os
import sys
import time
from pathlib import Path

os.environ.setdefault('USE_TF', '0')
os.environ.setdefault('USE_FLAX', '0')


def main():
    started = time.monotonic()
    request = json.loads(sys.stdin.read().lstrip('\ufeff'))
    path = Path(request['media_path'])
    model_dir = Path(os.getenv('AI_ORIGIN_MODEL_PATH', 'automation/models/ai-origin-siglip'))
    try:
        import torch
        from PIL import Image, ImageOps
        from transformers import AutoImageProcessor, AutoModelForImageClassification
        torch.set_num_threads(max(1, int(os.getenv('AI_ORIGIN_CPU_THREADS', '4'))))
        processor = AutoImageProcessor.from_pretrained(model_dir, local_files_only=True, trust_remote_code=False, use_fast=False)
        model = AutoModelForImageClassification.from_pretrained(model_dir, local_files_only=True, trust_remote_code=False, use_safetensors=True)
        if model.config.id2label != {0: 'ai', 1: 'hum'} or model.config.problem_type != 'single_label_classification':
            raise ValueError('Unsupported class/output contract')
        model.eval()
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        with Image.open(path) as original:
            original.load()
            dimensions = original.size
            image = ImageOps.exif_transpose(original).convert('RGB')
            inputs = processor(images=image, return_tensors='pt')
        with torch.inference_mode():
            logits = model(**inputs).logits
            if tuple(logits.shape) != (1, 2) or not torch.isfinite(logits).all():
                raise ValueError('Invalid output')
            scores = torch.softmax(logits, dim=-1)[0]
        if hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise ValueError('Input changed during inference')
        probability = float(scores[0])
        output = {'status': 'completed', 'media_type': 'image', 'label': 'fake' if probability >= .5 else 'real',
                  'fake_probability': probability, 'threshold': .5, 'input_sha256': digest,
                  'original_dimensions': list(dimensions), 'elapsed_ms': int((time.monotonic()-started)*1000),
                  'model_name': 'AI-origin SigLIP classifier', 'model_family': 'siglip',
                  'model_version': 'sha256:'+hashlib.sha256((model_dir/'model.safetensors').read_bytes()).hexdigest(),
                  'config_sha256': hashlib.sha256((model_dir/'config.json').read_bytes()).hexdigest(),
                  'processor_sha256': hashlib.sha256((model_dir/'preprocessor_config.json').read_bytes()).hexdigest(),
                  'label_mapping': ['ai', 'hum'],
                  'output_contract': {'activation': 'softmax', 'positive_class': 'ai', 'binary_exclusive': True,
                                      'validated_at_inference': True},
                  'preprocessing': {'tensor_shape': list(inputs['pixel_values'].shape), 'color_mode': 'RGB',
                                    'processor': 'bundled SiglipImageProcessor', 'exif_orientation': 'transpose'},
                  'calibration': 'not_established', 'adapter_version': 'ai-origin-v1'}
    except Exception as error:
        print(json.dumps({'status':'unavailable','failure_code':type(error).__name__}), flush=True)
        print(str(error), file=sys.stderr)
        return 1
    print(json.dumps(output), flush=True)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
