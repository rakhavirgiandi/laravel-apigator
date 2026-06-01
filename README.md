# ⚡ Laravel Apigator

<p>
  <strong>Auto-generate production-ready CRUD APIs from your database tables — in seconds.</strong>
</p>

<p>
  <img src="https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/License-MIT-brightgreen?style=flat-square" alt="MIT License">
  <img src="https://img.shields.io/badge/Packagist-rakhavirgiandi%2Flaravel--apigator-orange?style=flat-square&logo=composer" alt="Packagist">
</p>

---

**Laravel Apigator** is a developer-experience-first package that reads your existing database schema and generates a full, working CRUD API stack: **Model**, **Service**, **Controller**, and **Routes** — all wired together and ready to use. No more boilerplate. No more copy-paste. Just run one command and your API is live.

It also ships with a powerful runtime query engine that gives every generated endpoint free filtering, sorting, full-text search, pagination, eager loading, and DataTables server-side support — all through query parameters, with zero extra code.

---

## 📖 Table of Contents

- [✨ Features](#features)
- [📋 Requirements](#requirements)
- [📦 Installation](#installation)
- [⚙️ Configuration](#configuration)
- [🚀 Quick Start](#quick-start)
- [🛠️ The `apigator:generate` Command](#the-apigatorgenerate-command)
  - [📌 Generating a Single Table](#generating-a-single-table)
  - [📌 Generating All Tables](#generating-all-tables)
  - [📌 Selective Generation](#selective-generation)
  - [📌 Custom Directories](#custom-directories)
  - [📌 Multi-Database Connections](#multi-database-connections)
  - [📌 Force Overwrite](#force-overwrite)
- [📁 Generated Files](#generated-files)
  - [🗃️ Model](#model)
  - [🔧 Service](#service)
  - [🎮 Controller](#controller)
  - [🛣️ Routes](#routes)
- [🔍 Runtime Query API](#runtime-query-api)
  - [🔎 Filtering](#filtering)
  - [🔀 Sorting](#sorting)
  - [📄 Pagination](#pagination)
  - [🔤 Full-Text Search](#full-text-search)
  - [🔗 OR Groups](#or-groups)
  - [📥 Eager Loading (Relations)](#eager-loading-relations)
- [🗺️ Schema Customization (`mapSchema`)](#schema-customization-mapschema)
  - [📝 Defining Fields](#defining-fields)
  - [🔗 Adding JOINs](#adding-joins)
  - [🛡️ Static WHERE Conditions](#static-where-conditions)
  - [💾 Raw SQL Expressions](#raw-sql-expressions)
- [📊 DataTables Integration](#datatables-integration)
- [🔄 Model Revamping](#model-revamping)
- [🚨 Exception Handling](#exception-handling)
- [🧩 Traits Reference](#traits-reference)
- [🗂️ Configuration Reference](#configuration-reference)
- [📄 License](#license)

---

## ✨ Features

- **One-command API generation** — generates Model, Service, Controller, and Routes from any database table
- **Generate all tables at once** with `--table=all`, auto-skipping system tables
- **Selective generation** — only generate what you need (`model`, `service`, `controller`, `route`)
- **Smart validation rules** — auto-derived from column types with name-based heuristics (email, phone, uuid, slug, url, coordinates, and more)
- **Automatic type casting** — `$casts` populated from database column types
- **Soft Delete detection** — automatically adds `SoftDeletes` trait when a `deleted_at` column is present
- **Rich runtime query API** — filter, sort, search, and paginate any endpoint with query parameters
- **16 filter operators** — from `eq`/`neq` to `between`, `in`, `like`, `null`, `date_from`, and more
- **Eager loading** — load Eloquent relations on-the-fly via `?with=relation1,relation2.nested`
- **DataTables server-side** — every generated controller includes a `/resource_datatable` endpoint
- **Schema customization** — define custom `SELECT` columns, `JOIN`s, and static `WHERE` conditions via `mapSchema()`
- **Model Revamping** — surgically update an existing model's casts, rules, and schema when your table changes
- **Multi-database connection support** — target any configured database connection
- **SQL injection safe** — all column names are whitelisted and sanitized
- **OpenAPI annotations** — controller methods pre-annotated for Swagger/L5-Swagger

---

## 📋 Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.1` |
| Laravel | `^10.0`, `^11.0`, or `^12.0` |

Supported databases: **MySQL**, **MariaDB**, **PostgreSQL**, **SQLite**, **SQL Server**.

---

## 📦 Installation

Install the package via Composer:

```bash
composer require rakhavirgiandi/laravel-apigator
```

Laravel's auto-discovery will register the service provider automatically. No manual registration is required.

Optionally, publish the configuration file:

```bash
php artisan vendor:publish --tag=apigator-config
```

This creates `config/apigator.php` in your project, which you can customize to your liking.

---

## ⚙️ Configuration

After publishing, open `config/apigator.php`:

```php
return [
    // Directory for generated controllers (relative to app/)
    'controller_directory' => 'Http/Controllers/API',

    // Directory for generated models (relative to app/)
    'model_directory' => 'Models',

    // Directory for generated services (relative to app/)
    'service_directory' => 'Services',

    // Route URL segment delimiter: '_' → /my_resource, '-' → /my-resource
    'route_delimiter' => '_',

    // Route file where generated routes are appended
    'route_file' => 'routes/api.php',

    // Default items per page for paginated responses
    'default_per_page' => 10,

    // Tables to skip when using --table=all
    'exclude_tables' => [
        'migrations',
        'password_resets',
        'password_reset_tokens',
        'failed_jobs',
        'personal_access_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
    ],
];
```

All settings can also be overridden at runtime via command-line options (see [Command Reference](#the-apigatorgenerate-command)).

---

## 🚀 Quick Start

Suppose you have a `products` table. Run:

```bash
php artisan apigator:generate --table=products
```

That's it. Apigator will create:

```
app/
├── Http/Controllers/API/ProductController.php
├── Models/Product.php
└── Services/ProductService.php

routes/api.php  ← 6 routes appended automatically
```

Your API is now fully functional:

| Method | Endpoint | Action |
|---|---|---|
| `GET` | `/products` | Paginated list |
| `GET` | `/products/{id}` | Single record |
| `POST` | `/products` | Create |
| `PATCH` | `/products/{id}` | Update |
| `DELETE` | `/products/{id}` | Delete |
| `POST` | `/products_datatable` | DataTables server-side |

---

## 🛠️ The `apigator:generate` Command

```
php artisan apigator:generate [options]
```

### 📌 Generating a Single Table

```bash
php artisan apigator:generate --table=orders
```

Generates `Order`, `OrderService`, `OrderController`, and appends routes for the `orders` table.

### 📌 Generating All Tables

```bash
php artisan apigator:generate --table=all
```

Iterates every table in the database, skipping the ones listed in `exclude_tables`. Reports generated vs. skipped at the end.

### 📌 Selective Generation

Use `--generate` to control which components are created. Accepts a comma-separated list of: `model`, `service`, `controller`, `route`.

```bash
# Only generate the model
php artisan apigator:generate --table=invoices --generate=model

# Generate model and service, but not controller or routes
php artisan apigator:generate --table=invoices --generate=model,service

# Generate everything except routes
php artisan apigator:generate --table=invoices --generate=model,service,controller
```

> **Note:** The generator respects dependency order. Generating a `controller` requires the `service` file to already exist, and generating a `service` requires the `model` to exist.

### 📌 Custom Directories

Override the default output paths on a per-run basis:

```bash
php artisan apigator:generate \
  --table=users \
  --model-dir=Domain/Users/Models \
  --service-dir=Domain/Users/Services \
  --controller-dir=Http/Controllers/V1
```

Namespaces are derived automatically from the directory path (e.g. `Domain/Users/Models` → `App\Domain\Users\Models`).

### 📌 Multi-Database Connections

Target any connection defined in `config/database.php`:

```bash
php artisan apigator:generate --table=customers --connection=secondary_db
```

When a non-default connection is specified, the generated model will include a `$connection` property set to that connection name.

### 📌 Force Overwrite

By default, Apigator will not overwrite existing files. Add `--force` to regenerate:

```bash
php artisan apigator:generate --table=products --force
```

> ⚠️ This will completely replace existing model, service, and controller files. Any manual customizations will be lost. Consider using [`--revamp-table`](#model-revamping) to update only specific sections of an existing model.

---

## 📁 Generated Files

### 🗃️ Model is a fully-featured Eloquent model with:

**`$fillable`** — automatically populated with all non-system columns (`id`, `created_at`, `updated_at`, `deleted_at` are excluded):

```php
protected $fillable = [
    'name',
    'price',
    'category_id',
    'is_active',
    // ...
];
```

**`$casts`** — column types are mapped to appropriate PHP types:

| DB Type | PHP Cast |
|---|---|
| `int`, `bigint`, `integer` | `integer` |
| `tinyint` | `boolean` |
| `decimal`, `numeric` | `decimal:2` |
| `float`, `double`, `real` | `float` |
| `json`, `jsonb` | `array` |
| `date` | `date` |
| `datetime`, `timestamp` | `datetime` |
| Everything else | `string` |

**Validation rules** — both `createRules()` (for `POST`) and `updateRules()` (for `PATCH`) are auto-generated with smart type + name-based heuristics. See the full [Validation Rules](#validation-rules-reference) table below.

**`mapSchema()`** — a customizable method that defines which columns to `SELECT`, any `JOIN`s to apply, and static `WHERE` conditions. See [Schema Customization](#schema-customization-mapschema).

**Soft Deletes** — if a `deleted_at` column is detected, the `SoftDeletes` trait is automatically imported and applied.

#### Validation Rules Reference

Beyond basic type rules, Apigator applies smart name-based heuristics:

| Column Name Pattern | Generated Rule |
|---|---|
| Contains `email` | `string`, `email:rfc,dns` |
| Matches `url`, `link`, `website`, `endpoint` | `string`, `url` |
| Matches `uuid`, `guid` | `string`, `uuid` |
| Matches `ip_address`, `ip_addr` | `string`, `ip` |
| Matches `phone`, `mobile`, `handphone`, `telp` | `string`, `regex:/^+?[0-9\s\-().]{7,20}$/` |
| Exactly `password` | `string`, `min:8` |
| Exactly `password_confirmation` | `string`, `same:password` |
| Exactly `slug` or ends with `_slug` | `string`, slug regex |
| Matches `username`, `user_name` | `string`, `min:3`, `max:30`, alphanumeric regex |
| Matches `color`, `colour` | `string`, hex color regex |
| Matches `lat`, `latitude` | `numeric`, `between:-90,90` |
| Matches `lng`, `lon`, `longitude` | `numeric`, `between:-180,180` |
| Matches `price`, `amount`, `qty`, `total`, etc. | `numeric`, `min:0` |
| Exactly `age` | `integer`, `min:0`, `max:150` |
| Ends with `_id` | `integer`, `min:1` |
| `ENUM` or `SET` column | `Rule::in([...values])` |

---

### 🔧 Service (`app/Services/ProductService.php`) provides a clean static API for all CRUD operations. It handles validation, database transactions, and error handling for you.

```php
// Paginated list
ProductService::getList($request->all());

// Single record by ID
ProductService::getById($id, $request->all());

// Create (validates against createRules(), wraps in DB transaction)
ProductService::createRecord($request->all());

// Update (validates against updateRules(), wraps in DB transaction)
ProductService::updateRecord($id, $request->all());

// Delete (soft-delete aware)
ProductService::deleteRecord($id);

// DataTables server-side response
ProductService::getDatatable($request->all());
```

Every mutating method (`createRecord`, `updateRecord`, `deleteRecord`) runs inside a database transaction and throws an `ApigatorException` on failure, which Laravel's exception handler renders automatically as a clean JSON response.

---

### 🎮 Controller (`app/Http/Controllers/API/ProductController.php`) is a thin layer that delegates to the service and formats responses:

```php
class ProductController extends Controller
{
    use ApiControllerTrait;

    public function index(Request $request): JsonResponse
    {
        $result = ProductService::getList($request->all());
        return $this->successResponse($result);
    }

    public function store(Request $request): JsonResponse
    {
        $record = ProductService::createRecord($request->all());
        return $this->successResponse($record, 'Product created successfully.', 201);
    }

    // show, update, destroy, datatable ...
}
```

All methods include pre-written **OpenAPI / Swagger annotations** (`@OA\Get`, `@OA\Post`, etc.), ready to be picked up by tools like [L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger).

---

### 🛣️ Routes to your route file (default: `routes/api.php`) inside a clearly marked block:

```php
// [APIGATOR_ENDPOINTS] products
Route::get('/products',              [ProductController::class, 'index']);
Route::get('/products/{id}',         [ProductController::class, 'show']);
Route::post('/products',             [ProductController::class, 'store']);
Route::patch('/products/{id}',       [ProductController::class, 'update']);
Route::delete('/products/{id}',      [ProductController::class, 'destroy']);
Route::post('/products_datatable',   [ProductController::class, 'datatable']);
```

The `use ProductController;` import statement is also injected automatically. Running the command a second time will not duplicate routes — Apigator checks for the marker and skips gracefully.

---

## 🔍 Runtime Query API

Every generated endpoint supports a rich query API out of the box, driven entirely by request parameters. No extra code required.

### 🔎 Filtering

Append filter parameters as query strings. The default operator is `eq` (equality).

```
GET /products?is_active=1
GET /products?category_id=5
```

Use bracket notation to apply a specific operator:

```
GET /products?price[gte]=100
GET /products?price[lte]=500
GET /products?name[like]=phone
GET /products?created_at[date_from]=2024-01-01&created_at[date_to]=2024-12-31
GET /products?status[in]=active,pending
GET /products?deleted_at[null]=1
```

**Available operators:**

| Operator | SQL Equivalent | Example |
|---|---|---|
| `eq` (default) | `= value` | `?status=active` |
| `neq` | `!= value` | `?status[neq]=inactive` |
| `gt` | `> value` | `?price[gt]=100` |
| `gte` | `>= value` | `?price[gte]=100` |
| `lt` | `< value` | `?stock[lt]=10` |
| `lte` | `<= value` | `?stock[lte]=50` |
| `like` | `LIKE %value%` | `?name[like]=apple` |
| `starts` | `LIKE value%` | `?name[starts]=pro` |
| `ends` | `LIKE %value` | `?name[ends]=plus` |
| `in` | `IN (a, b, c)` | `?status[in]=active,pending` |
| `not_in` | `NOT IN (a, b, c)` | `?status[not_in]=deleted` |
| `null` | `IS NULL` | `?deleted_at[null]=1` |
| `not_null` | `IS NOT NULL` | `?published_at[not_null]=1` |
| `between` | `BETWEEN a AND b` | `?price[between]=10,100` |
| `date_from` | `DATE(col) >= value` | `?created_at[date_from]=2024-01-01` |
| `date_to` | `DATE(col) <= value` | `?created_at[date_to]=2024-12-31` |

> **Security:** All column names are validated against a whitelist derived from your schema. Unknown or unallowed columns are silently ignored, preventing SQL injection.

### 🔀 Sorting

Use `_sort` to specify sort columns. Prefix with `-` for descending order. Chain multiple columns with commas.

```
GET /products?_sort=name            → ORDER BY name ASC
GET /products?_sort=-price          → ORDER BY price DESC
GET /products?_sort=category_id,-price  → ORDER BY category_id ASC, price DESC
```

### 📄 Pagination

```
GET /products?page=2&per_page=25
```

The response includes a `meta` object:

```json
{
  "meta": {
    "current_page": 2,
    "per_page": 25,
    "total_pages": 10,
    "total_items": 250
  },
  "data": [ ... ]
}
```

The `per_page` value is clamped between `1` and `1000`. The default is controlled by `default_per_page` in the config.

### 🔤 Full-Text Search

Use `_search` to perform a `LIKE` search across all string-type columns simultaneously:

```
GET /products?_search=wireless+headphones
```

This generates a `WHERE (col1 LIKE '%wireless headphones%' OR col2 LIKE '%wireless headphones%' OR ...)` query across every searchable text column defined in your schema.

### 🔗 OR Groups

Combine multiple conditions with OR logic using the `_or` parameter:

```
GET /products?_or[0][status][eq]=active&_or[1][featured][eq]=1
```

This generates:

```sql
WHERE (status = 'active' OR featured = 1)
```

You can mix multiple operators within each group:

```
GET /products?_or[0][price][lt]=50&_or[1][price][gt]=500
```

### 📥 Eager Loading (Relations)

Load Eloquent relations on-the-fly without modifying the controller:

```
GET /products?with=category
GET /products?with=category,tags
GET /products?with=category.parent
GET /products?with=category,reviews.user
```

Apigator validates each relation segment against the model before passing it to `->with()`. Invalid or misspelled relations are silently dropped, so a client typo will never cause a 500 error.

---

## 🗺️ Schema Customization (`mapSchema`)

The `mapSchema()` method is the heart of Apigator's query engine. It lets you control exactly which columns are selected, which tables are joined, and which conditions are always applied — all without touching controller or service logic.

The generated version selects only the table's own columns, but you can freely extend it.

### 📝 Defining Fields

Each entry in `'field'` maps an alias to a `column` expression:

```php
public static function mapSchema(array $params = []): array
{
    $model = new self;

    return [
        'field' => [
            'id'          => ['column' => $model->table.'.id',          'alias' => 'id',          'type' => 'int'],
            'name'        => ['column' => $model->table.'.name',        'alias' => 'name',        'type' => 'string'],
            'price'       => ['column' => $model->table.'.price',       'alias' => 'price',       'type' => 'float'],
            'category_id' => ['column' => $model->table.'.category_id', 'alias' => 'category_id', 'type' => 'int'],
        ],
        'join'  => [],
        'where' => [],
    ];
}
```

**Field definition keys:**

| Key | Description |
|---|---|
| `column` | The actual SQL column expression (`table.column` or raw SQL) |
| `alias` | The key name in the response JSON |
| `type` | `string`, `int`, `float`, `bool`, `date`, `datetime`, `json` |
| `is_raw` | Set to `true` to treat `column` as a raw SQL expression |

> **Searchable columns:** Only `string`-type fields are included in `_search` full-text queries. Set `type` accurately for correct behavior.

### 🔗 Adding JOINs

Extend the `'join'` array to join related tables:

```php
'join' => [
    [
        'table' => 'categories as c',
        'type'  => 'left',
        'on'    => ['c.id', '=', $model->table.'.category_id'],
    ],
    [
        'table' => 'brands as b',
        'type'  => 'inner',
        'on'    => ['b.id', '=', $model->table.'.brand_id'],
    ],
],
```

Then add the joined columns to `'field'`:

```php
'field' => [
    // ... own columns ...
    'category_name' => ['column' => 'c.name', 'alias' => 'category_name', 'type' => 'string'],
    'brand_name'    => ['column' => 'b.name', 'alias' => 'brand_name',    'type' => 'string'],
],
```

Supported join types: `left`, `right`, `inner` (default).

### 🛡️ Static WHERE Conditions

Use `'where'` to apply conditions that are always active, regardless of request parameters:

```php
'where' => [
    // Only return published products
    ['column' => $model->table.'.is_published', 'operator' => '=',       'value' => 1],
    // Exclude archived records
    ['column' => $model->table.'.archived_at',  'operator' => 'IS NULL', 'value' => null],
],
```

### 💾 Raw SQL Expressions

Set `is_raw => true` to use any SQL expression as a column:

```php
'field' => [
    'full_name' => [
        'column' => "CONCAT(u.first_name, ' ', u.last_name)",
        'alias'  => 'full_name',
        'type'   => 'string',
        'is_raw' => true,
    ],
    'age' => [
        'column' => 'TIMESTAMPDIFF(YEAR, u.birth_date, CURDATE())',
        'alias'  => 'age',
        'type'   => 'int',
        'is_raw' => true,
    ],
],
```

### ⚡ Dynamic Context in `mapSchema`

The `$params` arguments are passed in from the request, allowing you to build dynamic schemas:

```php
public static function mapSchema(array $params = []): array
{
    $model = new self;

    $user_id = isset($params['user_id']) ? $params['user_id'] : null;

    $fields = [
        'id'   => ['column' => $model->table.'.id',   'alias' => 'id',   'type' => 'int'],
        'name' => ['column' => $model->table.'.name', 'alias' => 'name', 'type' => 'string'],
    ];

    // Only expose the cost_price field to admin users
    if ($user_id) {
        $fields['cost_price'] = ['column' => $model->table.'.cost_price', 'alias' => 'cost_price', 'type' => 'float'];
    }

    return [
        'field' => $fields,
        'join'  => [],
        'where' => [
            ['column' => $model->table.'.tenant_id', 'operator' => '=', 'value' => $user_id],
        ],
    ];
}
```

---

## 📊 DataTables Integration

Every generated controller includes a `datatable` action that accepts a standard DataTables server-side `POST` payload:

```
POST /products_datatable
Content-Type: application/json

{
  "draw": 1,
  "start": 0,
  "length": 25,
  "search": { "value": "wireless" },
  "order": [{ "column": 2, "dir": "asc" }],
  "columns": [
    { "data": "id",    "name": "id",    "searchable": "true", "orderable": "true" },
    { "data": "name",  "name": "name",  "searchable": "true", "orderable": "true" },
    { "data": "price", "name": "price", "searchable": "false","orderable": "true" }
  ]
}
```

The response follows the DataTables format:

```json
{
  "draw": 1,
  "recordsTotal": 500,
  "recordsFiltered": 12,
  "data": [ ... ]
}
```

The endpoint supports:
- **Global search** across all `searchable: true` string columns
- **Per-column search** via the `columns[n].search.value` field
- **Multi-column ordering** via the `order` array
- **All dynamic filter parameters** from the [Runtime Query API](#runtime-query-api), appended alongside DataTables parameters

---

## 🔄 Model Revamping

When your database schema changes (new columns added, types changed, columns removed), use `--revamp-table` to surgically update your existing model without losing any manual customizations:

```bash
# Revamp a single model
php artisan apigator:generate --revamp-table=products

# Revamp all models
php artisan apigator:generate --revamp-table=all
```

The revamper **only touches three sections** of your model file:

| Section | What changes |
|---|---|
| `protected $fillable` | Rebuilt entirely from current DB columns |
| `protected $casts` | Rebuilt entirely from current DB column types |
| `createRules()` return array | Rebuilt from current DB columns |
| `updateRules()` return array | Rebuilt from current DB columns |
| `mapSchema()` field entries | Only **own-table** entries are replaced; custom entries from joined tables are preserved |

Everything else — your Eloquent relations, custom methods, joins, static wheres, and comments — is left completely untouched.

**Example workflow for an evolving schema:**

```bash
# 1. Initial generation
php artisan apigator:generate --table=products

# 2. You manually add relations and joins to Product.php ...

# 3. Your team adds a `sku` column and removes `legacy_code`
php artisan apigator:generate --revamp-table=products
# ✅ $fillable, $casts, rules, and own-table schema fields updated
# ✅ Your custom relations and joined table fields preserved
```

---

## 🚨 Exception Handling

Apigator ships with two exception classes that integrate with Laravel's exception handler to return consistent JSON error responses automatically.

### `ApigatorException`

A general-purpose HTTP exception. Laravel calls its `render()` method automatically, so you never need to manually catch it in controllers.

**Named constructors for common scenarios:**

```php
use Virgiandi\Apigator\Support\ApigatorException;

// 400 Bad Request
throw ApigatorException::withMessage('Invalid input.');

// 401 Unauthorized
throw ApigatorException::unauthorized();

// 403 Forbidden
throw ApigatorException::forbidden();

// 404 Not Found
throw ApigatorException::notFound(
    translationKey: 'errors.product_not_found',
    errorCode: 'PRODUCT_NOT_FOUND'
);

// 422 Unprocessable
throw ApigatorException::unprocessable();

// 500 Server Error
throw ApigatorException::serverError();
```

**JSON response shape:**

```json
{
  "success": false,
  "message": "Not found.",
  "error_code": "PRODUCT_NOT_FOUND"
}
```

### `ApigatorValidationException`

Extends `ApigatorException` with field-level validation errors. Always returns HTTP `422`.

```php
use Virgiandi\Apigator\Support\ApigatorValidationException;

// From a Laravel Validator instance
$validator = Validator::make($data, $rules);
if ($validator->fails()) {
    throw ApigatorValidationException::fromValidator($validator);
}

// From a ValidationException
throw ApigatorValidationException::fromValidation($e);

// With manually specified errors
throw ApigatorValidationException::withErrors([
    'email' => ['This email is already registered.'],
    'items' => ['Cart cannot be empty.'],
]);
```

**JSON response shape:**

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "error_code": "VALIDATION_FAILED",
  "errors": {
    "email": ["This email is already registered."],
    "items": ["Cart cannot be empty."]
  }
}
```

> **Logging behavior:** `ApigatorException` only logs to your error tracker for `5xx` responses. `ApigatorValidationException` (422) is never logged, keeping your logs clean from expected client errors.

---

## 🧩 Traits Reference

### `ApiModelTrait`

Mixed into every generated model. Provides the query engine that powers the runtime API.

| Method | Description |
|---|---|
| `buildBaseQuery(array $params)` | Builds the base Eloquent query by applying `mapSchema()`, dynamic filters, sorting, and eager loads |
| `applyEagerLoads(Builder $query, $instance, $with)` | Validates and applies `?with=` relation chains |
| `applyDatatableSearch(Builder $query, array $params)` | Applies DataTables global and per-column search |
| `applyDatatableOrder(Builder $query, array $params)` | Applies DataTables column ordering |

You can call `buildBaseQuery()` directly in your own service methods if you need to build on top of the generated query:

```php
// Custom service method example
public static function getTopRated(array $params): array
{
    $query = Product::buildBaseQuery($params);
    $query->where('rating', '>=', 4.5)->orderBy('rating', 'desc');
    return $query->limit(10)->get()->toArray();
}
```

### `ApiControllerTrait`

Mixed into every generated controller. Provides standardized JSON response helpers.

```php
// 200 OK with data
$this->successResponse($data);

// 200 OK with custom message
$this->successResponse($data, 'Product updated successfully.');

// 201 Created
$this->successResponse($record, 'Product created.', 201);

// 400 Bad Request
$this->errorResponse('Something went wrong.', 400);

// 404 Not Found
$this->notFoundResponse('Product');

// 422 Validation Error
$this->validationErrorResponse($validationException);
```

**Response shape for `successResponse`:**

```json
{
  "success": true,
  "message": "Product updated successfully.",
  "data": { ... }
}
```

---

## 🗂️ Configuration Reference

| Key | Type | Default | Description |
|---|---|---|---|
| `controller_directory` | `string` | `Http/Controllers/API` | Controller output directory (relative to `app/`) |
| `model_directory` | `string` | `Models` | Model output directory (relative to `app/`) |
| `service_directory` | `string` | `Services` | Service output directory (relative to `app/`) |
| `route_delimiter` | `string` | `_` | URL segment delimiter (`_` → `/my_resource`, `-` → `/my-resource`) |
| `route_file` | `string` | `routes/api.php` | Route file where generated routes are appended |
| `default_per_page` | `int` | `10` | Default pagination page size |
| `exclude_tables` | `array` | *(Laravel system tables)* | Tables to skip during `--table=all` generation |

---

## 📄 License

This package is open-source software licensed under the [MIT License](https://opensource.org/licenses/MIT).

---

<p align="center">
  Made with ❤️ by <a href="mailto:rakhavirgiandi@gmail.com">Rakha R. Virgiandi</a>
</p>