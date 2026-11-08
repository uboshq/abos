<?php

declare(strict_types=1);

namespace App\Core\Navigation;

/**
 * Lucide আইকন (ISC, resources/icons/LUCIDE-LICENSE.txt) — রঙিন মেনুর প্রতিটা সারির নিজের চিহ্ন ([[MenuLook]], [[x-ui.licon]])।
 * ⓘ একবার পড়া, তারপর মনে রাখা; অচেনা নামে খালি — মেনু ভাঙে না, চিহ্নটা কেবল থাকে না।
 */
final class LucideIcons
{
    /** @var array<string, string>|null */
    private static ?array $icons = null;

    public static function inner(string $name): string
    {
        self::$icons ??= (array) require resource_path('icons/lucide.php');

        return self::$icons[$name] ?? '';
    }

    public static function has(string $name): bool
    {
        return self::inner($name) !== '';
    }
}
