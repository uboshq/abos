<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\SignedButNotPosted;
use App\Core\Engines\Approval\SignedPaperFinisher;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ তিন হাতের নিয়মে সই-হওয়া পরিশোধ তৃতীয় হাতের অপেক্ষায় থাকে — "আটকে গেছে" নয় (হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে শেষ সইয়ে শ্রোতা ([[ConfirmThePurchasePaperOnTheLastSignature]]) পরিশোধটা প্রস্তাবকের নামে নিজে পাকা করতে যেত — তিন হাতের
 * নিয়মে প্রস্তাবক নিজে টাকা দিতে পারেন না, তাই প্রতিবারই "সই হয়েছে, পোস্ট হয়নি — কারণ: প্রস্তাবক নিজে টাকা দিতে পারেন না"
 * খবর যেত লেখক আর সইকারীর কাছে, যেন কিছু ভেঙেছে। ⓘ আসলে এটাই নিয়ম: টাকা দেবেন তৃতীয় কেউ (ক্যাশিয়ার)।
 */
final class ASignedPaymentWaitsForTheThirdHandTest extends TestCase
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
        app(SettingsService::class)->set('purchase.payment_three_hands', true);

        $this->proposer = $this->member();
        $this->signer = $this->member();
        $this->cashier = $this->member();

        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'purchase', 'action' => 'payment',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'signer',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id]);
    }

    public function test_the_signature_leaves_it_for_the_third_hand_without_a_stuck_notice(): void
    {
        $draft = $this->propose();

        try {
            $this->confirmAs($this->cashier, $draft);
            $this->fail('প্রস্তুতি — সইয়ের জন্য থামার কথা');
        } catch (ValidationException) {
        }

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve(Approval::query()->where('approvable_type', Payment::class)->where('approvable_id', $draft->id)
            ->where('status', Approval::PENDING)->firstOrFail(), $this->signer, 'ঠিক আছে');

        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status, '⛔ তিন হাতের নিয়মে সইয়ের সাথে সাথেই টাকা বেরোল।');
        $this->assertFalse(DB::table('notifications')->where('type', SignedPaperFinisher::NOTICE)->exists(),
            '⛔ নিয়ম মেনে তৃতীয় হাতের অপেক্ষা — অথচ লেখক আর সইকারী "আটকে গেছে" খবর পেলেন।');

        // ⓘ তালিকায় থাকে — ক্যাশিয়ার দেখে টাকা দেন
        Cache::flush();
        $this->assertNotSame([], app(SignedButNotPosted::class)->papers($this->owner->fresh()),
            '⛔ সই-হওয়া পরিশোধ "সই হয়েছে, পোস্ট হয়নি" তালিকায় নেই — ক্যাশিয়ার খুঁজে পাবেন না।');

        $this->assertSame(DocumentStatus::CONFIRMED, $this->confirmAs($this->cashier, $draft)->status, '⛔ তৃতীয় হাতও টাকা দিতে পারলেন না।');
    }

    public function test_with_the_switch_off_the_signature_still_pays_by_itself(): void
    {
        app(SettingsService::class)->set('purchase.payment_three_hands', false);
        $draft = $this->propose();

        try {
            $this->confirmAs($this->proposer, $draft);
            $this->fail('প্রস্তুতি — সইয়ের জন্য থামার কথা');
        } catch (ValidationException) {
        }

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve(Approval::query()->where('approvable_type', Payment::class)->where('approvable_id', $draft->id)
            ->where('status', Approval::PENDING)->firstOrFail(), $this->signer, 'ঠিক আছে');

        $this->assertSame(DocumentStatus::CONFIRMED, $draft->fresh()->status, '⛔ নিয়ম বন্ধ, অথচ শেষ সইয়ে পরিশোধ নিজে পাকা হলো না।');
    }

    private function propose(): Payment
    {
        $this->actingAs($this->owner);
        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'TH3-'.fake()->unique()->numberBetween(10000, 99999),
        ], [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->value('id'),
            'qty' => '2', 'rate' => '50', 'sales_price' => '50', 'tax' => '0']])['bill']->fresh();

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
