"""Local inference architecture adapted from Bombek1's MIT-licensed release.

Source: https://huggingface.co/Bombek1/ai-image-detector-siglip-dinov2
Revision: 6455cf791436ee914c9556ab71578cce9761fef7
Only constructors differ: initialize from bundled configs instead of downloading
pretrained backbones, then load all released checkpoint weights without changes.
"""
import os
os.environ.setdefault('USE_TF', '0')
os.environ.setdefault('USE_FLAX', '0')
import json
import hashlib
from pathlib import Path
import torch
import numpy as np
from torch import nn
import timm
from peft import LoraConfig, get_peft_model
from accelerate import init_empty_weights
from transformers import SiglipImageProcessor, SiglipVisionConfig, SiglipVisionModel
from torchvision import transforms
from PIL import Image, ImageOps


class LoRALinear(nn.Module):
    def __init__(self, original, rank, alpha, dropout):
        super().__init__()
        self.original = original
        self.scaling = alpha / rank
        self.lora_A = nn.Linear(original.in_features, rank, bias=False)
        self.lora_B = nn.Linear(rank, original.out_features, bias=False)
        self.dropout = nn.Dropout(dropout)

    def forward(self, x):
        return self.original(x) + self.lora_B(self.lora_A(self.dropout(x))) * self.scaling


class ClassificationHead(nn.Module):
    def __init__(self, input_dim):
        super().__init__()
        self.head = nn.Sequential(nn.LayerNorm(input_dim), nn.Linear(input_dim, 512), nn.GELU(),
            nn.Dropout(.3), nn.Linear(512, 256), nn.GELU(), nn.Dropout(.3), nn.Linear(256, 1))

    def forward(self, x):
        return self.head(x).squeeze(-1)


class Ensemble(nn.Module):
    def __init__(self, directory, config):
        super().__init__()
        cfg = json.loads((directory/'siglip_config.json').read_text(encoding='utf-8'))['vision_config']
        self.siglip = SiglipVisionModel(SiglipVisionConfig(**cfg)).to(torch.bfloat16)
        self.dinov2 = timm.create_model(config.get('dinov2_model','vit_large_patch14_dinov2.lvd142m'),
            pretrained=False, num_classes=0, img_size=config.get('image_size',392))
        self.classifier = ClassificationHead(self.siglip.config.hidden_size+self.dinov2.num_features)
        rank,alpha,dropout = config.get('lora_rank',32),config.get('lora_alpha',64),config.get('lora_dropout',.1)
        self.siglip = get_peft_model(self.siglip,LoraConfig(r=rank,lora_alpha=alpha,
            target_modules=['q_proj','v_proj'],lora_dropout=dropout,bias='none'))
        for _,module in self.dinov2.named_modules():
            if hasattr(module,'qkv') and isinstance(module.qkv,nn.Linear):
                module.qkv=LoRALinear(module.qkv,rank,alpha,dropout)

    def forward(self, a,b):
        features=self.siglip(pixel_values=a).pooler_output
        return self.classifier(torch.cat([features.float(),self.dinov2(b)],dim=-1))


class BroadDetector:
    def __init__(self, directory):
        self.directory=Path(directory)
        torch.set_num_threads(max(1,int(os.getenv('AI_ORIGIN_CPU_THREADS','4'))))
        digest=hashlib.sha256()
        with (self.directory/'pytorch_model.pt').open('rb') as file:
            while chunk:=file.read(1024*1024): digest.update(chunk)
        self.weight_hash=digest.hexdigest()
        manifest=json.loads((self.directory/'source.json').read_text())
        if self.weight_hash!=manifest['sha256']:
            raise ValueError('Checkpoint does not match the pinned release')
        for name, expected in manifest['configuration_sha256'].items():
            if hashlib.sha256((self.directory/name).read_bytes()).hexdigest()!=expected:
                raise ValueError('Bundled model configuration changed: '+name)
        # Released metadata contains NumPy scalar metrics. Keep restricted loading;
        # allow only these numeric types, never arbitrary checkpoint classes.
        with torch.serialization.safe_globals([np.dtype,np.dtypes.Float64DType,np.dtypes.Float32DType,np._core.multiarray.scalar]):
            checkpoint=torch.load(self.directory/'pytorch_model.pt',map_location='cpu',weights_only=True,mmap=True)
        self.config=checkpoint.get('config',{})
        # Allocate architecture placeholders, then assign the released tensors.
        # This avoids a second multi-GB set of random parameters on an 8GB PC.
        with init_empty_weights():
            self.model=Ensemble(self.directory,self.config)
        self.model.load_state_dict(checkpoint['model_state_dict'],strict=True,assign=True)
        del checkpoint
        self.model.eval()
        self.processor=SiglipImageProcessor.from_pretrained(self.directory,local_files_only=True)
        self.transform=transforms.Compose([
            transforms.Resize((self.config.get('image_size',392),)*2,interpolation=transforms.InterpolationMode.BICUBIC),
            transforms.ToTensor(),transforms.Normalize(mean=[.485,.456,.406],std=[.229,.224,.225])])

    def predict(self,path):
        with Image.open(path) as original:
            original.load()
            image=ImageOps.exif_transpose(original).convert('RGB')
        a=self.processor(images=image,return_tensors='pt')['pixel_values'].to(torch.bfloat16)
        b=self.transform(image).unsqueeze(0)
        with torch.inference_mode():
            logits=self.model(a,b)
        if logits.numel()!=1 or not torch.isfinite(logits).all():
            raise ValueError('Invalid classifier output')
        return float(logits.sigmoid().item())
