#!/usr/bin/env bash
set -euo pipefail

repository="${LLAMA_MODEL_REPO:-bartowski/Meta-Llama-3.1-8B-Instruct-GGUF}"
revision="${LLAMA_MODEL_REVISION:-main}"
remote_file="${LLAMA_MODEL_REMOTE_FILE:-Meta-Llama-3.1-8B-Instruct-Q4_K_M.gguf}"
local_file="${LLAMA_MODEL_LOCAL_FILE:-llama-3.1-8b-instruct-q4_k_m.gguf}"
expected_sha256="${LLAMA_MODEL_SHA256:-}"
target_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/models"
target="${target_directory}/${local_file}"
url="https://huggingface.co/${repository}/resolve/${revision}/${remote_file}"

mkdir -p "${target_directory}"

if [[ -f "${target}" ]]; then
    echo "${target} already exists; delete it to download again."
else
    echo "Downloading ${url}"
    curl --fail --location --continue-at - --output "${target}.part" "${url}"
    mv "${target}.part" "${target}"
fi

actual_sha256="$(shasum -a 256 "${target}" | cut -d ' ' -f 1)"

if [[ -z "${expected_sha256}" ]]; then
    echo "sha256 ${actual_sha256}"
    echo "LLAMA_MODEL_SHA256 is not set, so this download was not verified. Pin ${repository} at a commit in LLAMA_MODEL_REVISION and set LLAMA_MODEL_SHA256 to this value."
    exit 0
fi

if [[ "${actual_sha256}" != "${expected_sha256}" ]]; then
    echo "sha256 mismatch for ${target}: expected ${expected_sha256}, got ${actual_sha256}" >&2
    exit 1
fi

echo "sha256 verified: ${actual_sha256}"
