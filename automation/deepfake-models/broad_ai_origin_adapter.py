import hashlib
import json
import os
import sys
import time
from pathlib import Path
from broad_ai_origin import BroadDetector


def main():
    started=time.monotonic()
    try:
        request=json.loads(sys.stdin.read().lstrip('\ufeff'))
        path=Path(request['media_path'])
        directory=Path(os.getenv('AI_ORIGIN_MODEL_PATH','automation/models/ai-origin-broad'))
        digest=hashlib.sha256(path.read_bytes()).hexdigest()
        manifest=json.loads((directory/'source.json').read_text())
        if manifest['revision']!='6455cf791436ee914c9556ab71578cce9761fef7':
            raise ValueError('Unrecognized model release')
        detector=BroadDetector(directory)
        probability=detector.predict(path)
        if hashlib.sha256(path.read_bytes()).hexdigest()!=digest:
            raise ValueError('Input changed during inference')
        result={'status':'completed','media_type':'image','model_family':'siglip2_dinov2',
            'model_name':'OpenFake SigLIP2 + DINOv2 AI-origin detector',
            'label':'fake' if probability>.5 else 'real','fake_probability':probability,'threshold':.5,
            'screening_score':round(probability*100),
            'input_sha256':digest,'model_version':'sha256:'+detector.weight_hash,
            'model_revision':manifest['revision'],'label_mapping':['real','fake'],
            'output_contract':{'activation':'sigmoid','positive_class':'fake','binary_exclusive':True,'validated_at_inference':True},
            'config_sha256':hashlib.sha256((directory/'config.json').read_bytes()).hexdigest(),
            'processor_sha256':hashlib.sha256((directory/'preprocessor_config.json').read_bytes()).hexdigest(),
            'preprocessing':{'color_mode':'RGB','exif_orientation':'transpose','processor':'bundled SigLIP2 and DINOv2 transforms'},
            'elapsed_ms':int((time.monotonic()-started)*1000),'calibration':'not_established','adapter_version':'broad-ai-origin-v1'}
        print(json.dumps(result),flush=True)
        return 0
    except Exception as error:
        print(json.dumps({'status':'unavailable','failure_code':type(error).__name__}),flush=True)
        print(str(error),file=sys.stderr)
        return 1


if __name__=='__main__':
    raise SystemExit(main())
