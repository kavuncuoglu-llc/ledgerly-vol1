# Linux AMD APU (Strix Halo): carve-out plus GTT

Not run on the machine this was written on; the commands are from the chapter and AMD's documentation, so check them against your kernel and ROCm release.

What ROCm sees:

    rocm-smi --showmeminfo vram gtt
    cat /sys/module/amdgpu/parameters/gttsize

The firmware carve-out ("UMA Frame Buffer Size" in the BIOS) and the GTT size (`amdgpu.gttsize`, a kernel parameter) add up to what a model can use. `php artisan ai:doctor --size` reads both through `rocm-smi --showmeminfo vram gtt --json` and sums them.

If the integrated GPU is not a supported ROCm target, `HSA_OVERRIDE_GFX_VERSION` can name a nearby one when starting `ollama serve`. Vulkan is the slower fallback.

On a discrete AMD card the same probe would add system-RAM GTT to the VRAM and overstate what fits; set `AI_REACHABLE_MEMORY_GIB` to the card's VRAM instead.
