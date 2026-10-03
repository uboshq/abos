<?php

declare(strict_types=1);

namespace App\Core\Engines\Map;

/**
 * একজন মানুষের চোখে আঁকা একটা মানচিত্র — [[MapEngine::draw()]]-এর ফল।
 *
 * ⓘ `done` আর `total` গোটা মানচিত্রের, কে দেখছেন তা দেখে নয় — অগ্রগতির সংখ্যা সত্যিকারের কাজের অনুপাত বলে। ⛔ তাই
 * সংখ্যাটা কেবল তিনিই দেখেন যিনি "বাকি" লাইনও দেখেন ([[seesPending]]); বাকিদের কাছে "৪০/২০০" লেখা থাকত অথচ
 * ১৬০টা লাইন চোখেই পড়ত না।
 */
final class DrawnMap
{
    /**
     * @param  list<array{no: ?string, title: string, code: ?string, done: int, total: int, items: list<array{label: string, url: ?string, note: ?string, done: bool}>}>  $sections
     */
    public function __construct(
        public readonly array $sections,
        public readonly int $done,
        public readonly int $total,
        public readonly bool $seesPending,
    ) {}

    public function percent(): int
    {
        return $this->total > 0 ? (int) round($this->done / $this->total * 100) : 0;
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }
}
