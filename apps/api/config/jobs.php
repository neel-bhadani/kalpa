<?php

/*
| Jobs: heavy or AI work run by the user's devices, their workers, or the server queue.
*/

return [
    // A claimed job belongs to its worker this long; each heartbeat extends it.
    'lease_seconds' => 60,

    // Workers that haven't been heard from for this long are shown offline.
    'worker_offline_after_seconds' => 120,

    // How long a worker pairing code stays valid.
    'pairing_code_minutes' => 10,

    // Thumbnails: the long side in pixels, and the largest source images we decode
    // (GD holds the whole image in memory: 16 MP is about 64 MB).
    'thumbnail_size' => 512,
    'thumbnail_max_bytes' => 30 * 1024 * 1024,
    'thumbnail_max_pixels' => 16_000_000,
];
