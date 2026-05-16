<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Controller Directory
    |--------------------------------------------------------------------------
    | Relative to app/ directory. Example: 'Http/Controllers/API'
    */
    'controller_directory' => 'Http/Controllers/API',

    /*
    |--------------------------------------------------------------------------
    | Default Model Directory
    |--------------------------------------------------------------------------
    | Relative to app/ directory. Example: 'Models'
    */
    'model_directory' => 'Models',

    /*
    |--------------------------------------------------------------------------
    | Default API route delimiter
    |--------------------------------------------------------------------------
    | Delimiter API hitpoint. Eample: (-) my-api | (_) my_api
    */
    'route_delimiter' => '_',

    /*
    |--------------------------------------------------------------------------
    | Default Route File
    |--------------------------------------------------------------------------
    | The route file where generated routes will be appended.
    */
    'route_file' => 'routes/api.php',

    /*
    |--------------------------------------------------------------------------
    | Default Pagination
    |--------------------------------------------------------------------------
    */
    'default_per_page' => 10,

    /*
    |--------------------------------------------------------------------------
    | Tables to exclude when using --table=all
    |--------------------------------------------------------------------------
    */
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
