"""CPU baseline for Fake News Filipino; never modifies the source CSV."""
import argparse
import csv
import hashlib
import json
import platform
import re
import unicodedata
import warnings
from collections import Counter, defaultdict
from pathlib import Path

import joblib
import numpy as np
import sklearn
from sklearn.exceptions import ConvergenceWarning
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix, f1_score
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline


def normalize(text):
    return re.sub(r"\s+", " ", unicodedata.normalize("NFKC", text)).strip().casefold()


def evaluate(model, rows):
    truth = [r['label'] for r in rows]
    predictions = model.predict([r['article'] for r in rows])
    return {
        'accuracy': accuracy_score(truth, predictions),
        'macro_f1': f1_score(truth, predictions, average='macro'),
        'confusion_matrix_label_order': list(model.classes_),
        'confusion_matrix': confusion_matrix(truth, predictions, labels=model.classes_).tolist(),
        'classification_report': classification_report(truth, predictions, output_dict=True, zero_division=0),
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--data', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path, help='New directory for this run')
    args = parser.parse_args()
    warnings.filterwarnings('error', category=ConvergenceWarning)
    with args.data.open(encoding='utf-8-sig', newline='') as handle:
        reader = csv.DictReader(handle)
        if not {'label', 'article'}.issubset(reader.fieldnames or []):
            raise ValueError('CSV must contain label and article columns')
        raw = list(reader)
    groups = defaultdict(list)
    blank = 0
    for index, row in enumerate(raw):
        text = normalize(row['article'])
        label = row['label'].strip()
        if not text or not label:
            blank += 1
            continue
        if label not in {'0', '1'}:
            raise ValueError(f'Unexpected label at row {index + 2}: {label}')
        digest = hashlib.sha256(text.encode()).hexdigest()
        groups[digest].append({'source_row': index + 2, 'sha256': digest, 'article': text, 'label': label})
    conflicts = [g for g in groups.values() if len({r['label'] for r in g}) > 1]
    rows = [g[0] for g in groups.values() if len({r['label'] for r in g}) == 1]
    train, held_out = train_test_split(rows, test_size=.3, random_state=42, stratify=[r['label'] for r in rows])
    validation, test = train_test_split(held_out, test_size=.5, random_state=42, stratify=[r['label'] for r in held_out])
    splits = {'train': train, 'validation': validation, 'test': test}
    hashes = [set(r['sha256'] for r in split) for split in splits.values()]
    assert not any(hashes[i] & hashes[j] for i in range(3) for j in range(i + 1, 3))
    args.output.mkdir(parents=True, exist_ok=False)
    for name, split in splits.items():
        with (args.output / f'{name}.csv').open('w', encoding='utf-8', newline='') as handle:
            writer = csv.DictWriter(handle, fieldnames=['source_row', 'sha256', 'article', 'label'])
            writer.writeheader()
            writer.writerows(split)
    print('Split sizes:', {k: len(v) for k, v in splits.items()}, flush=True)
    best_model, best_score, best_c = None, -1, None
    candidates = []
    for c in [.5, 1., 2.]:
        model = Pipeline([
            ('tfidf', TfidfVectorizer(ngram_range=(1, 2), min_df=2, max_df=.98, max_features=40000, sublinear_tf=True, dtype=np.float32)),
            ('classifier', LogisticRegression(C=c, solver='liblinear', max_iter=1000, random_state=42)),
        ])
        model.fit([r['article'] for r in train], [r['label'] for r in train])
        metrics = evaluate(model, validation)
        candidates.append({'C': c, 'validation': metrics})
        print(f'C={c}: validation macro-F1={metrics["macro_f1"]:.4f}', flush=True)
        if metrics['macro_f1'] > best_score:
            best_model, best_score, best_c = model, metrics['macro_f1'], c
    # Freeze the validation-selected model before inspecting the test results.
    test_metrics = evaluate(best_model, test)
    joblib.dump(best_model, args.output / 'model.joblib', compress=3)
    restored = joblib.load(args.output / 'model.joblib')
    test_text = [r['article'] for r in test]
    assert np.array_equal(best_model.predict(test_text), restored.predict(test_text))
    majority = Counter(r['label'] for r in train).most_common(1)[0][0]
    labels = [r['label'] for r in test]
    report = {
        'dataset': 'Fake News Filipino', 'source_sha256': hashlib.sha256(args.data.read_bytes()).hexdigest(),
        'seed': 42, 'raw_rows': len(raw), 'blank_rows_removed': blank,
        'conflicting_rows_removed': sum(map(len, conflicts)),
        'duplicate_rows_removed': sum(len(g) - 1 for g in groups.values() if len({r['label'] for r in g}) == 1),
        'split_counts': {k: dict(Counter(r['label'] for r in v)) for k, v in splits.items()},
        'versions': {'python': platform.python_version(), 'sklearn': sklearn.__version__, 'numpy': np.__version__, 'joblib': joblib.__version__},
        'selected_C': best_c, 'candidates': candidates, 'test': test_metrics,
        'majority_baseline': {'accuracy': accuracy_score(labels, [majority] * len(labels)), 'macro_f1': f1_score(labels, [majority] * len(labels), average='macro')},
        'saved_model_reload_check': 'passed',
        'limitations': ['Random article split, not publisher/topic/time-held-out.', 'Exact normalized duplicates removed; near duplicates may remain.', 'Text classification is not evidence-based fact verification.', 'Scores are not calibrated probabilities of factual truth.', 'Other downloaded datasets were not mixed into this run.'],
    }
    (args.output / 'metrics.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    with (args.output / 'test_predictions.csv').open('w', encoding='utf-8', newline='') as handle:
        writer = csv.writer(handle)
        writer.writerow(['source_row', 'true_label', 'predicted_label'])
        writer.writerows((r['source_row'], r['label'], p) for r, p in zip(test, restored.predict(test_text)))
    print(json.dumps({'test': test_metrics, 'output': str(args.output)}, indent=2), flush=True)


if __name__ == '__main__':
    main()
