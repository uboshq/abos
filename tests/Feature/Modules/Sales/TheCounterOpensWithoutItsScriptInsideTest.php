<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * কাউন্টারের পর্দা খোলে, যদিও যুক্তিটা আর তার ভিতরে নেই।
 *
 * ── ⭐ নিরীক্ষার ধাপ ৪.১, ১৮ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"ইনলাইন `<script>`-এর যুক্তি `resources/js/counter/` ফোল্ডারে মডিউল
 * হিসেবে সরান… লক্ষ্য: প্রতিটি ব্লেড ফাইল ১,০০০ লাইনের নিচে, শূন্য
 * ইনলাইন স্ক্রিপ্ট।"*
 *
 * ⓘ বিক্রয়ের কাউন্টার ছিল **৪,৪১৩ লাইন**, তার ভিতরে **১,৪৫৩ লাইন** JS।
 *
 * ── ⚠️ এই পরীক্ষাটা কেন ছোট, আর কী প্রমাণ করে না ─────────────────────
 * ⛔ এটা প্রমাণ করে না যে কাউন্টার **কাজ করে** — ওটা ব্রাউজারে দাঁড়িয়ে
 * বিল কেটে দেখার জিনিস, আর নিরীক্ষাতেও ঠিক সেটাই লেখা আছে।
 *
 * ⭐ এটা প্রমাণ করে যে পর্দাটা **খোলে**, আর ইনলাইন স্ক্রিপ্ট সত্যিই
 * শূন্য। ⓘ সরানোর পর সবচেয়ে সম্ভাব্য ভুল দুইটাই: ব্লেড ভেঙে যাওয়া, আর
 * `x-data`-র ডাকটা পুরনো সইয়ে থেকে যাওয়া — দুইটাই এখানে ধরা পড়ে।
 */
final class TheCounterOpensWithoutItsScriptInsideTest extends TestCase
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

    /**
     * ⭐ দুইটা কাউন্টারই খোলে, আর ভিতরে একটাও `<script>` নেই।
     *
     * @dataProvider counters
     */
    #[DataProvider('counters')]
    public function test_the_counter_opens_and_carries_no_script_of_its_own(string $route): void
    {
        $html = (string) $this->get(route($route))->assertOk()->getContent();

        /*
         * ⓘ খোলসের নিজের স্ক্রিপ্ট (Vite-এর বান্ডল) বাদে — সেগুলোর
         * `src` আছে। ⚠️ আমরা খুঁজছি **ইনলাইন** স্ক্রিপ্ট, অর্থাৎ যার
         * ভিতরে কোড লেখা।
         *
         * ── ⛔ আর `<script>` মানেই কোড নয় ────────────────────────────
         * প্রথম চালেই পাহারাটা একটা ভুল অভিযোগ তুলেছে: খোলসে একটা
         * `type="speculationrules"` ট্যাগ আছে, যেটা ব্রাউজারকে বলে
         * কোন লিংক আগে থেকে আনা যাবে। ⓘ ওটা **তথ্য**, কোড নয় — ব্রাউজার
         * ওটা চালায় না, JSON হিসেবে পড়ে।
         *
         * ⚠️ তাই কেবল সেই ধরনগুলোই গোনা হয় যেগুলো ব্রাউজার **চালায়**।
         * ⛔ নইলে পাহারাটা চিরকাল লাল থাকত, আর একদিন কেউ বিরক্ত হয়ে
         * সেটা তুলে দিতেন — তখন আসল ইনলাইন স্ক্রিপ্টও আর ধরা পড়ত না।
         */
        preg_match_all('/<script(?![^>]*\ssrc=)([^>]*)>(.*?)<\/script>/s', $html, $m, PREG_SET_ORDER);

        $withCode = [];

        foreach ($m as [, $attributes, $code]) {
            if (trim($code) === '') {
                continue;
            }

            // ⓘ ধরন না লেখা থাকলে সেটা JavaScript — ওটাই HTML-এর ডিফল্ট
            preg_match('/\stype=[\'"]?([^\'"\s>]+)/', $attributes, $t);
            $type = strtolower($t[1] ?? 'text/javascript');

            if (! in_array($type, ['text/javascript', 'module', 'application/javascript'], true)) {
                continue;
            }

            $withCode[] = $code;
        }

        $this->assertSame([], $withCode, sprintf(
            "কাউন্টারের পাতায় এখনো %d টা ইনলাইন স্ক্রিপ্ট:\n%s",
            count($withCode),
            mb_substr(implode("\n---\n", $withCode), 0, 400),
        ));
    }

    /**
     * ⛔ `x-data`-র ডাকটা নতুন সইয়ে — নামধারী ঘরে, অবস্থানে নয়।
     *
     * ⚠️ সই বদলানোর পর পুরনো ডাকটা থেকে গেলে Alpine চুপচাপ `undefined`
     * পেত, আর পর্দাটা **খুলত কিন্তু কিছু করত না** — ঠিক সেই নীরব
     * ব্যর্থতা যেটা আজ বারবার ধরা পড়েছে।
     */
    public function test_the_screen_asks_for_what_the_module_expects(): void
    {
        $html = (string) $this->get(route('sales.direct.create'))->assertOk()->getContent();

        foreach (['catalogue:', 'customers:', 'walkinId:', 'packs:', 'draftKey:', 'texts:'] as $key) {
            $this->assertStringContainsString($key, $html,
                "`x-data`-তে `{$key}` নেই — মডিউলটা ওটা চায়।");
        }
    }

    /**
     * দুইটা কাউন্টার — একই সারাই, একই দাবি।
     *
     * @return array<string, array{0: string}>
     */
    public static function counters(): array
    {
        return [
            'বিক্রয়' => ['sales.direct.create'],
            'ক্রয়' => ['purchase.direct.create'],
        ];
    }
}
