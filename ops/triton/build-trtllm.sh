#!/usr/bin/env bash
set -euo pipefail

TRTLLM_IMAGE="${TRTLLM_IMAGE:-nvcr.io/nvidia/tritonserver:25.10-trtllm-python-py3}"
TRTLLM_EXAMPLES="${TRTLLM_EXAMPLES:-/app/tensorrt_llm/examples}"
HF_MODEL_DIR="${HF_MODEL_DIR:-./hf/llama-8b}"
ENGINE_DIR="${ENGINE_DIR:-./engines}"
QUANT_FORMAT="${QUANT_FORMAT:-fp8}"
ENGINE_NAME="${ENGINE_NAME:-llama-8b-${QUANT_FORMAT}}"
MAX_BATCH_SIZE="${MAX_BATCH_SIZE:-64}"
MAX_INPUT_LEN="${MAX_INPUT_LEN:-8192}"
MAX_SEQ_LEN="${MAX_SEQ_LEN:-8704}"
DRY_RUN="${DRY_RUN:-0}"

inside_container="python3 ${TRTLLM_EXAMPLES}/quantization/quantize.py --model_dir /hf --qformat ${QUANT_FORMAT} --output_dir /ckpt && trtllm-build --checkpoint_dir /ckpt --output_dir /engines/${ENGINE_NAME} --max_batch_size ${MAX_BATCH_SIZE} --max_input_len ${MAX_INPUT_LEN} --max_seq_len ${MAX_SEQ_LEN} --kv_cache_type paged --use_paged_context_fmha enable --gemm_plugin auto"

if [ "${DRY_RUN}" = "1" ]; then
  echo "docker run --gpus all --rm -v ${HF_MODEL_DIR}:/hf:ro -v ${ENGINE_DIR}:/engines ${TRTLLM_IMAGE} bash -c '${inside_container}'"
  exit 0
fi

mkdir -p "${ENGINE_DIR}"

docker run --gpus all --rm -v "${HF_MODEL_DIR}:/hf:ro" -v "${ENGINE_DIR}:/engines" "${TRTLLM_IMAGE}" bash -c "${inside_container}"

echo "engine written to ${ENGINE_DIR}/${ENGINE_NAME}, built for this GPU architecture only"
