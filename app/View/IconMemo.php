<?php

declare(strict_types=1);

namespace App\View;

use App\Core\Support\AiMark;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * ⭐ One icon drawn once per page, not once per row (speed work, 23 Nov 2026).
 *
 * ⓘ Measured on live, owner: a list's row menus drew `<x-ui.icon>` 540–890 times per page (14 per row × 50 rows),
 * ~0.36 ms each on the shared host — 0.2–0.3 s of every list page spent drawing the same twenty icons again.
 *
 * ⛔ The markup is byte-for-byte the component's own: the first call for a name and size renders the very same
 * `<x-ui.icon :name :size />`, later calls hand that string back. The answer depends only on the name, the size and
 * whether the living "Ai" mark replaces the brain ([[AiMark::shows()]]) — all three are in the key. The memory lives
 * for one request (a scoped instance).
 */
final class IconMemo
{
    /** @var array<string, HtmlString> */
    private array $drawn = [];

    public static function render(string $name, int $size): HtmlString
    {
        return app(self::class)->draw($name, $size);
    }

    public function draw(string $name, int $size): HtmlString
    {
        $draw = fn (): HtmlString => new HtmlString(Blade::render(
            '<x-ui.icon :name="$name" :size="$size" />',
            ['name' => $name, 'size' => $size],
        ));

        // ⓘ the living "Ai" mark may carry its own ids — drawn afresh every time, exactly as before
        if (in_array($name, ['brain', 'assistant'], true) && AiMark::shows()) {
            return $draw();
        }

        return $this->drawn[$name.'|'.$size] ??= $draw();
    }
}
