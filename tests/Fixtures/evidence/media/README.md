# Synthetic upload fixtures

These tiny image, video, and selectable-text PDF files contain a fictional Example City library announcement. They are regression inputs, not real evidence, current-event assertions, or accuracy labels. The videos contain no audio and last three seconds.

`EvidenceSubmissionCoverageTest` sends the actual files through upload validation, local image/video/PDF preparation, persistence, and report generation. External OCR, claim planning, search, and evidence-assessment responses are explicitly mocked. Separate topic fixtures test general routing and outcomes, not the language model's real-world accuracy.

The local media tests require the existing Pillow/OpenCV Python dependencies and `pdftotext`. Live extraction smoke checks, when run, are reported separately from these mocks.
