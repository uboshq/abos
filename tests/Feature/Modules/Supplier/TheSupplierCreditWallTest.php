<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierCreditWall;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ সরবরাহকারীর বাকির দেয়াল — মালিক, ১৮ নভেম্বর ২০২৬ ([[SupplierCreditWall]])।
 *
 *   · সীমার ভিতরে মাল গ্রহণ, বিল, সরাসরি ক্রয় — নিশ্চিত হয়।
 *   · সীমা পেরোলে নিশ্চিত হয় না — খসড়া থেকে যায়, খাতায় কিছু বসে না, বার্তায় সীমা · বাকি · অবশিষ্ট।
 *   · ফাঁকা/০ সীমা = দেয়াল নেই, যত বড় বাকিই হোক।
 *   · সরাসরি ক্রয়ে এখনই দেওয়া টাকা বাদ — নগদে কেনা চলে।
 *   · চালান থেকে আসা বিল মূল্যটা GRNI থেকে দেনায় সরায় মাত্র — দেয়াল আবার গোনে না।
 *   · খসড়া লেখা থামে না।
 *   · তালিকায় সীমা · বাকি · অবশিষ্ট, আর "সীমা পেরোনো / কাছে" ছাঁকনি।
 *
 * ⓘ চালানো হয়নি — এই পিসিতে পরীক্ষা নিষেধ (মালিক); ম্যাকে চলবে।
 */
final class TheSupplierCreditWallTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();

        // ⓘ পুরনো দেনা ২,০০০ — "জায়গা ০" মানে সীমা = বাকি > ০, সীমাহীন (০) নয়
        $leaf = Account::query()->postable()->whereKey(StandardChart::find(StandardChart::PAYABLE_GROUP)->selfAndDescendants()->pluck('id'))
            ->orderBy('code')->firstOrFail();
        app(PostingEngine::class)->post(sourceType: 'test:wall-opening', sourceId: 1, trxDate: now()->toDateString(), lines: [
            ['account_id' => StandardChart::find(StandardChart::HAMMALI)->id, 'debit' => '2000'],
            ['account_id' => $leaf->id, 'credit' => '2000', 'party_type' => 'supplier', 'party_id' => $this->supplier->id],
        ], branchId: $this->supplier->branch_id ?? $this->company->defaultBranch()?->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /** সীমা = এখনকার বাকি + `$room` — জায়গা ঠিক `$room` টাকা */
    private function roomOf(string $room): void
    {
        $used = app(SupplierCreditWall::class)->used($this->supplier->fresh());
        $this->supplier->forceFill(['credit_limit' => bcadd($used, $room, 4)])->save();
        $this->supplier = $this->supplier->fresh();
    }

    private function receipt(string $qty, string $rate): PurchaseReceipt
    {
        return app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'received_qty' => $qty, 'rate' => $rate]],
        );
    }

    /** @return array<string, mixed> */
    private function directHeader(array $extra = []): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'WALL-'.Str::upper(Str::random(8)),
            ...$extra,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function directLines(string $qty, string $rate): array
    {
        return [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => $qty, 'rate' => $rate, 'sales_price' => $rate]];
    }

    public function test_a_receipt_within_the_limit_is_confirmed(): void
    {
        $this->roomOf('1000');

        $confirmed = app(PurchaseReceiptService::class)->confirm($this->receipt('10', '100'));

        $this->assertSame(DocumentStatus::CONFIRMED, $confirmed->status, '⛔ ঠিক সীমা পর্যন্ত মাল গ্রহণ থেমে গেল।');
    }

    public function test_a_receipt_past_the_limit_is_refused_with_the_figures_and_stays_a_draft(): void
    {
        $this->roomOf('500');
        $draft = $this->receipt('10', '100');

        try {
            app(PurchaseReceiptService::class)->confirm($draft);
            $this->fail('⛔ সীমা পেরোনো মাল গ্রহণ নিশ্চিত হয়ে গেল।');
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors()['supplier_id'] ?? [])->first();
            $this->assertNotSame('', $message, '⛔ দেয়ালের বার্তা সরবরাহকারীর ঘরে নেই।');
            $this->assertStringContainsString('500.00', $message, '⛔ বার্তায় অবশিষ্ট সীমা নেই।');
            $this->assertStringContainsString('1,000.00', $message, '⛔ বার্তায় এই কাগজের নতুন বাকি নেই।');
        }

        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status, '⛔ থামা মাল গ্রহণ খসড়া থাকেনি।');
        $this->assertSame(0, \App\Models\LedgerEntry::query()->where('source_type', PurchaseReceipt::drillSourceType())
            ->where('source_id', $draft->id)->count(), '⛔ থামা মাল গ্রহণ খাতায় বসে গেল।');
    }

    public function test_a_blank_or_zero_limit_walls_nothing(): void
    {
        $this->supplier->forceFill(['credit_limit' => 0])->save();
        $leaf = Account::query()->postable()->whereKey(StandardChart::find(StandardChart::PAYABLE_GROUP)->selfAndDescendants()->pluck('id'))
            ->orderBy('code')->firstOrFail();

        // ⓘ বিশাল পুরনো দেনা — তবু সীমা ০, তাই কিছু থামে না
        app(PostingEngine::class)->post(sourceType: 'test:wall-owed', sourceId: 1, trxDate: now()->toDateString(), lines: [
            ['account_id' => StandardChart::find(StandardChart::HAMMALI)->id, 'debit' => '9999999'],
            ['account_id' => $leaf->id, 'credit' => '9999999', 'party_type' => 'supplier', 'party_id' => $this->supplier->id],
        ], branchId: $this->supplier->branch_id ?? $this->company->defaultBranch()?->id);

        $confirmed = app(PurchaseReceiptService::class)->confirm($this->receipt('10', '100'));
        $this->assertSame(DocumentStatus::CONFIRMED, $confirmed->status, '⛔ ০ সীমার সরবরাহকারীর মাল গ্রহণ থামল — লাইভের কেনা বন্ধ হত।');

        $result = app(DirectPurchaseService::class)->complete($this->directHeader(), $this->directLines('5', '100'));
        $this->assertSame(DocumentStatus::CONFIRMED, $result['bill']->status);
        $this->assertNull(app(SupplierCreditWall::class)->room($this->supplier->fresh()), 'সীমা নেই মানে অবশিষ্টও নেই ("—")।');
    }

    public function test_a_direct_purchase_on_credit_past_the_limit_leaves_nothing_behind(): void
    {
        $this->roomOf('300');
        $bills = PurchaseBill::query()->withTrashed()->count();

        try {
            app(DirectPurchaseService::class)->complete($this->directHeader(), $this->directLines('5', '100'));
            $this->fail('⛔ সীমা পেরোনো বাকির সরাসরি ক্রয় নিশ্চিত হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supplier_id', $e->errors());
        }

        $this->assertSame($bills, PurchaseBill::query()->withTrashed()->count(), '⛔ থামা সরাসরি ক্রয় একটা বিল রেখে গেল।');
    }

    public function test_a_direct_purchase_paid_now_counts_the_cash_and_goes_through(): void
    {
        $this->roomOf('300');
        $till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        $this->putMoneyIn($till, '1000');

        // ⓘ ৫০০-এর মাল, ৩০০ জায়গা, এখনই ২০০ নগদ — নতুন বাকি ৩০০, ঠিক সীমায়
        $result = app(DirectPurchaseService::class)->complete(
            $this->directHeader(['paid_now' => '200', 'paid_from_account_id' => $till->id]),
            $this->directLines('5', '100'),
        );

        $this->assertSame(DocumentStatus::CONFIRMED, $result['bill']->status, '⛔ এখনই দেওয়া টাকা দেয়াল গোনেনি।');
    }

    public function test_a_bill_from_a_receipt_is_not_counted_twice(): void
    {
        $this->roomOf('1000');

        // ⓘ মাল গ্রহণ জায়গাটা পুরো খায়; তার বিল কেবল GRNI থেকে দেনায় সরায় — থামার কথা নয়
        $result = app(PurchaseReceiptService::class)->confirmAndBill($this->receipt('10', '100'));

        $this->assertSame(DocumentStatus::CONFIRMED, $result['receipt']->status);
        $this->assertNotNull($result['bill']);
        $this->assertNull($result['problem'], '⛔ চালানের বিল দেয়ালে থামল — একই মাল দুইবার গোনা হলো: '.$result['problem']);
        $this->assertSame(DocumentStatus::CONFIRMED, $result['bill']->status);
        $this->assertSame(0, bccomp(app(PurchaseBillService::class)->newCreditOf($result['bill']->fresh()), '0', 4),
            'চালানের দরে বিল — নতুন বাকি শূন্য।');
    }

    public function test_a_draft_is_still_written_past_the_limit(): void
    {
        $this->roomOf('0');

        $draft = $this->receipt('10', '100');

        $this->assertSame(DocumentStatus::DRAFT, $draft->status, '⛔ দেয়াল খসড়া লেখাও থামাল — থামার কথা কেবল নিশ্চিতে।');
    }

    public function test_paying_the_supplier_opens_the_wall_again(): void
    {
        $this->roomOf('0');
        $draft = $this->receipt('10', '100');

        try {
            app(PurchaseReceiptService::class)->confirm($draft);
            $this->fail('⛔ জায়গা শূন্য, তবু নিশ্চিত হলো।');
        } catch (ValidationException) {
        }

        // ⓘ ১,০০০ টাকা দেওয়া হলো — দেনা কমল, জায়গা হলো
        $leaf = Account::query()->postable()->whereKey(StandardChart::find(StandardChart::PAYABLE_GROUP)->selfAndDescendants()->pluck('id'))
            ->orderBy('code')->firstOrFail();
        app(PostingEngine::class)->post(sourceType: 'test:wall-paid', sourceId: 1, trxDate: now()->toDateString(), lines: [
            ['account_id' => $leaf->id, 'debit' => '1000', 'party_type' => 'supplier', 'party_id' => $this->supplier->id],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '1000'],
        ], branchId: $this->supplier->branch_id ?? $this->company->defaultBranch()?->id);

        $this->assertSame(DocumentStatus::CONFIRMED, app(PurchaseReceiptService::class)->confirm($draft->fresh())->status,
            '⛔ টাকা দেওয়ার পরেও দেয়াল খুলল না।');
    }

    public function test_the_list_shows_limit_owed_and_room_and_filters_over_and_near(): void
    {
        $this->roomOf('0');

        $page = $this->get(route('supplier.index', ['credit' => 'over']))->assertOk();
        $row = $page->viewData('suppliers')->getCollection()->firstWhere('id', $this->supplier->id);

        $this->assertNotNull($row, '⛔ সীমা পেরোনো সরবরাহকারী "সীমা পেরোনো" ছাঁকনিতে নেই।');
        $this->assertSame(0, bccomp((string) $row->credit_used, app(SupplierCreditWall::class)->used($this->supplier, true), 4),
            '⛔ তালিকার বাকি দেয়ালের অঙ্ক নয়।');
        $page->assertSee(__('supplier::credit_request.limit'))->assertSee(__('supplier::credit_request.used'))
            ->assertSee(__('supplier::credit_request.room'))->assertSee('data-credit-state="over"', false);

        $this->assertNull($this->get(route('supplier.index', ['credit' => 'near']))->viewData('suppliers')
            ->getCollection()->firstWhere('id', $this->supplier->id), '⛔ সীমা পেরোনো জন "কাছে" ছাঁকনিতেও উঠল।');

        // ⓘ ৯৫% ভরা — "কাছে"-তে আছে, "পেরোনো"-তে নেই
        $used = app(SupplierCreditWall::class)->used($this->supplier->fresh(), true);
        if (bccomp($used, '0', 4) > 0) {
            $this->supplier->forceFill(['credit_limit' => bcdiv($used, '0.95', 4)])->save();
            $this->assertNotNull($this->get(route('supplier.index', ['credit' => 'near']))->viewData('suppliers')
                ->getCollection()->firstWhere('id', $this->supplier->id), '⛔ সীমার কাছের সরবরাহকারী "কাছে" ছাঁকনিতে নেই।');
            $this->assertNull($this->get(route('supplier.index', ['credit' => 'over']))->viewData('suppliers')
                ->getCollection()->firstWhere('id', $this->supplier->id));
        }

        // ⓘ সীমাহীন জন কোনো ছাঁকনিতে নয়
        $this->supplier->forceFill(['credit_limit' => 0])->save();
        $this->assertNull($this->get(route('supplier.index', ['credit' => 'over_or_near']))->viewData('suppliers')
            ->getCollection()->firstWhere('id', $this->supplier->id), '⛔ সীমাহীন সরবরাহকারী সীমার ছাঁকনিতে উঠল।');
    }
}
