<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use stdClass;

abstract class Controller
{
    /**
     * Page size for cursor-paginated lists: ?per_page=1..100, default 25.
     */
    protected function perPage(Request $request): int
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return (int) $request->query('per_page', 25);
    }

    /**
     * The request body decoded as objects. Laravel's input decodes {} as [],
     * which breaks JSON documents (JSON Patch paths, checkpoints) that need the difference.
     */
    protected function jsonBody(Request $request): object
    {
        return json_decode($request->getContent() ?: '{}', false, 512, JSON_THROW_ON_ERROR) ?? new stdClass;
    }
}
