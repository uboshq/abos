<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use InvalidArgumentException;

/**
 * দ্রুত-কাজের টাইল — "নতুন চালান", "মাল ঢোকাও"।
 *
 * ── কেন সংখ্যার পাশে কাজও থাকে ───────────────────────────────────────
 * ড্যাশবোর্ড পড়ার পর মানুষ কিছু **করতে** চান। সেই কাজটা যদি মেনুর
 * তিন ধাপ ভেতরে থাকে, তবে পর্দাটা কেবল খবর দেয়, কাজ করায় না — আর
 * খবরের পর্দা কয়েক সপ্তাহে খোলা বন্ধ হয়ে যায়।
 *
 * ── কেন প্রতিটা টাইলে অনুমতি ─────────────────────────────────────────
 * যে কাজটা করার চাবি নেই, সেটার বোতাম দেখানো নিষ্ঠুর: মানুষ চাপেন,
 * ৪০৩ পান, আর ভাবেন ব্যবস্থাটা ভাঙা।
 */
final class Tile
{
    public function __construct(
        public readonly string $label,
        public readonly string $href,
        public readonly string $permission,
        public readonly ?string $icon = null,
    ) {
        if (trim($label) === '') {
            throw new InvalidArgumentException('A tile with no label is a button nobody can read.');
        }

        if (trim($permission) === '') {
            throw new InvalidArgumentException(
                "Tile '{$label}' names no permission. A button that leads to a 403 reads as a broken system."
            );
        }
    }
}
