<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ এক পাতায় প্রতিটা ঘরের id একটাই — একই নাম দুইবার এলে (বাতিলের কারণ, দুই ফর্মের "নোট") পরেরটা `name-2`, `name-3` (অডিট, ২৫ নভেম্বর
 *   ২০২৬; WCAG 2.2 SC 4.1.1/1.3.1)। ⓘ প্রথমটা আগের মতোই নামটাই, তাই পুরোনো লিংক আর স্ক্রিপ্ট অটুট; ফর্মের `name` কখনো বদলায় না।
 */
final class FieldId
{
    private const KEY = 'ui.field_ids';

    public static function of(string $name): string
    {
        $bag = request()->attributes;
        $seen = (array) $bag->get(self::KEY, []);
        $seen[$name] = ($seen[$name] ?? 0) + 1;
        $bag->set(self::KEY, $seen);

        return $seen[$name] === 1 ? $name : $name.'-'.$seen[$name];
    }
}
