<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Modules\Sales\Http\Middleware\RequireTransportBeforePrint;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * "মাল কীভাবে যাবে" — প্রশ্নটা ছাপার দরজায়, নিশ্চিতে নয় (মালিকের অনুমোদিত বদল, ১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ কী বদলাল ──────────────────────────────────────────────────────────
 * ২৮ সেপ্টেম্বর (ধাপ ৫) থেকে চালান পাকা করার প্রতিটা দরজা পরিবহন জিজ্ঞেস করত, আর এই পাহারা দেখত কোনো দরজা যেন
 * বাদ না পড়ে। ⓘ মালিক নিয়মটা সরিয়েছেন: বিক্রি নিশ্চিত হয় গাড়ি ঠিক না করেও; মাল বেরোনোর কাগজ — চালান আর গেট পাস
 * — ছাপার আগে প্রশ্নটা আসে ([[RequireTransportBeforePrint]])।
 *
 * ── এখানে কী দেখা হয় ──────────────────────────────────────────────────
 *   ১. চালান পাকা করার ডাকগুলোর তালিকা এখনো জানা — নতুন দরজা ফাঁকে ঢুকলে চোখে পড়ে — আর তাদের কেউ আর পরিবহন
 *      জিজ্ঞেস করে না (করলে বিক্রি আবার আটকাত)।
 *   ২. মাল বেরোনোর তিনটা ছাপার রুটেই মিডলওয়্যার বসানো — একটা বাদ পড়লে সেই কাগজ পরিবহন ছাড়াই ছাপা হত।
 * ⓘ আচরণের দাবি: [[TheGoodsLeftWithNoWordOnHowTheyTravelledTest]]।
 */
final class EveryChallanConfirmAsksHowTheGoodsTravelTest extends TestCase
{
    private const KNOWN = [
        // কাউন্টারের "নিশ্চিত" আর বিলের পাতার "নিশ্চিত" — complete() ও finishHeld()
        'app/Modules/Sales/Services/DirectSaleService.php' => 2,
        'app/Modules/Sales/Http/Controllers/DeliveryChallanController.php' => 1,
        // ⓘ শেষ সইয়ের পরে অফিসের চালান
        'app/Modules/Sales/Services/SignedChallanConfirmer.php' => 1,
    ];

    /** যে দরজাগুলো আগে নিয়ম জিজ্ঞেস করত — এখন আর নয় */
    private const DOORS = [
        'app/Modules/Sales/Http/Controllers/DirectSaleController.php',
        'app/Modules/Sales/Http/Controllers/SalesInvoiceController.php',
        'app/Modules/Sales/Http/Controllers/DeliveryChallanController.php',
        'app/Modules/Sales/Services/SignedChallanConfirmer.php',
    ];

    /** মাল বেরোনোর কাগজ ছাপার রুট — প্রতিটায় পরিবহনের প্রশ্ন */
    private const GOODS_OUT_PRINTS = ['sales.print.challan', 'sales.print.gatepass', 'sales.print.gate_pass'];

    private const CALL = '/(?:DeliveryChallanService::class\)|\$this->challans|\$challans|\$this->challanService)->confirm\(/';

    private const ASKS = '/TransportRule::class\)->assert/';

    private const CONTROLLER_CALL = '/\$this->service->confirm\(/';

    public function test_every_place_that_confirms_a_challan_is_known_and_none_asks_for_transport(): void
    {
        $found = $this->scan();

        $this->assertGreaterThanOrEqual(2, count($found),
            '⛔ চালান পাকা করার কোনো ডাকই পাওয়া যায়নি — খোঁজটা অন্ধ, পাহারা কিছু দেখছে না।');

        $expected = self::KNOWN;
        ksort($expected);
        ksort($found);

        $this->assertSame($expected, $found, implode(PHP_EOL, [
            'চালান পাকা করার ডাকের তালিকা বদলেছে।',
            '',
            'নতুন দরজা হলে: পরিবহন সেখানে নয় — ছাপার দরজাই জিজ্ঞেস করে ([[RequireTransportBeforePrint]]);',
            'তারপর এই ফাইলের KNOWN তালিকায় কারণসহ।',
        ]));

        foreach (self::DOORS as $door) {
            $this->assertDoesNotMatchRegularExpression(self::ASKS, (string) file_get_contents(base_path($door)),
                "⛔ {$door} আবার নিশ্চিতের সময় পরিবহন চাইছে — মালিকের নিয়মে প্রশ্নটা ছাপার আগে, নিশ্চিতে নয়।");
        }
    }

    public function test_every_goods_out_print_asks_how_the_goods_travel(): void
    {
        foreach (self::GOODS_OUT_PRINTS as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "ছাপার রুট {$name} নেই — তালিকাটা পুরনো।");
            $this->assertContains(RequireTransportBeforePrint::class, $route->gatherMiddleware(),
                "⛔ {$name} পরিবহন না জেনেই ছাপে — মাল কার গাড়িতে গেল, কাগজে থাকে না।");
        }
    }

    public function test_the_net_catches_a_planted_new_door(): void
    {
        $planted = '<?php class ApiChallanController { public function go($c) { app(DeliveryChallanService::class)->confirm($c); } }';

        $this->assertSame(1, preg_match_all(self::CALL, $planted),
            '⛔ বানানো দরজাটাও ধরা পড়ল না — জালটা ছেঁড়া।');

        // ⚠️ মন্তব্যে নামটা থাকা ডাক নয়; আসল ডাক ধরা পড়ে
        $this->assertSame(0, preg_match(self::ASKS, '// নিয়ম: [[TransportRule]] দেখুন'));
        $this->assertSame(1, preg_match(self::ASKS, 'app(TransportRule::class)->assertNamed($data);'));
    }

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
