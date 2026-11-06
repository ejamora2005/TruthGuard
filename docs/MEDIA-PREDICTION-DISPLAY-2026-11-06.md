# Primary detector verdict display

Completed media analyses with a valid saved class and finite detector score now display the primary prediction as Likely AI-Generated or Likely Not AI-Generated. This presentation applies to existing saved reports without another inference/API call. It does not alter stored historical origin-resolution findings, model thresholds, calibration, or detector outputs.

Disagreement, weak signals, compressed/composite input, and video sampling limitations stay in the explanation. Prediction is explicitly distinguished from proof of origin. Invalid, failed, and unsupported analyses, including videos without usable face samples, do not receive an invented binary prediction. Factual claim verdicts are unaffected.

Calibration is retained for the displayed class score. Public JSON includes media_prediction to identify this presentation and the underlying unresolved origin diagnostic. Repeated public projection preserves the same verdict.

Verification: 61 PHP tests, 463 assertions passed, including saved-report HTML/JSON rendering, both classes, weak scores, disagreement, repeated projection, unsupported video inputs, and calibration safeguards. Tests establish software behavior, not improved empirical detector accuracy.
