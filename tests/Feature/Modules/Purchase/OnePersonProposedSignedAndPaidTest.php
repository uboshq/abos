<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PaymentProposalService;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একজন মানুষই প্রস্তাব করতেন, সই দিতেন, টাকা দিতেন — মালিকের টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা, ধাপ খ ১২, ৭ অক্টোবর ২০২৬।
 *
 * ⭐ সুইচ `purchase.payment_three_hands` চালু থাকলে প্রস্তাবক ≠ সইদাতা ≠ টাকাদাতা ([[PaymentService::assertThreeHands()]])।
 * মালিক একা করলে আটকায় না, নিরীক্ষায় দাগ পড়ে; সুইচ বন্ধে আজকের আচরণ।
 */
final class OnePersonProposedSignedAndPaidTest extends TestCase
{
    use \Tests\Concerns\PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $proposer;

    private User $signer;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        $this->putMoneyIn(Account::query()->findOrFail($this->till()), '100000');

        $this->proposer = $this->member();
        $this->signer = $this->member();
        $this->cashier = $this->member();
    }

    public function test_the_proposer_cannot_pay_their_own_proposal(): void
    {
        $draft = $this->propose();

        $this->refused(fn () => $this->confirmAs($this->proposer, $draft), 'three_hands_proposer_pays');
        $this->assertSame(DocumentStatus::CONFIRMED, $this->confirmAs($this->cashier, $draft)->status, '⛔ তৃতীয় মানুষও টাকা দিতে পারলেন না।');
    }

    public function test_the_signer_cannot_pay_and_the_proposer_cannot_sign(): void
    {
        $this->flow($this->signer);
        $draft = $this->propose();

        $this->held(fn () => $this->confirmAs($this->cashier, $draft));
        app(ApprovalEngine::class)->approve($this->pending($draft), $this->signer, 'ঠিক আছে');

        $this->refused(fn () => $this->confirmAs($this->signer, $draft), 'three_hands_signer_pays');
        $this->assertSame(DocumentStatus::CONFIRMED, $this->confirmAs($this->cashier, $draft)->status);
    }

    /** ⓘ প্রস্তাবক নিজেই সইদাতার ধাপে — তাঁর সই থাকলে কেউই টাকা দিতে পারেন না */
    public function test_a_proposal_signed_by_its_own_proposer_cannot_be_paid(): void
    {
        $this->flow($this->proposer);
        $draft = $this->propose();

        $this->held(fn () => $this->confirmAs($this->cashier, $draft));
        app(ApprovalEngine::class)->approve($this->pending($draft), $this->proposer, 'নিজের');

        $this->refused(fn () => $this->confirmAs($this->cashier, $draft), 'three_hands_proposer_signed');
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status);
    }

    public function test_with_the_switch_off_one_person_may_do_it_all(): void
    {
        app(SettingsService::class)->set('purchase.payment_three_hands', false);
        $draft = $this->propose();

        $this->assertSame(DocumentStatus::CONFIRMED, $this->confirmAs($this->proposer, $draft)->status);
    }

    public function test_the_owner_alone_goes_through_and_is_marked(): void
    {
        $this->actingAs($this->owner);
        $made = app(PaymentProposalService::class)->propose([$this->bill()->id => '100'], $this->till());

        $paid = $this->confirmAs($this->owner, $made[0]);

        $this->assertSame(DocumentStatus::CONFIRMED, $paid->status, '⛔ মালিক একা হলে আটকে গেল।');
        $this->assertTrue(DB::table('audit_trails')->where('action', 'three_hands_override')->where('auditable_id', $paid->id)->exists(),
            '⛔ মালিক একা করলেন, অথচ নিরীক্ষায় দাগ নেই।');
    }

    public function test_the_migration_switches_it_off_in_every_company_that_exists_and_adds_the_proposal_number(): void
    {
        DB::table('settings')->where('key', 'purchase.payment_three_hands')->delete();
        $ids = DB::table('companies')->pluck('id');

        (require base_path('app/Modules/Purchase/Database/Migrations/2027_02_17_110000_three_hands_paid_one_supplier.php'))->up();

        $rows = DB::table('settings')->where('key', 'purchase.payment_three_hands')->pluck('value', 'company_id');
        $this->assertCount($ids->count(), $rows, '⛔ সব কোম্পানিতে সারি বসেনি।');
        $this->assertSame(['0'], $rows->map(fn ($v) => (string) $v)->unique()->values()->all(), '⛔ আজকের কোম্পানিতে বন্ধ লেখা হয়নি।');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('pur_payments', 'proposal_no'));
    }

    private function propose(): Payment
    {
        $bill = $this->bill();
        $this->actingAs($this->proposer);
        $made = app(PaymentProposalService::class)->propose([$bill->id => '100'], $this->till());
        $this->actingAs($this->owner);

        return $made[0];
    }

    private function confirmAs(User $user, Payment $payment): Payment
    {
        $this->actingAs($user);

        try {
            return app(PaymentService::class)->confirm($payment->fresh())->fresh();
        } finally {
            $this->actingAs($this->owner);
        }
    }

    private function refused(\Closure $act, string $key): void
    {
        try {
            $act();
            $this->fail('⛔ তিন হাতের নিয়ম থামায়নি ('.$key.')।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(__('purchase::validation.'.$key, ['no' => '']), implode(' ', $e->validator->errors()->all()).' ');
        }
    }

    private function held(\Closure $act): void
    {
        try {
            $act();
            $this->fail('⛔ ছক থাকা সত্ত্বেও সই ছাড়াই টাকা গেল।');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    private function pending(Payment $payment): Approval
    {
        return Approval::query()->where('approvable_type', Payment::class)->where('approvable_id', $payment->id)
            ->where('status', Approval::PENDING)->firstOrFail();
    }

    private function flow(User $approver): void
    {
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'purchase', 'action' => 'payment',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'signer',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $approver->id]);
    }

    private function bill()
    {
        $this->actingAs($this->owner);

        return app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'TH-'.fake()->unique()->numberBetween(10000, 99999),
        ], [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->value('id'),
            'qty' => '2', 'rate' => '50', 'sales_price' => '50', 'tax' => '0']])['bill']->fresh();
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }

    private function till(): int
    {
        return (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
    }
}
