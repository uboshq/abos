<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * চালান পাকা করার প্রতিটা জায়গা পরিবহনের প্রশ্ন করে — ধাপ ৫, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⓘ কেন পাহারাটা লাগে ─────────────────────────────────────────────
 * নিয়মটা ([[TransportRule]]) সেবার `DeliveryChallanService::confirm()`-এ
 * নেই — ইচ্ছে করে: কাউন্টার ভিতর থেকে ওটা ডাকে, আর তখন প্রশ্নটা আগেই
 * হয়ে গেছে। ⚠️ দাম হলো, নতুন কোনো দরজা (ধরুন মোবাইলের API) সেবাটা
 * সরাসরি ডাকলে নিয়মটা চুপচাপ এড়িয়ে যেত, কোনো পরীক্ষা লাল না হয়েই।
 *
 * ⭐ তাই এখানে তালিকা: কোন ফাইল চালান পাকা করে, কতবার — আর প্রতিটা ফাইল
 * `TransportRule` চেনে। নতুন ডাক এলে তালিকা মেলে না, লাল। তখন দরজায়
 * নিয়মটা বসিয়ে তালিকায় লিখুন ([[TheGoodsLeftWithNoWordOnHowTheyTravelledTest]]-এ
 * দরজার দাবিসহ)।
 *
 * ⚠️ পাঠ্য পড়া দুর্বল পরীক্ষা: ডাকের ধরন বদলালে (অন্য নামের চলক) চোখ
 * এড়াতে পারে। নিচের নিজের পরীক্ষা প্রমাণ করে জালটা অন্তত ছেঁড়া নয়।
 */
final class EveryChallanConfirmAsksHowTheGoodsTravelTest extends TestCase
{
    /**
     * ফাইল → চালান পাকা করার ডাক কতবার, আর কোন দরজাগুলো সেখানে পৌঁছানোর আগে নিয়মটা জিজ্ঞেস করে।
     *
     * @var array<string, array{0: int, 1: list<string>}>
     */
    private const KNOWN = [
        'app/Modules/Sales/Services/DirectSaleService.php' => [2, [
            // কাউন্টারের "নিশ্চিত" — complete()-এর পাকা পথ
            'app/Modules/Sales/Http/Controllers/DirectSaleController.php',
            // বিলের পাতার "নিশ্চিত" — রাখা খসড়া ও সইয়ে থাকা বিক্রি, finishHeld()
            'app/Modules/Sales/Http/Controllers/SalesInvoiceController.php',
            // ⓘ শেষ সইয়ের পরের স্বয়ংক্রিয় শেষ (HeldCounterSaleFinisher) রাখা খসড়া ছোঁয় না, আর
            // সইয়ে যাওয়া বিক্রি জমা দেওয়ার সময়েই কাউন্টারের দরজা পেরিয়েছে — তাই আলাদা দরজা নয়
        ]],
        'app/Modules/Sales/Http/Controllers/DeliveryChallanController.php' => [1, [
            'app/Modules/Sales/Http/Controllers/DeliveryChallanController.php',
        ]],
    ];

    /** যে ধরনে চালানের সেবার confirm() ডাকা হয় — চলকের নাম ধরে। */
    private const CALL = '/(?:DeliveryChallanService::class\)|\$this->challans|\$challans|\$this->challanService)->confirm\(/';

    /** দরজায় নিয়মের আসল ডাক — মন্তব্যে নাম থাকা নয়। */
    private const ASKS = '/TransportRule::class\)->assert/';

    /** চালানের কন্ট্রোলার নিজের সেবাকে `service` বলে। */
    private const CONTROLLER_CALL = '/\$this->service->confirm\(/';

    public function test_every_place_that_confirms_a_challan_is_known_and_asks_the_rule(): void
    {
        $found = $this->scan();

        $this->assertGreaterThanOrEqual(2, count($found),
            '⛔ চালান পাকা করার কোনো ডাকই পাওয়া যায়নি — খোঁজটা অন্ধ, পাহারা কিছু দেখছে না।');

        $expected = array_map(fn (array $k) => $k[0], self::KNOWN);
        ksort($expected);
        ksort($found);

        $this->assertSame($expected, $found, implode(PHP_EOL, [
            'চালান পাকা করার ডাকের তালিকা বদলেছে।',
            '',
            'নতুন দরজা হলে: সেখানে app(TransportRule::class)->assertNamed(...) বসান,',
            'TheGoodsLeftWithNoWordOnHowTheyTravelledTest-এ একটা দাবি লিখুন,',
            'তারপর এই ফাইলের KNOWN তালিকায় কারণসহ।',
        ]));

        foreach (self::KNOWN as $caller => [, $doors]) {
            foreach ($doors as $door) {
                // ⚠️ শব্দটা মন্তব্যে থাকলেই চলবে না — আসল ডাক চাই
                $this->assertMatchesRegularExpression(self::ASKS, (string) file_get_contents(base_path($door)),
                    "⛔ {$caller} চালান পাকা করে, অথচ তার দরজা {$door} TransportRule জিজ্ঞেস করে না।");
            }
        }
    }

    /** ⭐ নিজের পরীক্ষা — একটা বানানো নতুন দরজা ধরা পড়ে। */
    public function test_the_net_catches_a_planted_new_door(): void
    {
        $planted = '<?php class ApiChallanController { public function go($c) { app(DeliveryChallanService::class)->confirm($c); } }';

        $this->assertSame(1, preg_match_all(self::CALL, $planted),
            '⛔ বানানো দরজাটাও ধরা পড়ল না — জালটা ছেঁড়া।');

        // ⚠️ আর মন্তব্যে নামটা থাকা ডাক বলে গোনা হয় না
        $this->assertSame(0, preg_match(self::ASKS, '// নিয়ম: [[TransportRule]] দেখুন'),
            '⛔ মন্তব্যের নামকেও ডাক ধরা হচ্ছে — দরজা নিয়ম না জিজ্ঞেস করেও সবুজ হত।');
        $this->assertSame(1, preg_match(self::ASKS, 'app(TransportRule::class)->assertNamed($data);'));
    }

    /** @return array<string, int> */
    private function scan(): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $rel = 'app/'.ltrim(substr($path, strlen(str_replace('\\', '/', app_path()))), '/');

            // ⓘ সেবা নিজে — সংজ্ঞা, ডাক নয়
            if ($rel === 'app/Modules/Sales/Services/DeliveryChallanService.php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());
            $count = preg_match_all(self::CALL, $src);

            if (str_ends_with($rel, 'Controllers/DeliveryChallanController.php')) {
                $count += preg_match_all(self::CONTROLLER_CALL, $src);
            }

            if ($count > 0) {
                $found[$rel] = $count;
            }
        }

        return $found;
    }
}
