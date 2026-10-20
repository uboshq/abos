<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ⭐ রাতের হিসাবের বিক্রির ভিত্তি কবে বদলাল — নিট বিক্রি (VAT ও ফেরত বাদে; fe, ২১ অক্টোবর ২০২৬)।
 *
 * ── কেন ──────────────────────────────────────────────────────────────
 * আগের রাতগুলোর `sales` ঘরে বিলের মোট (VAT সহ, ফেরত না কেটে) লেখা; নতুন কোড নিট লেখে। ⛔ ইতিহাসের ধারায় দুই ভিত্তি
 * পাশাপাশি বসলে বদলের দিনে একটা মিথ্যা পতন দেখাত। ⓘ একবারের আদেশ (`abos:owner-snapshots-to-net`) পুরনো সারিগুলো নিট
 * করে; তার আগ পর্যন্ত ধারায় বদলের দিনটা চিহ্ন পায়।
 *
 * ── কোথায় রাখা ─────────────────────────────────────────────────────
 * ⓘ মাইগ্রেশন ছাড়া, তাই `storage/app`-এর একটা ছোট JSON — ক্যাশ নয়, কারণ ডেপ্লয়ে ক্যাশ মোছে আর চিহ্নটা হারাত বা ভুল দিনে
 * ফিরত। সেটিং নয়, কারণ সেটিং কোম্পানির আর পর্দায় ঘোষিত; এটা গোটা টেবিলের একটা ঘটনা।
 *   since      — নতুন কোডের প্রথম রাতের হিসাবের দিন
 *   converted  — সব পুরনো সারি নিট হয়েছে কি না (আদেশের পুরো চালে `true`)
 */
final class SalesBasis
{
    public const PATH = 'executive/sales-basis.json';

    /**
     * রাতের হিসাব লেখার আগে — প্রথমবারই কেবল লেখে। ⓘ আগে কোনো সারি না থাকলে বদলানোর কিছু নেই, তাই সাথে সাথেই `converted`।
     */
    public function noteNetSnapshot(): void
    {
        if ($this->read() !== null) {
            return;
        }

        $today = Carbon::today()->toDateString();
        $old = DB::table(Snapshots::TABLE)->where('taken_on', '<', $today)->exists();

        $this->write(['since' => $today, 'converted' => ! $old]);
    }

    /** চিহ্নের দিন — পুরনো সারি এখনো বিলের মোটে থাকলে; নইলে null */
    public function pending(): ?string
    {
        $state = $this->read();

        return $state !== null && ($state['converted'] ?? false) === false ? (string) $state['since'] : null;
    }

    /** নতুন ভিত্তির প্রথম দিন — জানা না থাকলে null (তখন সব সারিই পুরনো ধরা হয়) */
    public function since(): ?string
    {
        return $this->read()['since'] ?? null;
    }

    /** সব পুরনো সারি নিট — চিহ্ন সরে */
    public function markConverted(): void
    {
        $this->write(['since' => $this->since() ?? Carbon::today()->toDateString(), 'converted' => true]);
    }

    /** @return array{since: string, converted: bool}|null */
    private function read(): ?array
    {
        try {
            $raw = Storage::disk('local')->get(self::PATH);
        } catch (\Throwable) {
            return null;
        }

        $state = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($state) && isset($state['since']) ? $state : null;
    }

    /** @param  array{since: string, converted: bool}  $state */
    private function write(array $state): void
    {
        Storage::disk('local')->put(self::PATH, (string) json_encode($state));
    }
}
