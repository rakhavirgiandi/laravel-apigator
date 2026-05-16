<?php

namespace Virgiandi\Apigator\Generators;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ControllerGenerator
{
    public function __construct(protected Command $command) {}

    public function generate(array $context): void
    {
        $modelName      = $context['modelName'];
        $controllerName = $context['controllerName'];
        $controllerDir  = $context['controllerDir'];
        $modelDir       = $context['modelDir'];
        $force          = $context['force'];

        $controllerNamespace = $this->dirToNamespace($controllerDir);
        $modelNamespace      = $this->dirToNamespace($modelDir);

        $path = app_path(trim($controllerDir, '/') . "/{$controllerName}.php");

        if (file_exists($path) && !$force) {
            $this->command->warn("  Controller [{$controllerName}] already exists, skipping.");
            return;
        }

        $this->ensureDirectory(dirname($path));

        $stub = $this->buildStub($modelName, $controllerName, $controllerNamespace, $modelNamespace);
        file_put_contents($path, $stub);

        $this->command->info("  Created Controller: {$path}");
    }

    protected function buildStub(
        string $modelName,
        string $controllerName,
        string $controllerNamespace,
        string $modelNamespace
    ): string {
        $modelVar = lcfirst($modelName);

        return <<<PHP
<?php

namespace {$controllerNamespace};

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use {$modelNamespace}\\{$modelName};
use Virgiandi\Apigator\Traits\ApiControllerTrait;

class {$controllerName} extends Controller
{
    use ApiControllerTrait;

    // -------------------------------------------------------------------------
    // GET /slugs  (paginated list)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Get(
     *     path="/{$this->toSlug($modelName)}",
     *     summary="Get paginated list of {$modelName}",
     *     @OA\\Parameter(name="page", in="query", @OA\\Schema(type="integer")),
     *     @OA\\Parameter(name="per_page", in="query", @OA\\Schema(type="integer")),
     *     @OA\\Parameter(name="_sort", in="query", description="Sort by column. Prefix with - for DESC. Comma-separate multiple."),
     *     @OA\\Parameter(name="_search", in="query", description="Full-text search across all searchable columns"),
     *     @OA\\Response(response=200, description="Success")
     * )
     */
    public function index(Request \$request): JsonResponse
    {
        \$result = {$modelName}::getList(\$request->all(), \$this->getAuthUser(\$request));
        return \$this->successResponse(\$result);
    }

    // -------------------------------------------------------------------------
    // GET /slugs/{id}  (single record)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Get(
     *     path="/{$this->toSlug($modelName)}/{id}",
     *     summary="Get {$modelName} by ID or custom column",
     *     @OA\\Parameter(name="id", in="path", required=true),
     *     @OA\\Parameter(name="column", in="query", description="Column to search by (default: id)"),
     *     @OA\\Response(response=200, description="Success"),
     *     @OA\\Response(response=404, description="Not found")
     * )
     */
    public function show(Request \$request, mixed \$id): JsonResponse
    {
        \$record = {$modelName}::getById(\$id, \$request->all(), \$this->getAuthUser(\$request));

        if (!\$record) {
            return \$this->notFoundResponse('{$modelName}');
        }

        return \$this->successResponse(\$record);
    }

    // -------------------------------------------------------------------------
    // POST /slugs  (create)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Post(
     *     path="/{$this->toSlug($modelName)}",
     *     summary="Create new {$modelName}",
     *     @OA\\Response(response=201, description="Created"),
     *     @OA\\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request \$request): JsonResponse
    {
        \$validator = Validator::make(\$request->all(), {$modelName}::createRules());

        if (\$validator->fails()) {
            return \$this->validationErrorResponse(
                new \Illuminate\Validation\ValidationException(\$validator)
            );
        }

        \$record = {$modelName}::createRecord(\$validator->validated());
        return \$this->successResponse(\$record, '{$modelName} created successfully.', 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /slugs/{id}  (update)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Patch(
     *     path="/{$this->toSlug($modelName)}/{id}",
     *     summary="Update {$modelName}",
     *     @OA\\Parameter(name="id", in="path", required=true),
     *     @OA\\Response(response=200, description="Updated"),
     *     @OA\\Response(response=404, description="Not found"),
     *     @OA\\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request \$request, mixed \$id): JsonResponse
    {
        \$validator = Validator::make(\$request->all(), {$modelName}::updateRules());

        if (\$validator->fails()) {
            return \$this->validationErrorResponse(
                new \Illuminate\Validation\ValidationException(\$validator)
            );
        }

        \$record = {$modelName}::updateRecord(\$id, \$validator->validated());

        if (!\$record) {
            return \$this->notFoundResponse('{$modelName}');
        }

        return \$this->successResponse(\$record, '{$modelName} updated successfully.');
    }

    // -------------------------------------------------------------------------
    // DELETE /slugs/{id}  (delete)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Delete(
     *     path="/{$this->toSlug($modelName)}/{id}",
     *     summary="Delete {$modelName}",
     *     @OA\\Parameter(name="id", in="path", required=true),
     *     @OA\\Response(response=200, description="Deleted"),
     *     @OA\\Response(response=404, description="Not found")
     * )
     */
    public function destroy(mixed \$id): JsonResponse
    {
        \$deleted = {$modelName}::deleteRecord(\$id);

        if (!\$deleted) {
            return \$this->notFoundResponse('{$modelName}');
        }

        return \$this->successResponse(null, '{$modelName} deleted successfully.');
    }

    // -------------------------------------------------------------------------
    // POST /slugs_datatable  (DataTables server-side)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Post(
     *     path="/{$this->toSlug($modelName)}_datatable",
     *     summary="DataTables server-side for {$modelName}",
     *     @OA\\RequestBody(
     *         @OA\\JsonContent(
     *             @OA\\Property(property="draw", type="integer"),
     *             @OA\\Property(property="start", type="integer"),
     *             @OA\\Property(property="length", type="integer"),
     *             @OA\\Property(property="search", type="object"),
     *             @OA\\Property(property="order", type="array", @OA\\Items(type="object")),
     *             @OA\\Property(property="columns", type="array", @OA\\Items(type="object"))
     *         )
     *     ),
     *     @OA\\Response(response=200, description="DataTables response")
     * )
     */
    public function datatable(Request \$request): JsonResponse
    {
        \$result = {$modelName}::getDatatable(\$request->all(), \$this->getAuthUser(\$request));
        return response()->json(\$result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract authenticated user data to pass to model methods.
     */
    protected function getAuthUser(Request \$request): array
    {
        \$user = \$request->user();
        return \$user ? \$user->toArray() : [];
    }
}
PHP;
    }

    protected function toSlug(string $modelName): string
    {
        return Str::plural(Str::kebab($modelName));
    }

    protected function dirToNamespace(string $dir): string
    {
        return 'App\\' . str_replace('/', '\\', trim($dir, '/'));
    }

    protected function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
