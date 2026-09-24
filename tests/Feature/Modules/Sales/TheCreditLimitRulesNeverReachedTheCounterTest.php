<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সীমার নিয়মগুলো কাউন্টার পর্যন্ত পৌঁছাত না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"Available Balance … বিলের মোট box e আগের বকেয়া er niche"*, আর
 * সীমা ফুরালে এন্ট্রিতেই আটকাবে।
 *
 * ── ⛔ দেয়ালটা নতুন নয়, খবরটা দেরিতে আসত ────────────────────────────
 * ⓘ আসল দেয়াল [[SalesInvoiceService::assertWithinCreditLimit()]]-এ, আর
 * সেটা অক্ষত। ⚠️ কিন্তু সে কথা বলে **সংরক্ষণের সময়** — ত্রিশটা সারি
 * তোলার পরে, আর তখন কোনটা বাদ দিলে চলবে তা কেউ বলে না।
 *
 * ── ⚠️ এই ফাইলটা অঙ্ক মাপে না, **তার** মাপে ─────────────────────────
 * ⓘ অঙ্কটা [[direct-sale.test.js]]-এ মাপা (তেরোটা দাবি, মিউট্যান্টে
 * যাচাই করা)। ⛔ কিন্তু ঐ পরীক্ষাগুলো কম্পোনেন্টকে **হাতে** নিয়মগুলো
 * ধরিয়ে দেয় — সার্ভার সত্যিই ওগুলো পাঠায় কি না, তারা জানেই না।
 *
 * ⭐ এটাই ABOS-এর সবচেয়ে সাধারণ ভুলের আকার: *কাজটা হয়ে আছে, তারটা
 * জোড়া লাগেনি* — আর তাতে কিছুই লাল হয় না, কারণ `undefined` নিয়েও
 * পর্দা দিব্যি খোলে, কেবল কোনোদিন কিছু আটকায় না।
 */
final class TheCreditLimitRulesNeverReachedTheCounterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    private function counterHtml(): string
    {
        return (string) $this->get(route('sales.direct.create'))->assertOk()->getContent();
    }

    /**
     * ⭐ নিয়মগুলো সত্যিই পর্দায় যায়, আর সীমার ঘরটা আঁকা হয়।
     */
    public function test_the_counter_is_handed_the_rules_and_the_row(): void
    {
        $html = $this->counterHtml();

        $this->assertStringContainsString('creditRules', $html,
            '⛔ কাউন্টারে `creditRules` পৌঁছায়নি — তখন পর্দা কোনোদিন কিছু '
            .'আটকাত না, অথচ কোনো ত্রুটিও দেখা যেত না।');

        /*
         * ⚠️ চাবির নাম নয়, **লেখাটা** খোঁজা হয় — ⛔ চাবিটা অনুবাদে না
         * থাকলে পর্দায় `sales::field.credit_left` ছাপা হত, আর চাবির নাম
         * খুঁজলে সেই ভাঙা অবস্থাটাও সবুজ দেখাত।
         */
        $this->assertStringContainsString(__('sales::field.credit_left'), $html,
            '⛔ "বাকি দেওয়া যাবে" সারিটা বিলের মোট বাক্সে নেই।');

        /* ⓘ সতর্কবার্তার ঘরটাও — ওটা ছাড়া আটকানোর কোনো চিহ্নই থাকত না। */
        $this->assertStringContainsString('creditWarning', $html,
            '⛔ সীমা ছাড়ানোর বার্তার ঘরটা পর্দায় নেই — তখন সারিটা নীরবে '
            .'কার্টে যেত না, আর একটাও কারণ দেখাত না।');
    }

    /**
     * ⛔ এটাই এই ফাইলের আসল দাবি: সংখ্যাগুলো **সেটিংস থেকে** আসে।
     *
     * ── ⚠️ কেন উপস্থিতি যথেষ্ট নয় ───────────────────────────────────
     * ⓘ `creditRules` লেখাটা পর্দায় থাকলেই প্রমাণ হয় না যে ভিতরের
     * মানগুলো সত্যি। ⛔ কেউ ভুল করে ধ্রুবক `true` বসিয়ে দিলে উপরের
     * পরীক্ষাটা সবুজই থাকত, আর কোম্পানি সুইচটা বন্ধ করেও দেখত পর্দা
     * বিক্রি আটকাচ্ছে — কারণ খুঁজে পাওয়া যেত না।
     *
     * ⭐ তাই সুইচটা **দুই দিকেই** ঘুরিয়ে দেখা হয়।
     */
    public function test_the_rules_follow_the_settings_both_ways(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('customer.block_over_limit', true);
        $this->assertStringContainsString('&quot;blocks&quot;:true', $this->counterHtml(),
            '⛔ সুইচটা চালু, তবু পর্দা `blocks: true` পায়নি।');

        $settings->set('customer.block_over_limit', false);
        $this->assertStringContainsString('&quot;blocks&quot;:false', $this->counterHtml(),
            '⛔ সুইচটা বন্ধ, তবু পর্দা `blocks: false` পায়নি — অর্থাৎ মানটা '
            .'সেটিংস থেকে আসছে না, আর কোম্পানির সিদ্ধান্ত পর্দায় পৌঁছায় না।');
    }

    /**
     * ⓘ সেবার শর্তগুলোর একটাও বাদ পড়েনি।
     *
     * ⚠️ পর্দা একটু কড়া হলে সে এমন বিক্রি আটকাত যা সেবা মেনে নিত, আর
     * বিক্রেতা কারণ খুঁজে পেতেন না। একটু ঢিলা হলে সে ত্রিশটা সারি তুলতে
     * দিত, আর সংরক্ষণে গিয়ে সব ভেঙে পড়ত। ⛔ দুইটাই খারাপ।
     */
    public function test_every_condition_the_service_checks_is_handed_over(): void
    {
        $html = $this->counterHtml();

        foreach (['enabled', 'blocks', 'zeroBlocks', 'canOverride'] as $key) {
            $this->assertStringContainsString('&quot;'.$key.'&quot;:', $html,
                "⛔ `{$key}` পর্দায় যায়নি — সেবা ওটা দেখে, পর্দা দেখে না, "
                .'আর তখন দুইটা আলাদা উত্তর দেয়।');
        }
    }
}
