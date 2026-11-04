# Video screening correction

The deployed video model screens sampled human-face crops. It is not a validated detector for all generated videos, animals, animation, motion, or audio.

- Frame samples without a detected human face no longer fall back to whole-frame inference.
- Sampling stays bounded when frame-count metadata is unavailable.
- No usable face samples produce an inconclusive result with no synthetic-class score.
- A below-threshold face-screening result cannot establish non-AI origin. Its raw output stays saved; the public finding is inconclusive.
- Existing negative video reports receive the same read-only presentation correction. Existing wrong-task AI-origin questions show a retry message rather than unrelated factual references.
- Repeated polite requests route to the requested media task. Routing and video policy identities invalidate earlier cache fingerprints.

Local verification: the supplied 151-second video decoded successfully. The deployed model screened 12 usable human-face frames from 16 samples and returned a synthetic-class statistic of 0.3705195 against threshold 0.68. The corrected result is inconclusive, not a finding of human origin. The statistic is not an authenticity probability. The supplied video was not added as a canned matching rule.

Validation: 17 PHP routing/video tests (93 assertions), including upload-to-report behavior, and 4 Python adapter tests passed. Broader suites have six failures that also reproduce with the unchanged application: two media retry assertions, three media explanation/reuse assertions, and one older result-wording assertion. They are not reported as passing checks.

This change prevents unsupported reassurance. Reliable detection of a wider range of generated videos requires a suitable detector and representative video validation data; relabeling low scores as AI-generated would not provide that validation.
