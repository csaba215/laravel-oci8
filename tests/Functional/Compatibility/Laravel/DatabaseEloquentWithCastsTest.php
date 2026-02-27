<?php

namespace Yajra\Oci8\Tests\Functional\Compatibility\Laravel;

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Yajra\Oci8\Tests\LaravelTestCase;

class DatabaseEloquentWithCastsTest extends LaravelTestCase
{
    protected function createSchema()
    {
        $this->schema()->create('times', function ($table) {
            $table->increments('id');
            $table->time('time');
            $table->timestamps();
        });

        $this->schema()->create('unique_times', function ($table) {
            $table->increments('id');
            $table->time('time')->unique();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->schema()->drop('times');
        $this->schema()->drop('unique_times');
    }

    public function test_with_first_or_new()
    {
        $time1 = Time::query()->withCasts(['time' => 'string'])
            ->firstOrNew(['time' => '07:30']);

        Time::query()->insert(['time' => '07:30']);

        $time2 = Time::query()->withCasts(['time' => 'string'])
            ->firstOrNew(['time' => '07:30']);

        $this->assertContains($time1->time, ['07:30', '07:30:00']);
        $this->assertSame(
            strlen($time1->time) === 5 ? $time1->time.':00' : $time1->time,
            strlen($time2->time) === 5 ? $time2->time.':00' : $time2->time
        );
    }

    public function test_with_first_or_create()
    {
        $time1 = Time::query()->withCasts(['time' => 'string'])
            ->firstOrCreate(['time' => '07:30']);

        $time2 = Time::query()->withCasts(['time' => 'string'])
            ->firstOrCreate(['time' => '07:30']);

        $this->assertSame($time1->id, $time2->id);
    }

    public function test_with_create_or_first()
    {
        $time1 = UniqueTime::query()->withCasts(['time' => 'string'])
            ->createOrFirst(['time' => '07:30']);

        $time2 = UniqueTime::query()->withCasts(['time' => 'string'])
            ->createOrFirst(['time' => '07:30']);

        $this->assertSame($time1->id, $time2->id);
    }

    public function test_throws_exception_if_castable_attribute_was_not_retrieved_and_prevent_missing_attributes_is_enabled()
    {
        Time::create(['time' => now()]);
        $originalMode = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();

        $this->expectException(MissingAttributeException::class);
        try {
            $time = Time::query()->select('id')->first();
            $this->assertNull($time->time);
        } finally {
            Model::preventAccessingMissingAttributes($originalMode);
        }
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }
}

class Time extends Eloquent
{
    protected $guarded = [];

    protected $casts = [
        'time' => 'datetime',
    ];
}

class UniqueTime extends Eloquent
{
    protected $guarded = [];

    protected $casts = [
        'time' => 'datetime',
    ];
}
