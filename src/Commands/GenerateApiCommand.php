<?php

namespace Virgiandi\Apigator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Virgiandi\Apigator\Generators\ControllerGenerator;
use Virgiandi\Apigator\Generators\ModelGenerator;
use Virgiandi\Apigator\Generators\RouteGenerator;
use Virgiandi\Apigator\Generators\ServiceGenerator;

class GenerateApiCommand extends Command
{
    protected $signature = 'apigator:generate
                            {--table= : Table name or "all" to generate for all tables}
                            {--connection= : Database connection to use (default: app default connection)}
                            {--generate= : Comma-separated list of what to generate: model,controller,route (default: all)}
                            {--controller-dir= : Custom controller directory (relative to app/)}
                            {--service-dir= : Custom service directory (relative to app/)}
                            {--model-dir= : Custom model directory (relative to app/)}
                            {--force : Overwrite existing files}';

    protected $description = 'Generate CRUD API (Controller, Model, Routes) from database table(s)';

    /**
     * Valid generator keys and their corresponding generator classes.
     */
    protected const GENERATOR_MAP = [
        'model'      => ModelGenerator::class,
        'service'    => ServiceGenerator::class,
        'controller' => ControllerGenerator::class,
        'route'      => RouteGenerator::class,
    ];

    public function handle(): int
    {
        $table = $this->option('table');

        if (empty($table)) {
            $this->error('Please specify a table using --table=table_name or --table=all');
            return self::FAILURE;
        }

        // Resolve & validate --connection
        $connection = $this->option('connection')
            ?? config('apigator.connection')
            ?? config('database.default');

        if (!array_key_exists($connection, config('database.connections', []))) {
            $this->error("Database connection [{$connection}] is not configured.");
            return self::FAILURE;
        }

        // Resolve & validate --generate
        $generateTargets = $this->resolveGenerateTargets();
        if ($generateTargets === null) {
            return self::FAILURE; // error already printed inside
        }

        $controllerDir = $this->option('controller-dir')
            ?? config('apigator.controller_directory', 'Http/Controllers/Api');

        $modelDir = $this->option('model-dir')
            ?? config('apigator.model_directory', 'Models');

        $serviceDir = $this->option('service-dir')
            ?? config('apigator.service_directory', 'Services');

        if ($table === 'all') {
            return $this->generateAll($connection, $controllerDir, $modelDir, $generateTargets);
        }

        return $this->generateForTable($table, $connection, $controllerDir, $modelDir, $serviceDir, $generateTargets);
    }

    // -------------------------------------------------------------------------
    // Core logic
    // -------------------------------------------------------------------------

    protected function generateAll(
        string $connection,
        string $controllerDir,
        string $modelDir,
        array  $generateTargets
    ): int {
        $excludedTables = config('apigator.exclude_tables', []);
        $tables         = $this->getAllTables($connection);

        if (empty($tables)) {
            $this->error('No tables found in the database.');
            return self::FAILURE;
        }

        $generated = 0;
        $skipped   = 0;

        foreach ($tables as $table) {
            if (in_array($table, $excludedTables)) {
                $this->line("  <fg=gray>Skipping excluded table:</> {$table}");
                $skipped++;
                continue;
            }

            $result = $this->generateForTable(
                $table, $connection, $controllerDir, $modelDir, $generateTargets, quiet: true
            );

            if ($result === self::SUCCESS) {
                $generated++;
            } else {
                $skipped++;
            }
        }

        $this->info("\n✅  Done! Generated: {$generated}, Skipped: {$skipped}");
        return self::SUCCESS;
    }

    protected function generateForTable(
        string $table,
        string $connection,
        string $controllerDir,
        string $modelDir,
        string $serviceDir,
        array  $generateTargets,
        bool   $quiet = false
    ): int {
        // 1. Check if table exists on the given connection
        if (!Schema::connection($connection)->hasTable($table)) {
            $this->error("Table [{$table}] does not exist on connection [{$connection}].");
            return self::FAILURE;
        }

        $modelName      = Str::studly(Str::singular($table));
        $controllerName = "{$modelName}Controller";
        $serviceName = "{$modelName}Service";
        $force          = $this->option('force');

        // 2. Check if already generated (only for files we actually intend to create)
        $pathsToCheck = [];
        if (in_array('controller', $generateTargets)) {
            $pathsToCheck[] = app_path(trim($controllerDir, '/') . "/{$controllerName}.php");
        }
        if (in_array('model', $generateTargets)) {
            $pathsToCheck[] = app_path(trim($modelDir, '/') . "/{$modelName}.php");
        }
        if (in_array('service', $generateTargets)) {
            $pathsToCheck[] = app_path(trim($serviceDir, '/') . "/{$serviceName}.php");
        }

        $alreadyExists = !empty(array_filter($pathsToCheck, 'file_exists'));

        if ($alreadyExists && !$force) {
            if (!$quiet) {
                $this->warn("API for table [{$table}] already generated. Use --force to overwrite.");
            } else {
                $this->line("  <fg=yellow>Already exists:</> {$table}");
            }
            return self::FAILURE;
        }

        // 3. Get columns info using the specified connection
        $columns = $this->getColumnsInfo($table, $connection);

        // 4. Build generators based on selected targets
        $generators = [];
        foreach ($generateTargets as $target) {
            $class        = self::GENERATOR_MAP[$target];
            $generators[] = new $class($this);
        }

        $context = [
            'table'           => $table,
            'connection'      => $connection,
            'modelName'       => $modelName,
            'controllerName'  => $controllerName,
            'controllerDir'   => $controllerDir,
            'serviceName'     => $serviceName,
            'serviceDir'      => $serviceDir,
            'modelDir'        => $modelDir,
            'columns'         => $columns,
            'force'           => $force,
            'generateTargets' => $generateTargets,
        ];

        foreach ($generators as $generator) {
            $generator->generate($context);
        }

        if (!$quiet) {
            $targets = implode(', ', $generateTargets);
            $this->info("✅  Generated [{$targets}] for table [{$table}] on connection [{$connection}].");
        } else {
            $this->line("  <fg=green>Generated:</> {$table}");
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Option helpers
    // -------------------------------------------------------------------------

    /**
     * Parse --generate option into a validated array of targets.
     * Returns null if the option value is invalid.
     *
     * @return string[]|null
     */
    protected function resolveGenerateTargets(): ?array
    {
        $raw = $this->option('generate');

        if (empty($raw)) {
            // Default: generate everything
            return array_keys(self::GENERATOR_MAP);
        }

        $requested = array_map('trim', explode(',', strtolower($raw)));
        $valid     = array_keys(self::GENERATOR_MAP);
        $invalid   = array_diff($requested, $valid);

        if (!empty($invalid)) {
            $this->error(
                'Invalid --generate value(s): ' . implode(', ', $invalid) . '. ' .
                'Allowed: ' . implode(', ', $valid)
            );
            return null;
        }

        // Preserve declaration order (model → controller → route)
        return array_values(array_intersect($valid, $requested));
    }

    // -------------------------------------------------------------------------
    // Database helpers
    // -------------------------------------------------------------------------

    protected function getAllTables(string $connection): array
    {
        $driver = DB::connection($connection)->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => $this->getMysqlTables($connection),
            'pgsql'            => $this->getPgsqlTables($connection),
            'sqlite'           => $this->getSqliteTables($connection),
            'sqlsrv'           => $this->getSqlsrvTables($connection),
            default            => Schema::connection($connection)->getAllTables(),
        };
    }

    protected function getMysqlTables(string $connection): array
    {
        $rows = DB::connection($connection)->select('SHOW TABLES');
        return array_map(fn($r) => array_values((array) $r)[0], $rows);
    }

    protected function getPgsqlTables(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
        );
        return array_column($rows, 'tablename');
    }

    protected function getSqliteTables(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        );
        return array_column($rows, 'name');
    }

    protected function getSqlsrvTables(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'"
        );
        return array_column($rows, 'TABLE_NAME');
    }

    protected function getColumnsInfo(string $table, string $connection): array
    {
        $schemaColumns = Schema::connection($connection)->getColumns($table);
        $columns       = [];

        foreach ($schemaColumns as $col) {
            $columns[] = [
                'name'           => $col['name'],
                'type'           => $col['type_name'] ?? $col['type'] ?? 'string',
                'nullable'       => $col['nullable'] ?? true,
                'default'        => $col['default'] ?? null,
                'auto_increment' => ($col['auto_increment'] ?? false) || ($col['name'] === 'id'),
            ];
        }

        return $columns;
    }
}