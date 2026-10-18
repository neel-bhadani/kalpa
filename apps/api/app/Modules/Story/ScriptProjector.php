<?php

namespace App\Modules\Story;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Modules\Story\Models\ScriptLine;
use App\Modules\Story\Models\ScriptScene;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Keeps script_scenes and script_lines equal to a script asset's current version: rows are
 * inserted or updated by their spec id, and rows whose id left the spec are deleted. A row keeps
 * its own id as long as its spec id stays, so other tables can point at it.
 */
class ScriptProjector
{
    private const CHUNK = 500;

    public function project(Asset $asset, AssetVersion $version): void
    {
        $scenes = $version->spec->scenes ?? [];
        $this->ensureUniqueIds($scenes);

        $now = Carbon::now();
        $base = ['workspace_id' => $asset->workspace_id, 'asset_id' => $asset->id, 'asset_version_id' => $version->id, 'created_at' => $now, 'updated_at' => $now];

        $sceneRows = [];
        foreach ($scenes as $position => $scene) {
            $sceneRows[] = [
                ...$base,
                'id' => (string) Str::uuid7(),
                'spec_id' => $scene->id,
                'position' => $position,
                'heading' => $scene->heading,
                'location' => $scene->location ?? null,
                'time_of_day' => $scene->time_of_day ?? null,
                'summary' => $scene->summary ?? null,
            ];
        }
        foreach (array_chunk($sceneRows, self::CHUNK) as $chunk) {
            // On a known spec id, the row keeps its id and created_at.
            ScriptScene::upsert($chunk, ['asset_id', 'spec_id'], ['asset_version_id', 'position', 'heading', 'location', 'time_of_day', 'summary', 'updated_at']);
        }

        $sceneIds = ScriptScene::where('asset_id', $asset->id)->where('asset_version_id', $version->id)->pluck('id', 'spec_id');

        $lineRows = [];
        foreach ($scenes as $scene) {
            foreach ($scene->lines as $position => $line) {
                $lineRows[] = [
                    ...$base,
                    'id' => (string) Str::uuid7(),
                    'scene_id' => $sceneIds[$scene->id],
                    'spec_id' => $line->id,
                    'position' => $position,
                    'type' => $line->type,
                    'character_spec_id' => $line->character_id ?? null,
                    'text' => $line->text,
                    'emotion' => $line->emotion ?? null,
                ];
            }
        }
        foreach (array_chunk($lineRows, self::CHUNK) as $chunk) {
            ScriptLine::upsert($chunk, ['asset_id', 'spec_id'], ['asset_version_id', 'scene_id', 'position', 'type', 'character_spec_id', 'text', 'emotion', 'updated_at']);
        }

        // Everything not written from this version left the spec.
        DB::table('script_lines')->where('asset_id', $asset->id)->where('asset_version_id', '<>', $version->id)->delete();
        DB::table('script_scenes')->where('asset_id', $asset->id)->where('asset_version_id', '<>', $version->id)->delete();
    }

    /**
     * Scene and line ids must be unique in the script (the schema can't say so).
     *
     * @param  list<object>  $scenes
     */
    private function ensureUniqueIds(array $scenes): void
    {
        $sceneIds = array_column($scenes, 'id');
        $lineIds = array_merge(...array_map(fn (object $scene) => array_column($scene->lines, 'id'), $scenes ?: [(object) ['lines' => []]]));

        if (count($sceneIds) !== count(array_unique($sceneIds)) || count($lineIds) !== count(array_unique($lineIds))) {
            throw ValidationException::withMessages(['spec.scenes' => 'Each scene and each line needs its own id.']);
        }
    }
}
