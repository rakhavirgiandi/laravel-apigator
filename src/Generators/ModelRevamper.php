<?php

namespace Virgiandi\Apigator\Generators;

use Virgiandi\Apigator\Support\ValidationRuleBuilder;

/**
 * ModelRevamper
 *
 * Surgically patches an existing generated Model file with fresh column data
 * from the database. Only three sections are touched:
 *
 *  1. `protected $casts`          — replaced entirely
 *  2. `createRules()` return array — replaced entirely
 *  3. `mapSchema()` 'field' block  — own-table entries replaced; other-table
 *                                    entries (custom joins) are preserved
 *
 * "Own-table" field entries are those whose 'column' value references the
 * model's own table via either:
 *   • `$model->table.'.column_name'`  (dynamic, as generated)
 *   • `'table_name.column_name'`      (hard-coded table name)
 *
 * Everything else—joins, wheres, comments, and foreign-table fields—is left
 * untouched.
 */
class ModelRevamper extends ModelGenerator
{
    // -------------------------------------------------------------------------
    // Public entry-point
    // -------------------------------------------------------------------------

    public function revamp(array $context): void
    {
        $modelName  = $context['modelName'];
        $modelDir   = $context['modelDir'];
        $columns    = $context['columns'];
        $table      = $context['table'];
        $connection = $context['connection'];

        $path = app_path(trim($modelDir, '/') . "/{$modelName}.php");

        if (!file_exists($path)) {
            $this->command->error(
                "  Model [{$modelName}] not found at [{$path}]." .
                " Run apigator:generate first."
            );
            return;
        }

        $content  = file_get_contents($path);
        $original = $content;

        $content = $this->replaceFillableBlock($content, $columns);
        $content = $this->replaceCastsBlock($content, $columns);
        $content = $this->replaceCreateRulesBlock($content, $columns, $table, $connection);
        $content = $this->replaceUpdateRulesBlock($content, $columns, $table, $connection);
        $content = $this->replaceMapSchemaFields($content, $columns, $table);

        if ($content === $original) {
            $this->command->warn("  No changes detected in Model: {$path}");
            return;
        }

        file_put_contents($path, $content);
        $this->command->info("  Revamped Model: {$path}");
    }

    // -------------------------------------------------------------------------
    // Section replacers
    // -------------------------------------------------------------------------

    /**
     * Replace the entire `protected $casts = [...];` block.
     *
     * Handles both the empty (`$casts = [];`) and the multi-line variants
     * produced by ModelGenerator::buildCasts().
     */
    protected function replaceCastsBlock(string $content, array $columns): string
    {
        // buildCasts() already returns the fully indented block ending with \n
        $newCasts = $this->buildCasts($columns);

        $safeReplacement = str_replace(
            ['\\',  '$'],
            ['\\\\', '\\$'],
            $newCasts
        );

        $replaced = preg_replace(
            '/[ \t]*protected \$casts\s*=\s*\[(.*?)\];/s',
            $safeReplacement,
            $content
        );

        if ($replaced === null) {
            $this->command->warn('  Could not locate $casts block — skipping $casts update.');
            return $content;
        }

        return $replaced;
    }

    protected function replaceFillableBlock(string $content, array $columns): string
    {
        // buildFillable() already returns the fully indented block ending with \n
        $fillable = $this->buildFillable($columns);

        $newFillable = <<<PHP
            protected \$fillable = [{$fillable}
            ];
        PHP;

        $safeReplacement = str_replace(
            ['\\',  '$'],
            ['\\\\', '\\$'],
            $newFillable
        );
        
        $replaced = preg_replace(
            '/[ \t]*protected \$fillable\s*=\s*\[(.*?)\];/s',
            $safeReplacement,
            $content,
            1
        );

        if ($replaced === null) {
            $this->command->warn('  Could not locate $fillable block — skipping $fillable update.');
            return $content;
        }

        return $replaced;
    }

    /**
     * Replace the return-array content inside `createRules()`.
     *
     * Uses bracket-depth counting so nested arrays in rule definitions are
     * handled correctly.
     */
    protected function replaceCreateRulesBlock(string $content, array $columns, string $table, ?string $connection = null): string
    {
        // Locate "return [" inside createRules()
        if (!preg_match(
            '/public static function createRules\(\): array\s*\{.*?return\s*\[/s',
            $content,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            $this->command->warn('  Could not find createRules() — skipping createRules update.');
            return $content;
        }

        // The '[' is the very last char of the matched string
        $openBracketPos  = $match[0][1] + strlen($match[0][0]) - 1;
        $closeBracketPos = $this->findMatchingBracket($content, $openBracketPos);

        if ($closeBracketPos === null) {
            $this->command->warn('  Could not find closing bracket of createRules() return — skipping.');
            return $content;
        }

        $isConeectionDefault = $connection && $connection != config('database.default') ? true : false;

        $createRulesMap = ValidationRuleBuilder::build($columns, $table, $isConeectionDefault ? $connection : null);

        // buildRules() returns "\n            'col' => [...],\n        "
        // which already contains the correct trailing indent before "]"
        $newRules = $this->buildRules($createRulesMap);

        return substr($content, 0, $openBracketPos + 1)
            . $newRules
            . substr($content, $closeBracketPos);
    }

    protected function replaceUpdateRulesBlock(string $content, array $columns, string $table, ?string $connection = null): string
    {
        // Locate "return [" inside updateRules()
        if (!preg_match(
            '/public\s+static\s+function\s+updateRules\s*\([^)]*\)\s*:\s*array\s*\{.*?return\s*\[/s',
            $content,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            $this->command->warn('  Could not find updateRules() — skipping updateRules update.');
            return $content;
        }

        // The '[' is the very last char of the matched string
        $openBracketPos  = $match[0][1] + strlen($match[0][0]) - 1;
        $closeBracketPos = $this->findMatchingBracket($content, $openBracketPos);

        if ($closeBracketPos === null) {
            $this->command->warn('  Could not find closing bracket of updateRules() return — skipping.');
            return $content;
        }

        $isConeectionDefault = $connection && $connection != config('database.default') ? true : false;

        $updateRulesMap = ValidationRuleBuilder::build($columns, $table, $isConeectionDefault ? $connection : null, "\$id");

        // buildRules() returns "\n            'col' => [...],\n        "
        // which already contains the correct trailing indent before "]"
        $newRules = $this->buildRules($updateRulesMap);

        return substr($content, 0, $openBracketPos + 1)
            . $newRules
            . substr($content, $closeBracketPos);
    }

    /**
     * Replace only the own-table entries inside the `'field' => [...]` block
     * of `mapSchema()`.
     *
     * Preserved (kept as-is):
     *   • Field entries whose 'column' references a *different* table
     *     (e.g. `'contacts.name'`)
     *
     * Replaced:
     *   • Field entries whose 'column' uses `$model->table.'...'` or
     *     the hard-coded own-table name (e.g. `'users.name'`)
     *
     * Comment-only lines and blank lines inside the block are intentionally
     * dropped—they would be stale after a revamp anyway.
     */
    protected function replaceMapSchemaFields(
        string $content,
        array  $columns,
        string $table
    ): string {
        // Locate "'field' => ["
        if (!preg_match("/'field'\s*=>\s*\[/", $content, $match, PREG_OFFSET_CAPTURE)) {
            $this->command->warn("  Could not find 'field' block in mapSchema() — skipping field update.");
            return $content;
        }

        $openBracketPos  = $match[0][1] + strlen($match[0][0]) - 1;
        $closeBracketPos = $this->findMatchingBracket($content, $openBracketPos);

        if ($closeBracketPos === null) {
            $this->command->warn("  Could not find closing bracket of 'field' block — skipping.");
            return $content;
        }

        // Content between '[' and ']'
        $fieldContent = substr(
            $content,
            $openBracketPos + 1,
            $closeBracketPos - $openBracketPos - 1
        );

        // ── Collect non-own-table field entries to preserve ──────────────────
        $preservedLines = [];
        foreach (explode("\n", $fieldContent) as $line) {
            $trimmed = trim($line);

            // Keep only lines that look like field entries
            if ($trimmed === '' || !str_contains($trimmed, "'column'")) {
                continue;
            }

            // Drop own-table entries (they'll be regenerated from the DB)
            if ($this->isOwnTableField($line, $table)) {
                continue;
            }

            $preservedLines[] = $line;
        }

        // ── Build fresh DB-column field lines ────────────────────────────────
        $indent       = '                '; // 16 spaces — matches ModelGenerator
        $newFieldLines = [];
        foreach ($columns as $col) {
            $name            = $col['name'];
            $type            = $this->columnTypeToSchemaType($col['type']);
            $newFieldLines[] = "{$indent}'{$name}' => "
                . "['column' => \$model->table.'.{$name}', "
                . "'alias' => '{$name}', "
                . "'type' => '{$type}'],";
        }

        // DB columns first, then any preserved custom (other-table) entries
        $allLines       = array_merge($newFieldLines, $preservedLines);
        $newInnerContent = "\n" . implode("\n", $allLines) . "\n            ";

        return substr($content, 0, $openBracketPos + 1)
            . $newInnerContent
            . substr($content, $closeBracketPos);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether a field-entry line's 'column' value references the
     * model's own table (and should therefore be regenerated).
     *
     * @param string $line  A single source-code line from the 'field' block
     * @param string $table The database table name (e.g. "users")
     */
    protected function isOwnTableField(string $line, string $table): bool
    {
        // Dynamic reference: 'column' => $model->table.'.col'
        if (preg_match("/\\'column\\'\s*=>\s*\\\$model->table\\./", $line)) {
            return true;
        }

        // Hard-coded own-table reference: 'column' => 'users.col'
        if (preg_match(
            "/\\'column\\'\s*=>\s*\\'" . preg_quote($table, '/') . "\\./",
            $line
        )) {
            return true;
        }

        return false;
    }

    /**
     * Walk forward from `$openPos` (which must point at '[') counting bracket
     * depth, and return the position of the matching ']'.
     *
     * Returns null if no matching bracket is found.
     */
    protected function findMatchingBracket(string $content, int $openPos): ?int
    {
        $depth  = 0;
        $length = strlen($content);

        for ($i = $openPos; $i < $length; $i++) {
            if ($content[$i] === '[') {
                $depth++;
            } elseif ($content[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}