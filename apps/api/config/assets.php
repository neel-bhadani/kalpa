<?php

return [
    // JSON Schemas per asset kind (packages/schemas, mounted read-only into the api container).
    'schemas_path' => env('ASSET_SCHEMAS_PATH', '/schemas'),
];
