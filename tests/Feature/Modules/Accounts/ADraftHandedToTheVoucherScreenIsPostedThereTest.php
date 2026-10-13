<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use App\Modules\Customer\Models\Customer;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\FarePayment;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ যে মডিউলের অনুমোদিত কাগজ নিজের খসড়া ভাউচার ভাউচারের পর্দায় পাকা হতে পাঠায়, সেটা সেখানে পাকা হয় — চিরকাল আটকে থাকে না
 * (fe, ১৪ অক্টোবর ২০২৬; [[VoucherService::HANDED_TO_THE_VOUCHER_SCREEN]])।
 *
 * ⓘ "অন্য কাগজের ভাউচার হাতে নয়" পাহারা ([[AnotherPapersVoucherIsNotTouchedHereTest]]) প্রথমে সব `…voucher_id`-ওয়ালা টেবিল ধরত। কর্মীর
 * খরচের দাবি শেষ সইয়ে খসড়া পরিশোধ বানায় আর ক্যাশিয়ার সেটা ভাউচারের পর্দা থেকে পাকা করেন; Hr-এর নিজের পাকা করার পর্দা নেই।
 * ভাড়াও হাতে লেখা ভাউচারের পথে যায় — লেখক নিজে হলে অন্য কেউ পর্দা থেকে, সই লাগলে শেষ সইয়ে (দুটোই [[VoucherWriter::post()]])।
 * পাহারা এদের ধরলে দাবি আর ভাড়া কোনোদিন "দেওয়া" হত না। ⓘ অর্থের কাগজ নিজের সইয়ে নিজে পাকা করে (FinanceSignature), তাই পাহারায় থাকে।
 */
final class ADraftHandedToTheVoucherScreenIsPostedThereTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_an_expense_claims_draft_is_paid_by_the_cashier_on_the_voucher_screen(): void
    {
        $worker = $this->member('worker@handed.test', ['hr.claim.self']);
        $cashier = $this->member('cashier@handed.test', ['hr.claim.self']);
        $employee = app(EmployeeService::class)->create(['code' => 'EMP-HND', 'name_en' => 'Field Worker', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $employee->forceFill(['user_id' => $worker->id])->save();

        $till = app(CashTillService::class)->ensurePrimaryTill();
        $till->forceFill(['holder_id' => $cashier->id])->save();
        $this->putMoneyIn($till->account, '100000', now()->subDay()->toDateString());
        $expense = Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();

        $this->actingAs($worker);
        $claim = app(ExpenseClaimService::class)->submit($worker, ['kind' => ExpenseClaim::EXPENSE, 'amount' => '1200', 'reason' => 'Bus fare',
            'spent_on' => now()->toDateString(), 'expense_account_id' => $expense->id]);
        $this->actingAs($this->owner);
        app(ApprovalEngine::class)->approve(Approval::query()->where('approvable_type', $claim->getMorphClass())
            ->where('approvable_id', $claim->id)->where('status', Approval::PENDING)->sole(), $this->owner);

        $voucher = $claim->fresh()->paymentVoucher;
        $this->assertNotNull($voucher, 'দৃশ্যটাই বানানো যায়নি — শেষ সইয়ে খসড়া পরিশোধ হয়নি');
        $this->assertTrue($voucher->isDraft());

        // ⓘ ক্যাশিয়ার খসড়া বদলাতে পারেন (নিজের টিল বসাতে) — অন্য কারণে থামলেও "অন্য কাগজের" বলে নয়
        $this->actingAs($cashier);
        $this->assertNotOwnedElsewhere($voucher, fn () => app(VoucherService::class)->update($voucher->fresh(), ['narration' => 'নিজের টিল থেকে'],
            $voucher->lines->map(fn ($l) => ['account_id' => (int) $l->account_id, 'debit' => (string) $l->debit, 'credit' => (string) $l->credit,
                'party_type' => $l->party_type, 'party_id' => $l->party_id])->all(), byHand: true));

        $this->postOnTheScreen($voucher);

        $this->assertTrue($voucher->fresh()->isPosted(), '⛔ খরচের দাবির খসড়া ভাউচারের পর্দা থেকে পাকা হল না — দাবি চিরকাল আটকে');
        $this->assertSame(ExpenseClaim::PAID, $claim->fresh()->status);
    }

    public function test_a_due_fare_drafted_by_a_clerk_is_posted_by_someone_else_on_the_voucher_screen(): void
    {
        $till = Account::query()->findOrFail(app(CashTillService::class)->create(['code' => 'HND-F', 'name_en' => 'Fare till'])->account_id);
        $this->putMoneyIn($till, '2000');
        $carrier = Supplier::query()->create(['code' => 'TR-HND', 'name_en' => 'Handed Transport',
            'party_type_id' => PartyType::query()->where('code', 'TRANSPORT')->firstOrFail()->id]);

        $challan = app(DeliveryChallanService::class)->create(
            ['customer_id' => Customer::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );
        $challan = app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
        $this->post(route('sales.challan.fare.record', $challan), ['fare_paid_by' => 'us', 'transport_cost' => '300',
            'fare_when' => 'later', 'carrier_id' => $carrier->id])->assertSessionHasNoErrors();

        // ⓘ কর্মী দেন — লেখক ≠ পাকাকারী, তাই খসড়া অন্যের অপেক্ষায়
        $clerk = $this->member('clerk@handed.test', ['sales.challan.create', 'sales.challan.view', 'accounts.voucher.create']);
        $this->actingAs($clerk);
        $this->post(route('sales.challan.fare.pay', $challan), ['fare_account_id' => $till->id])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->findOrFail($challan->fresh()->fare_voucher_id);
        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, 'দৃশ্যটাই বানানো যায়নি — কর্মীর ভাড়ার PV খসড়া নয়');

        $this->actingAs($this->owner);
        $this->postOnTheScreen($voucher);

        $this->assertTrue($voucher->fresh()->isPosted(), '⛔ ভাড়ার খসড়া ভাউচারের পর্দা থেকে পাকা হল না — ভাড়া চিরকাল আটকে');
        $this->assertFalse(app(FarePayment::class)->isDue($challan->fresh()));
    }

    /** ভাউচারের পর্দার দরজা ([[VoucherWriter::post()]]); সই লাগলে মালিক সই করেন — শেষ সইয়ের শ্রোতাও একই দরজায় পাকা করে */
    private function postOnTheScreen(Voucher $voucher): void
    {
        $held = app(VoucherWriter::class)->post($voucher->fresh());

        if ($held !== null) {
            app(ApprovalEngine::class)->approve($held->fresh(), $this->owner);
        }
    }

    private function assertNotOwnedElsewhere(Voucher $voucher, callable $what): void
    {
        $refusal = __('accounts::revision.owned_elsewhere', ['no' => $voucher->document_no, 'module' => __('accounts::revision.module_hr')]);

        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertNotContains($refusal, Arr::flatten($e->errors()), '⛔ খরচের দাবির খসড়া "অন্য কাগজের" বলে ভাউচারের পর্দায় বদলানো গেল না');
        }
    }

    /** @param  list<string>  $keys */
    private function member(string $email, array $keys): User
    {
        $user = User::factory()->create(['email' => $email, 'current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(array_map(fn ($k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
