"""Run the Filipino transformer classifier from the command line."""
from __future__ import annotations

import argparse
import json
from pathlib import Path

from transformer_inference import PredictionSettings, TruthGuardFilipinoClassifier, default_model_dir


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model-dir", type=Path, default=default_model_dir())
    parser.add_argument("--text", help="Article text to classify")
    parser.add_argument("--text-file", type=Path, help="UTF-8 article file to classify")
    parser.add_argument("--uncertain-threshold", type=float, default=0.70)
    parser.add_argument("--min-words", type=int, default=40)
    parser.add_argument("--max-length", type=int, default=256)
    args = parser.parse_args()

    if args.text_file and args.text:
        parser.error("Use either --text or --text-file, not both.")

    if args.text_file:
        article = args.text_file.read_text(encoding="utf-8-sig")
    elif args.text:
        article = args.text
    else:
        parser.error("Provide --text or --text-file.")

    classifier = TruthGuardFilipinoClassifier(args.model_dir)
    settings = PredictionSettings(
        uncertain_threshold=args.uncertain_threshold,
        min_words=args.min_words,
        max_length=args.max_length,
    )

    print(json.dumps(classifier.predict(article, settings), indent=2))


if __name__ == "__main__":
    main()
