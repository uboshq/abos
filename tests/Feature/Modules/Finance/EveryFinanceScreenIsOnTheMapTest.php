<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Modules\Finance\Support\FinancePlan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * অর্থের প্রতিটা পর্দা মানচিত্রে থাকতেই হবে।
 *
 * ── কেন এটা উল্টো দিকের পাহারা ───────────────────────────────────────
 * [[TheFinanceMapCannotLieTest]] দেখে **মানচিত্র থেকে কোডের দিকে**:
 * একটা লাইনে রুটের নাম লেখা আছে, রুটটা সত্যিই আছে কি না।
 *
 * ⚠️ কিন্তু উল্টো দিকটা কেউ দেখত না — **একটা নতুন পর্দা বানানো হলো, আর
 * মানচিত্রে উঠল না**। ৪ সেপ্টেম্বর ২০২৬-এ ঠিক সেটাই ঘটেছে:
 * `finance.deposit.all` বানানো হলো, ড্যাশবোর্ডের টালি ওখানে নামল, আর
 * মানচিত্র জানতই না।
 *
 * ⭐ **তাতে মানচিত্র নীরবে কম বলত** — মালিক পড়ে ভাবতেন কম হয়েছে, অথচ
 * কাজটা হয়ে গেছে। মিথ্যা নয়, কিন্তু অসম্পূর্ণ — আর সিদ্ধান্তের জন্য
 * দুইটাই সমান খারাপ।
 *
 * এখন **নতুন দরজা মানচিত্রকে টেনে নেয়**, উল্টোটা নয়।
 *
 * ── কোন রুটগুলো "পর্দা" ─────────────────────────────────────────────
 * ⚠️ কাজের রুট (POST/DELETE) মানচিত্রে তোলার জিনিস নয় — ওগুলো তুললে
 * তালিকাটা কোলাহলে ভরে যেত আর কেউ পড়ত না। ⓘ প্যারামিটার-চাওয়া
 * পাতাগুলোও (`{deposit}`, `{handLoan}`) বাদ: ওগুলো তালিকা থেকে নামার
 * পথ, নিজে দাঁড়ানো পর্দা নয়।
 */
final class EveryFinanceScreenIsOnTheMapTest extends TestCase
{
    /**
     * মানচিত্রে নেই এমন কোনো পর্দা-রুট থাকতে পারে না।
     */
    public function test_no_finance_screen_is_missing_from_the_map(): void
    {
        $onMap = $this->routesOnMap();

        $this->assertNotSame([], $onMap, 'মানচিত্রে একটাও রুট নেই — পাহারাটা কি অন্ধ?');

        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'finance.')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // প্যারামিটার চাইলে সেটা নামার পথ, দাঁড়ানো পর্দা নয়
            if (str_contains($route->uri(), '{')) {
                continue;
            }

            /*
             * মানচিত্রের নিজের পাতাটা মানচিত্রে থাকে না — সে তো
             * তালিকাটাই। নিজেকে নিজের তালিকায় তুললে প্রতিটা পাঠক
             * একবার ঘুরে আবার একই জায়গায় ফিরতেন।
             */
            if ($name === 'finance.plan') {
                continue;
            }

            /*
             * পুরনো ঠিকানা থেকে নতুনটায় পাঠানোর রুট — পর্দা নয়।
             *
             * `finance.capital.moved` কেবল `/accounts/capital` থেকে
             * `finance.capital.index`-এ **রিডাইরেক্ট** করে। ⓘ গন্তব্যটা
             * মানচিত্রে আছেই; পাঠানোর পথটাকেও তুললে একই পর্দা দুইবার
             * গোনা হত, আর সংখ্যাটা মিথ্যা বড় দেখাত।
             *
             * ⚠️ চেনার নিয়ম নামে নয়, আচরণে: রুটটা কোনো কন্ট্রোলারে যায়
             * না, একটা closure। নাম ধরে বাদ দিলে পরের রিডাইরেক্টটা আবার
             * ধরা পড়ত।
             */
            if (! is_string($route->getAction('controller'))) {
                continue;
            }

            if (! in_array($name, $onMap, true)) {
                $missing[] = $name.'  ('.$route->uri().')';
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['এই পর্দাগুলো আছে, কিন্তু মানচিত্রে নেই:', ''],
            $missing,
            [
                '',
                'FinancePlan::sections()-এ লাইনটা যোগ করুন, আর RECONCILED_ON হালনাগাদ করুন।',
                'মানচিত্র কম বললে মালিক ভাবেন কাজটা হয়নি — মিথ্যার মতোই ক্ষতিকর।',
            ],
        )));
    }

    /**
     * মানচিত্রে যত রুটের নাম — `:` -এর আগের অংশটাই নাম।
     *
     * @return list<string>
     */
    private function routesOnMap(): array
    {
        $out = [];

        foreach (FinancePlan::sections() as $section) {
            foreach ($section['items'] as $item) {
                if (($item[1] ?? null) === null) {
                    continue;
                }

                $out[] = explode(':', (string) $item[1], 2)[0];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * ⚠️ তারিখটা যেন পিছিয়ে না থাকে — মানচিত্র বদলালে ওটাও বদলাতে হবে।
     *
     * ⓘ এটা "মানচিত্র সত্যি" প্রমাণ করে না, করতে পারেও না — কেবল বলে
     * **কবে শেষ মিলিয়ে দেখা হয়েছে**, আর সেটা পর্দায় দেখা যায়। ছয়
     * মাসের পুরনো তারিখ নিজেই সতর্কবার্তা।
     */
    public function test_the_map_says_when_it_was_last_reconciled(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            FinancePlan::RECONCILED_ON,
            'মিলিয়ে দেখার তারিখটা YYYY-MM-DD আকারে থাকা দরকার।',
        );
    }
}
