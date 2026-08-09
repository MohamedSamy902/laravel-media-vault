<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Validates uploaded files against Laravel validation rules.
 *
 * Validation rules are resolved from the package config in this order:
 *   1. Per-request custom rules passed by the caller
 *   2. Per-field rules from config("media-vault.validation.custom_fields")
 *   3. Per-type rules from config("media-vault.validation.{type}")
 *   4. The generic "other" fallback rule
 */
final class FileValidator
{
    /**
     * Validates an uploaded file and throws on failure.
     *
     * @param UploadedFile $file                  The file to validate
     * @param string       $mimeType              The detected MIME type
     * @param string       $fieldName             The form field name (used as validator key)
     * @param array<string, mixed> $customValidationRules Per-request rule overrides keyed by field name
     *
     * @throws RuntimeException When the file fails validation
     */
    public function validate(
        UploadedFile $file,
        string       $mimeType,
        string       $fieldName,
        array        $customValidationRules,
    ): void {
        $rule = $this->resolveRule($mimeType, $fieldName, $customValidationRules);

        $validator = Validator::make(
            [$fieldName => $file],
            [$fieldName => $rule],
        );

        if ($validator->fails()) {
            throw new RuntimeException($validator->errors()->first());
        }
    }

    /**
     * Validates that a MIME type is permitted for URL downloads.
     *
     * Compares against the "url_download.allowed_mimes" config map.
     * When the map is empty, all MIME types are permitted.
     *
     * @param string $mimeType The MIME type to check (e.g. "image/jpeg")
     * @throws RuntimeException When the MIME type is not in the allowed list
     */
    public function validateUrlMime(string $mimeType): void
    {
        $allowed = config('media-vault.url_download.allowed_mimes', []);

        if (empty($allowed)) {
            return;
        }

        [$type, $subtype] = array_pad(explode('/', $mimeType, 2), 2, '');

        $permitted = (isset($allowed[$type]) && in_array($subtype, $allowed[$type], true))
            || (isset($allowed['other']) && (empty($allowed['other']) || in_array($subtype, $allowed['other'], true)));

        if (!$permitted) {
            throw new RuntimeException("MIME type [{$mimeType}] is not allowed for URL uploads.");
        }
    }

    /**
     * Resolves the appropriate validation rule for the given context.
     *
     * @param string $mimeType              The detected MIME type
     * @param string $fieldName             The form field name
     * @param array<string, mixed> $customValidationRules Per-request overrides
     * @return string|array<mixed>         The resolved Laravel validation rule
     */
    private function resolveRule(
        string $mimeType,
        string $fieldName,
        array  $customValidationRules,
    ): string|array {
        if ($fieldName !== '' && isset($customValidationRules[$fieldName])) {
            return $customValidationRules[$fieldName];
        }

        $config    = config('media-vault.validation', []);
        [$prefix]  = explode('/', $mimeType, 2);

        if ($fieldName !== '' && isset($config['custom_fields'][$fieldName])) {
            return $config['custom_fields'][$fieldName];
        }

        return $config[$prefix]
            ?? $config['other']
            ?? 'required|file|max:5242880';
    }
}
