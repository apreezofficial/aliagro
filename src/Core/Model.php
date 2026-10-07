<?php

namespace App\Core;

/**
 * Lightweight active-record helpers. Rows are plain associative arrays
 * (ready for json_encode); casts, hidden fields and eager-loaded relations
 * are applied when a row is hydrated.
 *
 * Subclasses override the static properties and, optionally, relations():
 *
 *   'farmer'  => ['belongsTo', User::class, 'farmer_id', 'id'],
 *   'items'   => ['hasMany',   OrderItem::class, 'order_id', 'id'],
 *   'profile' => ['hasOne',    FarmerProfile::class, 'user_id', 'id'],
 *   'badges'  => ['belongsToMany', Badge::class, 'user_badges', 'user_id', 'badge_id', ['awarded_at']],
 */
abstract class Model
{
    public static string $table = '';
    /** @var array<string, string> column => int|float|bool|array|datetime */
    public static array $casts = [];
    public static array $hidden = [];
    public static bool $softDeletes = false;
    public static bool $timestamps = true;
    /** Mass-assignable columns. Anything else passed to create()/update() is dropped. */
    public static array $fillable = [];

    protected static function relations(): array
    {
        return [];
    }

    // ── Queries ──────────────────────────────────────────────────────────

    public static function query(): Query
    {
        return new Query(static::$table, static::class);
    }

    public static function find(int|string|null $id): ?array
    {
        // Reject "12abc": MySQL would silently cast it to 12.
        return $id === null || !ctype_digit((string) $id) ? null : static::query()->where('id', $id)->first();
    }

    public static function findWithTrashed(int|string $id): ?array
    {
        return !ctype_digit((string) $id) ? null : static::query()->withTrashed()->where('id', $id)->first();
    }

    public static function findOrFail(int|string|null $id): array
    {
        return static::find($id) ?? throw new ModelNotFoundException();
    }

    public static function where(string $column, mixed $operator, mixed $value = null): Query
    {
        return func_num_args() === 2
            ? static::query()->where($column, $operator)
            : static::query()->where($column, $operator, $value);
    }

    // ── Writes ───────────────────────────────────────────────────────────

    /** Insert and return the stored row (so DB defaults are present). */
    public static function create(array $attributes): array
    {
        $data = static::prepareForWrite($attributes);
        if (static::$timestamps) {
            $ts = now();
            $data += ['created_at' => $ts, 'updated_at' => $ts];
        }
        $id = DB::insert(static::$table, $data);
        return static::findWithTrashed($id) ?? throw new \RuntimeException('Row vanished after insert.');
    }

    /** Update by primary key; returns the fresh row. */
    public static function update(int|string $id, array $attributes): array
    {
        $data = static::prepareForWrite($attributes);
        if ($data) {
            if (static::$timestamps) {
                $data['updated_at'] = now();
            }
            DB::update(static::$table, $data, '`id` = ?', [$id]);
        }
        return static::findWithTrashed($id) ?? throw new ModelNotFoundException();
    }

    /** Delete (soft when the model uses soft deletes). */
    public static function delete(int|string $id): void
    {
        if (static::$softDeletes) {
            DB::update(static::$table, ['deleted_at' => now(), 'updated_at' => now()], '`id` = ?', [$id]);
        } else {
            DB::delete(static::$table, '`id` = ?', [$id]);
        }
    }

    public static function increment(int|string $id, string $column, int|float $by = 1): void
    {
        DB::statement('UPDATE `' . static::$table . "` SET `{$column}` = `{$column}` + ? WHERE `id` = ?", [$by, $id]);
    }

    public static function decrement(int|string $id, string $column, int|float $by = 1): void
    {
        static::increment($id, $column, -$by);
    }

    /** Find by $match or create with $match + $values. */
    public static function firstOrCreate(array $match, array $values = []): array
    {
        $q = static::query();
        foreach ($match as $k => $v) {
            $q->where($k, $v);
        }
        return $q->first() ?? static::create($match + $values);
    }

    /** Update the row matching $match or create it. */
    public static function updateOrCreate(array $match, array $values): array
    {
        $q = static::query();
        foreach ($match as $k => $v) {
            $q->where($k, $v);
        }
        $existing = $q->first();
        return $existing ? static::update($existing['id'], $values) : static::create($match + $values);
    }

    protected static function prepareForWrite(array $attributes): array
    {
        if (static::$fillable) {
            $attributes = array_intersect_key($attributes, array_flip(static::$fillable));
        }
        $out = [];
        foreach ($attributes as $key => $value) {
            $cast = static::$casts[$key] ?? null;
            if ($cast === 'array' && $value !== null && !is_string($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = (int) $value;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    // ── Hydration ────────────────────────────────────────────────────────

    public static function hydrate(array $row): array
    {
        $casts = static::$casts;
        if (static::$timestamps) {
            $casts += ['created_at' => 'datetime', 'updated_at' => 'datetime'];
        }
        if (static::$softDeletes) {
            $casts += ['deleted_at' => 'datetime'];
        }

        foreach ($casts as $column => $type) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            $row[$column] = match ($type) {
                'int'      => (int) $row[$column],
                'float'    => (float) $row[$column],
                'bool'     => (bool) $row[$column],
                'array'    => is_string($row[$column]) ? json_decode($row[$column], true) : $row[$column],
                'datetime' => Time::iso($row[$column]),
                default    => $row[$column],
            };
        }

        foreach (static::$hidden as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    // ── Eager loading ────────────────────────────────────────────────────

    /**
     * Attach relations to already-hydrated rows. Specs look like
     * "farmer:id,name,avatar", "items.product:id,name" (dot = nested).
     */
    public static function loadRelations(array &$rows, array $specs): void
    {
        if (!$rows || !$specs) {
            return;
        }

        $parsed = [];
        foreach ($specs as $spec) {
            [$path, $cols] = array_pad(explode(':', $spec, 2), 2, null);
            $parts = explode('.', $path, 2);
            $name  = $parts[0];
            $parsed[$name] ??= ['cols' => null, 'nested' => []];
            if (isset($parts[1])) {
                $parsed[$name]['nested'][] = $parts[1] . ($cols !== null ? ':' . $cols : '');
            } elseif ($cols !== null) {
                $parsed[$name]['cols'] = explode(',', $cols);
            }
        }

        $defs = static::relations();
        foreach ($parsed as $name => $opts) {
            $def = $defs[$name] ?? throw new \LogicException(static::class . " has no relation [{$name}]");
            $type = $def[0];
            match ($type) {
                'belongsTo'     => static::loadBelongsTo($rows, $name, $def, $opts),
                'hasOne'        => static::loadHas($rows, $name, $def, $opts, false),
                'hasMany'       => static::loadHas($rows, $name, $def, $opts, true),
                'belongsToMany' => static::loadBelongsToMany($rows, $name, $def, $opts),
            };
        }
    }

    private static function relatedQuery(string $related, array $opts, string $mustSelect): Query
    {
        $q = $related::query();
        if ($opts['cols']) {
            $q->select(array_values(array_unique([...$opts['cols'], $mustSelect])));
        }
        return $q->with($opts['nested']);
    }

    private static function loadBelongsTo(array &$rows, string $name, array $def, array $opts): void
    {
        [, $related, $fk, $ownerKey] = $def;
        $ids = array_values(array_unique(array_filter(array_column($rows, $fk), fn($v) => $v !== null)));
        $map = [];
        foreach ($ids ? static::relatedQuery($related, $opts, $ownerKey)->whereIn($ownerKey, $ids)->get() : [] as $r) {
            $map[$r[$ownerKey]] = $r;
        }
        foreach ($rows as &$row) {
            $row[$name] = $map[$row[$fk] ?? null] ?? null;
        }
    }

    private static function loadHas(array &$rows, string $name, array $def, array $opts, bool $many): void
    {
        [, $related, $fk, $localKey] = $def;
        $ids = array_values(array_unique(array_column($rows, $localKey)));
        $grouped = [];
        foreach (static::relatedQuery($related, $opts, $fk)->whereIn($fk, $ids)->orderBy('id')->get() as $r) {
            $grouped[$r[$fk]][] = $r;
        }
        foreach ($rows as &$row) {
            $list = $grouped[$row[$localKey]] ?? [];
            $row[$name] = $many ? $list : ($list[0] ?? null);
        }
    }

    private static function loadBelongsToMany(array &$rows, string $name, array $def, array $opts): void
    {
        [, $related, $pivotTable, $fk, $relatedFk, $pivotColumns] = array_pad($def, 6, []);
        $ids    = array_values(array_unique(array_column($rows, 'id')));
        $pivots = (new Query($pivotTable))->whereIn($fk, $ids)->orderBy('id')->get();

        $relatedMap = [];
        $relatedIds = array_values(array_unique(array_column($pivots, $relatedFk)));
        foreach ($relatedIds ? static::relatedQuery($related, $opts, 'id')->whereIn('id', $relatedIds)->get() : [] as $r) {
            $relatedMap[$r['id']] = $r;
        }

        $grouped = [];
        foreach ($pivots as $p) {
            $item = $relatedMap[$p[$relatedFk]] ?? null;
            if (!$item) {
                continue;
            }
            $pivot = [$fk => $p[$fk], $relatedFk => $p[$relatedFk]];
            foreach ($pivotColumns as $c) {
                $pivot[$c] = in_array($c, ['awarded_at'], true) ? Time::iso($p[$c]) : $p[$c];
            }
            $pivot['created_at'] = Time::iso($p['created_at'] ?? null);
            $pivot['updated_at'] = Time::iso($p['updated_at'] ?? null);
            $item['pivot'] = $pivot;
            $grouped[$p[$fk]][] = $item;
        }
        foreach ($rows as &$row) {
            $row[$name] = $grouped[$row['id']] ?? [];
        }
    }

    /** Eager load onto a single row. */
    public static function load(array $row, array $specs): array
    {
        $rows = [$row];
        static::loadRelations($rows, $specs);
        return $rows[0];
    }
}
