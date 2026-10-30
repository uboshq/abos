<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\OpenPeriod;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceCancellation;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বন্ধ মাসের বিলও বাতিল হয় — উল্টো দাখিলা বাতিলের নিজের দিনে (fe, ২১ অক্টোবর ২০২৬; IAS 8, SAP-এর উল্টো দাখিলার তারিখ;
 * [[SalesInvoiceCancellationService::confirm()]])।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * বিলের মাস বন্ধ হলে বাতিল-ইনভয়েস থামত (`cancellation.month_closed`)। অথচ উল্টো সারি সবসময় আজকের তারিখেই বসত — বন্ধ মাসের
 * কিছুই ছোঁয়ার কথা ছিল না। ফলে আগস্টের ভুল বিল অক্টোবরে বাতিল করতে আগে আগস্ট খুলতে হত, ছাপা হিসাব খোলা পড়ে থাকত।
 *
 * ── ⭐ এখন ───────────────────────────────────────────────────────────
 * আগস্ট বন্ধ, আগস্টের বিল অক্টোবরে বাতিল:
 *   · আগস্টের প্রতিটা খাতা, মজুদ আর খরচের স্তরের সারি হুবহু আগের মতো (আগে-পরে ছবি মিলিয়ে);
 *   · যত নতুন সারি বসেছে — টাকার আর মালের — সবই অক্টোবরের, বাতিলের দিনের;
 *   · ক্রেতার দেনা আর গুদামের মাল বিলের আগের জায়গায়।
 * সই (প্রতিষ্ঠানের ছক), বাধ্যতামূলক কারণ আর অডিট আগের মতোই; বাতিলের নিজের মাস বন্ধ হলে এখনো থামে।
 */
final class AnAugustBillCancelledInOctoberLeavesAugustAloneTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['ledger_entries', 'inv_stock_movements', 'inv_cost_layer_uses'];

    private const OCTOBER_DAY = '2026-10-10';

    private Company $company;

    private User $owner;

    private Product $biscuit;

    private Warehouse $warehouse;

    /** @var array<string, mixed> */
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->data = [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'own_transport' => '1',
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_august_is_closed_and_the_august_bill_is_cancelled_in_october(): void
    {
        $stockBefore = $this->onHand();
        $layersBefore = $this->inLayers();
        $dueBefore = $this->customerDue();

        $sale = $this->augustSale();
        $challan = $this->challanOf($sale);

        Carbon::setTestNow(self::OCTOBER_DAY.' 10:00:00');
        $this->closeAugust();

        // ── প্রস্তুতি: আগস্ট সত্যিই বন্ধ, বিলটা সত্যিই আগস্টের, আর তার ছাপ আগস্টের সারিতে ──
        $this->assertNotNull(app(OpenPeriod::class)->lockOn('2026-08-10'), 'প্রস্তুতিটাই ভুল — আগস্ট বন্ধ নয়।');
        $this->assertNull(app(OpenPeriod::class)->lockOn(self::OCTOBER_DAY), 'প্রস্তুতিটাই ভুল — অক্টোবরও বন্ধ।');
        $this->assertSame('2026-08-10', $sale->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — বিলটা আগস্টের নয়।');
        $this->assertSame('2026-08-10', $challan->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — চালানটা আগস্টের নয়।');
        $august = $this->august();
        $this->assertTrue(collect($august['ledger_entries'])->contains(fn ($r) => $r['source_type'] === SalesInvoice::drillSourceType() && (int) $r['source_id'] === (int) $sale->id),
            'প্রস্তুতিটাই ভুল — বিলের খাতার সারি আগস্টে নেই।');
        $this->assertTrue(collect($august['inv_stock_movements'])->contains(fn ($r) => $r['source_type'] === DeliveryChallan::STOCK_SOURCE && (int) $r['source_id'] === (int) $challan->id),
            'প্রস্তুতিটাই ভুল — চালানের মাল বেরোনো আগস্টে নেই।');
        $this->assertTrue(collect($august['inv_cost_layer_uses'])->contains(fn ($r) => $r['source_type'] === SalesInvoice::STOCK_SOURCE && (int) $r['source_id'] === (int) $sale->id),
            'প্রস্তুতিটাই ভুল — বিলের খরচের টান আগস্টে নেই।');
        $this->assertSame(0, bccomp(bcsub($stockBefore, '2', 4), $this->onHand(), 4), 'প্রস্তুতিটাই ভুল — মাল বেরোয়নি।');
        $last = $this->lastIds();

        // ── অক্টোবরে বাতিল, আসল দরজায় ──
        $this->post(route('sales.invoice.cancellation', $sale), ['reason' => 'ভুল গ্রাহকের নামে কাটা'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('saved');

        $paper = SalesInvoiceCancellation::query()->where('sales_invoice_id', $sale->id)->firstOrFail();
        $this->assertSame(DocumentStatus::CONFIRMED, $paper->status, '⛔ বন্ধ মাসের বিলের বাতিল-ইনভয়েস পাকা হয়নি।');
        $this->assertSame(self::OCTOBER_DAY, $paper->trx_date->toDateString(), '⛔ বাতিল-ইনভয়েস নিজের দিনের নয়।');
        $this->assertSame(DocumentStatus::CANCELLED, $sale->fresh()->status, '⛔ বিল বাতিল হয়নি।');
        $this->assertSame(DocumentStatus::CANCELLED, $challan->fresh()->status, '⛔ চালান বাতিল হয়নি।');

        // ⛔ আগস্টের প্রতিটা সারি হুবহু
        $this->assertSame($august, $this->august(), '⛔ বন্ধ আগস্টের খাতা, মজুদ বা খরচের স্তরের সারি বদলেছে বা নতুন বসেছে।');

        // ⭐ নতুন সারি — টাকার আর মালের — সবই বাতিলের দিনে
        $this->assertEverythingNewIsDatedOctober($last, $sale, $challan);

        // ⭐ শেষ অবস্থা: দেনা, মাল আর স্তর বিলের আগের জায়গায়
        $this->assertSame(0, bccomp($dueBefore, $this->customerDue(), 4), '⛔ বাতিলের পরে ক্রেতার দেনা বিলের আগের জায়গায় ফেরেনি।');
        $this->assertSame(0, bccomp('0', $this->receivableOf($sale), 4), '⛔ বিলের দেনা শূন্যে ফেরেনি।');
        $this->assertSame(0, bccomp($stockBefore, $this->onHand(), 4), '⛔ মাল তাকে ফেরেনি।');
        $this->assertSame(0, bccomp($layersBefore, $this->inLayers(), 4), '⛔ খরচের স্তরে মাল ফেরেনি।');
    }

    /**
     * ⭐ আগের মাস খোলা থাকলেও উল্টো সারি বিলের দিনে নয়, বাতিলের দিনে — তারিখটা নিয়ম, তালা কেবল তার পাহারা।
     */
    public function test_even_with_august_open_the_reversal_takes_the_cancellations_own_date(): void
    {
        $sale = $this->augustSale();
        $challan = $this->challanOf($sale);

        Carbon::setTestNow(self::OCTOBER_DAY.' 10:00:00');
        $this->assertNull(app(OpenPeriod::class)->lockOn('2026-08-10'), 'প্রস্তুতিটাই ভুল — আগস্ট বন্ধ।');
        $august = $this->august();
        $last = $this->lastIds();

        $this->post(route('sales.invoice.cancellation', $sale), ['reason' => 'দর ভুল'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CANCELLED, $sale->fresh()->status, 'প্রস্তুতিটাই ভুল — বিল বাতিল হয়নি।');
        $this->assertSame($august, $this->august(), '⛔ খোলা আগস্টেও উল্টো সারি বিলের মাসে বসেছে।');
        $this->assertEverythingNewIsDatedOctober($last, $sale, $challan);
    }

    /**
     * ⭐ সই, কারণ আর অডিট আগের মতোই — বন্ধ মাসের বিলেও। কর্মী চাইলে প্রতিষ্ঠানের ছকে সইয়ের অপেক্ষা (খাতায় কিছু নয়),
     * সইয়ের কেন্দ্রের দরজায় মালিক সই দিলে পাকা — উল্টো সারি সইয়ের দিনে; ফাঁকা কারণ থামে; বিলের অডিটে বাতিল-ইনভয়েস আর কারণ।
     */
    public function test_the_signature_the_reason_and_the_audit_still_hold(): void
    {
        $sale = $this->augustSale();
        $challan = $this->challanOf($sale);

        Carbon::setTestNow(self::OCTOBER_DAY.' 10:00:00');
        $this->closeAugust();
        $this->signatureFlow();
        // ⓘ ছকটা বিক্রির পরে বসল — আগে গড়া ইঞ্জিন পুরনো ছক মনে রাখলে সই চাওয়াই হত না
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetInstance(SalesInvoiceCancellationService::class);
        $this->app->forgetScopedInstances();
        $clerk = $this->clerk(['sales.invoice.view', 'sales.invoice.cancellation']);

        // ⛔ কারণ ছাড়া নয় — সেবার নিজের দেয়াল (ফাঁকা জায়গাও ফাঁকা)
        try {
            app(SalesInvoiceCancellationService::class)->request($sale, $this->owner, '   ');
            $this->fail('⛔ কারণ ছাড়াই বাতিল-ইনভয়েস হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertSame(__('sales::cancellation.reason_required'), $e->errors()['reason'][0] ?? null, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(0, SalesInvoiceCancellation::query()->count(), '⛔ কারণ ছাড়া কাগজ রয়ে গেছে।');
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status);

        $august = $this->august();
        $last = $this->lastIds();

        $this->actingAs($clerk)->post(route('sales.invoice.cancellation', $sale), ['reason' => 'দর ভুল'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $paper = SalesInvoiceCancellation::query()->where('sales_invoice_id', $sale->id)->firstOrFail();
        $this->assertSame(SalesInvoiceCancellation::AWAITING, $paper->status, '⛔ কর্মীর বাতিল সইয়ের অপেক্ষায় যায়নি।');
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status, '⛔ সইয়ের আগেই বিল উল্টে গেছে।');
        $this->assertSame($last, $this->lastIds(), '⛔ সইয়ের আগেই খাতা, মজুদ বা স্তরে সারি বসেছে।');

        $approval = app(ApprovalEngine::class)->latestFor($paper, SalesInvoiceCancellationService::APPROVAL_ACTION);
        $this->assertNotNull($approval, '⛔ সই চাওয়া হয়নি।');

        $this->actingAs($this->owner)->post(route('approval.inbox.approve', ['approval' => $approval->id]), [])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->fresh()->status, '⛔ শেষ সইয়ে বন্ধ মাসের বিলের বাতিল পাকা হয়নি।');
        $this->assertSame(DocumentStatus::CANCELLED, $sale->fresh()->status);
        $this->assertSame($august, $this->august(), '⛔ সইয়ের পথে বন্ধ আগস্টের সারি বদলেছে।');
        $this->assertEverythingNewIsDatedOctober($last, $sale, $challan);

        $audit = $sale->fresh()->auditTrail()->where('action', 'cancelled_by_cxl')->first();
        $this->assertNotNull($audit, '⛔ বিলের অডিটে বাতিল-ইনভয়েস নেই।');
        $this->assertStringContainsString('দর ভুল', (string) $audit->reason, '⛔ অডিটে বাতিলের কারণ নেই।');
        $this->assertStringContainsString($paper->document_no, (string) $audit->reason, '⛔ অডিটে বাতিল-ইনভয়েসের নম্বর নেই।');
    }

    /** ⛔ বাতিলের নিজের মাসই বন্ধ — এখনো থামে, কাগজ বা সারি কিছুই থাকে না */
    public function test_a_cancellation_whose_own_month_is_closed_is_still_refused(): void
    {
        Carbon::setTestNow(self::OCTOBER_DAY.' 10:00:00');
        $sale = $this->sell('2');
        $lock = PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2026, 'month' => 10,
            'reason' => 'অক্টোবর বন্ধ', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);
        $this->assertNotNull(app(OpenPeriod::class)->lockOn(self::OCTOBER_DAY), 'প্রস্তুতিটাই ভুল — অক্টোবর বন্ধ নয়।');
        $last = $this->lastIds();

        /*
         * ⛔ থামা মানে বাতিল-ইনভয়েসের নিজের বার্তা, হুবহু, ঠিক `reason` ঘরে — খাতার সাধারণ মাসের তালার কথা নয়। ⓘ সাধারণটা এলে
         * পাহারাটা কাজ করেনি, থামিয়েছে নিচের তালা (মজুদ বা খাতা), আর মানুষ জানত না বাতিল আজকের তারিখে বসে।
         */
        app()->setLocale('bn');
        $own = '⛔ আজকের মাস 10/2026 বন্ধ — বাতিল-ইনভয়েস বসে আজকের তারিখে; অনুমতিপ্রাপ্ত কেউ কারণ লিখে মাস খুললে তবেই।';

        try {
            app(SalesInvoiceCancellationService::class)->request($sale, $this->owner, 'ভুল বিল');
            $this->fail('⛔ বাতিলের নিজের মাস বন্ধ, তবু বাতিল-ইনভয়েস হয়ে গেল।');
        } catch (ValidationException $e) {
            $seen = json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
            $all = collect($e->errors())->flatten()->all();

            foreach (['bn', 'en'] as $locale) {
                $generic = (string) __('core.period.month_closed', ['month' => $lock->label(), 'reason' => $lock->reason], $locale);
                $this->assertNotContains($generic, $all, '⛔ পাহারা নয়, খাতার সাধারণ মাসের তালা থামাল: '.$seen);
            }

            $this->assertSame([$own], $e->errors()['reason'] ?? null, '⛔ `reason` ঘরে বাতিলের নিজের মাসের বার্তা হুবহু নেই: '.$seen);
        }

        $this->assertSame($own, (string) __('sales::cancellation.month_closed', ['month' => '10/2026'], 'bn'), '⛔ ভাষার ফাইলের বার্তা বদলেছে।');

        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status);
        $this->assertSame(0, SalesInvoiceCancellation::query()->count(), '⛔ থামার পরেও কাগজ রয়ে গেছে।');
        $this->assertSame($last, $this->lastIds(), '⛔ থামার পরেও খাতা, মজুদ বা স্তরে সারি রয়ে গেছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @param  array<string, int>  $last */
    private function assertEverythingNewIsDatedOctober(array $last, SalesInvoice $sale, DeliveryChallan $challan): void
    {
        $new = [];

        foreach (self::TABLES as $table) {
            $new[$table] = DB::table($table)->where('id', '>', $last[$table])->orderBy('id')->get();
            $dates = $new[$table]->map(fn ($r) => substr((string) $r->trx_date, 0, 10))->unique()->values()->all();

            if ($new[$table]->isNotEmpty()) {
                $this->assertSame([self::OCTOBER_DAY], $dates, "⛔ {$table}-এ বাতিলের নতুন সারি বাতিলের দিনের নয়।");
            }
        }

        $invoiceType = SalesInvoice::drillSourceType();
        $this->assertTrue($new['ledger_entries']->contains(fn ($r) => $r->source_type === $invoiceType.':reversal' && (int) $r->source_id === (int) $sale->id),
            '⛔ বিলের টাকার উল্টো সারি নেই।');
        $this->assertTrue($new['inv_stock_movements']->contains(fn ($r) => $r->source_type === DeliveryChallan::STOCK_SOURCE.':cancel' && (int) $r->source_id === (int) $challan->id),
            '⛔ চালানের মাল ফেরার সারি নেই।');
        $this->assertTrue($new['inv_cost_layer_uses']->contains(fn ($r) => $r->source_type === SalesInvoice::STOCK_SOURCE.':cancel' && (int) $r->source_id === (int) $sale->id),
            '⛔ খরচের স্তরে উল্টো টান নেই।');
    }

    /** @return array<string, list<array<string, mixed>>> আগস্টের প্রতিটা সারি, সব ঘরসহ */
    private function august(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $t) => [$t => DB::table($t)
            ->whereBetween('trx_date', ['2026-08-01', '2026-08-31'])
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    /** @return array<string, int> */
    private function lastIds(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $t) => [$t => (int) DB::table($t)->max('id')])->all();
    }

    private function augustSale(): SalesInvoice
    {
        Carbon::setTestNow('2026-08-10 10:00:00');

        return $this->sell('2');
    }

    private function closeAugust(): void
    {
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2026, 'month' => 8,
            'reason' => 'আগস্টের হিসাব ছাপা', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);
    }

    private function sell(string $qty): SalesInvoice
    {
        $this->actingAs($this->owner);

        return app(DirectSaleService::class)->complete($this->data, [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']])['invoice']->fresh();
    }

    private function challanOf(SalesInvoice $invoice): DeliveryChallan
    {
        return $invoice->lines()->firstOrFail()->challanLine->challan;
    }

    /** প্রতিষ্ঠানের বসানো সইয়ের ছক — এক ধাপ, সুপার অ্যাডমিন */
    private function signatureFlow(): void
    {
        $owner = Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'sales', 'action' => SalesInvoiceCancellationService::APPROVAL_ACTION,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'owner',
            'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => (int) $owner->id,
        ]);
    }

    /** @param  list<string>  $keys */
    private function clerk(array $keys): User
    {
        $user = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        CompanyContext::forCompany((int) $this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function onHand(): string
    {
        return (string) DB::table('inv_stock_movements')
            ->where('product_id', $this->biscuit->id)->where('warehouse_id', $this->warehouse->id)->sum('floor_change');
    }

    private function inLayers(): string
    {
        return (string) DB::table('inv_cost_layers')->where('product_id', $this->biscuit->id)->sum('qty_remaining');
    }

    /** ক্রেতার পুরো খাতা — সব কাগজ মিলে */
    private function customerDue(): string
    {
        return (string) DB::table('ledger_entries')->where('party_type', 'customer')->where('party_id', $this->data['customer_id'])
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }

    private function receivableOf(SalesInvoice $invoice): string
    {
        $type = SalesInvoice::drillSourceType();

        return (string) DB::table('ledger_entries')->whereIn('source_type', [$type, $type.':reversal'])
            ->where('source_id', $invoice->id)->where('party_type', 'customer')
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }
}
