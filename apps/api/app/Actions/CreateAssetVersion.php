<?php

namespace App\Actions;

use App\Core\Ai\AssistantStep;
use App\Core\Assets\AssetVersionWriter;
use App\Core\Exceptions\VersionConflict;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Swaggest\JsonDiff\Exception as JsonDiffException;
use Swaggest\JsonDiff\JsonPatch;

class CreateAssetVersion
{
    public function __construct(private AssetVersionWriter $versions) {}

    /**
     * Add a version on top of $baseVersion, from a JSON Patch or a full spec. With $aiStepId, the
     * version is that (running) assistant step's.
     * Files given replace that role's files; other roles carry over from the base version.
     *
     * @param  list<object>|null  $patch  RFC 6902 operations (decoded as objects)
     * @param  list<array{role: string, file_id: string}>|null  $files
     *
     * @throws VersionConflict when the asset moved past $baseVersion
     * @throws ValidationException for a patch that can't apply or an invalid result
     */
    public function handle(Asset $asset, User $user, int $baseVersion, ?array $patch, ?object $spec, ?string $message, ?array $files, ?string $aiStepId = null): AssetVersion
    {
        $step = AssistantStep::running($aiStepId, $asset->workspace_id);

        return DB::transaction(function () use ($asset, $user, $baseVersion, $patch, $spec, $message, $files, $step) {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $current = $asset->currentVersion;

            if ($current->number !== $baseVersion) {
                throw new VersionConflict($current);
            }

            if ($patch !== null) {
                $spec = json_decode(json_encode($current->spec));
                try {
                    JsonPatch::import($patch)->apply($spec);
                } catch (JsonDiffException $e) {
                    throw ValidationException::withMessages(['patch' => "The patch could not be applied: {$e->getMessage()}"]);
                }
            }

            if (! is_object($spec)) {
                throw ValidationException::withMessages(['spec' => 'The spec must be a JSON object.']);
            }

            return $this->versions->write($asset, $current, $spec, $patch, $this->mergeFiles($current, $files ?? []), $message, $user, $step);
        });
    }

    /**
     * New files replace their roles; the base version's other roles stay.
     *
     * @param  list<array{role: string, file_id: string}>  $files
     * @return list<array{role: string, file_id: string}>
     */
    private function mergeFiles(AssetVersion $base, array $files): array
    {
        $replaced = array_unique(array_column($files, 'role'));
        $kept = array_filter($this->versions->filesOf($base), fn (array $file) => ! in_array($file['role'], $replaced, true));

        return [...array_values($kept), ...$files];
    }
}
