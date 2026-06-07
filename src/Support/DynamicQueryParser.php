<?php

namespace Virgiandi\Apigator\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * DynamicQueryParser
 *
 * Parses request query parameters into Eloquent/DB query constraints.
 *
 * Supported operators (via query string):
 *  ?column=value                  → WHERE column = value
 *  ?column[eq]=value              → WHERE column = value
 *  ?column[neq]=value             → WHERE column != value
 *  ?column[gt]=value              → WHERE column > value
 *  ?column[gte]=value             → WHERE column >= value
 *  ?column[lt]=value              → WHERE column < value
 *  ?column[lte]=value             → WHERE column <= value
 *  ?column[like]=value            → WHERE column LIKE %value%
 *  ?column[starts]=value          → WHERE column LIKE value%
 *  ?column[ends]=value            → WHERE column LIKE %value
 *  ?column[in]=a,b,c              → WHERE column IN (a, b, c)
 *  ?column[not_in]=a,b,c          → WHERE column NOT IN (a, b, c)
 *  ?column[null]=1                → WHERE column IS NULL
 *  ?column[not_null]=1            → WHERE column IS NOT NULL
 *  ?column[between]=2024-01-01,2024-12-31 → WHERE column BETWEEN ...
 *  ?column[date_from]=2024-01-01  → WHERE DATE(column) >= ...
 *  ?column[date_to]=2024-12-31    → WHERE DATE(column) <= ...
 *  ?_or[0][column][eq]=val&_or[1][col2][like]=foo → OR group
 *  ?_search=keyword               → WHERE (col1 LIKE % OR col2 LIKE %)
 *  ?_sort=column                  → ORDER BY column ASC
 *  ?_sort=-column                 → ORDER BY column DESC
 *  ?_sort=col1,-col2              → multi-sort
 */
class DynamicQueryParser
{
    /**
     * Allowed operators (whitelist to prevent injection)
     */
    protected const OPERATORS = [
        'eq'       => '=',
        'neq'      => '!=',
        'gt'       => '>',
        'gte'      => '>=',
        'lt'       => '<',
        'lte'      => '<=',
        'like'     => 'LIKE',
        'starts'   => 'LIKE',
        'ends'     => 'LIKE',
        'in'       => 'IN',
        'not_in'   => 'NOT IN',
        'null'     => 'IS NULL',
        'not_null' => 'IS NOT NULL',
        'between'  => 'BETWEEN',
        'date_from' => '>=',
        'date_to'   => '<=',
    ];

    /**
     * Reserved parameter names (not treated as column filters)
     */
    protected const RESERVED = [
        '_search', '_sort', '_page', '_per_page',
        'page', 'per_page', '_or',
        // DataTables params
        'draw', 'start', 'length', 'columns', 'order', 'search',
    ];

    /**
     * Apply dynamic query filters to an Eloquent Builder.
     *
     * @param  Builder $query
     * @param  array   $params        Raw request()->all() or subset
     * @param  array   $schema Whitelist of column names. Empty = allow all schema columns.
     * @param  array   $searchableColumns Columns to search with _search param
     */
    public static function apply(
        Builder $query,
        array $params,
        array $columnMapSchemaByAlias,
        array $searchableColumns = []
    ): Builder {
        // $tableColumns = $allowedColumns ?: self::getTableColumns($query);

        foreach ($params as $key => $value) {

            if (empty($columnMapSchemaByAlias[$key])) {
                continue;
            }

            $col = $columnMapSchemaByAlias[$key];

            if (in_array($col, self::RESERVED, true)) {
                continue;
            }

            if ($key === '_or') {
                self::applyOrGroup($query, $value, $columnMapSchemaByAlias);
                continue;
            }

            if (is_array($value)) {
                // e.g. ?column[eq]=value
                foreach ($value as $op => $val) {
                    self::applyFilter($query, $col, $op, $val, $columnMapSchemaByAlias);
                }
            } else {
                // e.g. ?column=value  → implicit 'eq'
                self::applyFilter($query, $col, 'eq', $value, $columnMapSchemaByAlias);
            }
        }

        // _search
        if (!empty($params['_search']) && !empty($searchableColumns)) {
            $term = $params['_search'];
            $query->where(function (Builder $q) use ($term, $searchableColumns, $columnMapSchemaByAlias) {
                foreach ($searchableColumns as $searchableCol) {
                    if (self::isColumnAllowed($searchableCol, $columnMapSchemaByAlias)) {
                        $safeCol = self::sanitizeColumn($searchableCol);
                        $q->orWhere($safeCol, 'LIKE', '%' . self::escapeLike($term) . '%');
                    }
                }
            });
        }

        // _sort
        if (!empty($params['_sort'])) {
            self::applySort($query, $params['_sort'], $columnMapSchemaByAlias);
        }

        return $query;
    }

    protected static function applyFilter(
        Builder $query,
        string $column,
        string $operator,
        mixed $value,
        array $allowedColumns
    ): void {
        // Validate column
        if (!self::isColumnAllowed($column, $allowedColumns)) {
            return;
        }

        $op = strtolower(trim($operator));

        if (!array_key_exists($op, self::OPERATORS)) {
            return; // Unknown operator – skip silently (no injection)
        }

        $safeCol = self::sanitizeColumn($column);

        match ($op) {
            'like'     => $query->where($safeCol, 'LIKE', '%' . self::escapeLike($value) . '%'),
            'starts'   => $query->where($safeCol, 'LIKE', self::escapeLike($value) . '%'),
            'ends'     => $query->where($safeCol, 'LIKE', '%' . self::escapeLike($value)),
            'in'       => $query->whereIn($safeCol, self::parseList($value)),
            'not_in'   => $query->whereNotIn($safeCol, self::parseList($value)),
            'null'     => $query->whereNull($safeCol),
            'not_null' => $query->whereNotNull($safeCol),
            'between'  => self::applyBetween($query, $safeCol, $value),
            'date_from' => $query->whereDate($safeCol, '>=', $value),
            'date_to'   => $query->whereDate($safeCol, '<=', $value),
            default    => $query->where($safeCol, self::OPERATORS[$op], $value),
        };
    }

    protected static function applyBetween(Builder $query, string $column, mixed $value): void
    {
        $parts = self::parseList($value);
        if (count($parts) === 2) {
            $query->whereBetween($column, [$parts[0], $parts[1]]);
        }
    }

    protected static function applyOrGroup(Builder $query, mixed $orGroups, array $allowedColumns): void
    {
        if (!is_array($orGroups)) {
            return;
        }

        $query->where(function (Builder $q) use ($orGroups, $allowedColumns) {
            foreach ($orGroups as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $q->orWhere(function (Builder $inner) use ($group, $allowedColumns) {
                    foreach ($group as $column => $filters) {
                        if (!is_array($filters)) {
                            self::applyFilter($inner, $column, 'eq', $filters, $allowedColumns);
                        } else {
                            foreach ($filters as $op => $val) {
                                self::applyFilter($inner, $column, $op, $val, $allowedColumns);
                            }
                        }
                    }
                });
            }
        });
    }

    protected static function applySort(Builder $query, mixed $sort, array $columns): void
    {
        $parts = is_array($sort) ? $sort : explode(',', $sort);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $direction = 'ASC';
            if (str_starts_with($part, '-')) {
                $direction = 'DESC';
                $part = substr($part, 1);
            }

            if (!empty($columns[$part])) {
                $query->orderBy(self::sanitizeColumn($part), $direction);
            }
        }
    }

    /**
     * Sanitize a column name: only allow alphanumeric, underscore, dot (for table.column notation).
     * This prevents SQL injection completely.
     */
    protected static function sanitizeColumn(string $column): string
    {
        // Allow table.column notation
        return preg_replace('/[^a-zA-Z0-9_.]/', '', $column);
    }

    protected static function isColumnAllowed(string $column, array $allowedColumns): bool
    {
        if (empty($allowedColumns)) {
            return true;
        }
        // Support table.column notation
        $bare = Str::contains($column, '.') ? Str::afterLast($column, '.') : $column;
        return in_array($column, $allowedColumns, true)
            || in_array($bare, $allowedColumns, true);
    }

    protected static function getTableColumns(Builder $query): array
    {
        try {
            $table = $query->getModel()->getTable();
            return \Illuminate\Support\Facades\Schema::getColumnListing($table);
        } catch (\Throwable) {
            return [];
        }
    }

    protected static function parseList(mixed $value): array
    {
        if (is_array($value)) {
            return array_map('strval', $value);
        }
        return array_map('trim', explode(',', (string) $value));
    }

    /**
     * Escape special LIKE characters to prevent pattern injection.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
