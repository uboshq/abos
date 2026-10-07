<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পদ্ধতি ↔ খাত — সেটিংসের সেভ-দরজা আর কাউন্টারের টাকা নেওয়া।
 *
 * ── ⚠️ কেন আলাদা ফাইল (২৭ সেপ্টেম্বর ২০২৬) ─────────────────────────────
 * `MasterListService` আর `PosService` সমন্বয়কের বড় খোলা কাজের ভিতরে, তাই
 * পরিবর্তনটা প্যাচ-স্ক্রিপ্টে রাখা (scratchpad: `patch_masterlist.php`,
 * `patch_pos.php`)। ⛔ প্যাচ না বসা পর্যন্ত এই ফাইল **লাল — আর সেটাই
 * ঠিক**: skip করলে দাবিটা কখনো লাল হত না আর ভুলে যাওয়া হত। প্যাচের সাথে
 * একই কমিটে যায়।
 *
 * ⓘ নিয়মটা নিজে আর দাবি-মঞ্জুরের দরজা প্রমাণিত
 * `APaymentMethodFitsItsMoneyAccountTest`-এ।
 */
final class APaymentMethodFitsItsMoneyAccountAfterThePatchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $cash;

    private Account $bkash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ প্রধান টিলের নগদ — কাউন্টারের নগদ এখানেই বসে, আর টিলের নিয়মও মেলে
        $this->cash = app(CashTillService::class)->ensurePrimaryTill()->account;
        $this->bkash = $this->moneyAccount('1105-FITP', StandardChart::MOBILE_MONEY, Account::MFS);
    }

    // ── ১ · সেটিংসে সেভ ─────────────────────────────────────────────────

    /** ⛔ লাইভের ঘটনাটাই: "নগদ" পদ্ধতি বিকাশের খাতে — সেভ হয় না, কিছুই বসে না। */
    public function test_a_cash_method_cannot_be_saved_on_a_bkash_account(): void
    {
        $before = PaymentMethod::query()->count();

        $this->post(route('master_data.payment_method.store'), [
            'name_en' => 'Counter Cash FIT',
            'kind' => 'cash',
            'account_id' => $this->bkash->id,
        ])->assertSessionHasErrors('account_id');

        $this->assertSame($before, PaymentMethod::query()->count(),
            '⛔ "নগদ" পদ্ধতি বিকাশের খাতে সেভ হয়ে গেছে।');
    }

    /** ⭐ পাল্টা দাবি: একই দরজা, মেলা জোড়া — সেভ হয়। */
    public function test_a_cash_method_on_a_cash_account_saves(): void
    {
        $this->post(route('master_data.payment_method.store'), [
            'name_en' => 'Counter Cash FIT',
            'kind' => 'cash',
            'account_id' => $this->cash->id,
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('master_data.payment_method.index'));

        $this->assertSame($this->cash->id,
            (int) PaymentMethod::query()->where('name_en', 'Counter Cash FIT')->value('account_id'));
    }

    /** ⛔ সম্পাদনায়ও: ঠিক জোড়াটা পরে বিকাশে সরানো যায় না, সারি অক্ষত। */
    public function test_an_existing_cash_method_cannot_be_moved_to_bkash(): void
    {
        $method = $this->boundMethod('cash', $this->cash);

        $this->put(route('master_data.payment_method.update', $method->id), [
            'code' => $method->code,
            'name_en' => $method->name_en,
            'kind' => 'cash',
            'account_id' => $this->bkash->id,
        ])->assertSessionHasErrors('account_id');

        $this->assertSame($this->cash->id, (int) $method->fresh()->account_id,
            '⛔ সম্পাদনায় "নগদ" পদ্ধতি বিকাশের খাতে সরে গেছে।');
    }

    // ── ২ · কাউন্টারে টাকা নেওয়া ────────────────────────────────────────

    /**
     * ⛔ সেভের পাহারার আগে বসা ভুল জোড়া — কাউন্টারে থামে, বিকাশে টাকা বসে না।
     *
     * ⚠️ `forceFill` দিয়ে বসানো, সেভের দরজা এড়িয়ে: পুরনো সারি বা পরে ধরন
     * বদলানো খাত ঠিক এভাবেই আসে।
     */
    public function test_the_counter_refuses_a_cash_method_bound_to_bkash(): void
    {
        $method = $this->boundMethod('cash', $this->bkash);
        $bkashBefore = $this->bkash->fresh()->balanceOn();

        try {
            $this->sell(['payment_method_id' => $method->id]);
            $this->fail('⛔ "নগদ" চেপে বিক্রয় হয়ে গেছে, আর টাকা বসেছে বিকাশে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method_id', $e->errors(), 'ত্রুটিটা পদ্ধতির ঘরে বসেনি।');
        }

        $this->assertSame($bkashBefore, $this->bkash->fresh()->balanceOn(), '⛔ বিকাশের খাতে টাকা বসে গেছে।');
        $this->assertSame(0, Collection::query()->count(), '⛔ ভুল খাতে আদায়ের সারি বসে গেছে।');
    }

    /** ⭐ পাল্টা দাবি: MFS পদ্ধতি বিকাশে — কাউন্টারে টাকা বসে। */
    public function test_the_counter_takes_an_mfs_method_bound_to_bkash(): void
    {
        $method = $this->boundMethod('mfs', $this->bkash);

        $this->sell(['payment_method_id' => $method->id, 'reference' => 'TRX-FITP-1']);

        $this->assertSame(1, Collection::query()->where('account_id', $this->bkash->id)->count(),
            'মেলা জোড়ায় কাউন্টারের টাকা বিকাশে বসেনি।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** সেভের দরজা ([[MasterListService]]) এড়িয়ে সরাসরি মডেলে বসানো। */
    private function boundMethod(string $kind, Account $account): PaymentMethod
    {
        $method = new PaymentMethod;
        $method->forceFill([
            'company_id' => $this->company->id,
            'code' => 'FITP-'.strtoupper($kind),
            'name_en' => 'FITP '.$kind,
            'name_bn' => 'FITP '.$kind,
            'kind' => $kind,
            'account_id' => $account->id,
            'needs_reference' => $kind !== 'cash',
            'is_active' => true,
        ])->save();

        return $method;
    }

    private function sell(array $extra): array
    {
        return app(PosService::class)->checkout([
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'paid' => '100.00',
            ...$extra,
        ], [
            ['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => '100.00'],
        ]);
    }

    private function moneyAccount(string $code, string $parentCode, string $kind): Account
    {
        return Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $code,
            'name_bn' => $code,
            'parent_id' => StandardChart::find($parentCode)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => $kind,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }
}
