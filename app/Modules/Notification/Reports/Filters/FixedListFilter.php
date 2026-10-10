<?php

declare(strict_types=1);

namespace App\Modules\Notification\Reports\Filters;

use App\Core\Contracts\ReportFilterSource;

/**
 * ⓘ স্থির তালিকার ছাঁকনি — মাধ্যম, গুরুত্ব, অবস্থা, মডিউল (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৪)। রিপোর্টের ছাঁকনি নম্বর চায়
 * ([[ReportFilterSource::resolve()]]), তাই তালিকার ক্রম-নম্বরই মান; রিপোর্ট [[value()]] দিয়ে আসল লেখায় ফেরায়।
 * ⛔ তালিকার বাইরের কিছু এলে রিপোর্টই ফেরে — ঠিকানায় লিখে অচেনা মান ঢোকানো যায় না।
 */
abstract class FixedListFilter implements ReportFilterSource
{
    /** @return list<string> */
    abstract protected function values(): array;

    abstract protected function labelOf(string $value): string;

    public function options(): array
    {
        $out = [];

        foreach ($this->values() as $i => $value) {
            $out[$i + 1] = $this->labelOf($value);
        }

        return $out;
    }

    public function resolve(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $options = $this->options();

        if (ctype_digit($raw)) {
            return isset($options[(int) $raw]) ? (int) $raw : null;
        }

        $found = array_search($raw, $options, true);

        return $found === false ? null : (int) $found;
    }

    /** ছাঁকনির নম্বর থেকে আসল মান — না থাকলে `null` */
    public static function value(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $values = (new static)->values();

        return $values[(int) $id - 1] ?? null;
    }
}
