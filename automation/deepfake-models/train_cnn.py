import argparse
import json
from pathlib import Path


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Train a simple CNN deepfake classifier for TruthGuard.")
    parser.add_argument("--dataset-dir", required=True, help="Dataset root with train/real, train/fake and optional val/real, val/fake.")
    parser.add_argument("--output-model", required=True, help="Where to save the trained Keras model.")
    parser.add_argument("--image-size", type=int, default=224, help="Square image size used for training and inference.")
    parser.add_argument("--batch-size", type=int, default=32, help="Batch size for training.")
    parser.add_argument("--epochs", type=int, default=10, help="Number of training epochs.")
    parser.add_argument("--validation-split", type=float, default=0.2, help="Validation split when no val directory exists.")
    parser.add_argument("--seed", type=int, default=42, help="Random seed for dataset splitting.")
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    try:
        import tensorflow as tf
    except Exception as exc:  # pragma: no cover - import guard
        parser.error(
            "TensorFlow is required to train the CNN model. Install it first, for example: "
            f"python -m pip install tensorflow-cpu. Original error: {exc}"
        )
        return 2

    dataset_dir = Path(args.dataset_dir)
    output_model = Path(args.output_model)
    train_dir = dataset_dir / "train"
    val_dir = dataset_dir / "val"

    if not train_dir.is_dir():
        parser.error("Expected a training dataset at <dataset-dir>/train with class folders inside.")
        return 2

    image_size = (args.image_size, args.image_size)
    class_names = ["real", "fake"]

    if val_dir.is_dir():
        train_dataset = tf.keras.utils.image_dataset_from_directory(
            train_dir,
            class_names=class_names,
            image_size=image_size,
            batch_size=args.batch_size,
            label_mode="binary",
            shuffle=True,
            seed=args.seed,
        )
        validation_dataset = tf.keras.utils.image_dataset_from_directory(
            val_dir,
            class_names=class_names,
            image_size=image_size,
            batch_size=args.batch_size,
            label_mode="binary",
            shuffle=False,
            seed=args.seed,
        )
    else:
        train_dataset = tf.keras.utils.image_dataset_from_directory(
            train_dir,
            class_names=class_names,
            validation_split=args.validation_split,
            subset="training",
            seed=args.seed,
            image_size=image_size,
            batch_size=args.batch_size,
            label_mode="binary",
        )
        validation_dataset = tf.keras.utils.image_dataset_from_directory(
            train_dir,
            class_names=class_names,
            validation_split=args.validation_split,
            subset="validation",
            seed=args.seed,
            image_size=image_size,
            batch_size=args.batch_size,
            label_mode="binary",
        )

    normalization = tf.keras.layers.Rescaling(1.0 / 255.0)
    augmentation = tf.keras.Sequential(
        [
            tf.keras.layers.RandomFlip("horizontal"),
            tf.keras.layers.RandomRotation(0.05),
            tf.keras.layers.RandomZoom(0.1),
        ],
        name="augmentation",
    )

    autotune = tf.data.AUTOTUNE
    train_dataset = train_dataset.map(lambda images, labels: (normalization(images), labels)).prefetch(autotune)
    validation_dataset = validation_dataset.map(lambda images, labels: (normalization(images), labels)).prefetch(autotune)

    model = tf.keras.Sequential(
        [
            tf.keras.layers.Input(shape=(args.image_size, args.image_size, 3)),
            augmentation,
            tf.keras.layers.Conv2D(32, 3, activation="relu"),
            tf.keras.layers.MaxPooling2D(),
            tf.keras.layers.Conv2D(64, 3, activation="relu"),
            tf.keras.layers.MaxPooling2D(),
            tf.keras.layers.Conv2D(128, 3, activation="relu"),
            tf.keras.layers.MaxPooling2D(),
            tf.keras.layers.Flatten(),
            tf.keras.layers.Dense(128, activation="relu"),
            tf.keras.layers.Dropout(0.3),
            tf.keras.layers.Dense(1, activation="sigmoid"),
        ],
        name="truthguard_cnn",
    )

    model.compile(
        optimizer=tf.keras.optimizers.Adam(learning_rate=1e-4),
        loss="binary_crossentropy",
        metrics=[
            tf.keras.metrics.BinaryAccuracy(name="accuracy"),
            tf.keras.metrics.Precision(name="precision"),
            tf.keras.metrics.Recall(name="recall"),
        ],
    )

    history = model.fit(
        train_dataset,
        validation_data=validation_dataset,
        epochs=args.epochs,
    )

    output_model.parent.mkdir(parents=True, exist_ok=True)
    model.save(output_model)

    final_train_accuracy = float(history.history.get("accuracy", [0.0])[-1])
    final_val_accuracy = float(history.history.get("val_accuracy", [0.0])[-1])
    final_train_precision = float(history.history.get("precision", [0.0])[-1])
    final_train_recall = float(history.history.get("recall", [0.0])[-1])
    final_val_precision = float(history.history.get("val_precision", [0.0])[-1])
    final_val_recall = float(history.history.get("val_recall", [0.0])[-1])

    def f1_score(precision: float, recall: float) -> float:
        denominator = precision + recall
        return 0.0 if denominator == 0 else (2 * precision * recall) / denominator

    print(
        json.dumps(
            {
                "status": "completed",
                "model_path": str(output_model),
                "train_accuracy": final_train_accuracy,
                "train_precision": final_train_precision,
                "train_recall": final_train_recall,
                "train_f1": f1_score(final_train_precision, final_train_recall),
                "val_accuracy": final_val_accuracy,
                "val_precision": final_val_precision,
                "val_recall": final_val_recall,
                "val_f1": f1_score(final_val_precision, final_val_recall),
                "class_names": class_names,
                "positive_class": "fake",
            }
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
