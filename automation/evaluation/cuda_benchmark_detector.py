"""CUDA-only FP32 benchmark adapter; never used by the application CPU detector.

The original checkpoint is unchanged. FP32 avoids requiring native BF16 hardware
on GPUs such as T4. Different numerical execution gets a separate capture identity.
"""
from importlib.metadata import version

ADAPTER_VERSION = "broad-ai-origin-cuda-fp32-v1"


def execution_profile():
    import torch
    if not torch.cuda.is_available():
        raise RuntimeError("CUDA GPU unavailable. In Colab select Runtime > Change runtime type > GPU.")
    return {"device": "cuda", "gpu_name": torch.cuda.get_device_name(0),
            "compute_capability": list(torch.cuda.get_device_capability(0)),
            "precision": "float32", "tf32": False, "cuda_version": torch.version.cuda,
            "libraries": {name: version(name) for name in
                          ("torch", "torchvision", "transformers", "timm", "peft", "accelerate", "numpy", "Pillow")}}


def load_detector(directory):
    import torch
    from PIL import Image, ImageOps
    from broad_ai_origin import BroadDetector

    execution_profile()  # Explicitly fail instead of accidentally doing a slow CPU run.
    torch.backends.cuda.matmul.allow_tf32 = False
    torch.backends.cudnn.allow_tf32 = False
    torch.set_float32_matmul_precision("highest")

    class CudaBenchmarkDetector(BroadDetector):
        def __init__(self, path):
            super().__init__(path)
            self.model = self.model.float().to("cuda").eval()

        def predict(self, path):
            with Image.open(path) as original:
                original.load()
                image = ImageOps.exif_transpose(original).convert("RGB")
            a = self.processor(images=image, return_tensors="pt")["pixel_values"].to("cuda", dtype=torch.float32)
            b = self.transform(image).unsqueeze(0).to("cuda", dtype=torch.float32)
            with torch.inference_mode():
                logits = self.model(a, b)
            if logits.numel() != 1 or not torch.isfinite(logits).all():
                raise ValueError("Invalid CUDA classifier output")
            return float(logits.sigmoid().item())

    return CudaBenchmarkDetector(directory)
