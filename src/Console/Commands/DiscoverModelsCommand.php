<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use MohamedSamy902\LaravelMediaVault\Traits\HasMediaFields;

class DiscoverModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media-vault:discover-models';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover models using the HasMediaFields trait and cache them';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Scanning models for HasMediaFields trait...');

        $path = app_path('Models');
        $namespace = 'App\\Models\\';

        $models = [];

        if (File::exists($path)) {
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
                        if (!$reflection->isAbstract() && in_array(HasMediaFields::class, class_uses_recursive($class))) {
                            $models[] = $class;
                        }
                    } catch (ReflectionException $e) {
                        continue;
                    }
                }
            }
        }

        $cachePath = base_path('bootstrap/cache/media-vault-models.php');
        
        $content = "<?php\n\nreturn " . var_export($models, true) . ";\n";
        File::put($cachePath, $content);

        $this->info('Discovered ' . count($models) . ' models.');
        $this->info('Models cached successfully at: ' . $cachePath);

        return 0;
    }
}
