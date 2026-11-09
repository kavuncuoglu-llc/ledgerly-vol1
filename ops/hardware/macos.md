# macOS: how much memory the GPU may use

Check the cap (0 means the system default):

    sysctl iogpu.wired_limit_mb

Raise it until the next reboot (the number is MiB; leave room for macOS and everything else on the machine):

    sudo sysctl iogpu.wired_limit_mb=110000

Set `AI_REACHABLE_MEMORY_GIB` or run `php artisan ai:doctor --size --reachable-gib=107` to size against the raised number. Without a raised limit the app assumes `AI_MACOS_GPU_SHARE` (0.75) of RAM.

Measured on an Apple M1 Pro with 32 GiB (limit unset): Ollama's Metal backend reported 25.0 GiB usable, 78 percent, not 75. Run `ollama serve` and look for the `inference compute` line in `~/.ollama/logs/server.log` to see your own number, then set `AI_MACOS_GPU_SHARE` to match.

See what is loaded and whether any of it spilled to the CPU:

    ollama ps

Ollama allocates the KV cache for the whole context window when the model loads, so its `SIZE` is weights plus cache for `num_ctx`, not weights alone. Its default `num_ctx` on a machine with this much memory was 32768 here, four times the 8192 the app budgets for; set `num_ctx` in the Modelfile or the request to pay only for what the app uses.
