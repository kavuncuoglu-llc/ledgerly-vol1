#!/usr/bin/env bash
# Usage: ops/cloud/gpu-session.sh [--down]   (run on a rented Ubuntu 24.04 NVIDIA instance, from any directory)
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_DIR="${REPO_ROOT}/ops/compose"
ENV_FILE="${REPO_ROOT}/.env"
HEALTH_TIMEOUT_SECONDS="${GPU_SESSION_TIMEOUT:-1800}"

compose_files=(-f "${COMPOSE_DIR}/base.yml" -f "${COMPOSE_DIR}/vllm.yml" -f "${COMPOSE_DIR}/monitoring.yml")
compose_profiles=(--profile vllm --profile monitoring)

log() {
    printf '[gpu-session] %s\n' "$*"
}

fail() {
    printf '[gpu-session] error: %s\n' "$*" >&2
    exit 1
}

docker_cmd() {
    if docker info >/dev/null 2>&1; then
        docker "$@"
        return
    fi

    sudo docker "$@"
}

compose() {
    docker_cmd compose "${compose_files[@]}" "${compose_profiles[@]}" "$@"
}

check_driver() {
    command -v nvidia-smi >/dev/null 2>&1 || fail "nvidia-smi not found: choose an image with the NVIDIA driver preinstalled, or install the driver and reboot."

    nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader >/dev/null 2>&1 || fail "nvidia-smi cannot talk to the driver: reboot the instance or reinstall the driver."

    log "GPU: $(nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader | head -n 1)"
}

check_docker() {
    command -v docker >/dev/null 2>&1 || fail "docker not found: install docker.io and docker-compose-v2 (Appendix L.2)."

    docker_cmd compose version >/dev/null 2>&1 || fail "the docker compose plugin is missing: sudo apt install docker-compose-v2."
}

install_container_toolkit() {
    if command -v nvidia-ctk >/dev/null 2>&1; then
        log "nvidia-container-toolkit already installed"
        return
    fi

    log "installing nvidia-container-toolkit"

    curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey \
        | sudo gpg --dearmor --yes -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg

    curl -fsSL https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list \
        | sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' \
        | sudo tee /etc/apt/sources.list.d/nvidia-container-toolkit.list >/dev/null

    sudo apt-get update
    sudo apt-get install -y nvidia-container-toolkit
    sudo nvidia-ctk runtime configure --runtime=docker
    sudo systemctl restart docker
}

set_env_value() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" "${ENV_FILE}"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "${ENV_FILE}"
        return
    fi

    printf '%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
}

current_env_value() {
    grep "^$1=" "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true
}

write_env() {
    if [ ! -f "${ENV_FILE}" ]; then
        log "creating .env from ops/compose/.env.example"
        cp "${COMPOSE_DIR}/.env.example" "${ENV_FILE}"
    fi

    set_env_value AI_LOCAL_PROVIDER vllm
    set_env_value VLLM_URL http://127.0.0.1:8001/v1

    local apiKey
    apiKey="$(current_env_value VLLM_API_KEY)"

    if [ -z "${apiKey}" ] || [ "${apiKey}" = "vllm" ]; then
        set_env_value VLLM_API_KEY "$(openssl rand -hex 16)"
        log "generated VLLM_API_KEY in .env"
    fi

    if [ -n "${HF_TOKEN:-}" ]; then
        set_env_value HF_TOKEN "${HF_TOKEN}"
        log "wrote HF_TOKEN from the environment to .env"
    fi
}

start_stack() {
    log "starting base services, the vllm profile and the monitoring profile"
    compose up -d
}

wait_for_vllm() {
    local waited=0
    local status=""

    log "waiting for ledgerly-vllm to report healthy (up to ${HEALTH_TIMEOUT_SECONDS}s; the first start downloads the model)"

    while [ "${waited}" -lt "${HEALTH_TIMEOUT_SECONDS}" ]; do
        status="$(docker_cmd inspect --format '{{.State.Health.Status}}' ledgerly-vllm 2>/dev/null || true)"

        if [ "${status}" = "healthy" ]; then
            log "ledgerly-vllm is healthy"
            return
        fi

        sleep 10
        waited=$((waited + 10))
    done

    docker_cmd logs --tail 40 ledgerly-vllm || true
    fail "ledgerly-vllm was still '${status:-unknown}' after ${HEALTH_TIMEOUT_SECONDS}s."
}

print_doctor() {
    if ! command -v php >/dev/null 2>&1; then
        log "php is not installed on this instance (Appendix L.2), so ai:doctor was not run"
        return
    fi

    if [ ! -d "${REPO_ROOT}/vendor" ]; then
        log "run 'composer install && php artisan key:generate' in ${REPO_ROOT}, then: php artisan ai:doctor --provider=vllm"
        return
    fi

    (cd "${REPO_ROOT}" && php artisan ai:doctor --provider=vllm) || log "ai:doctor reported failures; read them before you start the chapter"
}

bring_up() {
    check_driver
    check_docker
    install_container_toolkit
    write_env
    start_stack
    wait_for_vllm
    print_doctor
    log "done. The instance bills until you stop it: run '$0 --down', then stop or delete the instance in the provider console."
}

bring_down() {
    check_docker

    log "stopping the stack (the Hugging Face cache volume is kept)"
    compose down
    log "stopped. Now stop or delete the instance in the provider console."
}

case "${1:-}" in
    "")
        bring_up
        ;;
    --down)
        bring_down
        ;;
    *)
        fail "usage: $0 [--down]"
        ;;
esac
