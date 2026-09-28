<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * সরবরাহকারীকে "পরিশোধ" দেওয়া গেল একটা খরচের খাত থেকে — abos-10 যা পেয়েছেন, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * সরাসরি ক্রয়ের "এখনই দেওয়া"-র খাত কেবল *আছে কি না* যাচাই হত (`exists`)।
 * তাই যেকোনো খাত — ভাড়া, বেতন, বিদ্যুৎ — থেকে "পরিশোধ" বসত: Dr দেনা /
 * Cr খরচ। দেনা কমে যেত, অথচ কোনো টাকা বেরোয়নি; আর খরচের খাত উল্টো
 * দিকে সরে লাভ বাড়িয়ে দেখাত। অডিট §১.১-এর বিক্রয়ের দিকের ঠিক জোড়া।
 *
 * ⭐ নিয়ম এক জায়গায় ([[MoneyAccountRule]]) — বিক্রয়, ক্রয় আর ভাউচার একই কথা বলে।
 */
final class TheSupplierWasPaidOutOfAnExpenseTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->till = app(CashTillService::class)->ensurePrimaryTill()->account;

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->putMoneyIn($this->till, '5000');
    }

    /** ⛔ খরচের খাত থেকে "পরিশোধ" বসে না — আর গোটা ক্রয়টাই বসে না। */
    public function test_an_expense_account_cannot_pay_the_supplier(): void
    {
        $expense = Account::query()->where('type', Account::EXPENSE)->where('is_group', false)->orderBy('code')->firstOrFail();
        $before = $this->counts();

        $this->buy(['deposits' => [$this->deposit($expense)]])
            ->assertSessionHasErrors('deposits.0.account_id');

        $this->assertSame($before, $this->counts(),
            '⛔ খরচের খাত থেকে পরিশোধ আটকেছে ঠিকই, কিন্তু বিল বা খাতার সারি থেকে গেছে।');
    }

    /** ⭐ আর আসল টাকার খাত (টিল) থেকে পরিশোধ আগের মতোই চলে। */
    public function test_the_till_still_pays(): void
    {
        $bills = PurchaseBill::query()->count();

        $this->buy(['deposits' => [$this->deposit($this->till)]])
            ->assertSessionHasNoErrors();

        $this->assertSame($bills + 1, PurchaseBill::query()->count());
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function deposit(Account $account): array
    {
        return [
            'amount' => '100',
            'payment_method_id' => PaymentMethod::query()->orderBy('id')->firstOrFail()->id,
            'account_id' => $account->id,
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private function buy(array $extra)
    {
        return $this->from(route('purchase.direct.create'))->post(route('purchase.direct.store'), [
            'supplier_id' => Supplier::query()->forPurchasing()->orderBy('id')->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'EXP-'.fake()->unique()->numberBetween(10000, 99999),
            'payment_term' => 'cash',
            ...$extra,
            'lines' => [['product_id' => Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail()->id,
                'qty' => '10', 'rate' => '60', 'tax' => '0']],
        ]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'bills' => PurchaseBill::query()->withTrashed()->count(),
            'ledger' => DB::table('ledger_entries')->count(),
            'vouchers' => DB::table('vouchers')->count(),
        ];
    }
}
