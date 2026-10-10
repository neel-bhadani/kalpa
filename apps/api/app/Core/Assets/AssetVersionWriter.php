<?php

namespace App\Core\Assets;

use App\Enums\ActorType;
use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\AssetVersion;
use App\Models\File;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Writes one asset version: validates the spec, records the files by role, counts the file
 * references and moves the asset's current version. Call inside a transaction.
 */
class AssetVersionWriter
{
    public function __construct(private AssetSchemas $schemas) {}

    /**
     * @param  list<array{role: string, file_id: string}>  $files
     * @param  list<object>|null  $patch  the JSON Patch that produced $spec, if any
     *
     * @throws ValidationException for an invalid spec or files from another workspace
     */
    public function write(
        Asset $asset,
        ?AssetVersion $parent,
        object $spec,
        ?array $patch,
        array $files,
        ?string $message,
        User $user,
    ): AssetVersion {
        $this->schemas->validate($asset->kind, $spec);
        $files = $this->checkFiles($asset->workspace_id, $files);

        $version = AssetVersion::create([
            'workspace_id' => $asset->workspace_id,
            'asset_id' => $asset->id,
            'number' => ($parent?->number ?? 0) + 1,
            'parent_version_id' => $parent?->id,
            'schema_version' => $this->schemas->version($asset->kind),
            'spec' => $spec,
            'patch' => $patch,
            'created_by_type' => ActorType::User,
            'created_by_id' => $user->id,
            'message' => $message,
        ]);

        foreach ($files as $file) {
            AssetFile::create([
                'workspace_id' => $asset->workspace_id,
                'asset_version_id' => $version->id,
                'file_id' => $file['file_id'],
                'role' => $file['role'],
            ]);
            // Every asset_files row is one reference. Nothing is garbage-collected yet.
            File::whereKey($file['file_id'])->increment('ref_count');
        }

        $asset->current_version_id = $version->id;
        $asset->touch();

        return $version;
    }

    /**
     * Files of an existing version, as role/file_id pairs.
     *
     * @return list<array{role: string, file_id: string}>
     */
    public function filesOf(AssetVersion $version): array
    {
        return $version->assetFiles()
            ->orderBy('created_at')
            ->get()
            ->map(fn (AssetFile $file) => ['role' => $file->role->value, 'file_id' => $file->file_id])
            ->all();
    }

    /**
     * Drop duplicates and refuse files that aren't in the asset's workspace.
     *
     * @param  list<array{role: string, file_id: string}>  $files
     * @return list<array{role: string, file_id: string}>
     */
    private function checkFiles(string $workspaceId, array $files): array
    {
        $files = array_values(array_unique($files, SORT_REGULAR));
        $ids = array_unique(array_column($files, 'file_id'));

        $found = File::where('workspace_id', $workspaceId)->whereKey($ids)->count();
        if ($found !== count($ids)) {
            throw ValidationException::withMessages(['files' => 'Some files do not exist in this workspace.']);
        }

        return $files;
    }
}
