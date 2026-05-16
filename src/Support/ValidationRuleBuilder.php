<?php

namespace Virgiandi\Apigator\Support;

/**
 * ValidationRuleBuilder
 *
 * Builds Laravel validation rules based on database column metadata.
 */
class ValidationRuleBuilder
{
    /**
     * Build validation rules array for a given set of columns.
     *
     * @param  array  $columns     Column info from Schema::getColumns()
     * @param  bool   $isUpdate    If true, make all rules optional (for PATCH)
     * @return array
     */
    public static function build(array $columns, bool $isUpdate = false): array
    {
        $rules = [];

        foreach ($columns as $col) {
            $name = $col['name'];

            // Skip auto-managed columns
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $rule = self::buildRule($col, $isUpdate);
            if (!empty($rule)) {
                $rules[$name] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Build a rule array for a single column.
     */
    protected static function buildRule(array $col, bool $isUpdate): array
    {
        $type     = strtolower($col['type'] ?? 'string');
        $nullable = $col['nullable'] ?? true;
        $rule     = [];

        // Required / nullable
        if ($isUpdate) {
            $rule[] = 'sometimes';
        }

        if ($nullable) {
            $rule[] = 'nullable';
        } else {
            $rule[] = $isUpdate ? 'nullable' : 'required';
        }

        // Type rules
        $typeRule = self::typeToRule($type);
        if ($typeRule) {
            $rule[] = $typeRule;
        }

        return $rule;
    }

    /**
     * Map database type to Laravel validation rule string.
     */
    protected static function typeToRule(string $type): ?string
    {
        // Integer types
        if (preg_match('/\b(int|int2|int4|int8|integer|bigint|smallint|tinyint|serial|bigserial)\b/', $type)) {
            return 'integer';
        }

        // Numeric / decimal / float
        if (preg_match('/\b(decimal|numeric|float|double|real|money)\b/', $type)) {
            return 'numeric';
        }

        // Boolean
        if (preg_match('/\b(bool|boolean)\b/', $type)) {
            return 'boolean';
        }

        // Date
        if ($type === 'date') {
            return 'date';
        }

        // Datetime / timestamp
        if (preg_match('/\b(datetime|timestamp)\b/', $type)) {
            return 'date';
        }

        // Time
        if ($type === 'time') {
            return 'date_format:H:i:s';
        }

        // JSON
        if (preg_match('/\b(json|jsonb)\b/', $type)) {
            return 'json';
        }

        // Email heuristic (column named email*)
        // Handled elsewhere; return string by default
        return 'string';
    }

    /**
     * Convert rules array to PHP source code string.
     * e.g. ['required', 'string'] → "'required', 'string'"
     */
    public static function rulesToCode(array $rules): string
    {
        $parts = array_map(fn($r) => "'{$r}'", $rules);
        return implode(', ', $parts);
    }
}
