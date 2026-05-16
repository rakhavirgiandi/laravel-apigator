<?php

namespace Virgiandi\Apigator\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * SchemaQueryBuilder
 *
 * Builds a query from a schema definition returned by mapSchema().
 *
 * Schema structure:
 * [
 *   'field' => [
 *     'alias' => [
 *       'column'  => 'table.column' | raw SQL,
 *       'alias'   => 'alias_name',
 *       'type'    => 'string|int|float|bool|date|datetime',
 *       'is_raw'  => true   // treat column as raw SQL expression
 *     ],
 *   ],
 *   'join'  => [
 *     ['table' => '...', 'type' => 'left|inner|right', 'on' => ['a.id','=','b.id']],
 *   ],
 *   'where' => [
 *     // Static where conditions (same format as DynamicQueryParser filters)
 *     ['column' => '...', 'operator' => '=', 'value' => '...'],
 *   ],
 * ]
 */
class SchemaQueryBuilder
{
    /**
     * Build an Eloquent/DB query from a schema definition.
     *
     * @param  Builder $query  Base Eloquent query
     * @param  array   $schema Result of Model::mapSchema()
     * @return Builder
     */
    public static function build(Builder $query, array $schema): Builder
    {
        // Apply joins
        if (!empty($schema['join'])) {
            foreach ($schema['join'] as $join) {
                self::applyJoin($query, $join);
            }
        }

        // Apply static where conditions
        if (!empty($schema['where'])) {
            foreach ($schema['where'] as $where) {
                self::applyStaticWhere($query, $where);
            }
        }

        return $query;
    }

    /**
     * Build SELECT columns from schema field definitions.
     *
     * @param  array $fields  Schema 'field' array
     * @return array          Array of select expressions (raw or normal)
     */
    public static function buildSelects(array $fields): array
    {
        $selects = [];

        foreach ($fields as $alias => $def) {
            $column  = $def['column'] ?? $alias;
            $isRaw   = $def['is_raw'] ?? false;
            $colAlias = $def['alias'] ?? $alias;

            if ($isRaw) {
                $selects[] = DB::raw("{$column} as {$colAlias}");
            } else {
                $selects[] = DB::raw("`{$column}` as `{$colAlias}`");
            }
        }

        return $selects;
    }

    /**
     * Build SELECT columns compatible with both MySQL/Postgres.
     */
    public static function buildSelectsCompat(array $fields): array
    {
        $driver  = DB::getDriverName();
        $quote   = in_array($driver, ['pgsql', 'sqlite']) ? '"' : '`';
        $selects = [];

        foreach ($fields as $alias => $def) {
            $column   = $def['column'] ?? $alias;
            $isRaw    = $def['is_raw'] ?? false;
            $colAlias = $def['alias'] ?? $alias;

            if ($isRaw) {
                $selects[] = DB::raw("{$column} as {$colAlias}");
            } elseif (str_contains($column, '.')) {
                [$tbl, $col] = explode('.', $column, 2);
                $selects[] = DB::raw("{$quote}{$tbl}{$quote}.{$quote}{$col}{$quote} as {$quote}{$colAlias}{$quote}");
            } else {
                $selects[] = DB::raw("{$quote}{$column}{$quote} as {$quote}{$colAlias}{$quote}");
            }
        }

        return $selects;
    }

    /**
     * Get allowed column aliases for DynamicQueryParser whitelist.
     */
    public static function getAllowedColumns(array $fields): array
    {
        return array_column($fields, 'column') ? array_column($fields, 'column') : array_keys($fields);
    }

    /**
     * Get searchable column aliases (all string-type columns by default).
     */
    public static function getSearchableColumns(array $fields): array
    {   
        return array_column(array_filter($fields, fn($f) => ($f['type'] ?? 'string') === 'string'), 'column');
    }

    /**
     * Map an alias sort to actual column expression for ORDER BY.
     */
    public static function mapSortColumn(string $alias, array $fields): string
    {
        if (isset($fields[$alias])) {
            $def = $fields[$alias];
            if ($def['is_raw'] ?? false) {
                return $def['column'];
            }
            return $def['column'] ?? $alias;
        }
        return $alias;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    protected static function applyJoin(Builder $query, array $join): void
    {
        $table = $join['table'];
        $type  = strtolower($join['type'] ?? 'inner');
        $on    = $join['on'] ?? [];

        if (count($on) !== 3) {
            return;
        }

        [$first, $operator, $second] = $on;

        match ($type) {
            'left'  => $query->leftJoin($table, $first, $operator, $second),
            'right' => $query->rightJoin($table, $first, $operator, $second),
            default => $query->join($table, $first, $operator, $second),
        };
    }

    protected static function applyStaticWhere(Builder $query, array $where): void
    {
        $column   = $where['column'] ?? null;
        $operator = $where['operator'] ?? '=';
        $value    = $where['value'] ?? null;

        if (!$column) {
            return;
        }

        if ($value === null && in_array(strtoupper($operator), ['IS NULL', 'IS NOT NULL'])) {
            strtoupper($operator) === 'IS NULL'
                ? $query->whereNull($column)
                : $query->whereNotNull($column);
            return;
        }

        $query->where($column, $operator, $value);
    }
}
