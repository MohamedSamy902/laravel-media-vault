<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Support;

use Illuminate\Support\Facades\Auth;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;

/**
 * Authorization helper for dashboard mutate operations.
 */
final class FileAuthorization
{
    public function isUnauthorizedToModify(string $path): bool
    {
        if (str_contains($path, '../') || str_contains($path, '..\\') || str_contains($path, "\0")) {
            return true;
        }

        if (!config('media-vault.database.enabled', true)) {
            return false;
        }

        $file = FileUpload::withTrashed()->where('path', $path)->first();
        if (!$file) {
            return true;
        }

        if (!config('media-vault.ui.enforce_ownership', true)) {
            return false;
        }

        if ($file->user_id === null) {
            return false;
        }

        $authId = Auth::id();
        if ($authId === null) {
            return true;
        }

        return (int) $file->user_id !== (int) $authId;
    }
}
