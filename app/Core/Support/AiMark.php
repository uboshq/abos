<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ The living "Ai" mark for Copilot — owner, 21 Nov 2026 (sample copilot-icon-options, option ★ o7, gold i-dot v5).
 *
 * Only the ABOS looks (ABOS Smart = rose, Navy, Blue) draw it. Every other look — above all SAP (tiles) and
 * Dynamic — keeps its old brain mark untouched, pixel for pixel (owner, 19 and 20 Nov 2026).
 *
 * ⓘ The look names live here, in PHP, not in a screen's Blade (EveryScreenObeysTheThemeTest): a screen asks
 * `AiMark::shows()` and never learns which look it is in.
 */
final class AiMark
{
    /** @var list<string> */
    public const LOOKS = ['rose', 'navy', 'blue'];

    /** Does the signed-in person's current look (preview included) draw the new mark? */
    public static function shows(): bool
    {
        // ⓘ no once(): a test signs in as several people in one run, and each must get their own look
        return self::isFor(LookRegistry::lookFor(LookPreview::orChosen(auth()->user()?->ui)));
    }

    public static function isFor(string $look): bool
    {
        return in_array($look, self::LOOKS, true);
    }
}
