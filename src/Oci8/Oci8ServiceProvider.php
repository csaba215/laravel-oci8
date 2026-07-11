<?php

namespace Yajra\Oci8;

use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use Yajra\Oci8\Auth\OracleUserProvider;
use Yajra\Oci8\Connectors\OracleConnector as Connector;
use Yajra\Oci8\Storage\BlobStorageAdapter;
use Yajra\Oci8\Storage\BlobStorageResolver;
use Yajra\Oci8\Storage\DefaultBlobStorageResolver;

class Oci8ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/oracle.php' => config_path('oracle.php'),
        ], 'oracle');

        // Testing for existence of AuthServiceProvider before invoking it
        // prevents errors when used with laravel-zero micro-framework which
        // doesn't need auth.
        if (class_exists(AuthServiceProvider::class)) {
            Auth::provider('oracle', fn ($app, array $config) => new OracleUserProvider($app['hash'], $config['model']));
        }

        if ($this->app->resolved('filesystem')) {
            $this->registerBlobStorageDriver($this->app['filesystem']);
        } else {
            $this->app->afterResolving('filesystem', fn ($filesystem) => $this->registerBlobStorageDriver($filesystem));
        }
    }

    public function register(): void
    {
        if (file_exists(config_path('oracle.php'))) {
            $this->mergeConfigFrom(config_path('oracle.php'), 'database.connections');
        } else {
            $this->mergeConfigFrom(__DIR__.'/../config/oracle.php', 'database.connections');
        }

        Connection::resolverFor('oracle', function ($connection, $database, $prefix, $config) {
            if (! empty($config['dynamic'])) {
                call_user_func_array($config['dynamic'], [&$config]);
            }

            $connector = new Connector;
            $connection = $connector->connect($config);
            $db = new Oci8Connection($connection, $database, $prefix, $config);

            if (! empty($config['skip_session_vars'])) {
                return $db;
            }

            // set oracle session variables
            $sessionVars = [
                'NLS_TIME_FORMAT' => 'HH24:MI:SS',
                'NLS_DATE_FORMAT' => 'YYYY-MM-DD HH24:MI:SS',
                'NLS_TIMESTAMP_FORMAT' => 'YYYY-MM-DD HH24:MI:SS',
                'NLS_TIMESTAMP_TZ_FORMAT' => 'YYYY-MM-DD HH24:MI:SS TZH:TZM',
                'NLS_NUMERIC_CHARACTERS' => '.,',
                ...($config['sessionVars'] ?? []),
            ];

            // Like Postgres, Oracle allows the concept of "schema"
            if (isset($config['schema'])) {
                $sessionVars['CURRENT_SCHEMA'] = $config['schema'];
            }

            if (isset($config['session'])) {
                $sessionVars = array_merge($sessionVars, $config['session']);
            }

            if (isset($config['edition'])) {
                $sessionVars = array_merge(
                    $sessionVars,
                    ['EDITION' => $config['edition']]
                );
            }

            $db->setSessionVars($sessionVars);

            return $db;
        });
    }

    public function makeBlobStorageResolver(array $config): BlobStorageResolver
    {
        $resolver = $config['resolver'] ?? DefaultBlobStorageResolver::class;

        if ($resolver instanceof BlobStorageResolver) {
            return $resolver;
        }

        if (is_string($resolver)) {
            $resolver = $this->app->make($resolver);
        }

        if (! $resolver instanceof BlobStorageResolver) {
            throw new InvalidArgumentException('The oracle-blob disk resolver must implement '.BlobStorageResolver::class.'.');
        }

        return $resolver;
    }

    private function registerBlobStorageDriver($filesystem): void
    {
        $provider = $this;

        $filesystem->extend('oracle-blob', static function ($app, array $config) use ($provider): FilesystemAdapter {
            $resolver = $provider->makeBlobStorageResolver($config);
            $adapter = new BlobStorageAdapter(
                $app['db']->connection($config['connection'] ?? null),
                $resolver,
                $config
            );

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
