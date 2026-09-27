# TruthGuard local NLP baseline

Standalone CPU training for the downloaded Fake News Filipino CSV. This is a
TF-IDF + logistic-regression article classifier, not a transformer or an
evidence-based fact-checker. It is not connected to Laravel's detection pipeline.

## Colab transformer run

The stronger Colab model is a multilingual DistilBERT classifier trained on the
same Fake News Filipino data after duplicate removal. The recorded held-out test
result was 91.13% macro-F1.

Recorded run:

```text
/content/drive/MyDrive/TruthGuard-NLP/runs/filipino-fakenews-20260926-160607/best_model
```

Put that exported `best_model` folder at:

```text
automation/models/filipino-transformer/best_model
```

The production model artifacts in `automation/models` are tracked with Git LFS,
so a fresh Docker build can include them without placing large binary weights in
normal Git objects.

The project mapping for this dataset is:

| Dataset label | TruthGuard label |
| --- | --- |
| `0` | Likely Real News |
| `1` | Likely Fake News |

The dataset card exposes labels as numeric strings only. This mapping was chosen
after inspecting local examples: label `0` looks like mainstream news copy, and
label `1` looks like fake-source/blog-style political articles. Keep this note
near the model so the labels are not accidentally flipped later.

### Run the transformer API

Install dependencies in a Python environment:

```powershell
python -m pip install -r automation/nlp/requirements-transformer.txt
```

Start the API:

```powershell
python automation/nlp/transformer_api.py
```

The service listens on `http://127.0.0.1:8765` by default. To use another model
folder:

```powershell
$env:TRUTHGUARD_NLP_MODEL_DIR = "C:/path/to/best_model"
python automation/nlp/transformer_api.py
```

Health check:

```powershell
Invoke-RestMethod http://127.0.0.1:8765/health
```

Prediction:

```powershell
Invoke-RestMethod `
  -Method Post `
  -Uri http://127.0.0.1:8765/predict `
  -ContentType "application/json" `
  -Body (@{
    article = "Mahabang Filipino news article dito..."
  } | ConvertTo-Json)
```

Short text is intentionally returned as `Uncertain`, because this model was
trained on article-length examples. Use claim-only models such as FEVER or
AVeriTeC for short claim experiments later.

### Run the transformer CLI

```powershell
python automation/nlp/transformer_predict.py --text-file C:/Datasets/article.txt
```

## Run from the repository root

The initial run used the existing Python installation; no packages were changed.
For a new environment, install `automation/nlp/requirements.txt` first.

```powershell
python automation/nlp/train_filipino.py --data C:/Datasets/NLP_Datasets/fakenews/full.csv --output storage/app/nlp/filipino-baseline-new
```

Use a new output directory for each run; existing runs are never overwritten.
The source CSV is read-only. All generated data and weights go under ignored
`storage/app/nlp/`, not into Git. Back up that directory separately if needed.

## Data preparation and evaluation

- Normalize Unicode, case and whitespace; preserve Filipino words and punctuation.
- Remove exact normalized duplicates before splitting. Remove all examples of
  a text if its labels conflict. Reject unexpected labels.
- Stratified 70/15/15 train/validation/test split, seed 42. Assert no normalized
  article hash overlaps between splits. These are custom splits, not official
  benchmark splits.
- Fit the TF-IDF vocabulary only on training text, with at most 40,000 unigram
  and bigram features.
- Compare regularization values 0.5, 1 and 2 using validation macro-F1.
- Evaluate the selected model on the test set once. Do not tune against this
  reported test score. The saved model remains trained on the training split.
- Reload saved weights and verify identical predictions for every test example.

## Initial run: 2026-09-26

Output: `storage/app/nlp/filipino-baseline-20260926/`

| Item | Result |
| --- | --- |
| Input articles | 3,206 |
| Exact normalized duplicate rows removed | 201 |
| Conflicting or blank rows | 0 |
| Train / validation / test | 2,103 / 451 / 451 |
| Selected C | 2.0 |
| Validation macro-F1 | 94.68% |
| Test accuracy | 96.01% (433 of 451) |
| Test macro-F1 | 96.01% |

Generated files: `model.joblib`, `metrics.json`, `train.csv`, `validation.csv`,
`test.csv`, and `test_predictions.csv`. The metrics include a source-file hash,
runtime versions, class counts, per-class scores, confusion matrix, validation
candidates, and a majority-class baseline.

## Predict on a UTF-8 article file

```powershell
python automation/nlp/predict.py --model storage/app/nlp/filipino-baseline-20260926/model.joblib --text-file C:/Datasets/article.txt
```

Only load trusted model files: joblib deserialization can execute Python code.
Scores are classifier outputs, not calibrated probabilities of factual truth.

## Limits and remaining work

Random article splits can share topics, publisher language, and near duplicates.
The dataset sources its classes from different publisher groups, so high scores
may reflect publisher/style cues. There is no publisher/date metadata in the
downloaded CSV to support a publisher-held-out or chronological split. Independent,
recent, manually verified examples are needed before any application integration.

LIAR, AVeriTeC and FEVER are not used in this run. They have different tasks and
label definitions and must not be merged by simply mapping their labels to binary
values. The local FEVER download also lacks its referenced Wikipedia evidence.

Dataset source and documentation:
https://huggingface.co/datasets/jcblaise/fake_news_filipino
The current dataset card lists its license as unknown; resolve dataset usage
terms before distributing data or using the resulting model commercially.
