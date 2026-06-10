<?php

namespace Virgiandi\Apigator\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * ValidationRuleBuilder
 *
 * Builds Laravel validation rules based on database column metadata.
 * Supports MySQL, MariaDB, PostgreSQL, SQLite, and SQL Server.
 *
 * Supported column metadata keys (from Schema::getColumns()):
 *   - name       (string)  Column name
 *   - type       (string)  Raw database type, e.g. "varchar(255)", "int", "enum('a','b')"
 *   - nullable   (bool)    Whether the column accepts NULL
 *   - length     (int)     Character/byte length (optional)
 *   - unsigned   (bool)    Whether numeric column is unsigned (optional)
 *   - values     (array)   Allowed values for ENUM/SET columns (optional)
 *   - unique     (bool)    Whether the column has a unique constraint (optional)
 */
class ValidationRuleBuilder
{
    /**
     * Columns that are always skipped (auto-managed by Laravel / DB).
     */
    protected const SKIP_COLUMNS = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'remember_token',
    ];

    /**
     * Build validation rules array for a given set of columns.
     *
     * @param  array       $columns      Column info from Schema::getColumns()
     * @param  bool        $isUpdate     If true, make all rules optional (for PATCH)
     * @param  string|null $table        Table name for unique() rules (null = skip unique)
     * @param  int|float|string|null    $ignoreParams     Params to ignore in unique checks (for update routes)
     * @return array<string, array>
     */
    public static function build(
        array $columns,
        bool $isUpdate = false,
        ?string $table = null,
        int|float|string|null $ignoreParams = null
    ): array {
        $rules = [];

        $uniqueColumns = ($table !== null) ? self::getUniqueColumns($table) : [];

        foreach ($columns as $col) {
            $name = $col['name'] ?? '';

            if (in_array($name, self::SKIP_COLUMNS, true)) {
                continue;
            }

            if (!isset($col['unique']) && in_array($name, $uniqueColumns, true)) {
                $col['unique'] = true;
            }

            $rule = self::buildRule($col, $isUpdate, $table, $ignoreParams);

            if (!empty($rule)) {
                $rules[$name] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Build a rule array for a single column.
     */
    protected static function buildRule(
        array $col,
        bool $isUpdate,
        ?string $table,
        int|float|string $ignoreParams = null
    ): array {
        $name     = $col['name'] ?? '';
        $type     = strtolower($col['type'] ?? 'string');
        $nullable = $col['nullable'] ?? true;
        $length   = isset($col['length']) ? (int) $col['length'] : null;
        $unsigned = $col['unsigned'] ?? false;
        $values   = $col['values'] ?? [];
        $unique   = $col['unique'] ?? false;
        $rule     = [];

        // ── 1. Presence rule ──────────────────────────────────────────────
        if ($isUpdate) {
            $rule[] = 'sometimes';
        }

        $rule[] = (!$nullable && !$isUpdate) ? 'required' : 'nullable';

        // ── 2. ENUM / SET → Rule::in() ───────────────────────────────────
        if (preg_match('/\b(enum|set)\b/', $type)) {
            if (empty($values)) {
                $values = self::extractEnumValues($type);
            }
            if (!empty($values)) {
                $rule[] = Rule::in($values);
                return $rule;
            }
        }

        // ── 3. Name heuristics (run first to detect type overrides) ──────
        [$nameRules, $typeOverride] = self::nameToRule($name, $table, $ignoreParams);

        // ── 4. Type-based rules (skip if name fully overrides the type) ──
        if (!$typeOverride) {
            $typeRule = self::typeToRule($type, $unsigned);
            if ($typeRule !== null) {
                $rule = array_merge($rule, (array) $typeRule);
            }

            // ── 5. Length / range constraints ─────────────────────────────
            $rule = array_merge($rule, self::lengthRules($type, $length, $unsigned, $name));
        }

        // ── 6. Append name-based extra rules ─────────────────────────────
        $rule = array_merge($rule, $nameRules);

        // ── 7. Unique constraint from column metadata ─────────────────────
        // Only added when: column is unique, table is known, and no unique
        // rule is already present (e.g. from nameToRule() for email/slug/username).
        if ($unique && $table !== null && !self::hasUniqueRule($rule)) {
            $uniqueRule = Rule::unique($table, $name);
            if ($ignoreParams !== null) {
                $uniqueRule = $uniqueRule->ignore($ignoreParams);
            }
            $rule[] = $uniqueRule;
        }

        return $rule;
    }

    /**
 * Resolve unique column names from single-column unique indexes.
 * Skips composite unique indexes (multi-column) as they can't map to a single field rule.
 *
 * @param  string $table
 * @return string[]
 */
protected static function getUniqueColumns(string $table): array
{
    $uniqueColumns = [];

    try {
        $indexes = \Illuminate\Support\Facades\Schema::getIndexes($table);

        foreach ($indexes as $index) {
            // Only single-column unique indexes are mappable to a per-field rule
            if (!empty($index['unique']) && count($index['columns']) === 1) {
                $uniqueColumns[] = $index['columns'][0];
            }
        }
    } catch (\Throwable $e) {
        // Silently ignore if driver doesn't support getIndexes()
    }

    return $uniqueColumns;
}

    /**
     * Check whether a rule array already contains a unique rule.
     * Prevents duplicate unique rules when nameToRule() already added one
     * (e.g. for email, slug, username columns).
     */
    public static function hasUniqueRule(array $rules): bool
    {
        foreach ($rules as $r) {
            if ($r instanceof Unique) {
                return true;
            }
            if (is_string($r) && str_starts_with($r, 'unique:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map database type to Laravel validation rule(s).
     * Covers MySQL/MariaDB, PostgreSQL, SQLite, and SQL Server types.
     *
     * @return string|array|null
     */
    protected static function typeToRule(string $type, bool $unsigned = false): string|array|null
    {
        // ── Tiny integers ─────────────────────────────────────────────────
        // MySQL/MariaDB: tinyint(1) is often used as boolean
        if (preg_match('/\btinyint\b/', $type)) {
            return $unsigned
                ? ['integer', 'min:0',    'max:255']
                : ['integer', 'min:-128', 'max:127'];
        }

        // ── Small integers ────────────────────────────────────────────────
        // MySQL/MariaDB: smallint | PostgreSQL: int2 | SQL Server: smallint
        if (preg_match('/\b(smallint|int2)\b/', $type)) {
            return $unsigned
                ? ['integer', 'min:0',      'max:65535']
                : ['integer', 'min:-32768', 'max:32767'];
        }

        // ── Medium integers (MySQL/MariaDB only) ──────────────────────────
        if (preg_match('/\bmediumint\b/', $type)) {
            return $unsigned
                ? ['integer', 'min:0',        'max:16777215']
                : ['integer', 'min:-8388608', 'max:8388607'];
        }

        // ── Big integers ──────────────────────────────────────────────────
        // MySQL/MariaDB: bigint | PostgreSQL: int8/bigserial | SQL Server: bigint
        if (preg_match('/\b(bigint|int8|bigserial)\b/', $type)) {
            return $unsigned
                ? ['integer', 'min:0', 'max:18446744073709551615']
                : ['integer', 'min:-9223372036854775808', 'max:9223372036854775807'];
        }

        // ── Standard integers ─────────────────────────────────────────────
        // MySQL/MariaDB: int/integer | PostgreSQL: int4/serial | SQL Server: int
        // SQLite: integer (affinity, any size — treated as standard)
        if (preg_match('/\b(int4|integer|int|serial)\b/', $type)) {
            return $unsigned
                ? ['integer', 'min:0',           'max:4294967295']
                : ['integer', 'min:-2147483648', 'max:2147483647'];
        }

        // ── SQL Server: tinyint is unsigned 0–255 ────────────────────────
        // (already matched above via \btinyint\b)

        // ── Numeric / decimal / float ─────────────────────────────────────
        // All engines support decimal/numeric; float/double in MySQL/MariaDB/PgSQL
        // SQL Server: real, money, smallmoney | PostgreSQL: real
        if (preg_match('/\b(decimal|numeric|float|double|real|money|smallmoney)\b/', $type)) {
            return $unsigned ? ['numeric', 'min:0'] : 'numeric';
        }

        // ── Boolean ───────────────────────────────────────────────────────
        // MySQL/MariaDB: tinyint(1) handled above; bool/boolean aliases
        // PostgreSQL: bool/boolean | SQL Server: bit | SQLite: integer affinity
        if (preg_match('/\b(bool|boolean)\b/', $type) || $type === 'bit') {
            return 'boolean';
        }

        // ── Date ──────────────────────────────────────────────────────────
        // All engines
        if ($type === 'date') {
            return 'date';
        }

        // ── Datetime / timestamp ──────────────────────────────────────────
        // MySQL/MariaDB: datetime/timestamp | PostgreSQL: timestamp/timestamptz
        // SQL Server: datetime/datetime2/smalldatetime/datetimeoffset | SQLite: text
        if (preg_match('/\b(datetime|timestamp|timestamptz|datetime2|smalldatetime|datetimeoffset)\b/', $type)) {
            return 'date';
        }

        // ── Time ──────────────────────────────────────────────────────────
        if (preg_match('/\btime\b/', $type)) {
            return 'date_format:H:i:s';
        }

        // ── Year (MySQL/MariaDB only) ─────────────────────────────────────
        if ($type === 'year') {
            return ['integer', 'digits:4', 'min:1901', 'max:2155'];
        }

        // ── JSON ──────────────────────────────────────────────────────────
        // MySQL 5.7+/MariaDB | PostgreSQL: json/jsonb | SQL Server: stored as nvarchar
        if (preg_match('/\b(json|jsonb)\b/', $type)) {
            return 'json';
        }

        // ── UUID / GUID ───────────────────────────────────────────────────
        // PostgreSQL: uuid | SQL Server: uniqueidentifier | others: char(36)
        if (preg_match('/\b(uuid|uniqueidentifier)\b/', $type)) {
            return 'uuid';
        }

        // ── PostgreSQL geometric / network types ──────────────────────────
        if (preg_match('/\b(inet|cidr|macaddr)\b/', $type)) {
            return 'ip';
        }

        // ── Binary / blob → skip validation ──────────────────────────────
        // MySQL: blob/tinyblob/mediumblob/longblob | PostgreSQL: bytea
        // SQL Server: varbinary/binary/image | SQLite: blob
        if (preg_match('/\b(blob|tinyblob|mediumblob|longblob|binary|varbinary|bytea|image)\b/', $type)) {
            return null;
        }

        // ── Array (PostgreSQL) ────────────────────────────────────────────
        if (str_ends_with($type, '[]') || preg_match('/\barray\b/', $type)) {
            return 'array';
        }

        // ── Text / varchar / char / nvarchar / string fallback ────────────
        // MySQL/MariaDB: varchar/char/text | PostgreSQL: text/varchar/character varying
        // SQL Server: nvarchar/nchar/ntext | SQLite: text
        return 'string';
    }

    /**
     * Derive length / digit constraints from the database type.
     * Only adds rules not already covered by typeToRule().
     *
     * @param  string   $type
     * @param  int|null $length   Explicit length from Schema metadata
     * @param  bool     $unsigned
     * @param  string   $name     Column name (to skip max for FK columns)
     * @return array
     */
    protected static function lengthRules(string $type, ?int $length, bool $unsigned, string $name = ''): array
    {
        $rules = [];

        // ── Foreign keys: skip generic integer max (min:1 handles it) ────
        if (str_ends_with(strtolower($name), '_id')) {
            return $rules;
        }

        // ── String types ──────────────────────────────────────────────────
        // MySQL/MariaDB: varchar/char/tinytext/text/mediumtext/longtext
        // PostgreSQL:    varchar/character varying/char/text
        // SQL Server:    nvarchar/nchar/varchar/char
        // SQLite:        text (no fixed length)
        $isStringType = preg_match(
            '/\b(varchar|nvarchar|char|nchar|tinytext|text|mediumtext|longtext|character varying|character)\b/',
            $type
        );

        if ($isStringType) {
            if ($length !== null && $length > 0) {
                // Explicit length from Schema metadata (most reliable)
                $rules[] = "max:{$length}";
            } elseif (preg_match('/\((\d+)\)/', $type, $m)) {
                // Parse from type string, e.g. varchar(191), nvarchar(255)
                $rules[] = 'max:' . $m[1];
            } else {
                // Known fixed caps when no length specified
                if (preg_match('/\btinytext\b/', $type)) {
                    $rules[] = 'max:255';
                } elseif (preg_match('/\bmediumtext\b/', $type)) {
                    $rules[] = 'max:16777215';
                } elseif (preg_match('/\blongtext\b/', $type)) {
                    $rules[] = 'max:4294967295';
                }
                // plain text / ntext / varchar(max) → no artificial cap
            }

            return $rules;
        }

        // ── Decimal/numeric with explicit precision ───────────────────────
        // e.g. decimal(10,2) → digits_between:1,10
        if (preg_match('/\b(decimal|numeric)\b.*\((\d+)\s*,\s*\d+\)/', $type, $m)) {
            $rules[] = 'digits_between:1,' . $m[2];
            return $rules;
        }

        // ── Integer types: max already included in typeToRule() ──────────
        // (tinyint, smallint, mediumint, int, bigint all carry min/max there)

        return $rules;
    }

    /**
     * Derive extra validation rules from the column name (heuristics).
     *
     * Returns [$rules, $typeOverride] where $typeOverride = true means
     * the name-based rules fully replace the type from typeToRule().
     *
     * @param  string      $name
     * @param  string|null $table
     * @param  int|null    $ignoreId
     * @return array{array, bool}
     */
    protected static function nameToRule(string $name, ?string $table, ?int $ignoreId): array
    {
        $rules = [];
        $lower = strtolower($name);

        // ── Email → overrides 'string' with specific format rule ─────────
        if ($lower == 'email') {
            $rules[] = 'string';
            $rules[] = 'email:rfc,dns';
            if ($table) {
                $unique = Rule::unique($table, $name);
                if ($ignoreId !== null) $unique = $unique->ignore($ignoreId);
                $rules[] = $unique;
            }
            return [$rules, true];
        }

        // ── URL ───────────────────────────────────────────────────────────
        if (preg_match('/\b(url|link|website|webpage|endpoint)\b/', $lower)) {
            return [['string', 'url'], true];
        }

        // ── UUID column name ──────────────────────────────────────────────
        if (preg_match('/\b(uuid|guid)\b/', $lower)) {
            return [['string', 'uuid'], true];
        }

        // ── IP address ────────────────────────────────────────────────────
        if (preg_match('/\b(ip_address|ip_addr|ipaddress)\b/', $lower)) {
            return [['string', 'ip'], true];
        }

        // ── Phone / mobile ────────────────────────────────────────────────
        if (preg_match('/\b(phone|mobile|telephone|handphone|hp|telp|telepon)\b/', $lower)) {
            return [['string', 'regex:/^\+?[0-9\s\-().]{7,20}$/'], true];
        }

        // ── Password ──────────────────────────────────────────────────────
        if ($lower === 'password') {
            return [['string', 'min:8'], true];
        }

        // ── Password confirmation ─────────────────────────────────────────
        if ($lower === 'password_confirmation') {
            return [['string', 'same:password'], true];
        }

        // ── Slug ──────────────────────────────────────────────────────────
        if ($lower === 'slug' || str_ends_with($lower, '_slug')) {
            $r = ['string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
            if ($table) {
                $unique = Rule::unique($table, $name);
                if ($ignoreId !== null) $unique = $unique->ignore($ignoreId);
                $r[] = $unique;
            }
            return [$r, true];
        }

        // ── Username ──────────────────────────────────────────────────────
        if (preg_match('/\b(username|user_name)\b/', $lower)) {
            $r = ['string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_.-]+$/'];
            if ($table) {
                $unique = Rule::unique($table, $name);
                if ($ignoreId !== null) $unique = $unique->ignore($ignoreId);
                $r[] = $unique;
            }
            return [$r, true];
        }

        // ── Color ─────────────────────────────────────────────────────────
        if (preg_match('/\b(color|colour)\b/', $lower)) {
            return [['string', 'regex:/^#?([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/'], true];
        }

        // ── Below: rules are additive (typeOverride = false) ─────────────

        // Latitude
        if (preg_match('/\b(lat|latitude)\b/', $lower)) {
            return [['between:-90,90'], false];
        }

        // Longitude
        if (preg_match('/\b(lng|lon|long|longitude)\b/', $lower)) {
            return [['between:-180,180'], false];
        }

        // Positive amounts / prices / quantities
        if (preg_match('/\b(price|amount|qty|quantity|total|subtotal|discount|tax|weight|height|width|length|size|rating|score)\b/', $lower)) {
            return [['min:0'], false];
        }

        // Age
        if ($lower === 'age') {
            return [['min:0', 'max:150'], false];
        }

        // Foreign keys → positive integer, skip generic integer max
        if (str_ends_with($lower, '_id')) {
            return [['min:1'], false];
        }

        return [[], false];
    }

    /**
     * Extract allowed values from a raw ENUM/SET type string.
     * e.g. "enum('active','inactive')" → ['active', 'inactive']
     */
    protected static function extractEnumValues(string $type): array
    {
        if (!preg_match('/\((.+)\)/', $type, $match)) {
            return [];
        }

        $values = [];
        foreach (explode(',', $match[1]) as $part) {
            $values[] = trim(trim($part), "'\"");
        }

        return array_values(array_filter($values));
    }

    /**
     * Convert rules array to PHP source code string.
     * Handles plain strings and Rule objects.
     */
    public static function rulesToCode(array $rules): string
    {
        $parts = array_map(function ($r) {
            if ($r instanceof \Illuminate\Validation\Rules\Unique) {
                return self::uniqueRuleToCode($r);
            }

            if (is_string($r)) {
                return "'{$r}'";
            }

            if (is_object($r)) {
                return (string) $r;
            }

            return var_export($r, true);
        }, $rules);

        return implode(', ', $parts);
    }

    /**
     * Convert a Rule::unique() instance to PHP source code string
     * by reflecting its internal properties.
     */
    protected static function uniqueRuleToCode(\Illuminate\Validation\Rules\Unique $rule): string
    {
        $ref   = new \ReflectionObject($rule);
        $get   = function (string $prop) use ($rule, $ref) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            return $p->getValue($rule);
        };

        $table  = $get('table');   // e.g. "contacts"
        $column = $get('column');  // e.g. "email"
        $ignore = $get('ignore');  // e.g. 5 or null

        $code = "Rule::unique('{$table}', '{$column}')";

        if ($ignore !== null) {
            $ignoreId      = is_array($ignore) ? $ignore[0] : $ignore;
            $ignoreColumn  = is_array($ignore) ? ($ignore[1] ?? 'id') : 'id';
            $code .= $ignoreColumn === 'id'
                ? "->ignore({$ignoreId})"
                : "->ignore({$ignoreId}, '{$ignoreColumn}')";
        }

        return $code;
    }
}