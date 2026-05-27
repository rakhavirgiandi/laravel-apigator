<?php

namespace Virgiandi\Apigator\Generators;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ServiceGenerator
{
    public function __construct(protected Command $command) {}

    public function generate (array $context): void
    {
        $serviceName      = $context['serviceName'];
        $serviceDir       = $context['serviceDir'];
        $modelName        = $context['modelName'];
        $modelDir         = $context['modelDir'];
        $force            = $context['force'];
        $connection       = $context['connection'];

        $namespace = $this->dirToNamespace($serviceDir);
        $path      = app_path(trim($serviceDir, '/') . "/{$serviceName}.php");

        if (file_exists($path) && !$force) {
            $this->command->warn("  Service [{$serviceName}] already exists, skipping.");
            return;
        }

        $modelPath = app_path(trim($modelDir, '/') . "/{$modelName}.php");

        if (!file_exists($modelPath) && !$force) {
            $this->command->warn("  Model [{$modelName}] is not exists, skipping.");
            return;
        }

        $modelNamespace = $this->dirToNamespace($modelDir);

        $this->ensureDirectory(dirname($path));

        $connectionName = $connection && $connection != config('database.default');

        $stub = $this->buildStub($serviceName, $namespace, $modelName, $modelNamespace, $connectionName);
        file_put_contents($path, $stub);

    }

    protected function buildStub (
        string $serviceName,
        string $namespace,
        string $modelName,
        string $modelNamespace,
        string $dbConnectionName = ''
    ): string {

        $dbConnection = $dbConnectionName ? "DB::connection({$dbConnectionName})->" : 'DB::';

        return <<<PHP
        <?php
        
        namespace {$namespace};

        use {$modelNamespace}\\{$modelName};
        use Virgiandi\Apigator\Support\ApigatorValidationException;
        use Virgiandi\Apigator\Support\ApigatorException;
        use Illuminate\Support\Str;
        use Illuminate\Support\Facades\Schema;
        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Validator;

        class {$serviceName}
        {   
            /**
             * Mix this into any generated (or custom) Eloquent model to enable:
             *  - getList()       → paginated list with dynamic filters
             *  - getById()       → find by id or custom column
             *  - createRecord()  → validated create
             *  - updateRecord()  → validated patch
             *  - deleteRecord()  → soft-delete aware delete
             *  - getDatatable()  → server-side DataTables response
             *
             */
        
            // -------------------------------------------------------------------------
            // GET (paginated)
            // -------------------------------------------------------------------------

            /**
             * Return paginated list.
             *
             * @param  array \$params  request()->all()
             * @param  array \$user    authenticated user data (passed to mapSchema)
             * @return array
             */
            public static function getList(array \$params = [], array \$user = []): array
            {
                \$perPage = (int) (\$params['per_page'] ?? \$params['_per_page']
                    ?? config('apigator.default_per_page', 10));

                \$perPage = max(1, min(\$perPage, 1000)); // clamp

                \$query = {$modelName}::buildBaseQuery(\$params, \$user);

                \$paginator = \$query->paginate(\$perPage, ['*'], 'page', \$params['page'] ?? 1);

                return [
                    'meta' => [
                        'current_page' => \$paginator->currentPage(),
                        'per_page'     => \$paginator->perPage(),
                        'total_pages'  => \$paginator->lastPage(),
                        'total_items'  => \$paginator->total(),
                    ],
                    'data' => \$paginator->items(),
                ];
            }

            // -------------------------------------------------------------------------
            // DATATABLES (server-side)
            // -------------------------------------------------------------------------

            /**
             * Return a DataTables-compatible response.
             *
             * @param  array \$params  DataTables POST parameters + custom user params
             * @param  array \$user
             * @return array
             */
            public static function getDatatable(array \$params = [], array \$user = []): array
            {
                \$draw    = (int) (\$params['draw'] ?? 1);
                \$start   = (int) (\$params['start'] ?? 0);
                \$length  = (int) (\$params['length'] ?? 10);
                \$length  = max(1, min(\$length, 1000));

                // Count total before filters
                \$baseQuery = {$modelName}::buildBaseQuery([], \$user);
                \$recordsTotal = (clone \$baseQuery)->count();

                // Apply DataTables search / column filters
                \$filteredQuery = {$modelName}::buildBaseQuery(\$params, \$user);
                {$modelName}::applyDatatableSearch(\$filteredQuery, \$params);
                {$modelName}::applyDatatableOrder(\$filteredQuery, \$params, \$user);

                \$recordsFiltered = (clone \$filteredQuery)->count();

                \$data = \$filteredQuery->skip(\$start)->take(\$length)->get();

                return [
                    'draw'            => \$draw,
                    'recordsTotal'    => \$recordsTotal,
                    'recordsFiltered' => \$recordsFiltered,
                    'data'            => \$data->toArray(),
                ];
            }

            // -------------------------------------------------------------------------
            // GET by ID (or custom column)
            // -------------------------------------------------------------------------

            /**
             * Find one record.
             *
             * @param  mixed  \$id
             * @param  array  \$params  May contain 'column' key to search by custom column
             * @param  array  \$user
             * @return \Illuminate\Database\Eloquent\Model|null
             */
            public static function getById(mixed \$id, array \$params = [], array \$user = []): ?{$modelName}
            {
                \$query = {$modelName}::buildBaseQuery(\$params, \$user);

                \$column = \$params['column'] ?? 'id';

                // Validate column exists to prevent injection
                \$allowedColumns = Schema::getColumnListing((new {$modelName})->getTable());
                if (!in_array(\$column, \$allowedColumns, true)) {
                    throw new ApigatorValidationException(
                        message:   "Column {\$column} does not exist.",
                        errorCode: 'COLUMN_NOT_FOUND'
                    );
                }

                \$data = \$query->where((new {$modelName})->getTable() . ".{\$column}", \$id)->first();
                
                if (!\$data) {
                    throw ApigatorException::notFound(
                        translationKey: 'errors.record_not_found',
                        errorCode: Str::upper('{$modelName}').'_NOT_FOUND',
                    );
                }

                return \$data;
            }

            // -------------------------------------------------------------------------
            // CREATE
            // -------------------------------------------------------------------------

            /**
             * Create a new record.
             *
             * @param  array \$params
             * @return static
             */
            public static function createRecord(array \$params): {$modelName}
            {   
                \$validator = Validator::make(\$params, {$modelName}::createRules());

                if (\$validator->fails()) {
                    throw ApigatorValidationException::fromValidator(\$validator);
                }

                {$dbConnection}beginTransaction();

                try {

                    \$new_record = {$modelName}::create(\$validator->validated());

                    {$dbConnection}commit();
                    
                    return self::getById(\$new_record->id);

                } catch (\Throwable \$e) {
                    {$dbConnection}rollBack();

                    if (\$e instanceof ApigatorException) {
                        throw \$e;
                    }

                    throw ApigatorException::serverError(
                        errorCode: 'DB_TRANSACTION_FAILED',
                        context:   ['reason' => \$e->getMessage()],
                    );
                }
            }

            // -------------------------------------------------------------------------
            // UPDATE
            // -------------------------------------------------------------------------

            /**
             * Update an existing record.
             *
             * @param  mixed \$id
             * @param  array \$params  \$request->all()
             * @return static|null
             */
            public static function updateRecord(mixed \$id, array \$params): ?{$modelName}
            {   
                \$validator = Validator::make(\$params, {$modelName}::createRules());

                if (\$validator->fails()) {
                    throw ApigatorValidationException::fromValidator(\$validator);
                }

                \$record = {$modelName}::find(\$id);

                if (!\$record) {
                    throw ApigatorException::notFound(
                        translationKey: 'errors.record_not_found',
                        errorCode: Str::upper('{$modelName}').'_NOT_FOUND',
                    );
                }

                {$dbConnection}beginTransaction();

                try {

                    \$record->fill(\$params)->save();

                    {$dbConnection}commit();
                    
                    return self::getById(\$record->id);

                } catch (\Throwable \$e) {
                    {$dbConnection}rollBack();

                    if (\$e instanceof ApigatorException) {
                        throw \$e;
                    }

                    throw ApigatorException::serverError(
                        errorCode: 'DB_TRANSACTION_FAILED',
                        context:   ['reason' => \$e->getMessage()],
                    );
                }
            }

            // -------------------------------------------------------------------------
            // DELETE
            // -------------------------------------------------------------------------

            /**
             * Delete a record (soft-delete if enabled).
             *
             * @param  mixed \$id
             * @return bool
             */
            public static function deleteRecord(mixed \$id): bool
            {
                \$record = {$modelName}::find(\$id);

                if (!\$record) {
                    throw ApigatorException::notFound(
                        translationKey: 'errors.record_not_found',
                        errorCode: Str::upper('{$modelName}').'_NOT_FOUND',
                    );
                }

                return (bool) \$record->delete();
            }
        }
        PHP;
    }

    protected function dirToNamespace(string $dir): string
    {
        $dir = trim($dir, '/');
        return 'App\\' . str_replace('/', '\\', $dir);
    }

    protected function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}