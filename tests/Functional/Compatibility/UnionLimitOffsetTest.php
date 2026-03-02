<?php

namespace Yajra\Oci8\Tests\Functional\Compatibility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Yajra\Oci8\Tests\TestCase;

class UnionLimitOffsetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('user_events', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('kind');
            $table->timestamps();
        });

        collect(range(1, 20))->each(function ($i) {
            DB::table('users')->insert([
                'name' => 'Record-'.$i,
                'email' => 'Email-'.$i.'@example.com',
            ]);
        });

        collect(range(1, 5))->each(function ($i) {
            DB::table('user_events')->insert([
                ['user_id' => $i, 'kind' => 'login'],
                ['user_id' => $i, 'kind' => 'login'],
            ]);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'oracle') {
            DB::statement('begin execute immediate \'drop view "UNION_USERS_VIEW"\'; exception when others then null; end;');
            DB::statement('create view "UNION_USERS_VIEW" as select "ID", "NAME", "EMAIL" from "USERS"');
        } elseif ($driver === 'pgsql') {
            DB::statement('drop view if exists "union_users_view"');
            DB::statement('create view "union_users_view" as select "id", "name", "email" from "users"');
        } elseif ($driver === 'mariadb') {
            DB::statement('drop view if exists `union_users_view`');
            DB::statement('create view `union_users_view` as select `id`, `name`, `email` from `users`');
        }
    }

    protected function tearDown(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'oracle') {
            DB::statement('begin execute immediate \'drop view "UNION_USERS_VIEW"\'; exception when others then null; end;');
        } elseif ($driver === 'pgsql') {
            DB::statement('drop view if exists "union_users_view"');
        } elseif ($driver === 'mariadb') {
            DB::statement('drop view if exists `union_users_view`');
        }

        Schema::dropIfExists('user_events');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    #[Test]
    public function it_can_perform_union_with_limit()
    {
        $results = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->limit(5)
            ->get();

        $this->assertCount(5, $results);
    }

    #[Test]
    public function it_can_perform_union_with_offset()
    {
        if ($this->isMariaDb()) {
            $this->markTestSkipped('MariaDB does not support OFFSET without LIMIT.');
        }

        $results = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->skip(15)
            ->get();

        $this->assertCount(5, $results);
    }

    #[Test]
    public function it_can_perform_union_with_zero_offset_and_no_limit()
    {
        if ($this->isMariaDb()) {
            $this->markTestSkipped('MariaDB does not support OFFSET without LIMIT.');
        }

        $results = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->skip(0)
            ->get();

        $this->assertCount(20, $results);
    }

    #[Test]
    public function it_can_perform_union_with_limit_and_offset()
    {
        $results = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->skip(5)->take(10)
            ->get();

        $this->assertCount(10, $results);
        $this->assertGreaterThanOrEqual(6, $results->first()->id);
    }

    #[Test]
    public function it_can_perform_union_with_order_by_and_limit()
    {
        $results = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->orderBy('id', 'asc')->take(3)
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals(1, $results->first()->id);
        $this->assertEquals(2, $results->get(1)->id);
        $this->assertEquals(3, $results->last()->id);
    }

    #[Test]
    public function it_can_perform_union_with_order_by_limit_and_offset()
    {
        $results = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->orderBy('id')->skip(5)->take(4)
            ->get();

        $this->assertCount(4, $results);
        $this->assertEquals([6, 7, 8, 9], $results->pluck('id')->all());
    }

    #[Test]
    public function it_can_perform_union_with_order_by_inside_union_query()
    {
        $results = DB::query()
            ->select('id', 'name')->from('users')->where('id', 1)
            ->union(
                DB::query()
                    ->select('id', 'name')->from('users')->where('id', '>', 10)
                    ->orderBy('id', 'desc')->limit(2)
            )
            ->orderBy('id')
            ->get();

        $this->assertEquals([1, 19, 20], $results->pluck('id')->all());
    }

    #[Test]
    public function it_can_perform_union_all_with_limit()
    {
        $results = DB::query()
            ->select('name')->from('users')->where('id', '<=', 5)
            ->unionAll(DB::query()->select('name')->from('users')->where('id', '<=', 5))
            ->take(7)
            ->get();

        $this->assertCount(7, $results);
    }

    #[Test]
    public function it_can_perform_multiple_unions_with_limit()
    {
        $results = DB::query()
            ->select('id')->from('users')->where('id', '<=', 5)
            ->union(DB::query()->select('id')->from('users')->where('id', '>', 5)->where('id', '<=', 10))
            ->union(DB::query()->select('id')->from('users')->where('id', '>', 10)->where('id', '<=', 15))
            ->limit(8)
            ->get();

        $this->assertCount(8, $results);
    }

    #[Test]
    public function it_can_perform_union_with_limit_one()
    {
        $results = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->limit(1)
            ->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function it_can_paginate_union_results()
    {
        $page1 = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->skip(0)->take(5)
            ->get();
        $this->assertCount(5, $page1);

        $page2 = DB::query()
            ->select('id', 'name')->from('users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('users')->where('id', '>', 10))
            ->skip(5)->take(5)
            ->get();
        $this->assertCount(5, $page2);

        $this->assertNotEquals($page1->first()->id, $page2->first()->id);
    }

    #[Test]
    public function it_can_paginate_union_results_with_aliased_columns()
    {
        $results = DB::query()
            ->select('u.id as user_id', 'u.name as user_name')
            ->from('users as u')
            ->where('u.id', '<=', 10)
            ->union(
                DB::query()
                    ->select('archived_users.id as user_id', 'archived_users.name as user_name')
                    ->from('users as archived_users')
                    ->where('archived_users.id', '>', 10)
            )
            ->orderBy('user_id')
            ->skip(2)->take(4)
            ->get();

        $this->assertEquals([3, 4, 5, 6], $results->pluck('user_id')->all());
        $this->assertEquals(['Record-3', 'Record-4', 'Record-5', 'Record-6'], $results->pluck('user_name')->all());
    }

    #[Test]
    public function it_can_paginate_union_results_from_schema_qualified_tables()
    {
        if (DB::connection()->getDriverName() !== 'oracle') {
            $this->markTestSkipped('Schema-qualified Oracle table names are covered by the Oracle driver.');
        }

        $schema = (string) DB::connection()->getConfig('username');

        $results = DB::query()
            ->select('id', 'name')->from($schema.'.users')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from($schema.'.users')->where('id', '>', 10))
            ->orderBy('id')
            ->skip(4)->take(3)
            ->get();

        $this->assertEquals([5, 6, 7], $results->pluck('id')->all());
    }

    #[Test]
    public function it_can_paginate_union_results_from_views()
    {
        $results = DB::query()
            ->select('id', 'name')->from('union_users_view')->where('id', '<=', 10)
            ->union(DB::query()->select('id', 'name')->from('union_users_view')->where('id', '>', 10))
            ->orderBy('id')
            ->skip(8)->take(4)
            ->get();

        $this->assertEquals([9, 10, 11, 12], $results->pluck('id')->all());
    }

    #[Test]
    public function it_can_paginate_union_all_results_when_joins_duplicate_target_rows()
    {
        $results = DB::query()
            ->select('users.id as user_id', 'users.name as user_name')
            ->from('users')
            ->join('user_events', 'users.id', '=', 'user_events.user_id')
            ->where('user_events.kind', 'login')
            ->where('users.id', '<=', 3)
            ->unionAll(
                DB::query()
                    ->select('users.id as user_id', 'users.name as user_name')
                    ->from('users')
                    ->join('user_events', 'users.id', '=', 'user_events.user_id')
                    ->where('user_events.kind', 'login')
                    ->where('users.id', '>', 3)
                    ->where('users.id', '<=', 5)
            )
            ->orderBy('user_id')
            ->skip(1)->take(6)
            ->get();

        $this->assertEquals([1, 2, 2, 3, 3, 4], $results->pluck('user_id')->all());
    }

    #[Test]
    public function it_keeps_union_binding_order_with_joins_and_limit()
    {
        $query = DB::query()
            ->select('users.id as user_id')
            ->from('users')
            ->join('user_events', function ($join) {
                $join->on('users.id', '=', 'user_events.user_id')
                    ->where('user_events.kind', '=', 'login');
            })
            ->where('users.id', '<=', 3)
            ->unionAll(
                DB::query()
                    ->select('users.id as user_id')
                    ->from('users')
                    ->join('user_events', function ($join) {
                        $join->on('users.id', '=', 'user_events.user_id')
                            ->where('user_events.kind', '=', 'login');
                    })
                    ->where('users.id', '>', 3)
                    ->where('users.id', '<=', 5)
            )
            ->orderBy('user_id')
            ->skip(1)->take(3);

        $this->assertEquals(['login', 3, 'login', 3, 5], $query->getBindings());
        $this->assertEquals([1, 2, 2], $query->get()->pluck('user_id')->all());
    }

}
