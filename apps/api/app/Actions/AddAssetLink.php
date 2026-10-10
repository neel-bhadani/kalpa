<?php

namespace App\Actions;

use App\Models\Asset;
use App\Models\AssetLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddAssetLink
{
    /**
     * Link two assets of the same workspace, e.g. a voice profile "voice_of" a character.
     *
     * @throws ValidationException for a self-link, a duplicate, or a version of another asset
     */
    public function handle(Asset $from, Asset $to, string $relation, ?string $fromVersionId, ?object $data): AssetLink
    {
        if ($from->is($to)) {
            $this->fail('to_asset_id', 'An asset cannot link to itself.');
        }

        if ($from->workspace_id !== $to->workspace_id) {
            $this->fail('to_asset_id', 'Both assets must be in the same workspace.');
        }

        if ($fromVersionId !== null && ! $from->versions()->whereKey($fromVersionId)->exists()) {
            $this->fail('from_version_id', 'That version belongs to another asset.');
        }

        return DB::transaction(function () use ($from, $to, $relation, $fromVersionId, $data) {
            $exists = AssetLink::where('from_asset_id', $from->id)
                ->where('to_asset_id', $to->id)
                ->where('relation', $relation)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                $this->fail('relation', 'These assets are already linked this way.');
            }

            return AssetLink::create([
                'workspace_id' => $from->workspace_id,
                'from_asset_id' => $from->id,
                'to_asset_id' => $to->id,
                'relation' => $relation,
                'from_version_id' => $fromVersionId,
                'data' => $data ?? new \stdClass,
            ]);
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
