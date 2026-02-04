<?php

namespace Yajra\Oci8\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Yajra\Oci8\Connectors\OracleConnector as Connector;
use Yajra\Oci8\Oci8Connection;

abstract class LaravelTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        $db = new DB;

        if (getenv('PGSQL') === 'true') {
            $db->addConnection([
                'driver' => 'pgsql',
                'host' => 'localhost',
                'port' => 5432,
                'database' => 'postgres',
                'username' => 'postgres',
                'password' => 'postgres',
                'options' => [
                    PDO::ATTR_PERSISTENT => false,
                ],
            ], 'default');

            $db->getDatabaseManager()->setDefaultConnection('default');
        } else {
            $db->addConnection([
                'driver' => 'oracle',
                'host' => 'localhost',
                'port' => 1521,
                'database' => 'xe',
                'service_name' => 'xe',
                'username' => 'system',
                'password' => 'oracle',
                'server_version' => getenv('SERVER_VERSION') ?: '11g',
            ], 'default');

            $db->addConnection([
                'driver' => 'oracle',
                'host' => 'localhost',
                'port' => 1521,
                'database' => 'xe',
                'service_name' => 'xe',
                'username' => 'second_connection',
                'password' => 'second_connection',
                'server_version' => getenv('SERVER_VERSION') ?: '11g',
            ], 'second_connection');

            $db->getDatabaseManager()->setDefaultConnection('default');
        }

        $db->bootEloquent();
        $db->setAsGlobal();
        try {
            try {
                $db->connection('default')->statement('ALTER SESSION SET "_ORACLE_SCRIPT" = true');
            } catch (\Illuminate\Database\QueryException $e) {
                // ORA-02248 = invalid option for ALTER SESSION (11g)
                if (! str_contains($e->getMessage(), 'ORA-02248')) {
                    throw $e;
                }
            }
            $db->connection('default')->statement('CREATE USER second_connection IDENTIFIED BY second_connection DEFAULT TABLESPACE USERS TEMPORARY TABLESPACE TEMP QUOTA UNLIMITED ON USERS');
            $db->connection('default')->statement('GRANT CREATE SESSION TO second_connection');
            $db->connection('default')->statement('GRANT CREATE TABLE, CREATE SEQUENCE, CREATE VIEW, CREATE TRIGGER TO second_connection');
        } catch (\Exception $e) {

            // ORA-01920 = user already exists
            if (! str_contains($e->getMessage(), 'ORA-01920')) {
                throw $e;
            }
        }

        if (method_exists($this, 'createSchema')) {
            $this->createSchema();
        }
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('default')->statement(
                'DROP USER second_connection CASCADE'
            );
        } catch (\Exception $e) {

        }

        try {
            DB::connection('default')->disconnect();
        } catch (\Throwable $e) {
            // ignore if already disconnected
        }

        parent::tearDown();
    }
}
