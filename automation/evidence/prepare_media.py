"""Prepare bounded local OCR inputs, using the existing Pillow/OpenCV dependencies.

Never changes the submitted file. Reads a local path from stdin; no URL fetching,
model inference, or claims about media authenticity take place here.
"""
from __future__ import annotations

import base64
import io
import json
import math
from pathlib import Path
import sys
import warnings

from PIL import Image, ImageOps

Image.MAX_IMAGE_PIXELS = 40_000_000
warnings.simplefilter('error', Image.DecompressionBombWarning)


def encode_image(image: Image.Image, edge: int, max_bytes: int) -> dict:
    original_size = image.size
    image = ImageOps.exif_transpose(image)
    image.thumbnail((edge, edge), Image.Resampling.LANCZOS)
    if image.mode in ('RGBA', 'LA') or 'transparency' in image.info:
        rgba = image.convert('RGBA')
        background = Image.new('RGB', rgba.size, 'white')
        background.paste(rgba, mask=rgba.getchannel('A'))
        image = background
    else:
        image = image.convert('RGB')
    for quality in (88, 75, 60):
        output = io.BytesIO()
        image.save(output, format='JPEG', quality=quality, optimize=True)
        data = output.getvalue()
        if len(data) <= max_bytes:
            return {'data': base64.b64encode(data).decode('ascii'),
                    'original_dimensions': list(original_size), 'prepared_dimensions': list(image.size)}
    raise ValueError('Prepared image exceeds the request size budget')


def prepare(path: Path, kind: str, max_bytes: int) -> dict:
    if not path.is_file():
        return {'status': 'missing_media', 'frames': []}
    if kind == 'image':
        with Image.open(path) as image:
            frame = encode_image(image, 2000, max_bytes)
        return {'status': 'completed', 'resized': True, 'frames': [frame],
                'original_dimensions': frame['original_dimensions'], 'prepared_dimensions': frame['prepared_dimensions']}
    if kind != 'video':
        return {'status': 'unsupported_media', 'frames': []}

    import cv2
    capture = cv2.VideoCapture(str(path))
    try:
        if not capture.isOpened():
            return {'status': 'unreadable_video', 'frames': []}
        count = int(capture.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
        fps = float(capture.get(cv2.CAP_PROP_FPS) or 0)
        width = capture.get(cv2.CAP_PROP_FRAME_WIDTH)
        height = capture.get(cv2.CAP_PROP_FRAME_HEIGHT)
        if width * height > Image.MAX_IMAGE_PIXELS:
            return {'status': 'media_dimensions_exceeded', 'frames': []}
        duration = count / fps if count > 0 and math.isfinite(fps) and fps > 0 else None
        indices = sorted(set([0, count // 2, max(0, count - 1)])) if count > 1 else [0]
        frames, times = [], []
        for index in indices:
            if index:
                capture.set(cv2.CAP_PROP_POS_FRAMES, index)
            ok, frame = capture.read()
            if not ok:
                continue
            image = Image.fromarray(cv2.cvtColor(frame, cv2.COLOR_BGR2RGB))
            frames.append(encode_image(image, 1280, max_bytes))
            times.append(round(index / fps, 3) if duration is not None else None)
        return {'status': 'completed' if frames and len(frames) == len(indices) else 'partial' if frames else 'unreadable_video',
                'frames': frames, 'duration_seconds': duration, 'sample_times_seconds': times}
    finally:
        capture.release()


def main():
    try:
        request = json.load(sys.stdin)
        result = prepare(Path(request['path']), request['kind'], max(1024, min(4_194_304, int(request['max_bytes']))))
    except Exception:
        # No file path, content, or raw decoder exception in stdout/stderr diagnostics.
        result = {'status': 'media_preparation_failed', 'frames': []}
    print(json.dumps(result, allow_nan=False))


if __name__ == '__main__':
    main()
