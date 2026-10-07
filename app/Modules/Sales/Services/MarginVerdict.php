<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

/**
 * একটা কাগজের মার্জিনের রায় — সারি ধরে, আর গোটা কাগজ ধরে।
 *
 * ⭐ দুই স্তরেই মাপা হয় (NEXUS §৩২): প্রতিটা সারি সীমার উপরে থেকেও
 * কাগজের মাথার ছাড় গোটা বিক্রয়টাকে খরচের নিচে নামাতে পারে। ⛔ কেবল
 * সারি দেখলে ঐ ছাড়টা পাহারার চোখেই পড়ত না।
 */
final class MarginVerdict
{
    /**
     * @param  list<MarginLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $floor,
        public readonly string $action,
        /** মাপা যায় এমন সারিগুলোর বিক্রয়, মাথার ছাড় বাদ দিয়ে। */
        public readonly string $net,
        public readonly string $cost,
        public readonly ?string $marginPercent,
        public readonly bool $documentBelow,
    ) {}

    /** কোনো সারি বা গোটা কাগজ সীমার নিচে কি না। */
    public function isBelow(): bool
    {
        return $this->documentBelow || $this->belowLines() !== [];
    }

    /** @return list<MarginLine> */
    public function belowLines(): array
    {
        return array_values(array_filter($this->lines, fn (MarginLine $l) => $l->below));
    }

    /** @return list<MarginLine> */
    public function unknownLines(): array
    {
        return array_values(array_filter($this->lines, fn (MarginLine $l) => ! $l->costKnown()));
    }

    /**
     * সবচেয়ে খারাপ মার্জিন — অনুমোদনের ছকের শর্তে যায় (`margin_percent`)।
     *
     * ⓘ সীমার নিচে কিছু না থাকলে `null`।
     */
    public function worstPercent(): ?string
    {
        $worst = $this->documentBelow ? $this->marginPercent : null;

        foreach ($this->belowLines() as $line) {
            if ($line->marginPercent === null) {
                continue;
            }

            if ($worst === null || bccomp($line->marginPercent, $worst, 4) < 0) {
                $worst = $line->marginPercent;
            }
        }

        return $worst;
    }
}
