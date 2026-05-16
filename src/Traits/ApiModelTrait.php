<?php

namespace Virgiandi\Apigator\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Virgiandi\Apigator\Support\DynamicQueryParser;
use Virgiandi\Apigator\Support\SchemaQueryBuilder;

/**
 * ApiModelTrait
 */
trait ApiModelTrait
{
    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the base query, applying mapSchema (if defined) and dynamic filters.
     */
    protected static function buildBaseQuery(array $params = [], array $user = []): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Model $instance */
        $instance = new static;
        $query    = $instance->newQuery();

        // If model defines mapSchema(), use it
        if (method_exists(static::class, 'mapSchema')) {
            $schema  = static::mapSchema($params, $user);
            $fields  = $schema['field'] ?? [];

            // Apply joins + static wheres
            SchemaQueryBuilder::build($query, $schema);

            // Apply custom selects
            if (!empty($fields)) {
                $selects = SchemaQueryBuilder::buildSelectsCompat($fields);
                $query->select($selects);
            }

            $allowedColumns = SchemaQueryBuilder::getAllowedColumns($fields);
            $searchable     = SchemaQueryBuilder::getSearchableColumns($fields);
        } else {
            // Default: all table columns
            $allowedColumns = Schema::getColumnListing($instance->getTable());
            $searchable     = $allowedColumns;
        }

        // Apply dynamic filters from params
        DynamicQueryParser::apply($query, $params, $allowedColumns, $searchable);

        // Apply eager loading from ?with=relation1,relation2,relation1.nested
        static::applyEagerLoads($query, $instance, $params['with'] ?? null);

        return $query;
    }

    /**
     * Parse the `with` query param and eager-load only valid Eloquent relations.
     *
     * Supports:
     *   ?with=user
     *   ?with=user,role
     *   ?with=user.organization
     *   ?with=user.organization,role.permissions
     *
     * Each segment of a dot-chain is validated against the corresponding model
     * before being passed to ->with(). Relations that don't exist are silently
     * dropped so that a typo from the client never causes a fatal error.
     */
    protected static function applyEagerLoads(Builder $query, $instance, mixed $with): void
    {
        if (empty($with)) {
            return;
        }

        // Accept comma-separated string or an array
        $requested = is_array($with)
            ? $with
            : array_map('trim', explode(',', $with));

        $validRelations = [];

        foreach ($requested as $relationChain) {
            if (empty($relationChain)) {
                continue;
            }

            // Walk each segment of the dot-chain (e.g. "user.organization.country")
            // and validate that the relation method exists on the corresponding model.
            $segments     = explode('.', $relationChain);
            $currentModel = $instance;
            $validChain   = [];

            foreach ($segments as $segment) {
                if (!static::relationExistsOn($currentModel, $segment)) {
                    // Drop the whole chain at the first invalid segment
                    $validChain = [];
                    break;
                }

                $validChain[] = $segment;

                // Resolve the related model to validate the next segment
                try {
                    $relation     = $currentModel->{$segment}();
                    $currentModel = $relation->getRelated();
                } catch (\Throwable) {
                    // If resolving fails for any reason, stop here
                    $validChain = [];
                    break;
                }
            }

            if (!empty($validChain)) {
                $validRelations[] = implode('.', $validChain);
            }
        }

        if (!empty($validRelations)) {
            $query->with($validRelations);
        }
    }

    /**
     * Check whether a relation method exists and actually returns an Eloquent Relation.
     */
    protected static function relationExistsOn(object $model, string $relation): bool
    {
        if (!method_exists($model, $relation)) {
            return false;
        }

        try {
            $result = $model->{$relation}();
            return $result instanceof \Illuminate\Database\Eloquent\Relations\Relation;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Apply DataTables global search across all searchable columns.
     */
    protected static function applyDatatableSearch(Builder $query, array $params): void
    {
        $searchValue = $params['search']['value'] ?? '';

        if (empty($searchValue)) {
            return;
        }

        // Get searchable columns from schema or table
        $searchable = self::getDatatableSearchableColumns($params);
        if (empty($searchable)) {
            return;
        }

        $query->where(function (Builder $q) use ($searchValue, $searchable) {
            foreach ($searchable as $col) {
                $q->orWhere($col, 'LIKE', '%' . DynamicQueryParser::escapeLike($searchValue) . '%');
            }
        });

        // Per-column search
        $columns = $params['columns'] ?? [];

        foreach ($columns as $col) {
            $colSearch = $col['search']['value'] ?? '';
            $colName   = $col['name'] ?? $col['data'] ?? null;

            if (empty($colSearch) || empty($colName)) {
                continue;
            }

            // Validate column
            $tableColumns = Schema::getColumnListing((new static)->getTable());
            if (!in_array($colName, $tableColumns, true)) {
                continue;
            }

            $query->where($colName, 'LIKE', '%' . DynamicQueryParser::escapeLike($colSearch) . '%');
        }
    }

    /**
     * Apply DataTables ORDER BY.
     */
    protected static function applyDatatableOrder(Builder $query, array $params, array $user = []): void
    {
        $orders  = $params['order'] ?? [];
        $columns = $params['columns'] ?? [];

        if (empty($orders) || empty($columns)) {
            return;
        }

        // Build fields map from schema if available
        $fields = [];
        if (method_exists(static::class, 'mapSchema')) {
            $schema = static::mapSchema($params, $user);
            $fields = $schema['field'] ?? [];
        }

        foreach ($orders as $order) {
            $colIndex = (int) ($order['column'] ?? 0);
            $dir      = strtolower($order['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
            $colDef   = $columns[$colIndex] ?? null;

            if (!$colDef) {
                continue;
            }

            $colName = $colDef['name'] ?? $colDef['data'] ?? null;

            if (empty($colName)) {
                continue;
            }


            // Map through schema fields if available
            if (!empty($fields) && isset($fields[$colName])) {
                $def = $fields[$colName];
                if ($def['is_raw'] ?? false) {
                    $query->orderByRaw("{$def['column']} {$dir}");
                    continue;
                }
                $colName = $def['column'];
            }

            // Sanitize
            $safe = preg_replace('/[^a-zA-Z0-9_.]/', '', $colName);
            if (!empty($safe)) {
                $query->orderByRaw("{$safe} {$dir}");
            }
        }
    }

    /**
     * Get columns eligible for DataTables search.
     */
    protected static function getDatatableSearchableColumns(array $params, array $user = []): array
    {
        $columns = $params['columns'] ?? [];
        $result  = [];

        $schema_column = [];

        if (method_exists(static::class, 'mapSchema')) {
            $schema = static::mapSchema($params, $user);
            $fields = $schema['field'] ?? [];

            $schema_column = array_column($fields, 'column') ? array_column($fields, 'column') : array_keys($fields);
        }

        foreach ($columns as $col) {
            $searchable = $col['searchable'] ?? 'true';
            $colName    = $col['name'] ?? $col['data'] ?? null;

            if (!empty($colName) && $searchable !== 'false') {
                $tableColumns = $schema_column;
                if (in_array($colName, $tableColumns, true)) {
                    $result[] = (new static)->getTable() . '.' . $colName;
                }
            }
        }

        return $result ?: $schema_column;
    }
}
