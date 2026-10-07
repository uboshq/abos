<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Concerns;

use App\Modules\MasterData\Models\Unit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * লাইনটা কোন প্যাকে লেখা হয়েছিল — দেখানোর দিকটা।
 *
 * ── কেন এটা মডেলে, কন্ট্রোলারে নয় ────────────────────────────────────
 * একই প্রশ্ন তিনটা ছাপার কন্ট্রোলার, পর্দার টেবিল আর রিপোর্ট — সবাই
 * করে: "এই সারিতে কী দেখাব"। তিন জায়গায় তিনবার লিখলে একদিন চালানে
 * "২ বাক্স" ছাপা হত আর বিলে "২০০ পিস", আর ক্রেতা দুইটা কাগজ পাশাপাশি
 * রেখে মেলাতে পারতেন না।
 *
 * হিসাবের কিছু এখানে নেই। qty আগের মতোই পণ্যের এককে, আর সব যোগফল
 * ওটাই পড়ে — এই দুইটা ঘর কেবল চোখের জন্য।
 */
trait HasEnteredPack
{
    public function enteredUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'entered_unit_id');
    }

    /** লাইনটা কি প্যাকে লেখা হয়েছিল, নাকি পণ্যের নিজের এককে। */
    public function wasEnteredInAPack(): bool
    {
        return $this->entered_unit_id !== null && $this->entered_qty !== null;
    }

    /**
     * কাগজে যে সংখ্যাটা যাবে।
     *
     * প্যাকে লেখা হলে যা লেখা হয়েছিল সেটাই — গুদামের লোক "২ বাক্স"
     * গুনতে পারেন, "২০০ পিস" গুনতে গেলে বাক্স খুলতে হত।
     */
    public function packedQty(string $baseField = 'qty'): string
    {
        return (string) ($this->wasEnteredInAPack() ? $this->entered_qty : $this->{$baseField});
    }

    /**
     * কাগজে যে দর যাবে — প্যাকপ্রতি, যাতে পরিমাণ × দর = টাকা মেলে।
     *
     * ── কেন ঘরে রাখা হয় না, গোনা হয় ─────────────────────────────────
     * এটা মজুদের দর থেকে ফিরে গোনা যায় (ভিত্তি দর × ভিত্তি পরিমাণ ÷
     * লেখা পরিমাণ), আর গোনা গেলে জমা রাখা উচিত নয়: জমা রাখা দর একদিন
     * লাইনের টাকার সাথে অমিল হত, আর তখন কাগজে "২ বাক্স × ৮০০ = ১৫০০"
     * ছাপা হত — যোগফলটা ঠিক, অথচ পড়ে কেউ মেলাতে পারত না।
     */
    public function packedRate(string $rateField = 'rate', string $baseField = 'qty'): string
    {
        $rate = (string) ($this->{$rateField} ?? '0');

        if (! $this->wasEnteredInAPack() || bccomp((string) $this->entered_qty, '0', 6) === 0) {
            return $rate;
        }

        return bcdiv(
            bcmul($rate, (string) $this->{$baseField}, 6),
            (string) $this->entered_qty,
            4,
        );
    }

    /** কাগজে যে এককের নাম যাবে — প্যাকেরটা, নাহলে পণ্যেরটা। */
    public function packedUnitName(): string
    {
        if ($this->wasEnteredInAPack()) {
            return $this->enteredUnit?->name() ?? '';
        }

        return $this->product?->unit?->name() ?? '';
    }
}
