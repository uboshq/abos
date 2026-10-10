<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * যে তালিকার শেষ নেই, তার পাতাও শেষ হয় না।
 *
 * ── ⛔ নিরীক্ষার ফলাফল, ১২ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"৩৪টি তালিকায় পেজিনেশন নেই"* — আর কার্যক্ষমতার নম্বর ৭.০।
 *
 * ⭐ সংখ্যাটা সত্যি ছিল, কিন্তু **ঝুঁকিটা নয়**। ১৭ সেপ্টেম্বর একটা একটা
 * করে খুলে দেখা গেছে: বেশিরভাগ তালিকারই স্বাভাবিক সীমা আছে — কোম্পানির
 * সংখ্যা, ভূমিকার সংখ্যা, আজকের শিফট, চলতি মাসের লক্ষ্য। ⓘ ওগুলোয় পাতা
 * ভাগ করা মানে পাঁচ সারির তালিকায় "১–৫ / ৫" লিখে রাখা — কাজ বাড়ে, লাভ নেই।
 *
 * ⛔ সত্যিকারের ঝুঁকি পাওয়া গেছে **একটাই** ([[BankFacilityController]]):
 * ব্যাংক-সুবিধা জমতেই থাকে, কারণ প্রতিটা নবায়ন একটা নতুন সারি আর
 * পুরনোগুলো ইতিহাস হিসেবে থেকে যায়। ⓘ ওটায় পাতা ভাগ বসেছে।
 *
 * ── ⭐ তাহলে এই ফাইলটা কেন ────────────────────────────────────────────
 * কারণ *"আমি দেখেছি, ঠিক আছে"* কোনো প্রমাণ নয় — ওটা একটা স্মৃতি, আর
 * স্মৃতি কমিটের সাথে থাকে না। ⚠️ ছয় মাস পর কেউ আবার নিরীক্ষা করে একই
 * ২৮টা পাবেন, আর আবার একই দিন খরচ করবেন।
 *
 * ⛔ আরও খারাপ: আগামীকাল কেউ একটা **নতুন** তালিকা বসাবেন যেটা সত্যিই
 * বাড়ে, আর সেটা এই ২৮-এর ভিড়ে মিশে যাবে। ⓘ ভিড়ের ভিতরে একটা সত্যিকারের
 * বিপদ লুকানো সবচেয়ে সহজ।
 *
 * ⭐ তাই নিয়মটা এখন যন্ত্রের হাতে: **পাতা ভাগ করো, নয়তো কারণ লেখো।**
 * তৃতীয় কোনো পথ নেই।
 */
final class TheListThatGrowsForeverAndThePageThatNeverEndsTest extends TestCase
{
    /**
     * যে তালিকাগুলো ইচ্ছাকৃতভাবে পুরোটা দেখায় — আর কেন।
     *
     * ⚠️ এখানে নাম বসানোর আগে **একবার ভাবুন**: তালিকাটার কি সত্যিই একটা
     * সীমা আছে, নাকি আজ ছোট বলে ছোট মনে হচ্ছে? ⛔ "আজ মাত্র ২০টা সারি"
     * কোনো কারণ নয়; "একটা কোম্পানিতে গুদাম দশটার বেশি হয় না" কারণ।
     *
     * @var array<class-string, string>
     */
    private const WHOLE_ON_PURPOSE = [
        // ── সীমাটা হিসাববর্ষের, তাই বছরে বারোটার বেশি বাড়ে না ──────────
        'App\Modules\Accounts\Http\Controllers\PeriodLockController' => 'হিসাববর্ষের মাসগুলো — বছরে বারোটা।',
        'App\Modules\Accounts\Http\Controllers\YearEndController' => 'কেবল চলতি বছর, একটাই।',

        // ── প্রতিষ্ঠানের নিজের সাজানো, আর সাজানো জিনিস গোনা যায় ────────
        'App\Modules\MasterData\Http\Controllers\NumberSeriesController' => 'প্রতি কাগজের ধরনে একটা সিরিজ — সংখ্যাটা ধরনের সংখ্যায় বাঁধা।',
        'App\Modules\SystemAdmin\Http\Controllers\CompanyController' => 'প্রতিষ্ঠানের কোম্পানি — হাতে গোনা।',
        'App\Modules\SystemAdmin\Http\Controllers\CustomFieldController' => 'নিজের বানানো ঘর — ডজনখানেক।',
        'App\Modules\SystemAdmin\Http\Controllers\LookController' => 'রূপের তালিকা কোডে লেখা, স্থির।',
        'App\Modules\SystemAdmin\Http\Controllers\RoleController' => 'ভূমিকা হাতে বানানো হয় — ডজনখানেক।',
        'App\Modules\SystemAdmin\Http\Controllers\ReportScheduleController' => 'নির্ধারিত রিপোর্ট হাতে বসানো — ডজনখানেক।',
        'App\Modules\Backup\Http\Controllers\DestinationController' => 'ব্যাকআপের গন্তব্য দুই-তিনটা; বেশি হলে সেটাই ভুল।',

        // ── ফাইল বা কাগজের সংখ্যা যেখানে নীতিই বেঁধে দেয় ────────────────
        'App\Modules\Backup\Http\Controllers\BackupController' => 'রাখার মেয়াদ (keep_days) নিজেই সীমা — পুরনোগুলো মুছে যায়।',
        'App\Modules\SystemAdmin\Http\Controllers\ImportController' => 'ইমপোর্ট করা যায় এমন ধরনের তালিকা — কোডে লেখা।',

        /*
         * ── ⭐ তারিখ বা অবস্থাই যেখানে সীমা ─────────────────────────────
         *
         * ⓘ এই দলটার তালিকা কাল বড় হয় না, কারণ কালকের প্রশ্নটাই আলাদা:
         * "আজকের শিফট", "এখনো বসানো হয়নি এমন মাল", "চলতি মাসের লক্ষ্য"।
         * ⚠️ সারি জমে ডাটাবেজে, কিন্তু **পর্দায় নয়**।
         */
        'App\Modules\Sales\Http\Controllers\ShiftController' => 'আজকের শিফট — তারিখেই বাঁধা।',
        'App\Modules\Sales\Http\Controllers\SalesTargetController' => 'চলতি মাসের স্কোরবোর্ড — মাস ও কর্মীর সংখ্যায় বাঁধা।',
        'App\Modules\Sales\Http\Controllers\PrintQueueController' => 'ছাপার অপেক্ষায় থাকা কাজ — ছাপা হলেই সারি চলে যায়।',
        'App\Modules\Inventory\Http\Controllers\StockPlacementController' => 'এখনো বসানো হয়নি এমন মাল — বসালেই তালিকা থেকে যায়।',
        'App\Modules\Governance\Http\Controllers\SessionController' => 'ব্যবহারকারীর নিজের চালু সেশন — কয়েকটা যন্ত্র।',

        /*
         * ── ⛔ যেখানে পাতা ভাগ করলে পর্দাটাই ভেঙে যেত ───────────────────
         *
         * ⚠️ এই দলটা সবচেয়ে জরুরি, কারণ এখানে পাতা ভাগ করা কেবল অপ্রয়োজনীয়
         * নয় — **ক্ষতিকর**।
         */
        'App\Modules\Inventory\Http\Controllers\LabelController' => 'চেকবক্সে পণ্য বাছার পর্দা — পাতা বদলালে আগের পাতার বাছাই হারাত।',
        'App\Modules\Sales\Http\Controllers\PosController' => 'কাউন্টারের ক্যাটালগ — খোঁজা তাৎক্ষণিক হতে হয়, পাতা ভাগ করলে প্রতিটা বিক্রিতে অপেক্ষা।',
        'App\Modules\Restaurant\Http\Controllers\KitchenBoardController' => 'রান্নাঘরের বোর্ড — সব কাজ একসাথে দেখাই উদ্দেশ্য।',
        'App\Modules\Inventory\Http\Controllers\StorageLocationController' => 'গুদামের তাকের ক্রম — হাঁটার পথ ধরে সাজানো, ভাগ করলে পথ ভাঙত।',

        // ── তালিকা নয়, হিসাবের পাতা: যোগফল ও কয়েকটা সারি ───────────────
        'App\Modules\Finance\Http\Controllers\ExpenseController' => 'খাত ধরে যোগফলের পাতা — সারির তালিকা নয়।',
        'App\Modules\Finance\Http\Controllers\IncomeController' => 'খাত ধরে যোগফলের পাতা — সারির তালিকা নয়।',
        'App\Modules\Finance\Http\Controllers\PlanController' => 'পরিকল্পনার ধাপ কোডে লেখা, স্থির।',
        'App\Modules\Finance\Http\Controllers\HandLoanController' => 'চালু হাতধার — শোধ হলে তালিকা থেকে যায়।',
        'App\Modules\Inventory\Http\Controllers\StockOverviewController' => 'মজুদের ড্যাশবোর্ড — যোগফল ও গুদামের ড্রপডাউন।',

        /*
         * ── ⭐ ১০ অক্টোবর ২০২৬-এ যোগ — প্রতিটা পড়ে দেখা, "আজ সারি কম" নয় ───────────────
         */
        'App\Modules\Approval\Http\Controllers\ApprovalLimitController' => 'রোল × শাখা × পথের সীমা — হাতে বসানো নিয়ম, অনুমোদনের পথের মতোই ডজনখানেক।',
        'App\Modules\Approval\Http\Controllers\ApprovalExceptionController' => 'কেবল খোলা সমস্যা, ঠিক হলেই তালিকা থেকে যায়; প্রতিটা খোঁজ নিজেই সীমাবদ্ধ (LOOK_AT = ২০০)।',
        'App\Modules\Finance\Http\Controllers\AccountAnalysisController' => 'একটা খাতের এক সময়ের বিশ্লেষণ — যোগফলের পাতা, সারির তালিকা নয়।',
        'App\Modules\Finance\Http\Controllers\CarrierAndLabourController' => 'পক্ষ ধরে যোগফল — এক পক্ষ এক সারি; একজনের খতিয়ান নিজেই পাতা ভাগ করে।',
        'App\Modules\Finance\Http\Controllers\CfoController' => 'মালিকের ড্যাশবোর্ড — কার্ডের সংখ্যা কোডে স্থির।',
        'App\Modules\Finance\Http\Controllers\RiskController' => 'ঝুঁকির খোঁজ কোডে স্থির; ঋণ-সুবিধা প্রতি সুবিধা এক সারি।',
        'App\Modules\Governance\Http\Controllers\RetentionController' => 'কোন তথ্য কতদিন রাখা হয় — কোডে লেখা স্থির তালিকা।',
        'App\Modules\Sales\Http\Controllers\SaleTrackingController' => 'খোঁজার উত্তর (JSON) — নিজেই ১০০-তে কাটা; পর্দা নয়।',
        'App\Modules\Sales\Http\Controllers\SalesOverviewController' => 'বিক্রয়ের ড্যাশবোর্ড — এক সময়ের যোগফল আর কার্ড।',
        // ⓘ EveryListScreenPaginatesTest::NOT_REALLY_A_LIST-এর একই কারণ — দুই পাহারা এক কথা বলে
        'App\Modules\Sales\Http\Controllers\CustomerTargetController' => 'ফর্ম — একটাই POST, প্রতি ডিলারের এক সারি; পাতা বদলালে ভরা ঘরগুলো নীরবে হারাত। সারি বাড়ে ডিলারের সংখ্যায়, দিনে নয়।',
        'App\Modules\Sales\Http\Controllers\DeliveryOrderController' => 'তালিকাই নেই — index() চালানের তালিকায় পাঠায়, সেটা পাতা ভাগ করে।',
    ];

    /**
     * ⭐ পাতা ভাগ সেবায় — কন্ট্রোলারে নয় (১০ অক্টোবর ২০২৬)।
     *
     * ⓘ স্ক্যানার কেবল কন্ট্রোলারের ফাইলে `paginate(` খোঁজে; অনেক তালিকা সেবায় পাতা ভাগ করে আর কন্ট্রোলার শুধু ডাকে।
     * ⚠️ ওগুলোকে "পুরোটা ইচ্ছাকৃত"-তে লেখা মিথ্যা হত — তাই আলাদা তালিকা, আর নামা সেবা-পদ্ধতিতে সত্যিই `paginate(` আছে কি না
     * পরীক্ষা নিজেই পড়ে দেখে ([[test_the_service_that_pages_really_pages()]])।
     *
     * @var array<string, string> কন্ট্রোলার => 'সেবা::পদ্ধতি'
     */
    private const PAGED_ELSEWHERE = [
        'App\Modules\Finance\Http\Controllers\BankChargeController' => 'App\Modules\Finance\Services\BankCharges::rows',
        'App\Modules\Finance\Http\Controllers\BudgetController' => 'App\Modules\Finance\Services\BudgetService::plan',
        'App\Modules\Sales\Http\Controllers\PortalDeliveryOrderController' => 'App\Modules\Sales\Services\CustomerPapers::deliveryOrders',
        'App\Modules\Sales\Http\Controllers\PortalOrderController' => 'App\Modules\Sales\Services\CustomerPapers::salesOrders',
        'App\Modules\Sales\Http\Controllers\PriceBookController' => 'App\Modules\Sales\Services\PriceBookService::lists',
        'App\Modules\Sales\Http\Controllers\PriceListController' => 'App\Modules\Sales\Services\SalePriceBook::list',
    ];

    /**
     * ⭐ পাতা ভাগ করো, নয়তো কারণ লেখো — তৃতীয় পথ নেই।
     */
    public function test_every_list_either_pages_or_says_why_it_does_not(): void
    {
        $undeclared = [];

        foreach ($this->controllersWithAnIndex() as $class => $paginates) {
            if ($paginates || isset(self::WHOLE_ON_PURPOSE[$class]) || isset(self::PAGED_ELSEWHERE[$class])) {
                continue;
            }

            $undeclared[] = $class;
        }

        $this->assertSame([], $undeclared, sprintf(
            "এই তালিকাগুলো পুরোটা দেখায়, আর কোথাও বলা নেই কেন:\n  %s\n\n".
            "⭐ দুইটার একটা করুন —\n".
            "  ১. `->paginate(50)->withQueryString()` বসান, আর পর্দায় `<x-ui.pager :rows=\"…\" />`\n".
            "  ২. অথবা %s::WHOLE_ON_PURPOSE-এ নাম ও **কারণ** লিখুন\n\n".
            '⚠️ "আজ সারি কম" কোনো কারণ নয়। কারণ হলো এমন কিছু যা তালিকাটাকে বড় হতে দেয় না।',
            implode("\n  ", $undeclared),
            class_basename(self::class),
        ));
    }

    /**
     * ⛔ ছাড়ের তালিকাটা যেন পচে না যায়।
     *
     * ── কেন এই দাবিটা আলাদা করে লাগে ──────────────────────────────────
     * ⚠️ একটা ছাড়ের তালিকা নিজের যত্ন নেয় না। ⓘ কন্ট্রোলারটা মুছে গেলে বা
     * পরে পাতা ভাগ বসে গেলে নামটা এখানে পড়ে থাকত, আর পরের পাঠক ভাবতেন
     * ঐ পর্দাটা এখনো পুরোটা দেখায়।
     *
     * ⛔ আর ঐভাবেই একটা পাহারা ধীরে ধীরে একটা রূপকথা হয়ে যায়।
     */
    /**
     * ⭐ যে সেবার নাম পাতা-ভাগ বলে লেখা, সে সত্যিই `paginate(` ডাকে — নামটা পুরনো হলে বা সেবা `get()`-এ ফিরলে লাল।
     */
    public function test_the_service_that_pages_really_pages(): void
    {
        $wrong = [];

        foreach (self::PAGED_ELSEWHERE as $controller => $where) {
            [$class, $method] = explode('::', $where);

            if (! method_exists($class, $method)) {
                $wrong[] = "{$controller} → {$where} — পদ্ধতিটা নেই।";

                continue;
            }

            $m = new \ReflectionMethod($class, $method);
            $lines = array_slice(file((string) $m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1);

            if (! str_contains(implode('', $lines), 'paginate(')) {
                $wrong[] = "{$controller} → {$where} — এখানে পাতা ভাগ নেই, পুরোটা দেখায়।";
            }
        }

        $this->assertSame([], $wrong, "পাতা-ভাগের সেবা যা বলে তা করে না:\n  ".implode("\n  ", $wrong));
    }

    public function test_the_excuse_list_does_not_rot(): void
    {
        $found = $this->controllersWithAnIndex();
        $stale = [];

        foreach (self::WHOLE_ON_PURPOSE as $class => $why) {
            if (! isset($found[$class])) {
                $stale[] = $class.' — এই কন্ট্রোলারটা আর নেই, বা তার `index()` নেই।';

                continue;
            }

            if ($found[$class]) {
                $stale[] = $class.' — এখন পাতা ভাগ করে; ছাড়ের দরকার ফুরিয়েছে।';
            }

            if (trim($why) === '') {
                $stale[] = $class.' — কারণটা খালি।';
            }
        }

        $this->assertSame([], $stale, "ছাড়ের তালিকাটা বাস্তবের সাথে মেলে না:\n  ".implode("\n  ", $stale));
    }

    /**
     * ⓘ সেটআপের দাবি — এই ফাইলটা সত্যিই কিছু দেখছে তো?
     *
     * ⚠️ পথটা একদিন বদলালে (`app/Modules` সরে গেলে) স্ক্যানার শূন্য
     * ফেরাত, আর উপরের দুইটা দাবি **চিরকাল সবুজ** থাকত — কিছুই না দেখে।
     * ⛔ আজকের দিনের শিক্ষা: নীরব সবুজই সবচেয়ে বিপজ্জনক।
     */
    public function test_the_scanner_actually_finds_the_screens(): void
    {
        $found = $this->controllersWithAnIndex();

        $this->assertGreaterThan(60, count($found),
            'তালিকার পর্দা এত কম হতে পারে না — স্ক্যানারটাই কিছু খুঁজে পাচ্ছে না।');

        $this->assertContains(true, array_values($found),
            'একটাও পাতা-ভাগ করা পর্দা পাওয়া গেল না — স্ক্যানার `paginate(` চিনছে না।');
    }

    /**
     * প্রতিটা `index()`-ওয়ালা কন্ট্রোলার, আর সে পাতা ভাগ করে কি না।
     *
     * @return array<class-string, bool>
     */
    private function controllersWithAnIndex(): array
    {
        $root = base_path('app/Modules');
        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, 'Controller.php') || ! str_contains($path, 'Http/Controllers')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'public function index(')) {
                continue;
            }

            if (! preg_match('/namespace\s+([^;]+);/', $source, $m)) {
                continue;
            }

            $found[trim($m[1]).'\\'.basename($path, '.php')] = str_contains($source, 'paginate(');
        }

        ksort($found);

        return $found;
    }
}
