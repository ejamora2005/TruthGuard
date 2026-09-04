## CNN Dataset Layout

This folder is ready for the CNN training pipeline.

Use one of these two approaches:

1. Put already-prepared images into:
   - `train/real`
   - `train/fake`
   - `val/real`
   - `val/fake`
   - `test/real`
   - `test/fake`

2. Keep your raw datasets somewhere else and generate this structure with:

```powershell
cd .\automation
python .\deepfake-models\prepare_dataset.py --manifest .\deepfake-models\dataset_manifest.example.json --output-dir .\datasets\deepfake
```

Notes:
- You should provide the actual dataset files. They are not bundled in this repo.
- Keep train, validation, and test samples separated by source video when possible.
- After this folder is populated, train the CNN with:

```powershell
python .\deepfake-models\train_cnn.py --dataset-dir .\datasets\deepfake --output-model .\models\deepfake-cnn.keras
```
