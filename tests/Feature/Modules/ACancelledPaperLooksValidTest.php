<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Http\Controllers\VoucherPrintController;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * বাতিল করা কাগজ দেখতে বৈধ কাগজের মতোই।
 *
 * ── কেন এই পাহারাটা আলাদা করে লাগে ──────────────────────────────────
 * HP-র পরীক্ষক ১৪ আগস্ট ভাউচারেরটা ধরেছিলেন: বাতিল করা ভাউচার ছাপলে
 * কাগজে কোথাও "বাতিল" লেখা উঠত না। খুঁজতে গিয়ে দেখা গেল বিক্রয় ও
 * ক্রয়ের কাগজগুলোও একই অবস্থায়। ভুলটা কোনো ভুল দেখায় না — বাতিল করা
 * চালান ছাপলে **হুবহু বৈধ একটা কাগজ** বেরোয়, আর সেটা দেখিয়ে গেট থেকে
 * মাল বের করে নেওয়া যায়।
 *
 * প্রতিটা কন্ট্রোলার এখন চিহ্নটা বসায়। কিন্তু কন্ট্রোলার ধরে ধরে
 * পরীক্ষা লিখলে **সপ্তম কাগজটা লেখার দিনে কেউ ভুলত** — ঠিক যেভাবে
 * প্রথম ছয়টায় ভোলা হয়েছিল। তাই শেষ সারিটা রুটের তালিকা ধরে হাঁটে:
 * নতুন কাগজ যোগ করলে হয় এখানে পরীক্ষা লিখতে হবে, নয় কারণ লিখে
 * ব্যতিক্রমে রাখতে হবে।
 */
class ACancelledPaperLooksValidTest extends TestCase
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
     * কাগজটা ছেপে তার HTML ফেরত — টেমপ্লেটে যা যায়, ঠিক তাই।
     *
     * PDF-এর ভেতরে লেখা খোঁজা যায় না (সংকুচিত), তাই টেমপ্লেটটা যে তথ্য
     * পেল সেটা ধরে আবার আঁকা হয়। টেমপ্লেট `notice` উপেক্ষা করলে এটাও
     * ধরা পড়ে — কেবল "পাঠানো হয়েছে" দেখলে পড়ত না।
     */
    private function paperHtml(string $template, string $url): string
    {
        $seen = [];

        View::composer($template, function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get($url)->assertOk();

        $this->assertNotSame([], $seen, "ছাপার টেমপ্লেট '{$template}' ডাকাই হয়নি।");

        return view($template, $seen)->render();
    }

    private function assertSaysCancelled(string $url, string $what): void
    {
        $html = $this->paperHtml('print.document', $url);

        $this->assertStringContainsString(__('core.print.cancelled_notice'), $html,
            $what.' বাতিল করা সত্ত্বেও কাগজে বাতিলের কথা নেই — চালু কাগজের মতোই দেখাবে, '
            .'আর সেটা দেখিয়ে মাল বা টাকা নেওয়া যাবে।');
    }

    // ── বিক্রয় ──────────────────────────────────────────────────────

    public function test_a_cancelled_invoice_says_so(): void
    {
        $invoice = $this->invoice();

        app(SalesInvoiceService::class)->cancel($invoice, 'ভুল দামে কাটা হয়েছিল');

        $this->assertSaysCancelled(route('sales.print.invoice', $invoice), 'বিক্রয় বিল');
    }

    public function test_a_cancelled_challan_says_so(): void
    {
        $challan = $this->challan();

        app(DeliveryChallanService::class)->cancel($challan, 'গাড়ি যায়নি');

        $this->assertSaysCancelled(route('sales.print.challan', $challan), 'ডেলিভারি চালান');
    }

    /**
     * গেটপাস — সবচেয়ে জরুরি কাগজ।
     *
     * এটাই সেই কাগজ যেটা দেখিয়ে গেট থেকে মাল বেরোয়। বাতিল করা চালানের
     * গেটপাসে চিহ্ন না থাকলে প্রহরীর কাছে ওটা বৈধ কাগজ।
     */
    public function test_a_cancelled_gatepass_says_so(): void
    {
        $challan = $this->challan();

        app(DeliveryChallanService::class)->cancel($challan, 'ক্রেতা নেননি');

        $this->assertSaysCancelled(route('sales.print.gatepass', $challan), 'গেটপাস');
    }

    public function test_a_cancelled_order_says_so(): void
    {
        $order = $this->order();

        app(SalesOrderService::class)->cancel($order, 'ক্রেতা বাতিল করেছেন');

        $this->assertSaysCancelled(route('sales.print.order', $order), 'বিক্রয় আদেশ');
    }

    // ── হিসাব ────────────────────────────────────────────────────────

    public function test_a_cancelled_voucher_says_so(): void
    {
        $voucher = $this->voucher();

        app(VoucherService::class)->post($voucher);
        app(VoucherService::class)->cancel($voucher->fresh(), 'ভুল খাতে বসেছিল');

        $html = $this->paperHtml('print.voucher', route('accounts.voucher.print', $voucher));

        $this->assertStringContainsString(__('accounts::print.cancelled'), $html,
            'বাতিল ভাউচারের কাগজে বাতিলের কথা নেই।');
    }

    // ── যে নিয়মটা সপ্তম কাগজকেও ধরবে ────────────────────────────────

    /**
     * প্রতিটা ছাপার রুট হয় এখানে পরীক্ষিত, নয় নাম-সহ ব্যতিক্রম।
     *
     * উপরের পরীক্ষাগুলো আজকের কাগজগুলো ধরে। কাল কেউ সপ্তম একটা কাগজ
     * লিখলে ওগুলো তা নিয়ে কিছুই বলবে না — আর ঠিক ওভাবেই প্রথম ছয়টা
     * কাগজে চিহ্নটা বসতে ভুলে গিয়েছিল।
     */
    /**
     * বাতিল ভাউচার জলছাপও চায়, বৈধটা চায় না।
     *
     * ---- কেন উপরের বাক্সটা যথেষ্ট নয়, ৩০ আগস্ট ২০২৬ ----
     * এই ফাইলের বাকি পরীক্ষাগুলো দেখে কাগজে "বাতিল" **লেখা** ওঠে কি
     * না। ওটা পড়ার জন্য, আর সেটাই তার কাজ।
     *
     * কিন্তু লেখাটা কাগজের একটা কোণে একটা বাক্সে থাকে: ভাঁজ করলে ঢাকা
     * পড়ে, ফটোকপিতে কেটে ফেলা যায়, আর উপরের অংশটুকু বাদ দিয়ে স্ক্যান
     * করলে বাকিটা হুবহু বৈধ একটা কাগজ। অর্থাৎ ১৪ আগস্টের সমাধানটা
     * এক ভাঁজ দূরে ছিল।
     *
     * জলছাপ কোনাকুনি সংখ্যাগুলোর উপর দিয়ে যায় -- কেটে বাদ দিতে গেলে
     * সংখ্যাগুলোও যায়।
     *
     * ---- কেন সিদ্ধান্তটা দেখা হয়, কাগজটা নয় ----
     * প্রথমে বাতিলের আগে-পরে দুইটা PDF-এর **মাপ** মেলানো হয়েছিল। ওটা
     * সবুজ কিন্তু অন্ধ: বাতিল করলে উপরের বাক্সটাও যোগ হয়, তাই কাগজ
     * এমনিতেই বড় হয় -- জলছাপের লাইনটা কোড থেকে সরিয়ে দিলেও পরীক্ষা
     * পাস করত। ইচ্ছা করে ভেঙে দেখতে গিয়েই ধরা পড়ল।
     */
    public function test_a_cancelled_voucher_asks_for_a_watermark_too(): void
    {
        $voucher = $this->voucher();
        app(VoucherService::class)->post($voucher);

        $this->assertNull($this->watermarkFor($voucher->fresh()),
            'বৈধ রসিদের গায়েও জলছাপ চাওয়া হচ্ছে।');

        app(VoucherService::class)->cancel($voucher->fresh(), 'ভুল খাতে বসেছিল');

        $this->assertSame(__('core.print.cancelled_watermark'),
            $this->watermarkFor($voucher->fresh()),
            'বাতিল রসিদে জলছাপ চাওয়া হয়নি।');
    }

    /** কন্ট্রোলার এই ভাউচারের জন্য জলছাপ চায় কি না। */
    private function watermarkFor($voucher): ?string
    {
        $controller = app(VoucherPrintController::class);

        $method = new \ReflectionMethod($controller, 'watermarkFor');
        $method->setAccessible(true);

        return $method->invoke($controller, $voucher);
    }

    public function test_every_printable_paper_is_accounted_for(): void
    {
        /** যেগুলো এই ফাইলে পরীক্ষিত */
        $tested = [
            'sales.print.invoice', 'sales.print.challan', 'sales.print.gatepass',
            'sales.print.order', 'accounts.voucher.print',
        ];

        /** যেগুলো এখানে নয়, আর কেন */
        $elsewhere = [
            'sales.print.draft' => 'খসড়া বিলের কাগজ — খসড়া আর বাতিল একসাথে হয় না',
            'sales.print.receipt' => 'আদায়ের রসিদ — একই কন্ট্রোলারের একই সেলাই (SalesPrintController::paper)',
            'sales.print.delivery_order' => 'ডেলিভারি অর্ডার — একই সেলাই, অর্ডারের সারিতে ধরা',
            'purchase.print.order' => 'ক্রয়ের চারটা কাগজ — PurchasePrintController-এ একই সেলাই',
            'purchase.print.bill' => 'ক্রয়ের চারটা কাগজ — একই সেলাই',
            'purchase.print.receipt' => 'ক্রয়ের চারটা কাগজ — একই সেলাই',
            'purchase.print.return' => 'ক্রয়ের চারটা কাগজ — একই সেলাই',
            'inventory.transfer.print' => 'স্থানান্তর — StockPrintController নিজে চিহ্ন বসায়',
            'accounts.transfer.print' => 'টাকা হস্তান্তরের স্লিপ — HandoverSlipTest ধরে',
            'hr.payslip.print' => 'পে-স্লিপ — বাতিল হয় না, খসড়া হয়',
            'inventory.label.print' => 'পণ্যের লেবেল — কোনো ডকুমেন্ট নয়',
            'sales.print_queue.index' => 'কিউয়ের পর্দা, কাগজ নয়',
            'sales.print_queue.settle' => 'কিউয়ের সারি মেটানোর কাজ, কাগজ নয়',
        ];

        $unaccounted = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->filter(fn (string $name) => str_contains($name, 'print'))
            ->reject(fn (string $name) => in_array($name, $tested, true))
            ->reject(fn (string $name) => array_key_exists($name, $elsewhere))
            ->unique()
            ->values()
            ->all();

        $this->assertSame([], $unaccounted,
            "এই ছাপার কাগজগুলোর বাতিল-চিহ্ন নিয়ে কিছুই বলা নেই:\n"
            .implode("\n", $unaccounted)
            ."\n\nহয় এখানে একটা পরীক্ষা লিখুন, নয় কারণসহ ব্যতিক্রমের তালিকায় রাখুন — "
            .'নাহলে বাতিল করা কাগজ বৈধ কাগজের মতোই ছাপা হবে।');
    }

    // ── কাগজ বানানোর ছোট সহায়ক ──────────────────────────────────────

    private function invoice()
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create($this->paperFor(), [[
            'product_id' => Product::query()->value('id'),
            'qty' => '1',
            'rate' => '100',
        ]]));
    }

    private function challan()
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create($this->paperFor(), [[
            'product_id' => Product::query()->value('id'),
            'delivered_qty' => '1',
            'rate' => '100',
        ]]));
    }

    private function order()
    {
        $service = app(SalesOrderService::class);

        return $service->confirm($service->create($this->paperFor(), [[
            'product_id' => Product::query()->value('id'),
            'ordered_qty' => '1',
            'rate' => '100',
        ]]));
    }

    private function voucher(): Voucher
    {
        return app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'ছাপার পরীক্ষা',
        ], [
            ['account_id' => $this->leaf(StandardChart::CASH_IN_TRANSIT), 'debit' => '500', 'credit' => '0'],
            ['account_id' => $this->leaf(StandardChart::RECEIVABLE), 'debit' => '0', 'credit' => '500'],
        ]);
    }

    /**
     * পাতার খাত — গ্রুপ খাতে সরাসরি লেনদেন বসে না।
     *
     * ── কেন নগদের খাত (১১০১) এখানে ব্যবহার হয় না ────────────────────
     * ওটা একটা গ্রুপ: প্রতিটা ক্যাশ টিল খোলার সময় তার নিজের পাতা-খাত
     * (`1101-<কোড>`) তৈরি হয়। ডেমো ডাটাবেজে কোনো টিল নেই, তাই ওখানে
     * ১১০১-এর নিচে কোনো পাতা নেই — আর সেবা ঠিকই ফিরিয়ে দেয়। এই
     * কাগজের পরীক্ষার জন্য পথের টাকা (১১০৩) ও প্রাপ্য (১১১০) যথেষ্ট;
     * এখানে প্রশ্নটা হিসাবের আকার নয়, কাগজে বাতিলের চিহ্ন।
     */
    private function leaf(string $code): int
    {
        $id = Account::query()->where('is_group', false)
            ->where('code', 'like', $code.'%')
            ->value('id');

        $this->assertNotNull($id, "খাত {$code}-এর কোনো পাতার সারি নেই।");

        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function paperFor(): array
    {
        return [
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ];
    }
}
