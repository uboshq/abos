<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Services\SettingsService;
use App\Modules\Accounts\Support\VoucherDesigns;
use App\Modules\Sales\Support\PaperDesigns;

/**
 * সাধারণ কাগজের ভিতরের কথা পরখ — নকশা নয় — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⓘ কেন লাগল ─────────────────────────────────────────────────────
 * মালিকের নির্দেশে (*"keu select na korle egulotei print hobe"*) বিল ও
 * চালানের ডিফল্ট এখন "মোনো ক্লাসিক হালকা", আর অর্ডার ও আদায়-রসিদের
 * "ট্যালি ক্লাসিক" ([[PaperDesigns::defaultFor()]])। যে পরীক্ষাগুলো
 * `print.document`-এর composer ধরে কাগজের **তথ্য** মাপে — মোট, দাম,
 * পরিবহন — তারা তখন কিছুই দেখত না, আর লাল হত "কাগজই আঁকেনি" বলে।
 *
 * ⚠️ ওদের প্রশ্নটা নকশার নয়, তথ্যের। তাই এখানে সব কাগজ-মাপে "standard"
 * বাছা হয়, আর প্রশ্নটা আগের মতোই সাধারণ কাগজে জিজ্ঞেস করা হয়।
 * ⭐ নকশাগুলো নিজেদের দাবিতে পরখ হয় — [[EveryPaperDesignPrintsTheRealPaperTest]]
 * আর [[EveryDesignKeepsThePaperRulesTest]]।
 */
trait PrintsTheStandardPaper
{
    protected function printTheStandardPaper(): void
    {
        $settings = app(SettingsService::class);

        foreach (PaperDesigns::PAPERS as $paper) {
            foreach (PaperDesigns::SIZES as $size) {
                $settings->set(PaperDesigns::key($paper, $size), 'standard');
            }
        }

        /* ⓘ ভাউচারের ডিফল্টও এখন নকশা (ট্যালি ক্লাসিক) — সাধারণ ভাউচার `print.voucher` */
        foreach (VoucherDesigns::SIZES as $size) {
            $settings->set(VoucherDesigns::key($size), 'standard');
        }

        /* ⓘ ১ অক্টোবর থেকে ক্রয় বিলেরও ডিফল্ট নতুন কাগজ ([[TheNewPurchaseBillPaperTest]]) */
        $settings->set('purchase.print.design.bill', 'standard');
    }
}
