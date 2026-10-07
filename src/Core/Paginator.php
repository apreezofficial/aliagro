<?php

namespace App\Core;

/** Builds the same JSON envelope as Laravel's LengthAwarePaginator. */
final class Paginator
{
    private const ON_EACH_SIDE = 3;

    public static function currentPage(): int
    {
        return max(1, (int) (Request::current()->query['page'] ?? 1));
    }

    public static function make(array $items, int $total, int $perPage, int $page): array
    {
        $path     = Request::current()->url();
        $lastPage = max((int) ceil($total / max(1, $perPage)), 1);
        $url      = fn(int $p) => $path . '?page=' . $p;

        $count = count($items);
        return [
            'current_page'   => $page,
            'data'           => array_values($items),
            'first_page_url' => $url(1),
            'from'           => $count ? ($page - 1) * $perPage + 1 : null,
            'last_page'      => $lastPage,
            'last_page_url'  => $url($lastPage),
            'links'          => self::links($page, $lastPage, $url),
            'next_page_url'  => $page < $lastPage ? $url($page + 1) : null,
            'path'           => $path,
            'per_page'       => $perPage,
            'prev_page_url'  => $page > 1 ? $url($page - 1) : null,
            'to'             => $count ? ($page - 1) * $perPage + $count : null,
            'total'          => $total,
        ];
    }

    /** Mirrors Illuminate\Pagination\UrlWindow so the `links` array matches. */
    private static function links(int $page, int $last, callable $url): array
    {
        $links = [[
            'url'    => $page > 1 ? $url($page - 1) : null,
            'label'  => '&laquo; Previous',
            'active' => false,
        ]];

        $side = self::ON_EACH_SIDE;
        $range = fn(int $a, int $b) => range($a, $b);

        if ($last < $side * 2 + 8) {
            $elements = [$range(1, $last)];
        } else {
            $window = $side + 4;
            if ($page <= $window) {
                $elements = [$range(1, $window + $side), '...', $range($last - 1, $last)];
            } elseif ($page > $last - $window) {
                $elements = [$range(1, 2), '...', $range($last - ($window + ($side - 1)), $last)];
            } else {
                $elements = [$range(1, 2), '...', $range($page - $side, $page + $side), '...', $range($last - 1, $last)];
            }
        }

        foreach ($elements as $element) {
            if (is_string($element)) {
                $links[] = ['url' => null, 'label' => $element, 'active' => false];
                continue;
            }
            foreach ($element as $p) {
                $links[] = ['url' => $url($p), 'label' => (string) $p, 'active' => $p === $page];
            }
        }

        $links[] = [
            'url'    => $page < $last ? $url($page + 1) : null,
            'label'  => 'Next &raquo;',
            'active' => false,
        ];

        return $links;
    }
}
