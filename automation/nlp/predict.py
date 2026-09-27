"""Run the local text-classification baseline on an article file."""
import argparse
import json
from pathlib import Path

import joblib
from train_filipino import normalize

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--model', required=True, type=Path, help='Only load a trusted local joblib file')
parser.add_argument('--text-file', required=True, type=Path)
args = parser.parse_args()
text = normalize(args.text_file.read_text(encoding='utf-8-sig'))
if not text:
    parser.error('The article file is empty')
model = joblib.load(args.model)
scores = model.predict_proba([text])[0]
print(json.dumps({
    'dataset_label': str(model.classes_[scores.argmax()]),
    'class_scores': dict(zip(map(str, model.classes_), map(float, scores))),
    'note': 'Dataset classification only; not a factual verdict or calibrated truth probability.',
}, indent=2))
