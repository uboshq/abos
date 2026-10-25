<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ⭐ রাতের হিসাবের বিক্রির ভিত্তি কবে বদলাল — নিট বিক্রি (VAT ও ফেরত বাদে; fe, ২১ অক্টোবর ২০২৬), **কোম্পানি ধরে**।
 *
 * ── কেন ──────────────────────────────────────────────────────────────
 * আগের রাতগুলোর `sales` ঘরে বিলের মোট (VAT সহ, ফেরত না কেটে) লেখা; নতুন কোড নিট লেখে। ⛔ ইতিহাসের ধারায় দুই ভিত্তি
 * পাশাপাশি বসলে বদলের দিনে একটা মিথ্যা পতন দেখাত। ⓘ একবারের আদেশ (`abos:owner-snapshots-to-net`) পুরনো সারিগুলো নিট
 * করে; তার আগ পর্যন্ত ধারায় বদলের দিনটা চিহ্ন পায়।
 *
 * ── ⛔ কোম্পানি ধরে, গোটা টেবিল নয় (fe, ২২ অক্টোবর ২০২৬) ─────────────
 * আগে চিহ্নটা গোটা টেবিলের একটা ঘটনা ছিল: "কোনো কোম্পানির পুরনো সারি আছে কি না"। ⚠️ তাতে এক কোম্পানির তথ্য আরেক
 * কোম্পানির আচরণ ঠিক করত — এক কোম্পানির একটা সারি থাকলে অন্যটার রূপান্তরও "বাকি", বা উল্টোটা। মাল্টি-টেন্যান্ট নিয়মের
 * বাইরে। ⭐ এখন প্রতিটা কোম্পানির নিজের চিহ্ন, নিজের রূপান্তর, নিজের পালা।
 *
 * ── কোথায় রাখা ─────────────────────────────────────────────────────
 * ⓘ মাইগ্রেশন ছাড়া, তাই `storage/app`-এর একটা ছোট JSON — ক্যাশ নয়, কারণ ডেপ্লয়ে ক্যাশ মোছে আর চিহ্নটা হারাত বা ভুল দিনে
 * ফিরত। `{"companies": {"<id>": {"since": "Y-m-d", "converted": bool}}}`:
 *   since      — সেই কোম্পানিতে নতুন কোডের প্রথম রাতের হিসাবের দিন
 *   converted  — সেই কোম্পানির সব পুরনো সারি নিট হয়েছে কি না
 */
final class SalesBasis
{
    public const PATH = 'executive/sales-basis.json';

    /**
     * রাতের হিসাব লেখার আগে, কোম্পানি ধরে — প্রথমবারই কেবল লেখে। ⓘ সেই কোম্পানির আগে কোনো সারি না থাকলে বদলানোর কিছু
     * নেই, তাই সাথে সাথেই `converted`।
     */
    public function noteNetSnapshot(int $companyId): void
    {
        if ($this->entry($companyId) !== null) {
            return;
        }

        $today = Carbon::today()->toDateString();
        $old = DB::table(Snapshots::TABLE)->where('company_id', $companyId)->where('taken_on', '<', $today)->exists();

        $this->put($companyId, ['since' => $today, 'converted' => ! $old]);
    }

    /**
     * চিহ্নের দিন — দেখা কোম্পানিগুলোর যেটার পুরনো সারি এখনো বিলের মোটে, তার বদলের দিন (একাধিক হলে সবচেয়ে আগেরটা);
     * সবগুলো নিট হলে null।
     *
     * @param  int|list<int>  $companyIds
     */
    public function pending(int|array $companyIds): ?string
    {
        $days = [];
        foreach ((array) $companyIds as $id) {
            $entry = $this->entry((int) $id);
            if ($entry !== null && ($entry['converted'] ?? false) === false) {
                $days[] = (string) $entry['since'];
            }
        }
        sort($days);

        return $days[0] ?? null;
    }

    /** সেই কোম্পানিতে নতুন ভিত্তির প্রথম দিন — জানা না থাকলে null (তখন সব সারিই পুরনো ধরা হয়) */
    public function since(int $companyId): ?string
    {
        return $this->entry($companyId)['since'] ?? null;
    }

    /** সেই কোম্পানির সব পুরনো সারি নিট — তার চিহ্ন সরে, অন্যদের নয় */
    public function markConverted(int $companyId): void
    {
        $this->put($companyId, ['since' => $this->since($companyId) ?? Carbon::today()->toDateString(), 'converted' => true]);
    }

    /** @return array{since: string, converted: bool}|null */
    private function entry(int $companyId): ?array
    {
        $entry = $this->read()[(string) $companyId] ?? null;

        return is_array($entry) && isset($entry['since']) ? $entry : null;
    }

    /** @param  array{since: string, converted: bool}  $entry */
    private function put(int $companyId, array $entry): void
    {
        $all = $this->read();
        $all[(string) $companyId] = $entry;
        Storage::disk('local')->put(self::PATH, (string) json_encode(['companies' => $all]));
    }

    /** @return array<string, mixed> কোম্পানি → চিহ্ন; ⓘ পুরনো গোটা-টেবিলের রূপ (`since` সরাসরি) কোনো কোম্পানির নয়, তাই উপেক্ষা */
    private function read(): array
    {
        try {
            $raw = Storage::disk('local')->get(self::PATH);
        } catch (\Throwable) {
            return [];
        }

        $state = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($state) && is_array($state['companies'] ?? null) ? $state['companies'] : [];
    }
}
