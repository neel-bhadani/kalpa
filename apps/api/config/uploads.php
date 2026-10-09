<?php

/*
| Resumable uploads (POST /api/v1/uploads). Files go straight from the browser to
| object storage in parts; the API only signs URLs and records the result.
*/

return [
    // Largest file accepted, in bytes (default 2 GB).
    'max_bytes' => (int) env('UPLOAD_MAX_BYTES', 2 * 1024 ** 3),

    // Size of each multipart part (S3 needs at least 5 MB for all but the last).
    'part_size' => 8 * 1024 ** 2,

    // How long a started upload may sit unfinished before it's aborted (uploads:expire).
    'expire_hours' => 24,

    // How long presigned URLs stay valid, in minutes.
    'part_url_minutes' => 30,
    'download_url_minutes' => 10,

    // Allowed file types by extension, with the type we store and serve them as.
    // No HTML or SVG: files are served from the app's origin (sandboxed by Caddy, but still).
    'types' => [
        // Images
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'avif' => 'image/avif',
        // Audio
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac',
        'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'opus' => 'audio/opus',
        // Video
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska',
        // 3D
        'glb' => 'model/gltf-binary', 'gltf' => 'model/gltf+json', 'fbx' => 'application/octet-stream',
        'obj' => 'model/obj', 'bvh' => 'text/plain',
        // Text and documents
        'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv', 'json' => 'application/json',
        'srt' => 'text/plain', 'vtt' => 'text/vtt', 'pdf' => 'application/pdf',
    ],
];
