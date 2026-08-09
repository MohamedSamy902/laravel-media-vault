<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;

class ModelScanner
{
    /**
     * Scan the given directory (usually app/Models) for models that have the uploadableFiles method.
     *
     * @param string $path
     * @param string $namespace
     * @return array<int, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public function getModelsWithUploads(string $path, string $namespace = 'App\\Models\\'): array
    {
        if (!File::exists($path)) {
            return [];
        }

        $models = [];
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $class = $namespace . str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getPathname(), $path . DIRECTORY_SEPARATOR)
            );

            if (class_exists($class)) {
                try {
                    $reflection = new ReflectionClass($class);
                    if (!$reflection->isAbstract() && $reflection->hasMethod('uploadableFiles')) {
                        $models[] = $class;
                    }
                } catch (ReflectionException $e) {
                    continue;
                }
            }
        }

        return $models;
    }
}
