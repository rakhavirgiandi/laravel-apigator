<?php

namespace Virgiandi\Apigator\Generators;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RouteGenerator
{
    public function __construct(protected Command $command) {}

    public function generate(array $context): void
    {
        $table          = $context['table'];
        $modelName      = $context['modelName'];
        $controllerName = $context['controllerName'];
        $controllerDir  = $context['controllerDir'];

        $routeFile        = base_path(config('apigator.route_file', 'routes/api.php'));
        $controllerClass  = $this->dirToNamespace($controllerDir) . "\\{$controllerName}";
        $slug             = Str::plural(Str::snake($modelName, config('apigator.route_delimiter', '_')));

        $marker = "// [APIGATOR_ENDPOINTS] {$table}";

        // Don't duplicate routes
        if (file_exists($routeFile) && str_contains(file_get_contents($routeFile), $marker)) {
            $this->command->warn("  Routes for [{$table}] already exist in {$routeFile}, skipping.");
            return;
        }

        $stub = $this->buildStub($controllerName, $slug, $marker);

        // Ensure route file exists
        if (!file_exists($routeFile)) {
            $dir = dirname($routeFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($routeFile, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
        }

        // Inject 'use ControllerClass;' after 'use Illuminate\Support\Facades\Route;'
        $this->injectUseStatement($routeFile, $controllerClass);

        $this->appendToMarkedArea($routeFile, $stub);

        $this->command->info("  Appended Routes to: {$routeFile}");
    }

    /**
     * Inject a 'use FullControllerClass;' statement after the Route facade import,
     * only if it doesn't already exist in the file.
     */
    protected function injectUseStatement(string $routeFile, string $controllerClass): void
    {
        $contents  = file_get_contents($routeFile);
        $useStatement = "use {$controllerClass};";

        // Skip if already imported
        if (str_contains($contents, $useStatement)) {
            return;
        }

        $anchor = 'use Illuminate\Support\Facades\Route;';

        if (str_contains($contents, $anchor)) {
            $contents = str_replace(
                $anchor,
                $anchor . "\n" . $useStatement,
                $contents
            );
        } else {
            // Fallback: append after <?php if anchor not found
            $contents = str_replace("<?php", "<?php\n\n{$useStatement}", $contents);
        }

        file_put_contents($routeFile, $contents);
    }

    /**
     * Append $stub inside the [APIGATOR_ENDPOINTS_START/END] block.
     * If the block does not exist yet, it is created at the end of the file.
     */
    protected function appendToMarkedArea(string $routeFile, string $stub): void
    {
        $startMarker = '// [APIGATOR_ENDPOINTS_START]';
        $endMarker   = '// [APIGATOR_ENDPOINTS_END]';

        $contents = file_get_contents($routeFile);

        if (str_contains($contents, $startMarker) && str_contains($contents, $endMarker)) {
            $contents = str_replace(
                $endMarker,
                $stub . $endMarker,
                $contents
            );
        } else {
            $contents = rtrim($contents)
                . "\n\n{$startMarker}\n"
                . $stub
                . "{$endMarker}\n";
        }

        file_put_contents($routeFile, $contents);
    }

    protected function buildStub(
        string $controllerName,  // sekarang hanya 'MyController', bukan full namespace
        string $slug,
        string $marker
    ): string {
        return <<<PHP
{$marker}
Route::get('/{$slug}',               [{$controllerName}::class, 'index']);
Route::get('/{$slug}/{id}',          [{$controllerName}::class, 'show']);
Route::post('/{$slug}',              [{$controllerName}::class, 'store']);
Route::patch('/{$slug}/{id}',        [{$controllerName}::class, 'update']);
Route::delete('/{$slug}/{id}',       [{$controllerName}::class, 'destroy']);
Route::post('/{$slug}_datatable',    [{$controllerName}::class, 'datatable']);

PHP;
    }

    protected function dirToNamespace(string $dir): string
    {
        return 'App\\' . str_replace('/', '\\', trim($dir, '/'));
    }
}