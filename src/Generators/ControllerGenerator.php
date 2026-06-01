<?php

namespace Virgiandi\Apigator\Generators;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ControllerGenerator
{
    public function __construct(protected Command $command) {}

    public function generate(array $context): void
    {
        $serviceDir     = $context['serviceDir'];
        $serviceName    = $context['serviceName'];
        $controllerName = $context['controllerName'];
        $controllerDir  = $context['controllerDir'];
        $force          = $context['force'];

        $controllerNamespace = $this->dirToNamespace($controllerDir);
        $serviceNamespace    = $this->dirToNamespace($serviceDir);

        $path = app_path(trim($controllerDir, '/') . "/{$controllerName}.php");

        if (file_exists($path) && !$force) {
            $this->command->warn("  Controller [{$controllerName}] already exists, skipping.");
            return;
        }

        $servicePath = app_path(trim($serviceDir, '/') . "/{$serviceName}.php");

        if (!file_exists($servicePath) && !$force) {
            $this->command->warn("  Service [{$serviceName}] is not exists, skipping.");
            return;
        }

        $this->ensureDirectory(dirname($path));

        $stub = $this->buildStub($serviceName, $serviceNamespace, $controllerName, $controllerNamespace);
        file_put_contents($path, $stub);

        $this->command->info("  Created Controller: {$path}");
    }

    protected function buildStub(
        string $serviceName,
        string $serviceNamespace,
        string $controllerName,
        string $controllerNamespace,
    ): string {
        return <<<PHP
<?php

namespace {$controllerNamespace};

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use {$serviceNamespace}\\{$serviceName};
use Virgiandi\Apigator\Traits\ApiControllerTrait;

class {$controllerName} extends Controller
{
    use ApiControllerTrait;

    // -------------------------------------------------------------------------
    // GET /slugs  (paginated list)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Get(
     *     path="/{$this->toSlug($serviceName)}",
     *     summary="Get paginated list of {$serviceName}",
     *     @OA\\Parameter(name="page", in="query", @OA\\Schema(type="integer")),
     *     @OA\\Parameter(name="per_page", in="query", @OA\\Schema(type="integer")),
     *     @OA\\Parameter(name="_sort", in="query", description="Sort by column. Prefix with - for DESC. Comma-separate multiple."),
     *     @OA\\Parameter(name="_search", in="query", description="Full-text search across all searchable columns"),
     *     @OA\\Response(response=200, description="Success")
     * )
     */
    public function index(Request \$request): JsonResponse
    {
        \$result = {$serviceName}::getList(\$request->all());
        return \$this->successResponse(\$result);
    }

    // -------------------------------------------------------------------------
    // GET /slugs/{id}  (single record)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Get(
     *     path="/{$this->toSlug($serviceName)}/{id}",
     *     summary="Get {$serviceName} by ID or custom column",
     *     @OA\\Parameter(name="id", in="path", required=true),
     *     @OA\\Parameter(name="column", in="query", description="Column to search by (default: id)"),
     *     @OA\\Response(response=200, description="Success"),
     *     @OA\\Response(response=404, description="Not found")
     * )
     */
    public function show(Request \$request, mixed \$id): JsonResponse
    {
        \$record = {$serviceName}::getById(\$id, \$request->all());

        return \$this->successResponse(\$record);
    }

    // -------------------------------------------------------------------------
    // POST /slugs  (create)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Post(
     *     path="/{$this->toSlug($serviceName)}",
     *     summary="Create new {$serviceName}",
     *     @OA\\Response(response=201, description="Created"),
     *     @OA\\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request \$request): JsonResponse
    {
        \$record = {$serviceName}::createRecord(\$request->all());

        return \$this->successResponse(\$record, '{$serviceName} created successfully.', 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /slugs/{id}  (update)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Patch(
     *     path="/{$this->toSlug($serviceName)}/{id}",
     *     summary="Update {$serviceName}",
     *     @OA\\Parameter(name="id", in="path", required=true),
     *     @OA\\Response(response=200, description="Updated"),
     *     @OA\\Response(response=404, description="Not found"),
     *     @OA\\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request \$request, mixed \$id): JsonResponse
    {
        \$record = {$serviceName}::updateRecord(\$id, \$request->all());

        return \$this->successResponse(\$record, '{$serviceName} updated successfully.');
    }

    // -------------------------------------------------------------------------
    // DELETE /slugs/{id}  (delete)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Delete(
     *     path="/{$this->toSlug($serviceName)}/{id}",
     *     summary="Delete {$serviceName}",
     *     @OA\\Parameter(name="id", in="path", required=true),
     *     @OA\\Response(response=200, description="Deleted"),
     *     @OA\\Response(response=404, description="Not found")
     * )
     */
    public function destroy(mixed \$id): JsonResponse
    {
        \$deleted = {$serviceName}::deleteRecord(\$id);

        return \$this->successResponse(null, '{$serviceName} deleted successfully.');
    }

    // -------------------------------------------------------------------------
    // POST /slugs_datatable  (DataTables server-side)
    // -------------------------------------------------------------------------

    /**
     * @OA\\Post(
     *     path="/{$this->toSlug($serviceName)}_datatable",
     *     summary="DataTables server-side for {$serviceName}",
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
        \$result = {$serviceName}::getDatatable(\$request->all());
        return response()->json(\$result);
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
