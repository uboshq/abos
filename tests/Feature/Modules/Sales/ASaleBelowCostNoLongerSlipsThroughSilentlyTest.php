<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Reports\MarginReport;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\MarginGuard;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * খরচের নিচের বিক্রি আর নীরবে পার হয় না — NEXUS §৩২।
 *
 * ── ⭐ কেন ─────────────────────────────────────────────────────────────
 * মালিকের ব্যবসার মার্জিন **৩.৮২%**। ⓘ একটা সারিতে ৪% ছাড়েই ঐ সারির
 * পুরো লাভ শেষ, আর আজ পর্যন্ত কোনো পাহারা খরচের সাথে দর মেলাত না —
 * দামের নীতি মাপত মান দাম, খরচ নয়।
 *
 * ── ⓘ খরচ কোথা থেকে ───────────────────────────────────────────────────
 * প্রতিটা দাবির পণ্য এই ফাইলেই বানানো, নিজের জানা FIFO স্তরসহ — ⚠️ ডেমোর
 * পণ্যের দর ধরে নিলে দাবিগুলো ডেমো বদলানোর দিন **ভুল কারণে** লাল হত।
 *
 * ── ⚠️ অভিনেতা ─────────────────────────────────────────────────────────
 * বেশিরভাগ দাবি মালিকের নামে (সব চাবি), কারণ প্রশ্নটা দেয়ালের, দরজার
 * নয়। ⓘ দরজা আর খরচের চাবির দাবিগুলো একজন ভূমিকাহীন মানুষের নামে,
 * আর একই মানুষকে চাবি দিয়ে আবার চালানো হয়।
 */
final class ASaleBelowCostNoLongerSlipsThroughSilentlyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /*
         * ⓘ অন্য দুই দেয়াল চুপ — এই ফাইল কেবল মার্জিন মাপে। ⚠️ বাকির সীমা
         * বা দামের নীতি থামালে একটা "আটকানো" দাবি ভুল কারণে সবুজ হত।
         */
        $this->setting('customer.credit_limit_enabled', false);
        $this->setting(PricingRule::POLICY, PricingRule::ALLOW);
        $this->setting(MarginGuard::FLOOR, '0');
    }

    // ── ⭐ তিনটা পথ ───────────────────────────────────────────────────────

    /**
     * ⭐ warn — বিক্রি হয়, আর সতর্কতাটা পর্দার জন্য সেশনে বসে।
     */
    public function test_warn_lets_a_below_cost_challan_through_and_says_so(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::WARN);
        $rice = $this->aProduct('Margin Rice Warn', [['10', '96']]);

        $challan = $this->confirmChallan($this->challan([[$rice, '1', '90']]));

        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status,
            '⛔ "সতর্ক করুন" বেছে নেওয়া কোম্পানিতে বিক্রিটাই থেমে গেছে।');

        $warnings = (array) session(MarginGuard::FLASH, []);

        $this->assertNotEmpty($warnings, '⛔ বিক্রি হয়ে গেল অথচ কোনো সতর্কতা নেই — ঠিক সেই নীরবতা।');
        $this->assertStringContainsString('Margin Rice Warn', implode(' ', $warnings));
    }

    /**
     * ⭐ approval — অনুরোধ বসে, চালান খসড়াই থাকে, মাল নড়ে না; সইয়ের পরে পার।
     */
    public function test_approval_holds_the_challan_until_a_human_signs(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::APPROVAL);
        $this->marginFlow();
        $rice = $this->aProduct('Margin Rice Sign', [['10', '96']]);
        $floor = $this->floorOf($rice);

        $challan = $this->challan([[$rice, '1', '90']]);

        $held = $this->refused(fn () => $this->confirmChallan($challan));

        $this->assertInstanceOf(HeldForApproval::class, $held, '⛔ অনুমোদনের পথে কাগজটা সইয়ে যায়নি।');
        $this->assertArrayHasKey('lines', $held->errors());

        $approval = Approval::query()
            ->where('approvable_type', DeliveryChallan::class)
            ->where('approvable_id', $challan->id)
            ->where('action', MarginGuard::APPROVAL_ACTION)
            ->first();

        $this->assertNotNull($approval, '⛔ "সইয়ের অপেক্ষায়" বলা হলো, অথচ সইকারীর তালিকায় কিছুই নেই।');
        $this->assertSame(Approval::PENDING, $approval->status);
        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);
        $this->assertSame($floor, $this->floorOf($rice), '⛔ সইয়ের আগেই মাল গুদাম থেকে বেরিয়েছে।');

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $this->confirmChallan($challan);

        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status,
            '⛔ সই হওয়ার পরেও চালান নিশ্চিত হয় না — কাগজটা চিরকাল আটকে থাকত।');
    }

    /**
     * ⛔ approval বাছা, অথচ ছক বসানো নেই — তখন নীরবে পার নয়, আটকানো।
     *
     * ⚠️ অনুমোদনের ইঞ্জিন ছক না পেলে "এগিয়ে যাও" বলে। ⓘ এই দাবি না থাকলে
     * ছক বসাতে ভুলে যাওয়া কোম্পানিতে খরচের নিচের বিক্রি সই ছাড়াই যেত।
     */
    public function test_approval_without_a_flow_stops_the_sale_instead_of_passing_it(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::APPROVAL);
        $rice = $this->aProduct('Margin Rice NoFlow', [['10', '96']]);

        $challan = $this->challan([[$rice, '1', '90']]);

        $refusal = $this->refused(fn () => $this->confirmChallan($challan));

        $this->assertNotInstanceOf(HeldForApproval::class, $refusal);
        $this->assertArrayHasKey('lines', $refusal->errors());
        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);
        $this->assertSame(0, Approval::query()->where('action', MarginGuard::APPROVAL_ACTION)->count());
    }

    /**
     * ⛔ block — সারির ঘরে বার্তা, পণ্যের নাম আর মার্জিনসহ; কিছুই বদলায় না।
     */
    public function test_block_refuses_on_the_line_and_names_the_product_and_margin(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Margin Rice Block', [['10', '96']]);
        $floor = $this->floorOf($rice);

        $challan = $this->challan([[$rice, '1', '90']]);

        $errors = $this->refused(fn () => $this->confirmChallan($challan))->errors();

        $this->assertArrayHasKey('lines.0.rate', $errors, '⛔ বার্তাটা সারির ঘরে বসেনি।');

        $message = $errors['lines.0.rate'][0];

        $this->assertStringContainsString('Margin Rice Block', $message);

        // ⓘ (৯০ − ৯৬) × ১০০ / ৯০ = −৬.৬৭
        $this->assertStringContainsString('-6.67', $message, '⛔ বার্তায় মার্জিনটা নেই।');

        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);
        $this->assertSame($floor, $this->floorOf($rice));
        $this->assertSame(0, Approval::query()->where('action', MarginGuard::APPROVAL_ACTION)->count(),
            '⛔ "আটকান" বাছা কোম্পানিতে সইয়ের অনুরোধ তৈরি হয়েছে।');
    }

    // ── ⚠️ কিনারা ─────────────────────────────────────────────────────────

    /**
     * ⭐ ঠিক সীমায় থাকা বিক্রি পার — "নিচে" মানে কঠোরভাবে নিচে।
     */
    public function test_a_sale_exactly_at_the_floor_passes(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $this->setting(MarginGuard::FLOOR, '4');
        $rice = $this->aProduct('Margin Rice Edge', [['10', '96']]);

        // ⓘ বিক্রয় ১০০, খরচ ৯৬ → মার্জিন ঠিক ৪%
        $challan = $this->confirmChallan($this->challan([[$rice, '1', '100']]));

        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status);
    }

    /**
     * ⛔ …আর এক পয়সা নিচে আটকায় — ঠিক-সীমার দাবিটা যেন ঢিলা দেয়াল না ঢাকে।
     */
    public function test_one_paisa_under_the_floor_is_refused(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $this->setting(MarginGuard::FLOOR, '4');
        $rice = $this->aProduct('Margin Rice Paisa', [['10', '96']]);

        $challan = $this->challan([[$rice, '1', '99.99']]);

        $this->assertArrayHasKey('lines.0.rate', $this->refused(fn () => $this->confirmChallan($challan))->errors());
    }

    /**
     * ⭐ একই পণ্য দুই সারিতে — দ্বিতীয় সারি পরের স্তর থেকে, আসল FIFO-র মতো।
     *
     * ⚠️ দুইটা সারিই প্রথম (সস্তা) স্তর দেখলে দুইটাই পার হত, অথচ দ্বিতীয়
     * মালটা আসলে ১১০ টাকার স্তর থেকে যায়।
     */
    public function test_two_lines_of_one_product_walk_the_layers_in_order(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Margin Rice Fifo', [['1', '90'], ['1', '110']]);

        $challan = $this->challan([[$rice, '1', '100'], [$rice, '1', '100']]);

        $errors = $this->refused(fn () => $this->confirmChallan($challan))->errors();

        $this->assertArrayNotHasKey('lines.0.rate', $errors, '⛔ প্রথম সারির খরচ ৯০, অথচ সেটাও আটকেছে।');
        $this->assertArrayHasKey('lines.1.rate', $errors, '⛔ দ্বিতীয় সারি সস্তা স্তরটাই আবার দেখেছে।');
    }

    /**
     * ⛔ কাগজের মাথার ছাড় — প্রতিটা সারি সীমার উপরে, গোটা বিক্রি নিচে।
     */
    public function test_a_document_discount_that_sinks_the_whole_sale_is_caught(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Margin Rice Header', [['10', '96']]);

        $challan = $this->challan([[$rice, '2', '100']]);

        // ⓘ বিক্রয় ২০০, খরচ ১৯২ — সারি পার; ১০ টাকার মাথার ছাড়ে ১৯০, খরচের নিচে
        $challan->update(['discount_amount' => '10']);

        $errors = $this->refused(fn () => $this->confirmChallan($challan))->errors();

        $this->assertArrayNotHasKey('lines.0.rate', $errors);
        $this->assertArrayHasKey('lines', $errors, '⛔ মাথার ছাড় গোটা বিক্রিকে খরচের নিচে নামাল, আর দেয়াল দেখল না।');
    }

    /**
     * ⭐ খরচ অজানা — বিচার হয় না, কিন্তু নীরবও নয়।
     *
     * ⓘ সিদ্ধান্ত: স্তরে দাম নেই মানে মার্জিন মাপা যায় না; একটা দাম ধরে
     * নেওয়াটাই ৭ আগস্টের ভুল। ⚠️ তাই আটকানো হয় না — বিলের দিনে আসল FIFO
     * টান নিজেই থামে (`no_cost_layer`) — কিন্তু সতর্কতায় পণ্যটার নাম যায়।
     */
    public function test_an_unknown_cost_is_not_judged_but_is_named(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Margin Rice Unknown', []);

        $challan = $this->challan([[$rice, '1', '1']]);

        $notes = app(MarginGuard::class)->assertMargin($challan->fresh(['lines']));

        $this->assertCount(1, $notes, '⛔ অজানা খরচের সারি নীরবে পার হয়েছে।');
        $this->assertStringContainsString('Margin Rice Unknown', $notes[0]);

        $verdict = app(MarginGuard::class)->judge($challan->fresh(['lines']));

        $this->assertNull($verdict->lines[0]->cost, '⛔ অজানা খরচকে শূন্য ধরা হয়েছে — তাহলে সারিটা ১০০% লাভ দেখাত।');
        $this->assertFalse($verdict->isBelow());
    }

    /**
     * ⛔ চালানের পরে বিলে দর কমালে আবার মাপা হয় — দেয়াল এড়ানোর দরজা নয়।
     *
     * ⭐ পাল্টা-দাবিও: একই দরে বিল হলে আবার মাপা হয় না (দ্বিতীয় সই বা
     * দ্বিতীয় সতর্কতা নয়) — চালানের পরে খরচের স্তর বদলালেও।
     */
    public function test_an_invoice_that_lowers_the_challan_price_is_judged_again(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Margin Rice Rebill', [['10', '96']]);

        $challan = $this->confirmChallan($this->challan([[$rice, '1', '100']]));
        $line = $challan->fresh(['lines'])->lines->first();

        $lower = $this->invoiceFor($line->id, $rice, '90');

        $this->assertArrayHasKey('lines.0.rate',
            $this->refused(fn () => app(SalesInvoiceService::class)->confirm($lower->fresh()))->errors(),
            '⛔ চালানে ১০০ দেখিয়ে বিলে ৯০ লিখলে খরচের নিচের বিক্রি পার হয়ে যেত।');

        // ⓘ একই খসড়া বিল, চালানের দরে ফিরিয়ে — এবার আর আবার মাপা হয় না
        $same = app(SalesInvoiceService::class)->update(
            $lower->fresh(),
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $rice->id,
                'delivery_challan_line_id' => $line->id,
                'qty' => '1',
                'rate' => '100',
            ]],
        );

        app(SalesInvoiceService::class)->confirm($same->fresh());

        $this->assertSame(DocumentStatus::CONFIRMED, $same->fresh()->status);
    }

    // ── ⭐ কাউন্টার ───────────────────────────────────────────────────────

    /**
     * ⭐ কাউন্টারে approval — সব খসড়া, অনুরোধ **টিকে থাকে**, সইয়ের পরে শেষ।
     *
     * ⛔ কাউন্টার সব এক লেনদেনে করে; ভিতরে অনুরোধ লিখে ছুঁড়লে লেনদেনটা
     * অনুরোধটাও মুছত — "সইয়ের অপেক্ষায়", অথচ তালিকায় কিছুই নেই।
     */
    public function test_the_counter_holds_a_below_cost_sale_and_the_request_survives(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::APPROVAL);
        $this->marginFlow();
        $rice = $this->aProduct('Margin Rice Counter', [['10', '96']]);

        $held = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0'],
            [['product_id' => $rice->id, 'qty' => '1', 'rate' => '90']],
        );

        $this->assertTrue($held['margin_held'] ?? false, '⛔ কাউন্টারের ফল বলে না যে বিক্রিটা মার্জিনের সইয়ে গেছে।');
        $this->assertSame(DocumentStatus::DRAFT, $held['challan']->fresh()->status);
        $this->assertSame(DocumentStatus::DRAFT, $held['invoice']->fresh()->status);

        $approval = Approval::query()
            ->where('approvable_type', DeliveryChallan::class)
            ->where('approvable_id', $held['challan']->id)
            ->where('action', MarginGuard::APPROVAL_ACTION)
            ->first();

        $this->assertNotNull($approval, '⛔ অনুরোধটা লেনদেনের সাথে মুছে গেছে।');

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        /*
         * ⓘ শেষ সইয়েই বিক্রি শেষ — কেউ বোতাম চাপে না (মালিকের সিদ্ধান্ত ১,
         * ২৭ সেপ্টেম্বর ২০২৬; [[HeldCounterSaleFinisher]])। ⚠️ আগে এখানে হাতে
         * `finishHeld()` ডাকা হত; এখন সেটা "খসড়া নেই" বলত, কারণ কাজ হয়ে গেছে।
         */
        $invoice = $held['invoice']->fresh();

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status);
        $this->assertSame(DocumentStatus::CONFIRMED, $held['challan']->fresh()->status);

        // ⓘ বিলে আবার সই চাওয়া হয়নি — দেয়াল চালানেই দাঁড়িয়েছিল
        $this->assertSame(0, Approval::query()
            ->where('approvable_type', SalesInvoice::class)
            ->where('action', MarginGuard::APPROVAL_ACTION)->count());
    }

    // ── ⛔ খরচের চাবি ─────────────────────────────────────────────────────

    /**
     * ⛔ চাবি ছাড়া বার্তায় পণ্যের নাম আছে, মার্জিন নেই — একই মানুষ, চাবি দিলে আছে।
     *
     * ⓘ বিক্রয় আর মার্জিন% জানলে খরচ এক অঙ্কেই বেরোয়।
     */
    public function test_the_refusal_hides_the_margin_from_a_seller_without_the_cost_key(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Margin Rice Secret', [['10', '96']]);
        $challan = $this->challan([[$rice, '1', '90']]);

        $seller = $this->aMember();
        $this->actingAs($seller);

        $this->assertFalse($seller->can(MarginGuard::COST_KEY), 'ⓘ ভিত্তি: ভূমিকাহীন মানুষের খরচের চাবি নেই।');

        $plain = $this->refused(fn () => $this->confirmChallan($challan))->errors()['lines.0.rate'][0];

        $this->assertStringContainsString('Margin Rice Secret', $plain);
        $this->assertStringNotContainsString('-6.67', $plain, '⛔ চাবি ছাড়া বিক্রেতা মার্জিনটা দেখলেন।');

        $seller->givePermissionTo(Permission::findOrCreate(MarginGuard::COST_KEY, 'web'));
        $seller = $seller->fresh();
        $this->actingAs($seller);

        $told = $this->refused(fn () => $this->confirmChallan($challan))->errors()['lines.0.rate'][0];

        $this->assertStringContainsString('-6.67', $told, '⛔ চাবি দেওয়ার পরেও সংখ্যাটা আসে না — তাহলে উপরের দাবি কিছুই মাপেনি।');
    }

    // ── ⭐ রিপোর্ট ───────────────────────────────────────────────────────

    /**
     * ⛔ রিপোর্টের দরজা — একই মানুষ, চাবি ছাড়া ৪০৩, চাবিতে খোলে।
     *
     * ⭐ আর খোলার পরেও খরচ ঢাকা থাকে যতক্ষণ খরচের চাবি নেই।
     */
    public function test_the_margin_report_door_and_its_cost_columns_ask_for_their_keys(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::WARN);
        $this->switchEveryModuleOn();

        // ⓘ স্বতন্ত্র একটা খরচ, যাতে পাতায় খুঁজলে অন্য কোনো সংখ্যা না মেলে
        $rice = $this->aProduct('Margin Rice Report', [['5', '9631.57']]);
        $this->sellAtTheCounter($rice, '9000');

        $reader = $this->aMember();
        $url = route('sales.margin.report.show', ['slug' => 'margin']);

        $this->actingAs($reader)->get($url)->assertForbidden();

        $reader->givePermissionTo(Permission::findOrCreate(MarginReport::PERMISSION, 'web'));
        $reader = $reader->fresh();

        $page = $this->actingAs($reader)->get($url);
        $page->assertOk();
        $page->assertSee('Margin Rice Report');
        $page->assertDontSee('9,631.57', escape: false);

        $reader->givePermissionTo(Permission::findOrCreate(MarginGuard::COST_KEY, 'web'));

        $this->actingAs($reader->fresh())->get($url)->assertOk()->assertSee('9,631.57', escape: false);
    }

    /**
     * ⭐ রিপোর্ট সারিটা সীমার নিচে বলে — আর অন্য কোম্পানি সারিটা দেখেই না।
     */
    public function test_the_report_flags_the_line_and_another_company_never_sees_it(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::WARN);
        $rice = $this->aProduct('Margin Rice Flag', [['5', '96']]);
        $invoice = $this->sellAtTheCounter($rice, '90');

        $ours = collect(app(ReportEngine::class)->run(MarginReport::KEY, [])->rows)
            ->map(fn ($row) => (array) $row)
            ->firstWhere('document_no', $invoice->document_no);

        $this->assertNotNull($ours, '⛔ নিশ্চিত বিলের সারি মার্জিন রিপোর্টে নেই।');
        $this->assertSame('96.0000', bcadd((string) $ours['cost'], '0', 4), '⛔ খরচটা FIFO স্তরের নয়।');
        $this->assertNotSame('', (string) $ours['below_floor'], '⛔ খরচের নিচের সারিকে রিপোর্ট "নিচে" বলেনি।');

        $theirs = Company::query()->where('id', '!=', $this->company->id)->firstOrFail();
        CompanyContext::set($theirs->id, $theirs->defaultBranch()?->id);

        $leaked = collect(app(ReportEngine::class)->run(MarginReport::KEY, [])->rows)
            ->map(fn ($row) => (array) $row)
            ->firstWhere('document_no', $invoice->document_no);

        $this->assertNull($leaked, '⛔ অন্য কোম্পানির মার্জিন রিপোর্টে আমাদের বিলের সারি দেখা যাচ্ছে।');
    }

    /**
     * ⛔ অন্য কোম্পানির "আটকান" আমাদের কাউন্টার থামায় না — সেটিং কোম্পানির।
     */
    public function test_another_companys_block_does_not_reach_us(): void
    {
        $theirs = Company::query()->where('id', '!=', $this->company->id)->firstOrFail();

        CompanyContext::set($theirs->id, $theirs->defaultBranch()?->id);
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(SettingsService::class)->flush();
        $this->setting(MarginGuard::ACTION, MarginGuard::WARN);

        $rice = $this->aProduct('Margin Rice Isolated', [['10', '96']]);
        $challan = $this->confirmChallan($this->challan([[$rice, '1', '90']]));

        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status,
            '⛔ অন্য কোম্পানির সেটিং আমাদের চালান থামিয়েছে।');

        CompanyContext::set($theirs->id, $theirs->defaultBranch()?->id);
        app(SettingsService::class)->flush();

        $this->assertSame(MarginGuard::BLOCK, app(MarginGuard::class)->action(),
            'ⓘ ভিত্তি: ওদের সেটিংটা সত্যিই "আটকান" — নাহলে উপরের দাবি কিছুই মাপেনি।');
    }

    // ── ⭐ বাকি পথগুলো ───────────────────────────────────────────────────

    /**
     * ⛔ কাউন্টারে block — কিছুই থাকে না: চালান নেই, বিল নেই, মাল নড়েনি।
     *
     * ⓘ কাউন্টার চালান আর বিল এক লেনদেনে বানায়; দেয়াল ভিতরে ছুঁড়লে পুরোটা
     * ফিরে যায়। ⚠️ একটা আধা-বানানো খসড়া থেকে গেলে সেটা গ্রাহকের সীমা
     * আটকে রাখত ([[CreditExposure]] খসড়া গোনে)।
     */
    public function test_the_counter_blocks_a_below_cost_sale_and_leaves_nothing_behind(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Margin Rice Counter Block', [['10', '96']]);
        $floor = $this->floorOf($rice);

        $errors = $this->refused(fn () => app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0'],
            [['product_id' => $rice->id, 'qty' => '1', 'rate' => '90']],
        ))->errors();

        $this->assertArrayHasKey('lines.0.rate', $errors);
        $this->assertSame(0, DB::table('sal_challan_lines')->where('product_id', $rice->id)->count(),
            '⛔ আটকানো বিক্রির চালান থেকে গেছে।');
        $this->assertSame(0, DB::table('sal_invoice_lines')->where('product_id', $rice->id)->count(),
            '⛔ আটকানো বিক্রির বিল থেকে গেছে।');
        $this->assertSame($floor, $this->floorOf($rice), '⛔ আটকানো বিক্রির মাল গুদাম থেকে বেরিয়েছে।');
    }

    /**
     * ⭐ ছকের সীমার নিচের বিক্রি সইয়ে যায় না — কিন্তু নীরবেও নয়।
     *
     * ⚠️ কোম্পানি বলেছে "এক লাখের নিচে সই লাগবে না"। ⛔ কাউন্টার কেবল "ছক
     * আছে কি" দেখলে ৯০ টাকার বিক্রিও খসড়ায় আটকাত, আর পর্দা বলত "সইয়ের
     * অপেক্ষায়" অথচ কারও তালিকায় কিছু যেত না। ⓘ আর পার হলেও সতর্কতা
     * যায় — নাহলে ছকের সীমাই খরচের নিচের বিক্রির নীরব দরজা হত।
     */
    public function test_a_sale_under_the_flows_threshold_is_not_held_but_is_still_warned(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::APPROVAL);
        $this->marginFlow('100000');
        $rice = $this->aProduct('Margin Rice Threshold', [['10', '96']]);

        $done = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0'],
            [['product_id' => $rice->id, 'qty' => '1', 'rate' => '90']],
        );

        $this->assertFalse($done['margin_held'] ?? false, '⛔ ছকের সীমার নিচের বিক্রি খসড়ায় আটকেছে।');
        $this->assertSame(DocumentStatus::CONFIRMED, $done['invoice']->fresh()->status);
        $this->assertSame(0, Approval::query()->where('action', MarginGuard::APPROVAL_ACTION)->count());

        $this->assertStringContainsString('Margin Rice Threshold', implode(' ', (array) session(MarginGuard::FLASH, [])),
            '⛔ ছকের সীমার নিচে বলে খরচের নিচের বিক্রি একটা শব্দও ছাড়া পার হয়েছে।');
    }

    /**
     * ⛔ চালান ছাড়া বিল — বিলের দেয়াল নিজেই মাপে।
     *
     * ⓘ বিলের সারি চালানে বাঁধা না থাকলে আগে মাপার কেউ ছিল না। ⚠️ এই দাবি না
     * থাকলে বিলের দেয়ালটা তুলে দিলেও সব সবুজ থাকত — চালানের দাবিগুলো
     * চালানের দেয়ালই মাপে।
     */
    public function test_a_bill_without_a_challan_is_judged_on_its_own(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Margin Rice Bill', [['10', '96']]);
        $floor = $this->floorOf($rice);

        $bill = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $rice->id, 'qty' => '1', 'rate' => '90']],
        );

        $errors = $this->refused(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh()))->errors();

        $this->assertArrayHasKey('lines.0.rate', $errors);
        $this->assertStringContainsString('Margin Rice Bill', $errors['lines.0.rate'][0]);
        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status);
        $this->assertSame($floor, $this->floorOf($rice));
    }

    /**
     * ⭐ ডিফল্ট "সতর্ক" আর সীমা ০ — আর অচেনা মান মানে "আটকান"।
     *
     * ⚠️ হাতে বসানো একটা টাইপো ("aproval") পাহারাটা নীরবে তুলে দিত, যদি
     * অচেনা মানকে ডিফল্ট ধরা হত।
     */
    public function test_the_defaults_are_warn_at_zero_and_a_typo_reads_as_block(): void
    {
        $guard = app(MarginGuard::class);

        $this->assertSame(MarginGuard::WARN, $guard->action(), 'ⓘ ভিত্তি: কেউ কিছু না বাছলে "সতর্ক"।');
        $this->assertSame(0, bccomp($guard->floor(), '0', 4));

        /*
         * ⓘ ৩ অক্টোবর ২০২৬ থেকে (aef34470) `set()` তালিকার বাইরের মান নেয়ই না — টাইপো লেখার দরজায় থামে।
         * ⚠️ তবু আগে থেকে বসে থাকা টাইপো (পুরনো সারি, হাতে বসানো) পাহারাটা নরম করবে না — সেটা "block" পড়ে।
         */
        try {
            $this->setting(MarginGuard::ACTION, 'aproval');
            $this->fail('⛔ তালিকার বাইরের মান সেটিংয়ে বসে গেল।');
        } catch (\InvalidArgumentException) {
            // প্রত্যাশিত
        }

        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        \App\Models\Setting::query()->where('company_id', $this->company->id)->where('key', MarginGuard::ACTION)
            ->update(['value' => 'aproval']);
        app(SettingsService::class)->flush();

        $this->assertSame(MarginGuard::BLOCK, app(MarginGuard::class)->action(),
            '⛔ আগে থেকে বসা অচেনা মান পাহারাটা নরম করেছে।');
    }

    /**
     * ⛔ কাউন্টারের পাতায় স্তরের খরচ কেবল খরচের চাবিধারী পান — একই মানুষ, চাবি ছাড়া নেই।
     *
     * ⓘ পাতার উৎসেও না: চাবি না থাকলে তালিকাটা খালি যায় ([[MarginGuard::screen()]])।
     */
    public function test_the_counter_page_carries_the_layer_cost_only_with_the_cost_key(): void
    {
        $this->switchEveryModuleOn();
        $this->aProduct('Margin Rice Screen', [['5', '9631.57']]);

        $seller = $this->aMember();
        $seller->givePermissionTo(Permission::findOrCreate('sales.challan.create', 'web'));
        $url = route('sales.direct.create');

        $page = $this->actingAs($seller->fresh())->get($url);
        $page->assertOk();
        $page->assertSee('Margin Rice Screen');
        $page->assertDontSee('9631.57', escape: false);

        $seller->givePermissionTo(Permission::findOrCreate(MarginGuard::COST_KEY, 'web'));

        $this->actingAs($seller->fresh())->get($url)->assertOk()->assertSee('9631.57', escape: false);
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function setting(string $key, mixed $value): void
    {
        app(SettingsService::class)->set($key, $value);
    }

    /**
     * নিজের পণ্য, নিজের স্তর — দর জানা, আর মাল গুদামে।
     *
     * @param  list<array{0: string, 1: string}>  $layers  [পরিমাণ, একক-খরচ]
     */
    private function aProduct(string $name, array $layers): Product
    {
        $product = app(ProductService::class)->create([
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '1',
            'sale_price' => '1',
            'reorder_level' => '0',
        ]);

        $stock = '0';

        foreach ($layers as [$qty, $cost]) {
            app(CostLayerService::class)->receive(
                product: $product,
                qty: $qty,
                unitCost: $cost,
                sourceType: 'opening',
                sourceId: $product->id,
                documentNo: 'OPENING',
            );

            $stock = bcadd($stock, $qty, 4);
        }

        // ⓘ স্তর ছাড়াও মাল তাকে থাকে — "খরচ অজানা" দাবির জন্য
        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'opening',
            sourceId: $product->id,
            floor: bccomp($stock, '0', 4) > 0 ? $stock : '10',
        );

        return $product->fresh();
    }

    /**
     * @param  list<array{0: Product, 1: string, 2: string}>  $lines  [পণ্য, পরিমাণ, দর]
     */
    private function challan(array $lines): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            array_map(fn (array $l) => [
                'product_id' => $l[0]->id,
                'delivered_qty' => $l[1],
                'rate' => $l[2],
            ], $lines),
        );
    }

    private function confirmChallan(DeliveryChallan $challan): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
    }

    private function invoiceFor(int $challanLineId, Product $product, string $rate): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $product->id,
                'delivery_challan_line_id' => $challanLineId,
                'qty' => '1',
                'rate' => $rate,
            ]],
        );
    }

    /** কাউন্টারের এক চাপে বিক্রি — চালান আর বিল দুইটাই নিশ্চিত। */
    private function sellAtTheCounter(Product $product, string $rate): SalesInvoice
    {
        $done = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0'],
            [['product_id' => $product->id, 'qty' => '1', 'rate' => $rate]],
        );

        $this->assertSame(DocumentStatus::CONFIRMED, $done['invoice']->fresh()->status);

        return $done['invoice']->fresh();
    }

    private function marginFlow(?string $threshold = null): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => 'sales',
            'action' => MarginGuard::APPROVAL_ACTION,
            'document_type' => '',
            'threshold_amount' => $threshold,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->owner->id,
        ]);
    }

    /** কোম্পানির সদস্য, কোনো ভূমিকা নয় — চাবি দাবির ভিতরেই দেওয়া হয়। */
    private function aMember(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }

    private function floorOf(Product $product): string
    {
        return bcadd((string) DB::table('inv_stock_movements')
            ->where('company_id', $this->company->id)
            ->where('product_id', $product->id)
            ->sum('floor_change'), '0', 4);
    }

    /** ⓘ বন্ধ-সুইচের পাতা অনুমতির আগেই ৪০৪ দেয় — তাই সব খোলা। */
    private function switchEveryModuleOn(): void
    {
        foreach (app(ModuleRegistry::class)->all() as $module) {
            $this->setting(app(MenuSwitches::class)->forModule($module->code), true);
        }
    }

    private function refused(callable $work): ValidationException
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return $e;
        }

        $this->fail('⛔ কিছুই আটকায়নি — খরচের নিচের বিক্রি দিব্যি পার হয়ে গেল।');
    }
}
