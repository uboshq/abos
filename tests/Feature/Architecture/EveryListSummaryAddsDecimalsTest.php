<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Contracts\SummarisesItsList;
use App\Core\Support\ListSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ⭐ তালিকার মোট এক জায়গা থেকে, আর তার টাকার কলাম DECIMAL — [[ListSummary]] (fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ── কেন পরীক্ষায়, চলার সময় নয় ──────────────────────────────────────────
 * ⓘ [[ListSummary]] নিজেও থামে (DECIMAL নয়, অচেনা দল, counted-এর বাইরের open) — কিন্তু তখন ভুলটা দেখা যেত তালিকা খোলার
 * সময়, মানুষের সামনে। ⭐ এখানে [[SummarisesItsList]] মানা প্রতিটা মডেলের আকার আগেই যাচাই হয়, তাই ভুলটা CI-তে ধরা পড়ে।
 *
 * ── ⛔ ফোনের তালিকার মোট নিজে যোগ করে না ──────────────────────────────
 * ফোনের ভাউচারের মোট `status <> cancelled` গুনত, ফোনের বিলের তালিকা বাতিল বাদ দিলেও খসড়া গুনত — প্রতিটা নিজের মতো, তাই ওয়েব আর
 * ফোন আলাদা সংখ্যা বলত (a4-এর নিরীক্ষা)। ⓘ তাই ফোনের API কন্ট্রোলারে নিজের যোগ (`->sum(`, `SUM(`, `grandTotals(`) কেবল
 * [[KNOWN]]-এর জায়গায় — তালিকাটা কেবল ছোট হয়, প্রতিটা সারির মালিক আর কারণসহ; নতুন জায়গা লাল, সারানো জায়গা তালিকা থেকে
 * না সরালেও লাল (তালিকা সত্য থাকে)।
 */
final class EveryListSummaryAddsDecimalsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ফোনের API-তে আজও নিজে যোগ করা জায়গা — ফাইল => [কতগুলো, মালিক আর কারণ]। ⓘ কেবল ছোট হবে।
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const KNOWN = [
        // ⓘ fe, ৩১ অক্টোবর ২০২৬ — অনুমোদনের ইনবক্স (দফা ২১): স্থায়ী — কাগজের তালিকা নয়: অনুরোধের সারি, ট্যাবেই ছাঁকা (খসড়া/বাতিল কাগজ
        //   কোনো ট্যাবে আসে না), টাকা অনুরোধের দিনের; দুইটা জায়গা — সবগুলোর টাকা আর ট্যাবের গোনার যোগ
        'app/Http/Controllers/Api/ApprovalApiController.php' => [2, 'স্থায়ী — কাগজের তালিকা নয়: অনুমোদনের অনুরোধের সারি, ট্যাবে ছাঁকা'],
        'app/Http/Controllers/Api/PhonePaperApiController.php' => [1, 'cb — ফেরত, কেনার কাগজ আর ট্রান্সফারের যোগফল; ListSummary-তে সরবে (তালিকার মোটের শাখা)'],
        'app/Modules/Purchase/Http/Controllers/PurchaseApiController.php' => [2, 'cb — কেনার তালিকা (status != cancelled) ListSummary-তে সরবে; অন্যটা সরবরাহকারীর দেনা: স্থায়ী — কাগজের তালিকা নয়'],
        // ⓘ ১ → ২ (fe, ৩১ অক্টোবর ২০২৬): main-এর আদায়ের সারিতে "বিলে প্রয়োগ হয়নি" = আদায় − তার লাইনের যোগ (`$c->lines->sum`) —
        //   একটা সারির নিজের হিসাব, তালিকার মোট নয়; তালিকার মোটটা আগের মতোই একটা (ListSummary-তে সরবে)
        'app/Modules/Sales/Http/Controllers/CollectionApiController.php' => [2, 'a6 → fe — আদায়ের মোট ListSummary-তে সরবে; অন্যটা সারির নিজের বাকি, স্থায়ী'],
        'app/Modules/Sales/Http/Controllers/CustomerLedgerApiController.php' => [2, 'স্থায়ী — কাগজের তালিকা নয়: খতিয়ানের বাকি (SUM ডেবিট − SUM ক্রেডিট, এক লাইনে দুটো)'],
        'app/Modules/Sales/Http/Controllers/DeliveryOrderApiController.php' => [1, 'a6 — DO-র মোট; ListSummary-তে সরবে (counted + open)'],
        'app/Modules/Sales/Http/Controllers/SalesDeskApiController.php' => [1, 'a6 — বিক্রির ডেস্কের সর্বমোট; Sales-এর মোটের শাখায়, DO আর SO-র পরে (fe)'],
        'app/Modules/Sales/Http/Controllers/SalesOrderApiController.php' => [1, 'a6 — SO-র মোট; ListSummary-তে সরবে'],
        'app/Modules/Sales/Http/Controllers/SalesPaperApiController.php' => [2, 'a6 — ফোনের বিল (sum) আর চালানের মোট; ListSummary-তে সরবে'],
        'app/Modules/Sales/Http/Controllers/SalesQuotationApiController.php' => [1, 'a6 — দরপত্রের মোট; Sales-এর মোটের শাখায়, DO আর SO-র পরে (fe)'],
    ];

    private const ADDS_ITSELF = '/->sum\(|SUM\(|grandTotals\(/';

    public function test_every_list_summary_shape_holds(): void
    {
        foreach ($this->summarisers() as $class) {
            try {
                ListSummary::assertShape($class::listSummary());
            } catch (LogicException $refused) {
                $this->fail("⛔ {$class}-এর তালিকার মোটের আকার চলবে না: ".$refused->getMessage());
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_shape_check_refuses_a_column_that_rounds(): void
    {
        $this->expectExceptionMessage('DECIMAL নয়');

        ListSummary::assertShape(new \App\Core\Support\SummaryShape(\App\Modules\Accounts\Models\Voucher::class, 'id', ['confirmed' => ListSummary::COUNTED]));
    }

    public function test_the_finder_sees_every_class_that_says_it_summarises(): void
    {
        $said = [];

        foreach ($this->appFiles() as $path => $src) {
            if (preg_match('/^(?:final |readonly |abstract )*class \w+[^{]*\bimplements\b[^{]*\bSummarisesItsList\b/m', $src)) {
                $said[] = $path;
            }
        }

        $this->assertCount(count($said), $this->summarisers(), '⛔ SummarisesItsList ঘোষণা করা ক্লাসের সংখ্যা আর পাহারার খুঁজে পাওয়া সংখ্যা আলাদা — পাহারা কাউকে না দেখেই সবুজ হত');
        $this->assertContains(\App\Modules\Accounts\Models\Voucher::class, $this->summarisers(), '⛔ পাহারা ভাউচারের তালিকার মোটই দেখল না');
    }

    public function test_a_phone_list_does_not_add_its_own_total(): void
    {
        $found = [];

        foreach ($this->appFiles() as $path => $src) {
            if (! str_ends_with($path, 'ApiController.php')) {
                continue;
            }

            $hits = preg_match_all(self::ADDS_ITSELF, $src);

            if ($hits > 0) {
                $found[$path] = $hits;
            }
        }

        ksort($found);

        foreach ($found as $path => $hits) {
            $known = self::KNOWN[$path][0] ?? 0;
            $this->assertLessThanOrEqual($known, $hits,
                "⛔ {$path}: ফোনের তালিকা নিজে মোট যোগ করছে ({$hits} জায়গা, তালিকায় {$known}) — মোট আসবে ListSummary থেকে, শুধু পাকা কাগজ গুনে");
        }

        foreach (self::KNOWN as $path => [$known, $why]) {
            $this->assertSame($known, $found[$path] ?? 0,
                "⛔ {$path}: KNOWN বলছে {$known} জায়গা, আছে ".($found[$path] ?? 0)." — সারানো হলে তালিকা থেকেও সরাও ({$why})");
        }
    }

    /** @return list<class-string<SummarisesItsList>> */
    private function summarisers(): array
    {
        $out = [];

        foreach ($this->appFiles() as $path => $src) {
            if (! preg_match('/^namespace ([^;]+);/m', $src, $ns)) {
                continue;
            }

            $class = $ns[1].'\\'.basename($path, '.php');

            if (class_exists($class) && is_subclass_of($class, SummarisesItsList::class)) {
                $out[] = $class;
            }
        }

        sort($out);

        return $out;
    }

    /** @return array<string, string>  আপেক্ষিক পথ => লেখা */
    private function appFiles(): array
    {
        $out = [];

        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
            $out[str_replace('\\', '/', 'app/'.$file->getRelativePathname())] = (string) file_get_contents($file->getPathname());
        }

        ksort($out);

        return $out;
    }
}
