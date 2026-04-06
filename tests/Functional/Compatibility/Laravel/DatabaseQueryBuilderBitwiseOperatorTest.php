<?php

namespace Yajra\Oci8\Tests\Functional\Compatibility\Laravel;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Builder;
use Yajra\Oci8\Tests\LaravelTestCase;

class DatabaseQueryBuilderBitwiseOperatorTest extends LaravelTestCase
{
    protected function createSchema()
    {
        $this->schema()->create('bitwise_flags', function ($table) {
            $table->increments('id');
            $table->integer('flags');
        });
    }

    protected function tearDown(): void
    {
        $this->schema()->dropIfExists('bitwise_flags');

        parent::tearDown();
    }

    public function test_where_supports_bitwise_operators()
    {
        foreach ($this->whereBitwiseCases() as [$operator, $values, $expected]) {
            DB::table('bitwise_flags')->truncate();
            DB::table('bitwise_flags')->insert(array_map(
                static fn (int $flags) => ['flags' => $flags],
                $values
            ));

            $results = DB::table('bitwise_flags')
                ->where('flags', $operator, 4)
                ->orderBy('id')
                ->pluck('flags')
                ->map(static fn ($flags) => (int) $flags)
                ->all();

            $this->assertSame($expected, $results, sprintf('Failed asserting where operator "%s".', $operator));
        }
    }

    public function test_having_supports_bitwise_operators()
    {
        foreach ($this->havingBitwiseCases() as [$operator, $values, $expected]) {
            DB::table('bitwise_flags')->truncate();
            DB::table('bitwise_flags')->insert(array_map(
                static fn (int $flags) => ['flags' => $flags],
                $values
            ));

            $results = DB::table('bitwise_flags')
                ->select('flags')
                ->groupBy('flags')
                ->having('flags', $operator, 4)
                ->orderBy('flags')
                ->pluck('flags')
                ->map(static fn ($flags) => (int) $flags)
                ->all();

            $this->assertSame($expected, $results, sprintf('Failed asserting having operator "%s".', $operator));
        }
    }

    protected function whereBitwiseCases(): array
    {
        return [
            ['&', [1, 4, 5, 8], [4, 5]],
            ['|', [0, 1, 4], [1, 4]],
            [$this->xorOperator(), [0, 1, 4], [0, 1]],
            ['<<', [0, 1, 4], [1, 4]],
            ['>>', [0, 1, 8], [8]],
        ];
    }

    protected function havingBitwiseCases(): array
    {
        return [
            ['&', [1, 4, 4, 5, 8], [4, 5]],
            ['|', [0, 0, 1, 4], [1, 4]],
            [$this->xorOperator(), [0, 0, 1, 4, 4], [0, 1]],
            ['<<', [0, 0, 1, 4, 4], [1, 4]],
            ['>>', [0, 1, 8, 8], [8]],
        ];
    }

    protected function xorOperator(): string
    {
        return $this->connection()->getDriverName() === 'pgsql' ? '#' : '^';
    }

    protected function schema($connection = 'default'): Builder
    {
        return $this->connection($connection)->getSchemaBuilder();
    }

    protected function connection($name = 'default')
    {
        return DB::connection($name);
    }
}
