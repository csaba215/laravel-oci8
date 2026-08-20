<?php

namespace Yajra\Oci8\Tests\Database;

use Illuminate\Database\Query\Builder;
use Mockery as m;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Yajra\Oci8\Oci8Connection;
use Yajra\Oci8\Query\Processors\OracleProcessor;

class OracleProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_native_pdo_oci_uses_streams_and_native_output_lengths_for_lobs()
    {
        [$query, $statement] = $this->queryWithDriver('oci');
        $processor = new OracleProcessor;

        $processor->saveLob($query, 'insert into blobs values (?, ?) returning id into ?', ['name'], ['blob']);

        $this->assertSame(PDO::PARAM_STR, $statement->bindings[0]['type']);
        $this->assertSame(PDO::PARAM_LOB, $statement->bindings[1]['type']);
        $this->assertSame(3, $statement->bindings[1]['argument_count']);
        $this->assertIsResource($statement->bindings[1]['value']);
        $this->assertSame('blob', stream_get_contents($statement->bindings[1]['value']));
        $this->assertSame(PDO::PARAM_INT, $statement->bindings[2]['type']);
        $this->assertSame(32, $statement->bindings[2]['length']);
        $this->assertSame(4, $statement->bindings[2]['argument_count']);
    }

    public function test_oci8_compatibility_driver_keeps_legacy_lob_bindings()
    {
        [$query, $statement] = $this->queryWithDriver('oci8');
        $processor = new OracleProcessor;

        $processor->saveLob($query, 'insert into blobs values (?, ?) returning id into ?', ['name'], ['blob']);

        $this->assertSame('blob', $statement->bindings[1]['value']);
        $this->assertSame(PDO::PARAM_LOB, $statement->bindings[1]['type']);
        $this->assertSame(-1, $statement->bindings[1]['length']);
        $this->assertSame(PDO::PARAM_INT, $statement->bindings[2]['type']);
        $this->assertSame(-1, $statement->bindings[2]['length']);
    }

    private function queryWithDriver(string $driver): array
    {
        $statement = new OracleProcessorTestStatement;
        $pdo = new OracleProcessorTestPdo($driver, $statement);
        $connection = m::mock(Oci8Connection::class);
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('recordsHaveBeenModified')->once();
        $connection->shouldReceive('logQuery')->once();

        $query = m::mock(Builder::class);
        $query->shouldReceive('getConnection')->andReturn($connection);

        return [$query, $statement];
    }
}

class OracleProcessorTestPdo extends PDO
{
    public function __construct(
        private readonly string $driver,
        private readonly PDOStatement $statement
    ) {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->statement;
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? $this->driver : null;
    }
}

class OracleProcessorTestStatement extends PDOStatement
{
    public array $bindings = [];

    public function __construct() {}

    public function bindParam(
        string|int $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        $this->bindings[] = [
            'parameter' => $param,
            'value' => $var,
            'type' => $type,
            'length' => $maxLength,
            'driver_options' => $driverOptions,
            'argument_count' => func_num_args(),
        ];

        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }
}
