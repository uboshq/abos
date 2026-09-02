<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use InvalidArgumentException;

/**
 * সময়ের সাথে দুইটা রেখা — যেমন মাসে মাসে ঢোকা আর বেরোনো।
 *
 * ── কেন দুইটা মান, একটা নয় ───────────────────────────────────────────
 * একটা রেখা প্রায়ই সমান থাকে আর চোখে কিছুই বলে না। দুইটা পাশাপাশি
 * রাখলে **ব্যবসাটা দেখা যায়**: কোন মাসে বেশি এসেছে, কোন মাসে বেশি
 * গেছে, আর ফারাকটা বাড়ছে না কমছে।
 *
 * ── কেন প্রতিটা ধাপ তালিকায় থাকতেই হয় ───────────────────────────────
 * কেবল যেসব মাসে সারি আছে সেগুলো দেখালে ফাঁকা মাসগুলো **উধাও** হত,
 * আর সাতটা বারের বদলে পাঁচটা দেখে কেউ ভাবতেন ব্যবসা সাত মাস চলেনি।
 * তাই শূন্য মাসও একটা ধাপ, শূন্য মান নিয়ে।
 */
final class Series
{
    /**
     * @param  list<array{label: string, first: string, second: string}>  $points
     */
    public function __construct(
        public readonly string $label,
        public readonly array $points,
        public readonly string $firstLabel,
        public readonly string $secondLabel,
    ) {
        if ($points === []) {
            throw new InvalidArgumentException("Series '{$label}' has no points, so it can only draw an empty box.");
        }

        foreach ($points as $point) {
            foreach (['label', 'first', 'second'] as $key) {
                if (! array_key_exists($key, $point)) {
                    throw new InvalidArgumentException("Series '{$label}' has a point missing '{$key}'.");
                }
            }
        }
    }

    /**
     * সবচেয়ে বড় মান — বারগুলোর উচ্চতা এর সাপেক্ষে।
     *
     * শূন্য হলে ১ ফেরে: সব মান শূন্য হলে ভাগ করার সময় শূন্য দিয়ে ভাগ
     * হত, আর পর্দাটা ৫০০ দিত — অথচ "কিছুই নড়েনি" একটা বৈধ অবস্থা।
     */
    public function peak(): float
    {
        $peak = 0.0;

        foreach ($this->points as $point) {
            $peak = max($peak, (float) $point['first'], (float) $point['second']);
        }

        return $peak > 0 ? $peak : 1.0;
    }
}
