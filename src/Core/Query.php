<?php

namespace App\Core;

/**
 * Tiny fluent SELECT builder. All values are bound; identifiers passed to
 * where()/orderBy()/select() come from application code, never from users.
 */
final class Query
{
    private array $columns  = ['*'];
    private array $joins    = [];
    private array $wheres   = [];
    private array $bindings = [];
    private array $orders   = [];
    private ?int $limit     = null;
    private ?int $offset    = null;
    private array $with     = [];
    private bool $trashed   = false;
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    /** @param class-string<Model>|null $model */
    public function __construct(private string $table, private ?string $model = null) {}

    private static function col(string $c): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $c)
            ? '`' . str_replace('.', '`.`', $c) . '`'
            : $c; // expression such as COUNT(*) or `a` AS `b`
    }

    public function select(array|string $columns): self
    {
        $this->columns = (array) $columns;
        return $this;
    }

    public function join(string $table, string $first, string $second): self
    {
        $this->joins[] = 'INNER JOIN ' . self::col($table) . ' ON ' . self::col($first) . ' = ' . self::col($second);
        return $this;
    }

    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }
        $operator = strtolower($operator);
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid operator [{$operator}]");
        }
        if ($value === null) {
            return $operator === '=' ? $this->whereNull($column) : $this->whereNotNull($column);
        }
        $this->wheres[] = self::col($column) . " {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = "({$sql})";
        array_push($this->bindings, ...array_values($bindings));
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        if (!$values) {
            $this->wheres[] = '0 = 1';
            return $this;
        }
        $this->wheres[] = self::col($column) . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        array_push($this->bindings, ...array_values($values));
        return $this;
    }

    public function whereNotIn(string $column, array $values): self
    {
        if (!$values) {
            return $this;
        }
        $this->wheres[] = self::col($column) . ' NOT IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        array_push($this->bindings, ...array_values($values));
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = self::col($column) . ' IS NULL';
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = self::col($column) . ' IS NOT NULL';
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = self::col($column) . (strtolower($direction) === 'desc' ? ' DESC' : ' ASC');
        return $this;
    }

    public function latest(string $column = 'created_at'): self
    {
        // `id` as tie-breaker keeps pagination stable for rows created in the same second.
        $this->orderBy($column, 'desc');
        if (!in_array('`id` DESC', $this->orders, true)) {
            $this->orderBy('id', 'desc');
        }
        return $this;
    }

    public function limit(int $n): self
    {
        $this->limit = max(0, $n);
        return $this;
    }

    public function offset(int $n): self
    {
        $this->offset = max(0, $n);
        return $this;
    }

    public function with(array|string $relations): self
    {
        $this->with = array_merge($this->with, (array) $relations);
        return $this;
    }

    public function withTrashed(): self
    {
        $this->trashed = true;
        return $this;
    }

    // ── Execution ────────────────────────────────────────────────────────

    private function from(): string
    {
        return '`' . $this->table . '`' . ($this->joins ? ' ' . implode(' ', $this->joins) : '');
    }

    private function whereSql(): string
    {
        $wheres = $this->wheres;
        if (!$this->trashed && $this->model && $this->model::$softDeletes) {
            $wheres[] = "`{$this->table}`.`deleted_at` IS NULL";
        }
        return $wheres ? ' WHERE ' . implode(' AND ', $wheres) : '';
    }

    private function toSql(bool $withOrderAndLimit = true): string
    {
        $sql = 'SELECT ' . implode(', ', array_map([self::class, 'col'], $this->columns))
            . ' FROM ' . $this->from() . $this->whereSql();
        if ($withOrderAndLimit) {
            if ($this->orders) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orders);
            }
            if ($this->limit !== null) {
                $sql .= ' LIMIT ' . $this->limit;
                if ($this->offset) {
                    $sql .= ' OFFSET ' . $this->offset;
                }
            }
        }
        return $sql;
    }

    /** @return array<int, array> */
    public function get(): array
    {
        $rows = DB::select($this->toSql(), $this->bindings);
        if ($this->model) {
            $rows = array_map([$this->model, 'hydrate'], $rows);
            if ($this->with) {
                $this->model::loadRelations($rows, $this->with);
            }
        }
        return $rows;
    }

    public function first(): ?array
    {
        $rows = (clone $this)->limit(1)->get();
        return $rows[0] ?? null;
    }

    public function pluck(string $column): array
    {
        $rows = DB::select($this->toSql(), $this->bindings);
        $key  = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
        return array_map(fn($r) => $r[$key], $rows);
    }

    private function aggregate(string $fn, string $column): mixed
    {
        $sql = "SELECT {$fn}(" . self::col($column) . ') FROM ' . $this->from() . $this->whereSql();
        return DB::value($sql, $this->bindings);
    }

    public function count(): int
    {
        return (int) $this->aggregate('COUNT', '*');
    }

    public function sum(string $column): mixed
    {
        return $this->aggregate('SUM', $column) ?: 0; // string|int, like Laravel's sum()
    }

    public function avg(string $column): mixed
    {
        return $this->aggregate('AVG', $column);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /** Laravel-style length-aware pagination. */
    public function paginate(int $perPage = 15, ?int $page = null): array
    {
        $page  ??= Paginator::currentPage();
        $perPage = max(1, $perPage);
        $total   = $this->count();
        $items   = $total ? (clone $this)->limit($perPage)->offset(($page - 1) * $perPage)->get() : [];
        return Paginator::make($items, $total, $perPage, $page);
    }

    /** Hard delete of the rows matched (soft-delete scope is NOT applied). */
    public function delete(): int
    {
        $where = $this->wheres ? ' WHERE ' . implode(' AND ', $this->wheres) : '';
        return DB::statement('DELETE FROM `' . $this->table . '`' . $where, $this->bindings);
    }
}
