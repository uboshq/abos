<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierBankDetail;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsTheRefusal;
use Tests\TestCase;

/**
 * ⛔ ভাউচার দিয়েও সই-না-পাওয়া সরবরাহকারী-ব্যাংকে টাকা নয় — ওয়েবে আর ফোনে (a4, ২৪ অক্টোবর ২০২৬, লাইভ 95a60587b-এ ধরা; জালিয়াতির ফাঁক)।
 *
 * ⓘ সরবরাহকারী-পরিশোধ এটা আগে থেকেই আটকাত ([[SupplierBank::assertBankPayable()]]), কিন্তু হিসাবের পরিশোধ ভাউচার কখনো ডাকত না —
 * ব্যাংক ট্রান্সফারে টাকা এমন হিসাবে যেত যার তথ্য দুই হাতে সই-পাওয়া নয় (SAP vendor master dual control-এর ঠিক উল্টো)।
 *
 * দাবি:
 *   ১. ওয়েব: সই-না-পাওয়া সরবরাহকারীকে ট্রান্সফারের পরিশোধ ভাউচার ফেরে, `payee_bank`-এ নিজের বার্তায়; কিছু পোস্ট হয় না;
 *      প্রিভিউও একই বার্তায় থামে
 *   ২. ফোন: একই খসড়া ফোনের "পোস্ট" দরজায় ৪২২, একই বার্তায়
 *   ৩. খরচের ভাউচারেও (সরবরাহকারী পক্ষ, ট্রান্সফার) একই
 *   ৪. সই-পাওয়া ব্যাংক থাকলে ট্রান্সফার চলে; নগদে সই লাগে না
 */
final class AVoucherPaysOnlyASignedSupplierBankTest extends TestCase
{
    use ReadsTheRefusal;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        // ⓘ শুরুতে সই-পাওয়া কোনো ব্যাংক তথ্য নেই — দাবি যা মাপে তার ভিত্তি
        SupplierBankDetail::query()->where('supplier_id', $this->supplier->id)->delete();
        $this->bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create(['code' => StandardChart::BANK.'-01', 'name_en' => 'Payee guard bank',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')]);
    }

    public function test_the_ground_is_there(): void
    {
        $this->assertSame(0, SupplierBankDetail::query()->where('supplier_id', $this->supplier->id)->where('status', SupplierBankDetail::SIGNED)->count(),
            'দৃশ্যটাই ঠিক নয় — সরবরাহকারীর সই-পাওয়া ব্যাংক তথ্য আছে');
    }

    /** ১ — ওয়েব */
    public function test_the_web_payment_voucher_refuses_an_unsigned_bank(): void
    {
        $before = $this->postedCount();

        $this->post(route('accounts.voucher.store', ['type' => Voucher::PAYMENT]), $this->payment('transfer', 'TT-UNSIGNED-1'));

        $this->assertContains($this->refusalOn('payee_bank'), $this->unsignedMessages(),
            '⛔ সই-না-পাওয়া সরবরাহকারী-ব্যাংকে ভাউচারের ট্রান্সফার থামল না, বা অন্য কারণে থামল: '.json_encode($this->refusalMessages(), JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, $this->postedCount(), '⛔ থামানো পরিশোধ খাতায় বসে গেল');
    }

    /** ১ক — প্রিভিউও পোস্টের একই কারণে থামে, নিজের বার্তায় (fe, ২৪ অক্টোবর) */
    public function test_the_preview_says_the_same_refusal(): void
    {
        $preview = $this->post(route('accounts.voucher.simulate'), $this->payment('transfer', 'TT-UNSIGNED-P'))->assertOk()->json();

        $this->assertSame([], $preview['rows'], '⛔ সই-না-পাওয়া ব্যাংকে ট্রান্সফারের প্রিভিউ খাতার সারি দেখায় — পোস্ট ফেরাবে');
        $this->assertContains((string) ($preview['message'] ?? ''), $this->unsignedMessages(),
            '⛔ প্রিভিউ সই-না-পাওয়া ব্যাংকের কারণ বলে না: '.json_encode($preview, JSON_UNESCAPED_UNICODE));
    }

    /** ২ — ফোন */
    public function test_the_phone_post_refuses_an_unsigned_bank_with_422(): void
    {
        $draft = app(VoucherService::class)->create(
            ['type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'ফোনের পরিশোধ',
                'party_type' => 'supplier', 'party_id' => $this->supplier->id, 'instrument' => 'transfer', 'instrument_no' => 'TT-UNSIGNED-2'],
            [['account_id' => $this->payable()->id, 'debit' => '1000', 'party_type' => 'supplier', 'party_id' => $this->supplier->id],
                ['account_id' => $this->bank->id, 'credit' => '1000']],
        );

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $response = $this->postJson('/api/v1/accounts/vouchers/'.$draft->public_id.'/post');

        $this->assertSame(422, $response->getStatusCode(), '⛔ ফোন থেকে সই-না-পাওয়া ব্যাংকে পরিশোধ ৪২২ পেল না: '.$response->getContent());
        $this->assertContains($response->json('errors.payee_bank.0'), $this->unsignedMessages(),
            '⛔ ফোনের পোস্ট থামল, কিন্তু সই-না-পাওয়া ব্যাংকের কারণে নয়: '.$response->getContent());
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status, '⛔ থামানো ফোনের পরিশোধ পাকা হয়ে গেল');
    }

    /** ৩ — খরচের ভাউচারেও */
    public function test_an_expense_voucher_paying_the_supplier_bank_refuses_too(): void
    {
        $this->post(route('accounts.voucher.store', ['type' => Voucher::EXPENSE]), [
            'type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'amount' => '700', 'narration' => 'সরবরাহকারীকে খরচ',
            'from_account_id' => $this->bank->id, 'to_account_id' => StandardChart::find(StandardChart::FUEL)->id,
            'party_type' => 'supplier', 'party_id' => $this->supplier->id, 'instrument' => 'transfer', 'instrument_no' => 'TT-UNSIGNED-3',
        ]);

        $this->assertContains($this->refusalOn('payee_bank'), $this->unsignedMessages(),
            '⛔ খরচের ভাউচার দিয়ে সই-না-পাওয়া সরবরাহকারী-ব্যাংকে ট্রান্সফার চলে গেল: '.json_encode($this->refusalMessages(), JSON_UNESCAPED_UNICODE));
    }

    /** ৪ — সই-পাওয়া ব্যাংকে চলে; নগদে সই লাগে না */
    public function test_a_signed_bank_or_cash_goes_through(): void
    {
        $this->post(route('accounts.voucher.store', ['type' => Voucher::PAYMENT]), $this->payment('cash', null, $this->till()));
        $this->assertNull($this->refusalOn('payee_bank'), '⛔ নগদের পরিশোধও ব্যাংকের সইয়ের জন্য থামল — নগদে প্রাপকের ব্যাংক লাগে না');

        SupplierBankDetail::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'bank_name' => 'Dutch-Bangla', 'account_name' => 'Supplier Ltd',
            'account_no' => '1234567890', 'account_last4' => '7890', 'status' => SupplierBankDetail::SIGNED,
            'requested_by' => $this->owner->id, 'signed_by' => $this->owner->id, 'signed_at' => now(),
        ]);

        $this->post(route('accounts.voucher.store', ['type' => Voucher::PAYMENT]), $this->payment('transfer', 'TT-SIGNED-1'));
        $this->assertNull($this->refusalOn('payee_bank'), '⛔ সই-পাওয়া ব্যাংকেও ট্রান্সফার থামল: '.json_encode($this->refusalMessages(), JSON_UNESCAPED_UNICODE));
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function payment(string $method, ?string $reference, ?Account $from = null): array
    {
        return array_filter([
            'type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'amount' => '1000', 'narration' => 'সরবরাহকারীকে পরিশোধ',
            'from_account_id' => ($from ?? $this->bank)->id, 'to_account_id' => $this->payable()->id,
            'party_type' => 'supplier', 'party_id' => $this->supplier->id, 'instrument' => $method, 'instrument_no' => $reference,
        ], fn ($v) => $v !== null);
    }

    /** @return list<string> */
    private function unsignedMessages(): array
    {
        return array_map(fn (string $l) => trans('supplier::bank.unsigned_blocks_payment', [], $l), ['bn', 'en']);
    }

    private function postedCount(): int
    {
        return Voucher::query()->where('party_type', 'supplier')->where('party_id', $this->supplier->id)->where('status', DocumentStatus::CONFIRMED)->count();
    }

    private function payable(): Account
    {
        return StandardChart::find(StandardChart::PAYABLE);
    }

    private function till(): Account
    {
        return Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
    }
}
