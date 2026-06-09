<?php

namespace Virgiandi\Apigator\Traits;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

trait ApiServiceTrait
{
    /**
     * Return the Eloquent model class string tied to this service.
     * Each generated service must implement this method.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    abstract protected static function modelClass(): string;

    /**
     * Return a DB connection that always matches the model's configured connection.
     * Uses the model's $connection property, falling back to the default connection.
     *
     * @return Connection
     */
    protected static function db(): Connection
    {
        $class = static::modelClass();
        return DB::connection((new $class)->getConnectionName());
    }
}