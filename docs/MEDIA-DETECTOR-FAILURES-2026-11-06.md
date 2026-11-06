# Direct detector control audit - November 6, 2026

Both packaged image models were run directly on the ten original media-test files, with every SHA-256 checked against the documented origins. Verified-file lookups, cached results, and OpenAI explanations were bypassed. Model weights and thresholds were unchanged. The primary model and calibration artifact match the deployment identities previously reported by the operator; this run used the local Windows CPU runtime.

## Observations

- Primary broad AI-origin model: 8 of these 10 controls classified correctly. It falsely flagged the real sunflower photograph and missed the generated beach/boat image.
- Generated beach: raw synthetic-class output 0.399777; calibrated synthetic-class score 0.224512. The corresponding non-AI class score is 0.775488, reproducing the roughly 78% displayed in the reported production result despite the image's documented AI origin.
- Supporting image CNN: 5 of these 10 classified correctly, with all five documented AI controls missed. It classified every control as real at its packaged threshold.
- The generated lake received a borderline primary raw output of 0.533860. Its raw class prediction is AI, while its calibrated class score is lower; the calibration mapping does not change model class labels.

These are pre-existing development controls, not an independent or representative estimate of deployment accuracy. The observed counts cannot support a claim of 80% or 50% real-world accuracy. Model-card metrics and calibration benchmarks do not erase these failures.

## Consequences

The earlier software commits changed routing, explanations, score calibration handling, and verdict presentation. They did not retrain or replace either model. Primary-class display exposes the primary model's errors. Exact provenance can establish the origin of a matched file but cannot validate detection of unknown uploads. Neither changing labels, lowering thresholds to fit these controls, nor adding these answers to the database is a general detector improvement.

The beach remains a genuine model false negative in direct inference. A general detection improvement requires validating a suitable model or revised inference method on separate held-out images, including modern generated photographs, with false positives and false negatives reported. These ten controls must not be reused as an independent evaluation after tuning on them.

Raw outputs and model identities: [media-detector-controls-2026-11-06.json](evaluation/media-detector-controls-2026-11-06.json).
