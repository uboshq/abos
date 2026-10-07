<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PaymentProposalService;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মেয়াদি বিল একটা একটা করে পরিশোধ হত — মালিকের টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা, ধাপ খ ১১ (Payment Proposal), ৭ অক্টোবর ২০২৬।
 *
 * ⭐ হিসাবরক্ষক বিল আর অঙ্ক বাছেন — এক চাপে প্রতিটা সরবরাহকারীর একটা খসড়া পরিশোধ, বিলে ভাগসহ ([[PaymentProposalService]])।
 * খাতায় কিছুই নয়; অনুমোদন আর টাকা দেওয়া আজকের পথে।
 */
final class TheDueBillsWerePaidOneAtATimeTest extends TestCase
{
    use \Tests\Concerns\PutsMoneyInTheTill;
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
    }

    public function test_one_press_makes_one_draft_payment_per_supplier_with_its_bills(): void
    {
        [$s1, $s2] = Supplier::query()->orderBy('id')->take(2)->get()->all();
        $a1 = $this->bill($s1, '10');
        $a2 = $this->bill($s1, '5');
        $b1 = $this->bill($s2, '4');
        $entries = DB::table('ledger_entries')->count();

        $made = app(PaymentProposalService::class)->propose([$a1->id => '600', $a2->id => '100', $b1->id => '240'], $this->till());

        $this->assertCount(2, $made, '⛔ সরবরাহকারী প্রতি একটা পরিশোধ নয়।');
        $first = collect($made)->firstWhere('supplier_id', $s1->id);
        $this->assertSame(DocumentStatus::DRAFT, $first->status);
        $this->assertSame(0, bccomp((string) $first->amount, '700', 4), '⛔ প্রথম সরবরাহকারীর মোট ৭০০ নয়।');
        $this->assertSame([$a1->id => '600.0000', $a2->id => '100.0000'],
            $first->lines->mapWithKeys(fn ($l) => [(int) $l->purchase_bill_id => (string) $l->amount])->sortKeys()->all(), '⛔ বিলে ভাগ ভুল।');
        $this->assertSame($entries, DB::table('ledger_entries')->count(), '⛔ প্রস্তাবেই খাতায় দাখিলা বসল।');

        // ⓘ টাকা দেওয়া আজকের পথে — খসড়া নিশ্চিত হলে বিলের বাকি কমে
        $this->putMoneyIn(\App\Modules\Accounts\Models\Account::query()->findOrFail($this->till()), '5000');
        app(PaymentService::class)->confirm($first->fresh());
        $this->assertSame(0, bccomp($a1->fresh()->dueAmount(), '0', 4));
        $this->assertSame(0, bccomp($a2->fresh()->dueAmount(), '200', 4));
    }

    public function test_more_than_the_due_or_a_cancelled_bill_makes_nothing(): void
    {
        $s = Supplier::query()->orderBy('id')->firstOrFail();
        $open = $this->bill($s, '10');
        $gone = $this->bill($s, '2');
        app(PurchaseBillService::class)->cancel($gone->fresh(), 'ভুল');

        $this->refused(fn () => app(PaymentProposalService::class)->propose([$open->id => '601'], $this->till()));
        $this->refused(fn () => app(PaymentProposalService::class)->propose([$open->id => '100', $gone->id => '50'], $this->till()));
        $this->refused(fn () => app(PaymentProposalService::class)->propose([$open->id => '0'], $this->till()));

        $this->assertSame(0, Payment::query()->where('supplier_id', $s->id)->count(), '⛔ ভুল সারি থাকা সত্ত্বেও খসড়া তৈরি হলো।');
    }

    public function test_the_schedule_screen_makes_the_proposal_and_leaves_out_bills_paid_at_the_counter(): void
    {
        $s = Supplier::query()->orderBy('id')->firstOrFail();
        $open = $this->bill($s, '3');

        // ⓘ কাউন্টারে ভাউচারে পুরো শোধ — বাকি তালিকায় আর নয়
        $this->putMoneyIn(\App\Modules\Accounts\Models\Account::query()->findOrFail($this->till()), '1000');
        $paid = app(DirectPurchaseService::class)->complete([
            'supplier_id' => $s->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'PP-PAID-1',
            'deposits' => [['payment_method_id' => (int) \App\Modules\MasterData\Models\PaymentMethod::query()->where('code', 'CASH')->value('id'),
                'account_id' => $this->till(), 'amount' => '120', 'reference' => null, 'ref_date' => now()->toDateString()]],
        ], [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '60', 'sales_price' => '60', 'tax' => '0']])['bill'];

        $page = $this->get(route('purchase.payment_schedule.index'))->assertOk();
        $page->assertSee('data-payment-proposal', false)->assertSee('name="picks['.$open->id.']"', false);
        $page->assertDontSee('name="picks['.$paid->id.']"', false);

        $this->post(route('purchase.payment_schedule.propose'), ['picks' => [$open->id => '180'], 'account_id' => $this->till()])
            ->assertSessionHasNoErrors();
        $draft = Payment::query()->where('supplier_id', $s->id)->where('status', DocumentStatus::DRAFT)->firstOrFail();

        // ⭐ প্রস্তাব নিজেই নম্বরধারী কাগজ (PP-…): তার পাতা, আর পরিশোধের তালিকায় তাকে ধরে ছাঁকা
        $this->assertStringStartsWith('PP', (string) $draft->proposal_no, '⛔ প্রস্তাবের নিজের নম্বর নেই।');
        $this->assertStringContainsString((string) $draft->proposal_no, (string) $draft->narration);
        $this->get(route('purchase.payment_schedule.proposal', ['no' => $draft->proposal_no]))->assertOk()
            ->assertSee('data-payment-proposal-page', false)->assertSee($draft->document_no)
            ->assertSee('data-proposal-state="draft"', false);
        $other = app(PaymentService::class)->create(['supplier_id' => $s->id, 'account_id' => $this->till(), 'trx_date' => now()->toDateString(), 'amount' => '10'],
            [['purchase_bill_id' => $open->id, 'amount' => '10']]);
        $this->get(route('purchase.payment.index', ['proposal' => $draft->proposal_no]))->assertOk()
            ->assertSee($draft->document_no)->assertDontSee($other->document_no);
        $this->get(route('purchase.payment_schedule.proposal', ['no' => 'PP-NO-SUCH']))->assertNotFound();

        // ⓘ পরিশোধ লেখার চাবি নেই — ঘরও নেই, দরজাও বন্ধ
        $viewer = User::factory()->create(['is_active' => true, 'current_company_id' => CompanyContext::id()]);
        $viewer->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $viewer->givePermissionTo('purchase.payment.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($viewer)->get(route('purchase.payment_schedule.index'))->assertOk()->assertDontSee('data-payment-proposal', false);
        $this->actingAs($viewer)->post(route('purchase.payment_schedule.propose'), ['picks' => [$open->id => '1'], 'account_id' => $this->till()])->assertForbidden();
    }

    private function refused(\Closure $act): void
    {
        try {
            $act();
            $this->fail('⛔ প্রস্তাবটা থামেনি।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bills', $e->errors());
        }
    }

    private function bill(Supplier $supplier, string $qty): PurchaseBill
    {
        return app(DirectPurchaseService::class)->complete([
            'supplier_id' => $supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'PP-'.fake()->unique()->numberBetween(10000, 99999),
        ], [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '60', 'sales_price' => '60', 'tax' => '0']])['bill']->fresh();
    }

    private function till(): int
    {
        return (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
    }
}
