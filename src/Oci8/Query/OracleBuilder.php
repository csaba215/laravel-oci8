<?php

namespace Yajra\Oci8\Query;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Yajra\Oci8\Query\Grammars\OracleGrammar;
use Yajra\Oci8\Query\Processors\OracleProcessor;

class OracleBuilder extends Builder
{
    /**
     * Put the query's results in random order.
     *
     * @param  string|int  $seed
     * @return $this
     */
    public function inRandomOrder($seed = '')
    {
        $orders = $this->unions ? 'unionOrders' : 'orders';

        parent::inRandomOrder($seed);

        $index = array_key_last($this->{$orders});
        $this->{$orders}[$index]['randomSeed'] = $seed === '' || $seed === null ? null : (string) $seed;

        return $this;
    }

    /**
     * Insert a new record and get the value of the primary key.
     */
    public function insertLob(array $values, array $binaries, string $sequence = 'id'): int
    {
        /** @var OracleGrammar $grammar */
        $grammar = $this->grammar;
        $sql = $grammar->compileInsertLob($this, $values, $binaries, $sequence);

        $values = $this->cleanBindings($values);
        $binaries = $this->cleanBindings($binaries);

        /** @var OracleProcessor $processor */
        $processor = $this->processor;

        return $processor->saveLob($this, $sql, $values, $binaries);
    }

    /**
     * Update a new record with blob field.
     */
    public function updateLob(array $values, array $binaries, string $sequence = 'id'): bool
    {
        $bindings = array_values(array_merge($values, $this->getBindings()));

        /** @var OracleGrammar $grammar */
        $grammar = $this->grammar;
        $sql = $grammar->compileUpdateLob($this, $values, $binaries, $sequence);

        $values = $this->cleanBindings($bindings);
        $binaries = $this->cleanBindings($binaries);

        /** @var OracleProcessor $processor */
        $processor = $this->processor;

        return $processor->saveLob($this, $sql, $values, $binaries);
    }

    /**
     * Add a "where in" clause to the query.
     * Split one WHERE IN clause into multiple clauses each
     * with up to 1000 expressions to avoid ORA-01795.
     *
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false): OracleBuilder
    {
        $type = $not ? 'NotIn' : 'In';

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        if (is_array($values) && count($values) > 1000) {
            $chunks = array_chunk($values, 1000);

            return $this->where(function ($query) use ($column, $chunks, $type, $not) {
                foreach ($chunks as $ch) {
                    $sqlClause = $not ? 'where'.$type : 'orWhere'.$type;
                    $query->{$sqlClause}($column, $ch);
                }
            }, null, null, $boolean);
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Set the table which the query is targeting.
     *
     * @param  \Closure|Builder|string  $table
     * @param  string|null  $as
     * @return $this
     */
    public function from($table, $as = null): static
    {
        if ($this->isQueryable($table)) {
            return $this->fromSub($table, $as);
        }

        $this->from = $as ? "{$table} {$as}" : $table;

        return $this;
    }

    /**
     * Makes "from" fetch from a subquery.
     *
     * @param  \Closure|Builder|string  $query
     * @param  string  $as
     */
    public function fromSub($query, $as): static
    {
        [$query, $bindings] = $this->createSub($query);

        return $this->fromRaw('('.$query.') '.$this->grammar->wrapTable($as), $bindings);
    }

    /**
     * Add a subquery join clause to the query.
     *
     * @param  \Closure|Builder|string  $query
     * @param  string  $as
     * @param  \Closure|string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @param  string  $type
     * @param  bool  $where
     */
    public function joinSub($query, $as, $first, $operator = null, $second = null, $type = 'inner', $where = false): static
    {
        [$query, $bindings] = $this->createSub($query);

        $expression = '('.$query.') '.$this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        return $this->join(new Expression($expression), $first, $operator, $second, $type, $where);
    }

    /**
     * Add a subquery cross join to the query.
     *
     * @param  \Closure|Builder|string  $query
     * @param  string  $as
     */
    public function crossJoinSub($query, $as): static
    {
        [$query, $bindings] = $this->createSub($query);

        $expression = '('.$query.') '.$this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        $this->joins[] = $this->newJoinClause($this, 'cross', new Expression($expression));

        return $this;
    }

    /**
     * Add a lateral join clause to the query.
     *
     * @param  \Closure|Builder|string  $query
     */
    public function joinLateral($query, string $as, string $type = 'inner'): static
    {
        [$query, $bindings] = $this->createSub($query);

        $expression = '('.$query.') '.$this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        $this->joins[] = $this->newJoinLateralClause($this, $type, new Expression($expression));

        return $this;
    }

    /**
     * Get the count of the total records for the paginator.
     *
     * @param  array  $columns
     * @return int
     */
    public function getCountForPagination($columns = ['*'])
    {
        $results = $this->runPaginationCountQuery($columns);

        // Once we have run the pagination count query, we will get the resulting count and
        // take into account what type of query it was. When there is a group by we will
        // just return the count of the entire results set since that will be correct.
        if (! isset($results[0])) {
            return 0;
        } elseif (is_object($results[0])) {
            return (int) (property_exists($results[0], 'AGGREGATE') ? $results[0]->AGGREGATE : $results[0]->aggregate);   // to solve the Oracle issue: auto-convert field to uppercase
        }

        return (int) array_change_key_case((array) $results[0])['aggregate'];
    }

    /**
     * Run a pagination count query.
     *
     * @param  array<string|\Illuminate\Contracts\Database\Query\Expression>  $columns
     * @return array<mixed>
     */
    protected function runPaginationCountQuery($columns = ['*'])
    {
        if ($this->groups || $this->havings) {
            $clone = $this->cloneForPaginationCount();

            if (is_null($clone->columns) && ! empty($this->joins)) {
                $clone->select($this->from.'.*');
            }

            return $this->newQuery()
                ->from(new Expression('('.$clone->toSql().') '.$this->grammar->wrap('aggregate_table')))
                ->mergeBindings($clone)
                ->setAggregate('count', $this->withoutSelectAliases($columns))
                ->get()->all();
        }

        $without = $this->unions ? ['unionOrders', 'unionLimit', 'unionOffset'] : ['columns', 'orders', 'limit', 'offset'];

        return $this->cloneWithout($without)
            ->cloneWithoutBindings($this->unions ? ['unionOrder'] : ['select', 'order'])
            ->setAggregate('count', $this->withoutSelectAliases($columns))
            ->get()->all();
    }

    /**
     * Run the query as a select statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        $sql = $this->toSql();

        $this->seedRandomGenerator();

        return $this->connection->select(
            $sql, $this->getBindings(), ! $this->useWritePdo, $this->fetchUsing
        );
    }

    /**
     * Get a lazy collection for the given query.
     *
     * @return LazyCollection<int, \stdClass>
     */
    public function cursor()
    {
        if (is_null($this->columns)) {
            $this->columns = ['*'];
        }

        return (new LazyCollection(function () {
            $sql = $this->toSql();

            $this->seedRandomGenerator();

            yield from $this->connection->cursor(
                $sql, $this->getBindings(), ! $this->useWritePdo, $this->fetchUsing
            );
        }))->map(function ($item) {
            return $this->applyAfterQueryCallbacks(new Collection([$item]))->first();
        })->reject(fn ($item) => is_null($item));
    }

    /**
     * Seed Oracle's session random number generator when the query has a seed.
     */
    protected function seedRandomGenerator(): void
    {
        $seed = $this->randomOrderSeed();

        if (is_null($seed)) {
            return;
        }

        /** @var OracleGrammar $grammar */
        $grammar = $this->grammar;

        $this->connection->select(
            $grammar->compileRandomSeed(), [$seed], ! $this->useWritePdo
        );
    }

    /**
     * Get the last seed attached to the query's random order clauses.
     */
    protected function randomOrderSeed(): ?string
    {
        $orders = array_merge($this->orders ?? [], $this->unionOrders ?? []);

        foreach (array_reverse($orders) as $order) {
            if (array_key_exists('randomSeed', $order) && ! is_null($order['randomSeed'])) {
                return $order['randomSeed'];
            }
        }

        return null;
    }
}
