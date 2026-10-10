<?php

/*
| Models Kalpa knows about (model_registry, seeded from here by ModelRegistrySeeder).
| Every one is off (enabled = false) until the phase that wires it; a CHECK keeps any model
| without commercial_ok off for good. Licences match docs/licences.md (Models).
| size_mb is approximate (the download, quantized where that's how it ships).
*/

return [
    // Scripted stand-in for an LLM, so the assistant loop can be built and tested. Dev and tests only.
    'fake-brain' => [
        'capability' => 'llm', 'engine' => 'fake', 'display_name' => 'Fake brain (dev)',
        'licence' => 'Kalpa Studio code', 'commercial_ok' => true, 'size_mb' => 0, 'min_tier' => 'low',
        'targets' => ['device'], 'source_url' => 'https://github.com/kalpa-studio/kalpa', 'enabled' => true, 'dev_only' => true,
    ],

    'qwen3-0.6b' => [
        'capability' => 'llm', 'engine' => 'llama.cpp', 'display_name' => 'Qwen3 0.6B (4-bit)',
        'licence' => 'Apache-2.0', 'commercial_ok' => true, 'size_mb' => 480, 'min_tier' => 'low',
        'targets' => ['device', 'worker'], 'source_url' => 'https://huggingface.co/Qwen/Qwen3-0.6B',
    ],
    'qwen3-1.7b' => [
        'capability' => 'llm', 'engine' => 'llama.cpp', 'display_name' => 'Qwen3 1.7B (4-bit)',
        'licence' => 'Apache-2.0', 'commercial_ok' => true, 'size_mb' => 1100, 'min_tier' => 'mid',
        'targets' => ['device', 'worker'], 'source_url' => 'https://huggingface.co/Qwen/Qwen3-1.7B',
    ],
    'qwen3-4b' => [
        'capability' => 'llm', 'engine' => 'llama.cpp', 'display_name' => 'Qwen3 4B (4-bit)',
        'licence' => 'Apache-2.0', 'commercial_ok' => true, 'size_mb' => 2500, 'min_tier' => 'high',
        'targets' => ['device', 'worker'], 'source_url' => 'https://huggingface.co/Qwen/Qwen3-4B',
    ],
    'kokoro-82m' => [
        'capability' => 'tts', 'engine' => 'kokoro', 'display_name' => 'Kokoro 82M',
        'licence' => 'Apache-2.0', 'commercial_ok' => true, 'size_mb' => 330, 'min_tier' => 'low',
        'targets' => ['device', 'worker'], 'source_url' => 'https://huggingface.co/hexgrad/Kokoro-82M',
    ],
    'piper' => [
        'capability' => 'tts', 'engine' => 'piper', 'display_name' => 'Piper voices',
        'licence' => 'unclear: original rhasspy/piper (MIT) archived Oct 2025; successor piper1-gpl is GPL-3.0; each voice has its own licence',
        'commercial_ok' => false, 'size_mb' => 60, 'min_tier' => 'low',
        'targets' => ['device', 'worker'], 'source_url' => 'https://github.com/rhasspy/piper',
    ],
    'chatterbox-multilingual' => [
        'capability' => 'voice_clone', 'engine' => 'chatterbox', 'display_name' => 'Chatterbox Multilingual',
        'licence' => 'MIT', 'commercial_ok' => true, 'size_mb' => 2000, 'min_tier' => 'high',
        'targets' => ['worker'], 'source_url' => 'https://github.com/resemble-ai/chatterbox',
    ],
    'ace-step-1.5' => [
        'capability' => 'music', 'engine' => 'ace-step', 'display_name' => 'ACE-Step 1.5',
        'licence' => 'MIT (code and weights; model card: generated music may be used commercially)',
        'commercial_ok' => true, 'size_mb' => 4000, 'min_tier' => 'high',
        'targets' => ['worker'], 'source_url' => 'https://github.com/ace-step/ACE-Step',
    ],
    'real-esrgan-x4plus' => [
        'capability' => 'upscale', 'engine' => 'real-esrgan', 'display_name' => 'Real-ESRGAN x4plus',
        'licence' => 'BSD-3-Clause', 'commercial_ok' => true, 'size_mb' => 64, 'min_tier' => 'mid',
        'targets' => ['worker'], 'source_url' => 'https://github.com/xinntao/Real-ESRGAN',
    ],
    'whisper-small' => [
        'capability' => 'stt', 'engine' => 'whisper.cpp', 'display_name' => 'Whisper small',
        'licence' => 'MIT', 'commercial_ok' => true, 'size_mb' => 466, 'min_tier' => 'mid',
        'targets' => ['device', 'worker'], 'source_url' => 'https://huggingface.co/openai/whisper-small',
    ],
    'sam2' => [
        'capability' => 'segment', 'engine' => 'sam2', 'display_name' => 'SAM 2',
        'licence' => 'Apache-2.0', 'commercial_ok' => true, 'size_mb' => 180, 'min_tier' => 'mid',
        'targets' => ['device', 'worker'], 'source_url' => 'https://github.com/facebookresearch/sam2',
    ],
    'depth-anything-v2-small' => [
        'capability' => 'depth', 'engine' => 'depth-anything', 'display_name' => 'Depth Anything V2 Small',
        'licence' => 'Apache-2.0 (Small only; Base and Large are CC-BY-NC)', 'commercial_ok' => true, 'size_mb' => 100, 'min_tier' => 'low',
        'targets' => ['device', 'worker'], 'source_url' => 'https://huggingface.co/depth-anything/Depth-Anything-V2-Small',
    ],
    'flux1-schnell' => [
        'capability' => 'image', 'engine' => 'flux', 'display_name' => 'FLUX.1 [schnell]',
        'licence' => 'Apache-2.0 (schnell only; FLUX.1 [dev] is non-commercial)', 'commercial_ok' => true, 'size_mb' => 6800, 'min_tier' => 'high',
        'targets' => ['worker'], 'source_url' => 'https://huggingface.co/black-forest-labs/FLUX.1-schnell',
    ],
    'trellis' => [
        'capability' => 'asset3d', 'engine' => 'trellis', 'display_name' => 'TRELLIS',
        'licence' => 'unclear: models MIT, but needs nvdiffrast (NVIDIA Source Code License, non-commercial only) and diffoctreerast (derived from diff-gaussian-rasterization)',
        'commercial_ok' => false, 'size_mb' => 4000, 'min_tier' => 'high',
        'targets' => ['worker'], 'source_url' => 'https://github.com/microsoft/TRELLIS',
    ],
];
