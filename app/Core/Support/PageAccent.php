<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ The open module's colour, for the coloured page title in the three ABOS looks (owner "ok", 24 Nov 2026, sample 9VVoe6uQ v2).
 *
 * ⓘ The same two rules the rail uses to find the open module ([[sidebar]]): a menu row marked active, else the route's prefix.
 * ⓘ A class, not an inline php block in the layout — an earlier one-line php block there swallows a later block (demo 500,
 *   24 Nov 2026: "Undefined variable $hereCode").
 */
final class PageAccent
{
    /** @param array<int, array<string, mixed>>|null $menu */
    public static function style(?array $menu): ?string
    {
        $code = self::code($menu);

        return $code === null ? null : '--page-module-colour: var(--color-module-'.$code.', var(--color-brand-600))';
    }

    /** @param array<int, array<string, mixed>>|null $menu */
    public static function code(?array $menu): ?string
    {
        $route = (string) (request()->route()?->getName() ?? '');
        $menu = collect($menu ?? []);

        $found = $menu->first(fn ($m) => collect($m['groups'] ?? [])->flatten(1)->contains('active', true))
            ?? $menu->first(fn ($m) => collect($m['codes'] ?? [])->contains(fn ($c) => str_starts_with($route, $c.'.')));

        $code = is_array($found) ? (string) ($found['code'] ?? '') : '';

        return preg_match('/^[a-z0-9_-]+$/', $code) ? $code : null;
    }
}
