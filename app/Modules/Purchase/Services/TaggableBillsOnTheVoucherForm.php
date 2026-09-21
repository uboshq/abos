<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Contracts\OffersChoicesOnAForm;
use App\Core\Support\CompanyContext;
use App\Modules\Purchase\Models\PurchaseBill;

/**
 * ভাউচারের ফর্মে "এই খরচটা কোন চালানে বসবে" — মালিকের ট্যাগের তালিকা।
 *
 * ── ⚠️ কেন কথাটা এখানে, Accounts-এ নয় ───────────────────────────────
 * আগে ভাউচারের কন্ট্রোলার `PurchaseBill` সরাসরি ডাকত। ⛔ কিন্তু Purchase
 * নিজেই accounts-এর উপর দাঁড়িয়ে, তাই নির্ভরতাটা ঘোষণা করলে চক্র হত
 * (২১ সেপ্টেম্বর ২০২৬)।
 *
 * ── "আগে বসেছে" কলামটা কেন ──────────────────────────────────────────
 * মালিকের কথা: *"একই পণ্যের বিলে দুইবার ভাড়া বসলে সমস্যা, তাই যেগুলো
 * পেন্ডিং তালিকা করে দিলেই ভালো"*। ⓘ তাই প্রতিটা চালানের পাশে আগে কত
 * খরচ বসেছে তা দেখানো হয়। ⛔ দুইবার বসানো **আটকানো হয় না** — মালিকের
 * নির্দেশ: *"আটকে দেব না, দেখিয়ে দেব"*। কখনো সত্যিই দুইবার ভাড়া লাগে
 * (ফেরত, পুনঃপরিবহন)।
 *
 * ⓘ কেবল ৬০ দিনের — বছরের সব চালান দেখালে তালিকাটা শ'য়ে শ'য়ে সারি হত,
 * আর আজ আসা ট্রাকটা খুঁজে পাওয়া যেত না।
 */
final class TaggableBillsOnTheVoucherForm implements OffersChoicesOnAForm
{
    /**
     * @return array<string, mixed>
     */
    public function choicesFor(string $form): array
    {
        if ($form !== 'accounts.voucher') {
            return [];
        }

        return [
            'taggableBills' => PurchaseBill::query()
                /*
                 * ⛔ `goods_summary` সারিগুলো পড়ে, তাই ওগুলো আগেই নিয়ে আসতে হয়।
                 *
                 * ⓘ এই রিপোতে অলস লোড বন্ধ (`preventLazyLoading`), আর সেটা
                 * সুবিধা: লুকানো N+1 এখানে নীরবে ধীর হয় না, সাথে সাথে ভাঙে।
                 *
                 * ⚠️ কিন্তু ভাঙাটা দেখা গেছে কেবল মালিকের মেশিনে: পরীক্ষার
                 * ডেটায় ট্যাগ করার মতো একটাও চালান নেই, তাই টেবিলটাই আঁকা
                 * হত না আর সবুজ থাকত। ⓘ লেখা রহিল: খালি ডেটায় সবুজ
                 * হওয়া আর কাজ করা এক কথা নয়।
                 */
                ->with(['lines.product'])
                ->where('company_id', CompanyContext::id())
                ->where('trx_date', '>=', now()->subDays(60)->toDateString())
                ->withSum('billShares as already_charged', 'share_amount')
                ->withSum('lines as total_qty', 'qty')

                /*
                 * মূল্যও লাগে — ভাগ কেবল পরিমাণে হয় না। ⓘ "ভাগ হবে কীসের
                 * অনুপাতে" ঘরটা পরিমাণ ও মূল্য দুইটাই বলে; মূল্যের যোগফল
                 * না আনলে ঐ বাছাইটা পর্দায় থাকত আর কাজ করত না।
                 */
                ->withSum('lines as total_value', 'amount')
                ->orderByDesc('trx_date')->orderByDesc('id')
                ->limit(50)
                ->get(),
        ];
    }
}
