---
name: laravel-oci8-development
description: Build and troubleshoot Oracle database features in Laravel applications using yajra/laravel-oci8. Use when configuring OCI8 connections, writing Oracle migrations or queries, handling sequences and LOBs, or calling PL/SQL procedures and functions.
---

# Laravel OCI8 Development

Use this package's Oracle connection, query grammar, schema builder, and Eloquent extensions when working with `yajra/laravel-oci8`.

## Connection configuration

- Inspect the application's existing Oracle connection and the installed package version before changing configuration. This package's 13.x release targets Laravel 13 and requires PHP 8.3+, `ext-oci8`, and `ext-pdo`.
- Use the `oracle` driver. Laravel discovers the package's service providers automatically.
- Publish configuration only when customization is needed: `php artisan vendor:publish --tag=oracle`. The resulting `config/oracle.php` contains an `oracle` connection entry that the provider merges into `database.connections`.
- Configure credentials through environment variables: `DB_CONNECTION=oracle`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_SERVICE_NAME`, `DB_USERNAME`, and `DB_PASSWORD`. Inspect `DB_TNS` when the application uses a TNS descriptor.
- Set `DB_SERVER_VERSION` to the actual database version. SQL feature selection uses the configured `server_version`, which defaults to `11g`; it is not automatically inferred from the connected server.
- `DB_PREFIX` prefixes table names; `DB_SCHEMA_PREFIX` qualifies objects with a schema. Keep these purposes distinct.
- Object names default to a 30-character limit. Set `ORA_MAX_NAME_LEN=128` only for databases supporting longer identifiers (Oracle 12cR2+).

Select the intended connection explicitly in applications using multiple databases:

```php
use Illuminate\Support\Facades\DB;

$oracle = DB::connection('oracle');
$users = $oracle->table('users')->where('active', 1)->get();
```

## Queries and migrations

Prefer Laravel's query and schema builders so the Oracle grammars handle identifiers, bindings, pagination, and generated IDs. Use the README's **Laravel Builder Compatibility** tables in the installed package to check less common operations.

- Oracle 12c+ supports identity columns and lateral joins. Earlier versions use sequences and triggers for incrementing columns. Let the schema builder choose based on `server_version`.
- The query builder splits large `whereIn()` lists automatically to respect Oracle's 1,000-expression limit.
- JSON path updates such as `update(['options->theme' => 'dark'])` require Oracle 19c+. Update only one path per JSON column in a single call. On older databases, read and update the full JSON document.
- Schema JSON columns use native `JSON` on Oracle 21c+ and `CLOB` on older versions, with JSON constraints on Oracle 12c through 19c.
- `upsert()` and `insertOrIgnore()` use `MERGE`. For ordinary tables, `insertOrIgnore()` matches all inserted columns; do not assume it suppresses every unique-key conflict.
- `sharedLock()` compiles to `FOR UPDATE`. Account for the resulting update lock.
- Do not assume MySQL schema modifiers work on Oracle: `unsigned()`, `after()`, and foreign-key update actions have no effect. Consult the compatibility tables before choosing an alternative.

## Eloquent, sequences, and LOBs

Standard Laravel models work for ordinary queries. Use `Yajra\Oci8\Eloquent\OracleEloquent` when the model needs the package's sequence or BLOB handling. Match the existing schema's primary key, timestamps, and sequence configuration.

```php
use Yajra\Oci8\Eloquent\OracleEloquent;

class Document extends OracleEloquent
{
    protected $connection = 'oracle';

    protected $table = 'documents';

    protected $binaries = ['content'];

    public $sequence = 'documents_id_seq';
}
```

Set `$sequence` for an existing custom sequence; the default sequence name is `{table}_{primaryKey}_seq`. Do not add a sequence override to an identity-based table without checking its existing behavior.

For query-builder LOB operations, inspect `insertLob()` and `updateLob()` in `src/Oci8/Query/OracleBuilder.php`. They accept ordinary values and binary values separately; their third argument identifies the returned key column (default `id`). Scope updates with a `where` clause.

## PL/SQL calls

Use the methods on `Yajra\Oci8\Oci8Connection`:

- `executeFunction($name, $bindings, $returnType, $length)` returns the function result; the default return type is `PDO::PARAM_STR`.
- `executeProcedure($name, $bindings)` returns an execution boolean. Receive OUT values through referenced bindings.
- `executeProcedureWithCursor($name, $bindings, ':cursor')` returns an array of row objects and appends the cursor argument after the other arguments. For another cursor position, prepare and bind a statement explicitly.

Binding keys omit the leading colon. Arguments are passed positionally in array insertion order, so match the PL/SQL signature. Binding descriptors support `value`, `type`, `length`, and `options`.

```php
use Illuminate\Support\Facades\DB;

$status = '';

DB::connection('oracle')->executeProcedure('app_pkg.process_order', [
    'p_order_id' => 123,
    'p_status' => [
        'value' => &$status,
        'type' => PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT,
        'length' => 200,
    ],
]);
```

Choose output types and buffer lengths to match the procedure declaration. Routine names are interpolated into PL/SQL; use application-controlled identifiers and bind data values.

## Sessions and troubleshooting

- Configure NLS defaults through the connection's `sessionVars`. The package defaults date and timestamp formats to `YYYY-MM-DD HH24:MI:SS`.
- `setDateFormat()`, `setSessionVars()`, `setSchema()`, `useCaseInsensitiveSession()`, and `useCaseSensitiveSession()` affect the current connection's session. Account for session reuse when changing them in long-running workers.
- Session options and schema names are interpolated into `ALTER SESSION`; use trusted configuration values.
- `serverVersion()` returns the configured version. `getServerVersion()` queries Oracle's `v$version`; use the former when investigating grammar feature selection.
- For static analysis of OCI8-specific facade methods, include `vendor/yajra/laravel-oci8/extension.neon` in the application's PHPStan configuration.
- Verify Oracle-specific SQL and binding behavior against Oracle. SQLite tests cannot establish Oracle compatibility.

When a signature or supported feature is uncertain, inspect the installed package's `src/Oci8/Oci8Connection.php`, query/schema grammars, and README. These paths are relative to the package root, normally `vendor/yajra/laravel-oci8`. Additional documentation is available at https://yajrabox.com/docs/laravel-oci8; select the documentation matching the installed release.
