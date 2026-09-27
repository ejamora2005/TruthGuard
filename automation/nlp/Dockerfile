FROM python:3.11-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    TRANSFORMERS_OFFLINE=1 \
    HF_HUB_OFFLINE=1 \
    TRUTHGUARD_NLP_HOST=0.0.0.0 \
    TRUTHGUARD_NLP_PORT=8765 \
    TRUTHGUARD_NLP_MODEL_DIR=/app/automation/models/filipino-transformer/best_model

WORKDIR /app

COPY automation/nlp/requirements-transformer.txt ./automation/nlp/requirements-transformer.txt

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 \
    && python -m pip install --no-cache-dir --upgrade pip \
    && python -m pip install --no-cache-dir -r automation/nlp/requirements-transformer.txt \
    && rm -rf /var/lib/apt/lists/*

COPY automation/nlp ./automation/nlp
COPY automation/models/filipino-transformer/best_model ./automation/models/filipino-transformer/best_model

WORKDIR /app/automation/nlp

EXPOSE 8765

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
    CMD python -c "import json, urllib.request; data=json.load(urllib.request.urlopen('http://127.0.0.1:8765/health', timeout=5)); raise SystemExit(0 if data.get('ok') and data.get('loaded') else 1)"

CMD ["python", "transformer_api.py"]
