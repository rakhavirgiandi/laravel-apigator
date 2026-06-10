<?php

namespace Virgiandi\Apigator\Generators;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Virgiandi\Apigator\Support\ValidationRuleBuilder;

class ModelGenerator
{
    public function __construct(protected Command $command) {}

        /**
     * Check whether any column rules contain a Rule::unique() instance.
     * Used by the code generator to decide whether to add:
     *   use Illuminate\Validation\Rules\Unique;
     */
    function needsUniqueImport(array $rulesMap): bool
    {
        foreach ($rulesMap as $rules) {
            if (ValidationRuleBuilder::hasUniqueRule($rules)) {
                return true;
            }
        }

        return false;
    }

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

        $createRulesMap = ValidationRuleBuilder::build($columns, false, $table);
        $updateRulesMap = ValidationRuleBuilder::build($columns, true,  $table);

        $createRules = $this->buildRules($createRulesMap);
        $updateRules = $this->buildRules($updateRulesMap);
        
        $needsRule = self::needsUniqueImport($createRulesMap)
                  || self::needsUniqueImport($updateRulesMap);

        $ruleImport = $needsRule ? "use Illuminate\\Validation\\Rule;\n" : '';

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
{$ruleImport}
{$softDeleteUse}

class {$modelName} extends Model
{   
    use {$trait};
    {$modelConnection}
    protected \$table = '{$table}';

    protected \$fillable = [{$fillable}
    ];

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
     */
    public static function mapSchema(array \$params = []): array
    {
{$mapSchema}
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

    protected function buildRules($rulesMap): string
    {
        
        $lines = [];
        foreach ($rulesMap as $col => $rule) {
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
