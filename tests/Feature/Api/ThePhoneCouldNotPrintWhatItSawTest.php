<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Report\ReportColumn;
use App\Core\Licence\LicenceReader;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Security\FieldSecurity;
use App\Core\Services\ListExport;
use App\Core\Services\PaperTrail;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Models\Company;
use App\Models\ExportLog;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Services\MasterListService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

/**
 * ফোন নথিটা দেখত, কিন্তু ছাপতে পারত না — চুক্তি §১০।
 *
 * ⭐ প্রতিটা দরজার দাবি একই মানুষকে দুইবার: চাবি ছাড়া বন্ধ, **সেই মানুষটাকেই**
 * চাবি দিলে খোলা ([[same-user-key-off-then-on]])। ⓘ ভূমিকাহীন মানুষ — ডেমোর
 * ভূমিকা বদলায়, আর ভূমিকার উপর দাঁড়ানো দাবি কাল ভুল কারণে লাল হত।
 *
 * ⓘ PDF-এর ভেতরের লেখা সংকুচিত, তাই কাগজে কী গেল সেটা মাপা হয় ছাপার
 * ভিউ যা পেল তা ধরে ([[SalesPrintTest]]-এর একই পথ) — আর বাইটগুলো সত্যিই
 * PDF কি না, সেটা আলাদা করে (`%PDF`)।
 */
final class ThePhoneCouldNotPrintWhatItSawTest extends TestCase
{
    use RefreshDatabase;

    /** রিপোর্ট চালানোর চাবি */
    private const REPORT_KEY = 'customer.report';

    /** ঢাকা কলামের চাবি */
    private const COST_KEY = 'inventory.cost.view';

    private const LIMITS = 'ec2doc.opening_limits';

    private const COUNTING = 'ec2doc.one_hundred_fifty';

    /** ঢাকা কলামের প্রতিটা ঘরে এই চিহ্ন — ফাইলের কোথাও থাকলেই ধরা পড়ে */
    private const SECRET = 'S3CR3T';

    private const MARCH = ['from' => '2031-03-01', 'to' => '2031-03-31'];

    private const IN_MARCH = ['EC2D-01', 'EC2D-02', 'EC2D-03'];

    private Company $company;

    private Company $other;

    private User $user;

    /** @var list<PrintableDocument|null> ছাপার ভিউ যা যা পেল, ক্রমে */
    private array $drawn = [];

    /** @var list<array<string, mixed>|null> নতুন ক্রয়-বিলের কাগজ যা পেয়েছে */
    private array $billFacts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        /*
         * ⓘ এই পরীক্ষা চলতি নকশার কাগজ (`print.document`) মাপে। ⚠️ ২৯ সেপ্টেম্বর ২০২৬ থেকে
         * বিলের ডিফল্ট ক্লাসিক ([[AClassicTableInvoiceCanBeChosenTest]]), তাই নকশাটা এখানে
         * বেঁধে দেওয়া — নইলে পরীক্ষাটা মাপার কাগজই পেত না।
         */
        app(\App\Core\Services\SettingsService::class)->set('sales.print.design.invoice', 'standard');

        $this->user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->user->companies()->attach($this->company->id, ['is_active' => true]);

        View::composer('print.document', function ($view): void {
            $this->drawn[] = $view->getData()['doc'] ?? null;
        });

        /*
         * ⚠️ ১ অক্টোবর ২০২৬ থেকে ক্রয়-বিল নিজের নতুন কাগজে আঁকা ([[purchase::print.bill-modern]], 66f81eab),
         * `print.document`-এ নয় — তখন থেকে উপরের কান কিছুই শুনত না, আর ক্রয়মূল্যের দাবিটা অন্ধ ছিল (abos-2c ধরল, ২ অক্টোবর)।
         * তাই নতুন কাগজেরও কান: কাগজের `doc` আর তার নিজের তথ্য (`bill`) দুটোই।
         */
        View::composer('purchase::print.bill-modern', function ($view): void {
            $this->drawn[] = $view->getData()['doc'] ?? null;
            $this->billFacts[] = $view->getData()['bill'] ?? null;
        });

        $engine = app(ReportEngine::class);

        $engine->register(new ReportDefinition(
            key: self::LIMITS,
            title: 'Opening limits',
            query: fn (array $f) => DB::table('customers')
                ->where('company_id', $f['company_id'])
                ->whereBetween('opening_date', [$f['from'], $f['to']])
                ->where('code', 'like', 'EC2D-%')
                ->orderBy('code')
                ->select([
                    'code',
                    'credit_limit as limit_amount',
                    DB::raw("CONCAT('".self::SECRET."-', code) as secret_cost"),
                ]),
            columns: [
                ['key' => 'code', 'label' => 'Code', 'type' => ReportColumn::DOCUMENT],
                ['key' => 'limit_amount', 'label' => 'Limit', 'type' => ReportColumn::MONEY],
                ['key' => 'secret_cost', 'label' => 'Hidden cost', 'type' => ReportColumn::TEXT, 'permission' => self::COST_KEY],
            ],
            filters: ['date_range'],
            permission: self::REPORT_KEY,
        ));

        /* ⓘ ১৫০টা সারি — §৯-এর এক পাতা (১০০) ছাড়িয়ে */
        $engine->register(new ReportDefinition(
            key: self::COUNTING,
            title: 'Counting',
            query: function (array $f) {
                $numbers = DB::query()->selectRaw('1 as n');

                for ($i = 2; $i <= 150; $i++) {
                    $numbers->unionAll(DB::query()->selectRaw("{$i} as n"));
                }

                return DB::query()->fromSub($numbers, 'numbers')->orderBy('n')->select('n');
            },
            columns: [['key' => 'n', 'label' => 'N', 'type' => ReportColumn::TEXT]],
            filters: [],
            permission: self::REPORT_KEY,
        ));

        foreach (self::IN_MARCH as $i => $code) {
            $this->customer($code, $this->company->id, '2031-03-1'.$i, (string) (1000 * ($i + 1)));
        }

        $this->customer('EC2D-FMART', $this->other->id, '2031-03-15', '999.00');
    }

    // ── নথি · দরজা (নিয়ম খ) ──────────────────────────────────────────────

    /**
     * ⭐ একই মানুষ: বিল দেখার চাবি ছাড়া ৪০৩ (৪০৪ নয়), আর একটা কাগজও আঁকা হয় না;
     * সেই মানুষকেই চাবি দিলে সত্যিকারের PDF, নিজের নামে।
     */
    public function test_without_the_invoice_key_it_is_403_and_the_same_user_with_it_gets_a_real_pdf(): void
    {
        $invoice = $this->invoice();

        $refused = $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id))->assertForbidden();
        $this->assertStringStartsNotWith('%PDF', (string) $refused->getContent());
        $this->assertSame([], $this->drawn, '⛔ চাবি ছাড়াই কাগজটা আঁকা হয়েছে।');

        $this->grant('sales.invoice.view');

        $response = $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id, ['paper' => PaperSize::A4]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', (string) $response->getContent(), '⛔ PDF বলে পাঠানো বাইটগুলো PDF নয়।');
        $this->assertCount(1, $this->drawn, 'ছাপার ভিউ চলেনি — দাবিটা কিছু মাপছে না।');

        /* ⓵ ফাইলের নাম নথির নম্বর — শেয়ার-শিটে সাতটা "document.pdf" নয় */
        $this->assertNotEmpty($invoice->document_no);
        $this->assertStringContainsString('filename="'.$invoice->document_no.'.pdf"',
            (string) $response->headers->get('Content-Disposition'));
    }

    /** ⭐ `papers`-ও একই পাহারায় — চাবি ছাড়া ৪০৩, একই মানুষ চাবি পেলে তালিকা। */
    public function test_papers_is_behind_the_same_key_as_the_pdf(): void
    {
        $invoice = $this->invoice();

        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertForbidden();

        $this->grant('sales.invoice.view');

        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))
            ->assertOk()
            ->assertExactJson(PaperSize::all());
    }

    /**
     * ⭐ `papers` কেবল সেই মাপগুলো দেয় যা ওয়েব দেয় — আর প্রতিটা সত্যিই একটা
     * PDF হয়ে বেরোয়; তালিকার একটা বোতামও অর্থহীন কাগজ দেয় না।
     */
    public function test_every_paper_it_lists_really_prints_and_nothing_else_is_listed(): void
    {
        $this->grant('purchase.receipt.view');
        $receipt = $this->goodsReceipt();

        $papers = $this->phone($this->papersUrl('PurchaseReceipt', $receipt->public_id))->assertOk()->json();

        /*
         * ⓘ ওয়েবের ছাপার বোতাম (x-ui.print-menu) প্রতিটা কাগজে ঠিক এই তালিকা দেখায়।
         * ⭐ A5 যোগ হয়েছে ২৮ সেপ্টেম্বর ২০২৬ (গেট পাসের আধা পাতা, 05c1f4a9) — ওয়েবে চেনা মাপ,
         * তাই ফোনেও।
         */
        $this->assertSame([PaperSize::A4, PaperSize::A5, PaperSize::THERMAL_80, PaperSize::THERMAL_58], $papers);

        foreach ($papers as $paper) {
            $bytes = $this->phone($this->pdfUrl('PurchaseReceipt', $receipt->public_id, ['paper' => $paper]))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->getContent();

            $this->assertStringStartsWith('%PDF', (string) $bytes, "⛔ {$paper} তালিকায় আছে, অথচ PDF বেরোয় না।");
        }
    }

    /** ⛔ চুরি যাওয়া refresh টোকেনে তিনটা দরজার একটাও খোলে না — চাবি থাকলেও। */
    public function test_a_refresh_token_opens_none_of_the_three_doors(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');
        $this->grant(self::REPORT_KEY);

        /* ⓘ access টোকেনে দরজা খোলে — নাহলে নিচের ৪০৩ অন্য কারণে হত */
        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertOk();

        $this->app['auth']->forgetGuards();
        $token = $this->user->createToken('refresh', [AuthController::REFRESH])->plainTextToken;

        $this->withToken($token)->getJson($this->pdfUrl('SalesInvoice', $invoice->public_id))->assertForbidden();
        $this->withToken($token)->getJson($this->papersUrl('SalesInvoice', $invoice->public_id))->assertForbidden();
        $this->withToken($token)->getJson($this->exportUrl(self::COUNTING, ['format' => 'csv']))->assertForbidden();
        $this->assertSame([], $this->drawn);
    }

    // ── নথি · ঠিকানা (§৩ ক, কোম্পানির দেয়াল, অচেনা ধরন) ───────────────────

    /** ⛔ `{id}` কেবল `public_id` — ক্রমিক আইডিতে কিছুই খোলে না (§৩ ক)। */
    public function test_a_numeric_id_opens_nothing(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');

        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertOk();

        $this->phone($this->pdfUrl('SalesInvoice', (string) $invoice->id))->assertNotFound();
        $this->phone($this->papersUrl('SalesInvoice', (string) $invoice->id))->assertNotFound();
        $this->assertSame([], $this->drawn, '⛔ ক্রমিক আইডিতে কাগজ আঁকা হয়েছে।');
    }

    /**
     * ⛔ অন্য কোম্পানির নথি ৪০৪ — ৪০৩ নয় ("আছে" বলাও খবর), আর তার বাইট কখনো নয়।
     */
    public function test_another_companys_document_is_404_and_never_its_bytes(): void
    {
        $invoice = $this->invoice();
        DB::table('sal_invoices')->where('id', $invoice->id)->update(['company_id' => $this->other->id]);

        $this->assertTrue(
            SalesInvoice::query()->withoutGlobalScopes()->where('public_id', $invoice->public_id)
                ->where('company_id', $this->other->id)->exists(),
            'নথিটা FMART-এ নেই — দাবিটা কিছু মাপছে না।',
        );

        $this->grant('sales.invoice.view');

        foreach ([$this->pdfUrl('SalesInvoice', $invoice->public_id), $this->papersUrl('SalesInvoice', $invoice->public_id)] as $url) {
            $response = $this->phone($url)->assertNotFound();
            $this->assertStringStartsNotWith('%PDF', (string) $response->getContent());
        }

        $this->assertSame([], $this->drawn, '⛔ অন্য কোম্পানির কাগজ আঁকা হয়েছে।');
    }

    /**
     * ⛔ অচেনা ধরন ৪০৪, ৫০০ নয় — আর বেতনশিট (ডেস্কের কাগজ, §৪) চাবি থাকলেও অচেনা।
     */
    public function test_an_unknown_type_is_404_not_500_and_payslips_stay_on_the_desk(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');
        $this->grant('hr.payroll.view');

        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertOk();

        $this->assertArrayHasKey('hr_payslip', PaperTrail::DOCUMENT_ROUTES, 'বেতনশিট ছাপার তালিকাতেই নেই — দাবিটা কিছু মাপছে না।');

        foreach (['Nonsense', 'salesinvoice', 'Payslip', 'PayrollRun', 'Payment'] as $type) {
            $this->phone($this->pdfUrl($type, $invoice->public_id))->assertNotFound();
            $this->phone($this->papersUrl($type, $invoice->public_id))->assertNotFound();
        }
    }

    /**
     * ⭐ ধরনের শব্দভাণ্ডার §৫-এর `documentType` — মডেলের ইংরেজি নাম, ছাপার
     * তালিকার প্রতিটা কাগজের জন্য, বেতনশিট বাদে।
     */
    public function test_the_type_vocabulary_is_the_model_names_the_approval_inbox_uses(): void
    {
        $controller = app(DocumentApiController::class);
        $printable = (new ReflectionMethod($controller, 'printable'))->invoke($controller);

        // ⓘ বাতিলের অনুরোধ আর নোট পরে কাগজের তালিকায় এসেছে ([[PaperTrail::DOCUMENT_ROUTES]]) — ফোনও সেগুলো ছাপে
        // ⓘ গেট পাস (নিজের মডেল) ছাপা-গোনায় এসেছে (পুনঃঅডিট ৯ অক্টোবর ২০২৬) — ফোনও তার নিজের অনুমতিতে ছাপে
        $this->assertSame([
            'SalesInvoice', 'SalesInvoiceCancellation', 'DeliveryChallan', 'SalesOrder', 'GatePass', 'Collection',
            'PurchaseBill', 'PurchaseOrder', 'PurchaseReceipt', 'PurchaseReturn',
            'Voucher', 'MoneyTransfer', 'Note', 'StockTransfer',
        ], array_keys($printable));

        foreach ($printable as $type => [, $class]) {
            $this->assertSame($type, class_basename($class), '⛔ §৫ class_basename পাঠায়, আর এখানে অন্য নাম।');
        }

        /*
         * ⛔ একই মডেলের দ্বিতীয় কাগজ মূলটাকে সরায় না (১১ অক্টোবর ২০২৬)। ⓘ চালানের গেট পাস আর DO বাঁধে চালান আর অর্ডারের
         * মডেলই — পরেরটা জিতলে ফোন চালান চাইলে দাম-ছাড়া গেট পাস পেত, অর্ডার চাইলে DO।
         */
        $this->assertSame('sales_challan', $printable['DeliveryChallan'][0], '⛔ ফোনে চালান চাইলে গেট পাস আসছে।');
        $this->assertSame('sales_order', $printable['SalesOrder'][0], '⛔ ফোনে অর্ডার চাইলে DO আসছে।');

        // ⓘ বেতনশিট (ডেস্কে) আর ঐ দুই দ্বিতীয় কাগজ বাদে বাকি সবই
        $this->assertCount(count(PaperTrail::DOCUMENT_ROUTES) - 3, $printable, '⛔ বেতনশিট আর দুই দ্বিতীয় কাগজ ছাড়া অন্য কোনো কাগজও বাদ পড়েছে।');
    }

    // ── নথি · নিয়ম ক · ক্রয়মূল্য PDF-এর ভিতরেও ────────────────────────────

    /**
     * ⛔⛔ ক্রয় বিলের চাবি আছে, ক্রয়মূল্যের নেই — কাগজ আসে, কিন্তু দাম-ছাড়া;
     * সেই মানুষকেই ক্রয়মূল্যের চাবি দিলে কাগজে দরটা আছে।
     *
     * ⓘ ২৭ সেপ্টেম্বর ২০২৬ পর্যন্ত ফোন পুরো কাগজটাই ফেরাত, কারণ ওয়েবের কাগজ
     * দাম ঢাকতে পারত না। এখন পারে (`41abd596`), তাই ফোন ওয়েবের সমান।
     */
    public function test_a_purchase_bill_without_the_cost_key_carries_no_price_and_with_it_carries_the_rate(): void
    {
        $this->assertSame(self::COST_KEY, FieldSecurity::permissionFor(Product::class, 'purchase_price'),
            'ক্রয়মূল্য কারও চাবির পেছনে নেই — দাবিটা কিছু মাপছে না।');

        $bill = $this->purchaseBill();
        $this->grant('purchase.bill.view');

        $this->billFacts = [];
        $this->assertPricelessPaper('PurchaseBill', $bill->public_id);

        /* ⛔ নতুন কাগজ সত্যিই আঁকা হয়েছে, আর তার নিজের তথ্যেও দাম নেই — দর, টাকা, মোট, কথায় টাকা */
        $facts = $this->billFacts[0] ?? null;
        $this->assertIsArray($facts, '⛔ নতুন ক্রয়-বিলের কাগজ আঁকাই হয়নি — দাবিটা কিছু মাপছে না।');
        $this->assertSame('', $facts['lines'][0]['rate'] ?? 'missing', '⛔ চাবি ছাড়াই নতুন কাগজের সারিতে দর।');
        $this->assertSame('', $facts['lines'][0]['amount'] ?? 'missing', '⛔ চাবি ছাড়াই নতুন কাগজের সারিতে টাকা।');
        $this->assertSame([], $facts['sums'], '⛔ চাবি ছাড়াই নতুন কাগজে মোটের ঘর।');
        $this->assertSame('', $facts['words'], '⛔ চাবি ছাড়াই নতুন কাগজে কথায় টাকা।');

        $this->grant(self::COST_KEY);
        $this->drawn = [];
        $this->billFacts = [];

        $bytes = $this->phone($this->pdfUrl('PurchaseBill', $bill->public_id))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF', (string) $bytes);

        $doc = $this->drawn[0] ?? null;
        $this->assertInstanceOf(PrintableDocument::class, $doc);
        $this->assertTrue($doc->showMoney);
        $this->assertStringContainsString('100', (string) ($doc->lines[0]['rate'] ?? ''), '⛔ চাবিওয়ালার কাগজে দরটাই নেই।');
        $this->assertStringContainsString('100', (string) ($this->billFacts[0]['lines'][0]['rate'] ?? ''), '⛔ চাবিওয়ালার নতুন কাগজে দরটাই নেই।');
        $this->assertNotSame([], $this->billFacts[0]['sums'] ?? [], '⛔ চাবিওয়ালার নতুন কাগজে মোট নেই।');
    }

    /**
     * ⓘ ছাঁকনিটা ক্রয়মূল্যের, ক্রয়ের নয়: দাম-ছাড়া মাল বুঝে নেওয়ার কাগজ
     * ক্রয়মূল্যের চাবি ছাড়াই খোলে — আর তাতে দামের ঘর নেই।
     */
    public function test_the_priceless_goods_receipt_opens_without_the_cost_key(): void
    {
        $receipt = $this->goodsReceipt();
        $this->grant('purchase.receipt.view');

        $this->phone($this->pdfUrl('PurchaseReceipt', $receipt->public_id))->assertOk();

        $doc = $this->drawn[0] ?? null;
        $this->assertInstanceOf(PrintableDocument::class, $doc);
        $this->assertFalse($doc->showMoney, '⛔ মাল বুঝে নেওয়ার কাগজে দাম।');
        $this->assertArrayNotHasKey('rate', $doc->lines[0] ?? []);
    }

    // ── নথি · নিয়ম গ · অচেনা কাগজ ────────────────────────────────────────

    /**
     * ⛔ অচেনা কাগজ ৪২২ — ওয়েবের মতো চুপচাপ A4 নয়; আর চাবিহীন মানুষ ৪২২ নয়,
     * ৪০৩ পান, যাতে ভুল কাগজ চেয়ে নথির অস্তিত্ব জানা না যায়।
     */
    public function test_an_unknown_paper_is_422_not_a_quiet_a4(): void
    {
        $invoice = $this->invoice();

        $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id, ['paper' => 'a3']))->assertForbidden();

        $this->grant('sales.invoice.view');

        /* ⚠️ `a5` এখন চেনা মাপ (05c1f4a9) — অচেনার নমুনা তাই `a3` */
        foreach (['a3', '100mm', 'A4 '] as $paper) {
            $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id, ['paper' => $paper]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('paper');
        }

        $this->assertSame([], $this->drawn, '⛔ অচেনা কাগজে তবু একটা কাগজ আঁকা হয়েছে।');

        /* ⓘ চেনা কাগজে একই নথি খোলে — ৪২২ কাগজের জন্যই */
        $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id, ['paper' => PaperSize::THERMAL_80]))->assertOk();
    }

    // ── নথি · নিয়ম ঘ · সংখ্যা সার্ভারে সাজানো, ওয়েবের কাগজটাই ─────────────

    /**
     * ⭐ একই মানুষ ওয়েবে যে কাগজ পান, ফোনেও সেটাই — একই সারি, একই মোট, একই
     * মাথা। ⛔ আলাদা আঁকা হলে একদিন একটায় ভ্যাটের সারি থাকত আর অন্যটায় না।
     */
    public function test_the_phone_gets_the_very_paper_the_web_draws(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user->fresh());
        $this->get(route('sales.print.invoice', $invoice).'?paper='.PaperSize::A4)->assertOk();

        $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id, ['paper' => PaperSize::A4]))->assertOk();

        $this->assertCount(2, $this->drawn, 'দুইটা কাগজ আঁকা হয়নি — দাবিটা কিছু মাপছে না।');
        [$web, $phone] = $this->drawn;

        $this->assertNotEmpty($web->totals, 'ওয়েবের কাগজে মোট নেই — দাবিটা কিছু মাপছে না।');
        $this->assertSame($web->title, $phone->title);
        $this->assertSame($web->meta, $phone->meta);
        $this->assertSame($web->lines, $phone->lines);
        $this->assertSame($web->totals, $phone->totals, '⛔ একই বিলের মোট ওয়েবে এক, ফোনে আরেক।');
        $this->assertSame($web->payments, $phone->payments);
    }

    /**
     * ⭐ ওয়েবের নিজের নিয়মও ফোনে চলে — খসড়া বিল ছাপা হয় না (মালিকের নিয়ম,
     * ২৫ সেপ্টেম্বর), ফোন থেকেও না; আর কারণটা ৪২২, কাগজ নয়।
     */
    public function test_a_draft_invoice_is_refused_on_the_phone_as_on_the_web(): void
    {
        $draft = $this->invoice(confirm: false);
        $this->assertTrue($draft->fresh()->isNotFinalYet(), 'বিলটা খসড়া নয় — দাবিটা কিছু মাপছে না।');

        $this->grant('sales.invoice.view');

        $this->phone($this->pdfUrl('SalesInvoice', $draft->public_id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame([], $this->drawn);
    }

    /** ⛔ বিক্রয় বন্ধ করা কোম্পানির বিল ফোনেও ৪০৪ — ওয়েবের সুইচ, ওয়েবের পাহারা। */
    public function test_a_switched_off_module_hides_its_papers_as_on_the_web(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');

        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertOk();

        CompanyContext::forCompany($this->company->id, fn () => app(SettingsService::class)->set('sales.enabled', false));

        $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id))->assertNotFound();
        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertNotFound();
        $this->assertSame([], $this->drawn, '⛔ বন্ধ মডিউলের কাগজ আঁকা হয়েছে।');
    }

    // ── নথি · পাহারার ফাঁক (মিউটেশনে ধরা পড়া) ──────────────────────────────

    /**
     * ⛔ ছাপার রুট দুইটা চাবি চাইলে দুইটাই লাগে — একটা থাকলেই খোলা নয়।
     *
     * ⓘ আজ প্রতিটা ছাপার রুটে একটাই `can:`, তাই "সবগুলো লাগে" আর "যেকোনো
     * একটা" বাকি দাবিগুলোর চোখে একই — কাল দ্বিতীয় চাবি বসলে ফোন নীরবে
     * আধা-পাহারায় খুলত। ⭐ একই মানুষ: প্রথম চাবিতে ৪০৩, দ্বিতীয়টা পেলে খোলা।
     */
    public function test_every_key_the_print_route_asks_is_required_not_just_one(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');

        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertOk();

        $route = Route::getRoutes()->getByName('sales.print.invoice');
        /* ⓘ চাবিটা কন্ট্রোলারের নিজের middleware() থেকেও আসে, তাই জড়ো করা তালিকাতেই বসানো */
        $route->computedMiddleware = [...$route->gatherMiddleware(), 'can:ec10doc.second_key'];

        $this->assertSame(['sales.invoice.view', 'ec10doc.second_key'], PaperTrail::abilitiesFor('sales_invoice'),
            'রুটে দ্বিতীয় চাবি বসেনি — দাবিটা কিছু মাপছে না।');

        $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id))->assertForbidden();
        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertForbidden();
        $this->assertSame([], $this->drawn, '⛔ দুই চাবির একটা নিয়েই কাগজ আঁকা হয়েছে।');

        $this->grant('ec10doc.second_key');

        $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * ⛔ যে ছাপার রুট কোনো চাবিই বলে না, ফোনে সেটা বন্ধ — সবার জন্য খোলা নয়।
     *
     * ⓘ খালি তালিকায় `foreach` একবারও চলে না, আর পাহারাটা চুপচাপ পাশ করত
     * ([[PaperShareController]]-এর একই শিক্ষা)। ⭐ একই মানুষ, একই বিল: রুটে
     * চাবি থাকলে খোলা, চাবিটা রুট থেকে সরালে ৪০৩ — চাবি হাতে থাকা সত্ত্বেও।
     */
    public function test_a_print_route_that_names_no_key_is_closed_not_open(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');

        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertOk();

        $route = Route::getRoutes()->getByName('sales.print.invoice');
        /* ⓘ চাবিটা কন্ট্রোলারের middleware() থেকে আসে — জড়ো করা তালিকা থেকেই সরানো */
        $route->computedMiddleware = array_values(array_filter(
            $route->gatherMiddleware(),
            fn ($m): bool => ! (is_string($m) && str_starts_with($m, 'can:')),
        ));

        $this->assertSame([], PaperTrail::abilitiesFor('sales_invoice'), 'রুটে এখনও চাবি আছে — দাবিটা কিছু মাপছে না।');

        $this->phone($this->pdfUrl('SalesInvoice', $invoice->public_id))->assertForbidden();
        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertForbidden();
        $this->assertSame([], $this->drawn, '⛔ চাবিহীন রুটের কাগজ আঁকা হয়েছে।');
    }

    /**
     * ⛔⛔ ক্রয় আদেশ আর ক্রয় ফেরতও প্রতিটা সারিতে ক্রয়মূল্য ছাপে — চাবি ছাড়া
     * দুইটাই দাম-ছাড়া, চাবি পেলে দামসহ। ⭐ একই মানুষ।
     */
    public function test_the_order_and_the_return_also_hide_the_price_without_the_cost_key(): void
    {
        $order = $this->purchaseOrder();
        $return = $this->purchaseReturn();
        $this->grant('purchase.order.view');
        $this->grant('purchase.return.view');

        foreach (['PurchaseOrder' => $order->public_id, 'PurchaseReturn' => $return->public_id] as $type => $id) {
            $this->assertNotEmpty($id, "{$type}-এর public_id নেই — দাবিটা কিছু মাপছে না।");
            $this->assertPricelessPaper($type, $id);
        }

        $this->grant(self::COST_KEY);

        foreach (['PurchaseOrder' => $order->public_id, 'PurchaseReturn' => $return->public_id] as $type => $id) {
            $this->drawn = [];
            $this->phone($this->pdfUrl($type, $id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $doc = $this->drawn[0] ?? null;
            $this->assertInstanceOf(PrintableDocument::class, $doc);
            $this->assertTrue($doc->showMoney, "⛔ {$type}: চাবিওয়ালার কাগজে দাম নেই।");
        }
    }

    /** চাবি ছাড়া: কাগজ আসে, আঁকা হয় দাম ছাড়া — দর, অঙ্ক, মোট কিছুই নয়। */
    private function assertPricelessPaper(string $type, string $id): void
    {
        $this->drawn = [];

        $bytes = $this->phone($this->pdfUrl($type, $id))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF', (string) $bytes, "⛔ {$type}: দাম-ছাড়া কাগজটাও আসেনি।");

        $doc = $this->drawn[0] ?? null;
        $this->assertInstanceOf(PrintableDocument::class, $doc);
        $this->assertFalse($doc->showMoney, "⛔ {$type}: ক্রয়মূল্যের চাবি ছাড়াই কাগজে দাম।");
        $this->assertArrayNotHasKey('rate', $doc->lines[0] ?? [], "⛔ {$type}: চাবি ছাড়াই সারিতে দর।");
        $this->assertSame([], $doc->totals, "⛔ {$type}: চাবি ছাড়াই মোটের সারি।");
    }

    /**
     * ⛔ লাইসেন্সের তালা ফোনে ৪০৩ — ওয়েবের মতো লাইসেন্স-পাতায় পাঠানো নয়।
     *
     * ⓘ ফোনের কাছে ঐ পাতার কোনো মানে নেই; ৩০২ পেলে ফোন হয় ঘুরে বেড়াত, নয়
     * একটা HTML পাতাকে PDF ভাবত। ⭐ একই মানুষ, একই বিল: তালা ঘুমালে খোলা,
     * তালা জাগলে (কাগজ নেই) ৪০৩।
     */
    public function test_a_lapsed_licence_is_403_on_the_phone_not_a_redirect(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');

        config(['abos.licence.enforced' => false]);
        $this->phone($this->papersUrl('SalesInvoice', $invoice->public_id))->assertOk();

        config(['abos.licence.enforced' => true]);
        app(LicenceReader::class)->forget();
        $this->assertFalse(app(LicenceReader::class)->read()->isValid(), 'লাইসেন্সটা বৈধ — দাবিটা কিছু মাপছে না।');

        foreach ([$this->pdfUrl('SalesInvoice', $invoice->public_id), $this->papersUrl('SalesInvoice', $invoice->public_id)] as $url) {
            $response = $this->phone($url)->assertForbidden();
            $this->assertStringStartsNotWith('%PDF', (string) $response->getContent());
        }
        $this->assertSame([], $this->drawn, '⛔ তালাবন্ধ অবস্থায় কাগজ আঁকা হয়েছে।');
    }

    /**
     * ⛔⛔ সত্যিকারের বেতনশিট, সত্যিকারের `public_id`, বেতনের চাবি হাতে — ফোনে
     * তবু ৪০৪; অথচ একই মানুষ ওয়েবে ঐ বেতনশিটটাই ছাপতে পারেন।
     *
     * ⓘ অচেনা-ধরনের দাবি `Payslip` চাইত বিলের আইডি দিয়ে — বেতনশিট খুঁজে না
     * পেয়ে ৪০৪ এমনিতেই আসত, তাই ডেস্কের ছাঁকনি উঠে গেলেও সেটা সবুজ থাকত।
     * ⭐ ওয়েবে খোলা প্রমাণ করে চাবি আর নথি দুটোই ঠিক — ফোনের ৪০৪ তাই কেবল
     * ডেস্কের ছাঁকনির।
     */
    public function test_a_real_payslip_with_the_payroll_key_still_never_reaches_the_phone(): void
    {
        $this->asOwner();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create([
            'code' => 'EC10-EMP', 'name_en' => 'Rafiq Islam', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        $slip = app(PayrollService::class)->build('2026-08-01')->payslips()->firstOrFail();
        $this->assertNotEmpty($slip->public_id, 'বেতনশিটের public_id নেই — দাবিটা কিছু মাপছে না।');

        $this->grant('hr.payroll.view');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user->fresh());
        $web = $this->get(route('hr.payslip.print', $slip))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $web->getContent(), 'ওয়েবেও বেতনশিট ছাপা হয় না — দাবিটা কিছু মাপছে না।');
        $drawn = count($this->drawn);

        foreach ([$this->pdfUrl('Payslip', $slip->public_id), $this->papersUrl('Payslip', $slip->public_id)] as $url) {
            $response = $this->phone($url)->assertNotFound();
            $this->assertStringStartsNotWith('%PDF', (string) $response->getContent(), '⛔ বেতনশিট ফোনে নেমেছে।');
        }
        $this->assertCount($drawn, $this->drawn, '⛔ বেতনশিট ফোনের জন্য আঁকা হয়েছে।');
    }

    // ── নিয়ম ঙ · অনলাইন-only, ক্যাশ নয় ─────────────────────────────────────

    /** ⛔ কাগজ আর ফাইল কোনোটাই ক্যাশে বসে না — পুরনো PDF বর্তমান বলে ভান করত। */
    public function test_nothing_it_sends_may_be_cached(): void
    {
        $invoice = $this->invoice();
        $this->grant('sales.invoice.view');
        $this->grant(self::REPORT_KEY);

        foreach ([
            $this->pdfUrl('SalesInvoice', $invoice->public_id),
            $this->papersUrl('SalesInvoice', $invoice->public_id),
            $this->exportUrl(self::COUNTING, ['format' => 'xlsx']),
        ] as $url) {
            $cache = (string) $this->phone($url)->assertOk()->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache, "⛔ {$url} ক্যাশযোগ্য।");
        }
    }

    // ── রপ্তানি ──────────────────────────────────────────────────────────

    /**
     * ⭐ `/reports/{key}` আর `/reports/{slug}/export` দুইটাই নিজের জায়গায় পৌঁছায় —
     * একটা আরেকটাকে গিলে ফেলে না।
     */
    public function test_the_export_route_and_the_report_route_both_resolve(): void
    {
        $this->assertSame('api.reports.export',
            Route::getRoutes()->match(Request::create('/api/v1/reports/'.self::COUNTING.'/export'))->getName());
        $this->assertSame('api.reports.show',
            Route::getRoutes()->match(Request::create('/api/v1/reports/'.self::COUNTING))->getName());

        $this->grant(self::REPORT_KEY);

        $this->phone('/api/v1/reports/'.self::COUNTING)->assertOk()->assertJsonPath('key', self::COUNTING);
        $this->phone($this->exportUrl(self::COUNTING, ['format' => 'csv']))
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    /**
     * ⭐ রিপোর্টের PDF — মালিক, ৪ অক্টোবর ২০২৬: *"all ledger & report date veue print share korazay pdf e"*।
     * একই মানুষ: চাবি ছাড়া ৪০৩; চাবি দিলে সত্যিকারের PDF, না-ক্যাশযোগ্য। ⛔ আর কাগজে **সব** ছাঁকা সারি, একটা পাতার
     * ১০০টা নয় (a3ecb8e3-এর ভুল — bb-এর সতর্কতা): ১৫০ সারির রিপোর্টের কাগজে ১৫০টাই।
     */
    public function test_the_report_pdf_needs_the_key_and_carries_every_row_not_one_page(): void
    {
        $this->phone($this->exportUrl(self::COUNTING, ['format' => 'pdf']))->assertForbidden();

        $this->grant(self::REPORT_KEY);

        $rows = null;
        \Illuminate\Support\Facades\View::creator('print.report', function ($view) use (&$rows): void {
            $rows = $view->getData()['rows'];
        });

        $response = $this->phone($this->exportUrl(self::COUNTING, ['format' => 'pdf']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', (string) $response->getContent(), '⛔ PDF নয়।');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertCount(150, $rows ?? [], '⛔ কাগজে কেবল এক পাতার সারি গেল।');
    }

    /** ⭐ একই মানুষ: রিপোর্টের নিজের চাবি ছাড়া ৪০৩; চাবি দিলে ফাইল। */
    public function test_the_export_needs_the_reports_own_key(): void
    {
        $this->phone($this->exportUrl(self::COUNTING, ['format' => 'csv']))->assertForbidden();

        $this->grant(self::REPORT_KEY);

        $this->phone($this->exportUrl(self::COUNTING, ['format' => 'csv']))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="abos-ec2doc-one_hundred_fifty-'.now()->format('Y-m-d').'.csv"');
    }

    /**
     * ⛔ ঢাকা কলাম ফাইলে নেই — শিরোনামে না, কোনো ঘরে না; তিন ফরম্যাটেই।
     * ⭐ সেই মানুষকেই চাবি দিলে তিনটাতেই আছে — নাহলে "নেই" মানে কিছুই না।
     */
    public function test_a_guarded_column_is_absent_from_every_format_until_its_key_is_granted(): void
    {
        $this->grant(self::REPORT_KEY);

        foreach (ListExport::FORMATS as $format) {
            [$headers, $text] = $this->exported(self::LIMITS, $format);

            $this->assertSame(['Code', 'Limit'], $headers, "⛔ {$format}: ঢাকা কলামের শিরোনাম ফাইলে।");
            $this->assertStringNotContainsString(self::SECRET, $text, "⛔ {$format}: ঢাকা কলামের সংখ্যা ফাইলে।");
            $this->assertStringContainsString('EC2D-01', $text, "{$format}: নিজের সারিও নেই — দাবিটা কিছু মাপছে না।");
        }

        $this->grant(self::COST_KEY);

        foreach (ListExport::FORMATS as $format) {
            [$headers, $text] = $this->exported(self::LIMITS, $format);

            $this->assertSame(['Code', 'Limit', 'Hidden cost'], $headers, "⛔ {$format}: চাবি দেওয়ার পরেও কলাম আসেনি।");
            $this->assertStringContainsString(self::SECRET.'-EC2D-01', $text);
        }
    }

    /**
     * ⭐ ফাইলে পুরো রিপোর্ট — §৯-এর এক পাতা (১০০) নয়; আর সংখ্যাটা §৯-এর
     * `totalRows`-এর সমান। ⛔ কাটা ফাইলকে মানুষ পুরো ভাবতেন।
     */
    public function test_the_file_holds_the_whole_report_not_one_page(): void
    {
        $this->grant(self::REPORT_KEY);

        $total = $this->phone('/api/v1/reports/'.self::COUNTING)->assertOk()->json('totalRows');
        $this->assertSame(150, $total, 'এক পাতার বেশি সারি নেই — দাবিটা কিছু মাপছে না।');

        $rows = $this->phone($this->exportUrl(self::COUNTING, ['format' => 'json']))->assertOk()->json('rows');

        $this->assertCount(150, $rows);
        $this->assertSame(array_map('strval', range(1, 150)), array_column($rows, 'n'));
    }

    /** ⛔ অন্য কোম্পানির সারি ফাইলে আসে না — ঠিকানায় `company_id` বসিয়ে চাইলেও না। */
    public function test_another_companys_rows_never_reach_the_file(): void
    {
        $this->grant(self::REPORT_KEY);

        $theirs = CompanyContext::forCompany($this->other->id,
            fn () => array_column(app(ReportEngine::class)->run(self::LIMITS, self::MARCH)->rows, 'code'));
        $this->assertContains('EC2D-FMART', $theirs, 'FMART-এর সারি ওদের নিজেদের রিপোর্টেও নেই — দাবিটা কিছু মাপছে না।');

        $text = (string) $this->phone($this->exportUrl(self::LIMITS, [...self::MARCH, 'company_id' => $this->other->id, 'format' => 'csv']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('EC2D-01', $text);
        $this->assertStringNotContainsString('EC2D-FMART', $text, '⛔ অন্য কোম্পানির সারি এই কোম্পানির ফাইলে।');
    }

    /**
     * ⛔ `docx` নেই (মালিকের সিদ্ধান্ত), অচেনা বা না-বলা ফরম্যাট ৪২২; অচেনা
     * রিপোর্ট ৪০৪; আর চাবিহীন মানুষ ভুল ফরম্যাটে ৪২২ নয়, ৪০৩ পান।
     * ⓘ `pdf` আর এই তালিকায় নেই — মালিক, ৪ অক্টোবর ২০২৬: *"all ledger & report date veue print share korazay pdf e"*;
     * ১৩ সেপ্টেম্বরের ভয় ("তৃতীয় ফরম্যাট, তৃতীয় জায়গায় অঙ্ক") খাটে না, কারণ PDF রপ্তানির একই ধরা টেবিল থেকে
     * ([[test_the_report_pdf_needs_the_key_and_carries_every_row_not_one_page()]])।
     */
    public function test_docx_and_unknown_formats_are_422_and_unknown_reports_404(): void
    {
        $this->phone($this->exportUrl(self::COUNTING, ['format' => 'docx']))->assertForbidden();

        $this->grant(self::REPORT_KEY);

        foreach (['docx', 'html', ''] as $format) {
            $this->phone($this->exportUrl(self::COUNTING, ['format' => $format]))
                ->assertUnprocessable()->assertJsonValidationErrors('format');
        }

        $this->phone($this->exportUrl(self::COUNTING))->assertUnprocessable()->assertJsonValidationErrors('format');
        $this->phone($this->exportUrl('ec2doc.no_such_report', ['format' => 'csv']))->assertNotFound();

        /* ⓘ §৯-এর তারিখের পাহারাও এখানে — উল্টো পরিসর ৪২২, ৫০০ নয় */
        $this->phone($this->exportUrl(self::LIMITS, ['from' => '2031-03-31', 'to' => '2031-03-01', 'format' => 'csv']))
            ->assertUnprocessable();
    }

    /**
     * ⓘ ফোনের রপ্তানিও রপ্তানির খাতায় — ওয়েবেরগুলোর পাশে। ⛔ নাহলে ফোন হত
     * বিনা চিহ্নে পুরো তালিকা নামানোর পথ।
     */
    public function test_the_phones_export_is_written_in_the_export_journal(): void
    {
        $this->grant(self::REPORT_KEY);

        $before = ExportLog::query()->withoutGlobalScopes()->count();

        $this->phone($this->exportUrl(self::COUNTING, ['format' => 'xlsx']))->assertOk();

        $entry = ExportLog::query()->withoutGlobalScopes()->latest('id')->first();
        $this->assertSame($before + 1, ExportLog::query()->withoutGlobalScopes()->count(), '⛔ রপ্তানিটা খাতায় ওঠেনি।');
        $this->assertSame('api.reports.export', $entry->route);
        $this->assertSame(self::COUNTING, $entry->title);
        $this->assertSame(150, (int) $entry->row_count);
        $this->assertSame($this->user->id, (int) $entry->user_id);
        $this->assertSame($this->company->id, (int) $entry->company_id);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function phone(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user->fresh(), [AuthController::APP]);

        return $this->get($url, ['Accept' => 'application/json']);
    }

    /** @param array<string, string> $query */
    private function pdfUrl(string $type, string $id, array $query = []): string
    {
        return "/api/v1/documents/{$type}/{$id}/pdf".($query === [] ? '' : '?'.http_build_query($query));
    }

    private function papersUrl(string $type, string $id): string
    {
        return "/api/v1/documents/{$type}/{$id}/papers";
    }

    /** @param array<string, mixed> $query */
    private function exportUrl(string $key, array $query = []): string
    {
        return "/api/v1/reports/{$key}/export".($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * রপ্তানির ফাইল খুলে শিরোনামের সারি আর পুরো লেখা — ফরম্যাট যা-ই হোক।
     *
     * ⓘ xlsx সত্যিই খোলা হয় (zip → শীটের XML); ⛔ বাইটে খুঁজলে সংকুচিত
     * লেখায় ঢাকা সংখ্যাটা "নেই" দেখাত, থাকলেও।
     *
     * @return array{0: list<string>, 1: string}
     */
    private function exported(string $key, string $format): array
    {
        $bytes = (string) $this->phone($this->exportUrl($key, [...self::MARCH, 'format' => $format]))->assertOk()->getContent();

        if ($format === 'json') {
            $body = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

            return [array_column($body['columns'], 'label'), $bytes];
        }

        if ($format === 'csv') {
            $lines = preg_split('/\r\n/', trim(substr($bytes, 3)));

            return [str_getcsv((string) $lines[0], ',', '"', ''), $bytes];
        }

        $path = tempnam(sys_get_temp_dir(), 'ec2doc_');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, '⛔ xlsx বলে পাঠানো ফাইলটা zip-ই নয়।');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $xml = simplexml_load_string($sheet);
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $headers = array_map(fn ($t): string => (string) $t, $xml->xpath('//s:row[@r="1"]/s:c/s:is/s:t') ?: []);

        return [$headers, $sheet];
    }

    private function grant(string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $this->user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function asOwner(): User
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        return $owner;
    }

    /**
     * আজকের একটা বিল — আসল পথে ([[SalesInvoiceService]]), মালিকের হাতে।
     *
     * ⓘ ডেমোতে দাখিলা বিল নেই, তাই বানাতে হয়। `confirm: false` হলে খসড়া।
     */
    private function invoice(bool $confirm = true): SalesInvoice
    {
        $this->asOwner();

        $service = app(SalesInvoiceService::class);
        $invoice = $service->create(
            [
                'customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => Carbon::today()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']],
        );

        $invoice = $confirm ? $service->confirm($invoice) : $invoice;
        $this->assertNotEmpty($invoice->public_id, 'বিলের public_id নেই — দাবিটা কিছু মাপছে না।');

        return $invoice->fresh();
    }

    private function purchaseBill(): PurchaseBill
    {
        $this->asOwner();

        return app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '10', 'rate' => '100']],
        )->fresh();
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $this->asOwner();

        return app(PurchaseOrderService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'ordered_qty' => '10', 'rate' => '100']],
        )->fresh();
    }

    private function purchaseReturn(): PurchaseReturn
    {
        $this->asOwner();

        return app(PurchaseReturnService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '2', 'rate' => '100']],
        )->fresh();
    }

    private function goodsReceipt(): PurchaseReceipt
    {
        $this->asOwner();

        return app(PurchaseReceiptService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'received_qty' => '10', 'rate' => '100']],
        )->fresh();
    }

    private function customer(string $code, int $companyId, string $openedOn, string $limit): void
    {
        $row = [
            'company_id' => $companyId,
            'code' => $code,
            'name_en' => $code,
            'credit_limit' => $limit,
            'opening_date' => $openedOn,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('customers', 'public_id')) {
            $row['public_id'] = (string) Str::uuid7();
        }

        DB::table('customers')->insert($row);
    }
}
