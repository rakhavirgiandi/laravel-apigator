<?php

namespace Virgiandi\Apigator\Generators;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Virgiandi\Apigator\Support\ValidationRuleBuilder;

class ModelGenerator
{
    public function __construct(protected Command $command) {}

    public function generate(array $context): void
    {
        $table         = $context['table'];
        $modelName     = $context['modelName'];
        $modelDir      = $context['modelDir'];
        $columns       = $context['columns'];
        $force         = $context['force'];
        $connection    = $context['connection'];

        $namespace = $this->dirToNamespace($modelDir);
        $path      = app_path(trim($modelDir, '/') . "/{$modelName}.php");

        if (file_exists($path) && !$force) {
            $this->command->warn("  Model [{$modelName}] already exists, skipping.");
            return;
        }

        $this->ensureDirectory(dirname($path));

        $stub = $this->buildStub($table, $modelName, $namespace, $columns, $connection);
        file_put_contents($path, $stub);

        $this->command->info("  Created Model: {$path}");
    }

    protected function buildStub(
        string $table,
        string $modelName,
        string $namespace,
        array $columns,
        string $connection = ''
    ): string {
        $fillable    = $this->buildFillable($columns);
        $casts       = $this->buildCasts($columns);
        $createRules = $this->buildRules($columns, false);
        $updateRules = $this->buildRules($columns, true);
        $mapSchema   = $this->buildMapSchema($table, $columns);
        $hasSoftDelete = $this->hasSoftDelete($columns);
        $softDeleteUse = $hasSoftDelete ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;" : '';
        $softDeleteImport = $hasSoftDelete ? 'SoftDeletes' : '';

        $modelConnection = $connection && $connection != config('database.default') ? "\nprotected \$connection = '{$connection}';\n" : '';

        $trait = "ApiModelTrait";

        if ($hasSoftDelete) {
            $trait .= ", ".$softDeleteImport;
        }

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Model;
use Virgiandi\Apigator\Traits\ApiModelTrait;
use Illuminate\Support\Facades\Schema;
{$softDeleteUse}

class {$modelName} extends Model
{   
    use {$trait};
    {$modelConnection}
    protected \$table = '{$table}';

    protected \$fillable = [{$fillable}
    ];

    /**
     * Mix this into any generated (or custom) Eloquent model to enable:
     *  - getList()       → paginated list with dynamic filters
     *  - getById()       → find by id or custom column
     *  - createRecord()  → validated create
     *  - updateRecord()  → validated patch
     *  - deleteRecord()  → soft-delete aware delete
     *  - getDatatable()  → server-side DataTables response
     *
     * Models may optionally define:
     *   public static function mapSchema(array \$params = [], array \$user = []): array
     */

{$casts}

    // Relations ...

    // -------------------------------------------------------------------------
    // Validation Rules
    // -------------------------------------------------------------------------

    /**
     * Validation rules for POST (create).
     */
    public static function createRules(): array
    {
        return [{$createRules}
        ];
    }

    /**
     * Validation rules for PATCH (update).
     */
    public static function updateRules(): array
    {
        return [{$updateRules}
        ];
    }

    // -------------------------------------------------------------------------
    // Schema Definition (optional - customize to add joins, computed columns)
    // -------------------------------------------------------------------------

    /**
     * Define the query schema for this model.
     * Fields here control SELECT columns, JOINs, and static WHERE conditions.
     *
     * @param  array \$params  Request parameters (can be used for dynamic conditions)
     * @param  array \$user    Authenticated user data
     */
    public static function mapSchema(array \$params = [], array \$user = []): array
    {
{$mapSchema}
    }

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

        \$query = self::buildBaseQuery(\$params, \$user);

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
        \$baseQuery = self::buildBaseQuery([], \$user);
        \$recordsTotal = (clone \$baseQuery)->count();

        // Apply DataTables search / column filters
        \$filteredQuery = self::buildBaseQuery(\$params, \$user);
        self::applyDatatableSearch(\$filteredQuery, \$params);
        self::applyDatatableOrder(\$filteredQuery, \$params, \$user);

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
    public static function getById(mixed \$id, array \$params = [], array \$user = []): ?self
    {
        \$query = self::buildBaseQuery(\$params, \$user);

        \$column = \$params['column'] ?? 'id';

        // Validate column exists to prevent injection
        \$allowedColumns = Schema::getColumnListing((new self)->getTable());
        if (!in_array(\$column, \$allowedColumns, true)) {
            abort(400, "Column [{\$column}] does not exist.");
        }

        return \$query->where((new self)->getTable() . ".{\$column}", \$id)->first();
    }

    // -------------------------------------------------------------------------
    // CREATE
    // -------------------------------------------------------------------------

    /**
     * Create a new record.
     *
     * @param  array \$data  Validated data
     * @return static
     */
    public static function createRecord(array \$data): static
    {
        \$new_record = self::create(\$data);

        return self::getById(\$new_record->id);
    }

    // -------------------------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------------------------

    /**
     * Update an existing record.
     *
     * @param  mixed \$id
     * @param  array \$data  Validated data
     * @return static|null
     */
    public static function updateRecord(mixed \$id, array \$data): ?static
    {
        \$record = self::find(\$id);
        if (!\$record) {
            return null;
        }
        \$record->fill(\$data)->save();

        return self::getById(\$record->id);
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
        \$record = self::find(\$id);
        if (!\$record) {
            return false;
        }
        return (bool) \$record->delete();
    }
}
PHP;
    }

    protected function buildFillable(array $columns): string
    {
        $skip   = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $fields = array_filter($columns, fn($c) => !in_array($c['name'], $skip));
        $lines  = array_map(fn($c) => "\n        '{$c['name']}'", $fields);
        return implode(',', $lines) . ($lines ? ',' : '');
    }

    protected function buildCasts(array $columns): string
    {   
        $castMap = [
            'int'      => 'integer',
            'int2'      => 'integer',
            'int4'      => 'integer',
            'int8'      => 'integer',
            'integer'  => 'integer',
            'bigint'   => 'integer',
            'smallint' => 'integer',
            'tinyint'  => 'boolean', // often boolean in MySQL
            'bool'     => 'boolean',
            'boolean'  => 'boolean',
            'decimal'  => 'decimal:2',
            'numeric'  => 'decimal:2',
            'float'    => 'float',
            'double'   => 'float',
            'real'     => 'float',
            'json'     => 'array',
            'jsonb'    => 'array',
            'date'     => 'date',
            'datetime' => 'datetime',
            'timestamp'=> 'datetime',
        ];

        $casts = [];
        foreach ($columns as $col) {
            $type = strtolower(preg_replace('/\(.*\)/', '', $col['type']));
            if (!in_array($col['name'], ['id'])) {
                $casts[$col['name']] = isset($castMap[$type]) ? $castMap[$type] : 'string';
            }
        }

        if (empty($casts)) {
            return "    protected \$casts = [];\n";
        }

        $lines = [];
        foreach ($casts as $col => $cast) {
            $lines[] = "        '{$col}' => '{$cast}',";
        }

        return "    protected \$casts = [\n" . implode("\n", $lines) . "\n    ];\n";
    }

    protected function buildRules(array $columns, bool $isUpdate): string
    {
        $rules = ValidationRuleBuilder::build($columns, $isUpdate);
        $lines = [];
        foreach ($rules as $col => $rule) {
            $ruleStr = ValidationRuleBuilder::rulesToCode($rule);
            $lines[] = "\n            '{$col}' => [{$ruleStr}],";
        }
        return implode('', $lines) . ($lines ? "\n        " : '');
    }

    protected function buildMapSchema(string $table, array $columns): string
    {
        $fieldLines = [];
        foreach ($columns as $col) {
            $name = $col['name'];
            $type = $this->columnTypeToSchemaType($col['type']);
            $fieldLines[] = "                '{$name}' => ['column' => \$model->table.'.{$name}', 'alias' => '{$name}', 'type' => '{$type}'],";
        }

        $fields = implode("\n", $fieldLines);

        return <<<PHP
        \$model = new self;

        return [
            'field' => [
{$fields}
            ],
            'join' => [
                // Example join:
                // ['table' => 'other_table as ot', 'type' => 'left', 'on' => ['ot.id', '=', \$model->table.'.other_table_id']],
            ],
            'where' => [
                // Example static where:
                // ['column' => \$model->table.'.deleted_at', 'operator' => 'IS NULL', 'value' => null],
            ],
        ];
PHP;
    }

    protected function columnTypeToSchemaType(string $dbType): string
    {
        $type = strtolower(preg_replace('/\(.*\)/', '', $dbType));
        
        return match (true) {
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'serial', 'bigserial', 'int2', 'int4', 'int8']) => 'int',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'money'])                  => 'float',
            in_array($type, ['bool', 'boolean'])                                                          => 'bool',
            in_array($type, ['date'])                                                                     => 'date',
            in_array($type, ['datetime', 'timestamp'])                                                    => 'datetime',
            in_array($type, ['json', 'jsonb'])                                                            => 'json',
            default                                                                                       => 'string',
        };
    }

    protected function hasSoftDelete(array $columns): bool
    {
        return (bool) array_filter($columns, fn($c) => $c['name'] === 'deleted_at');
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
