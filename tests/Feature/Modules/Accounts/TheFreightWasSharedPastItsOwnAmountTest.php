<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ম৫ — খরচের চালান-ভাগ খরচের বেশি নয়, বাতিল চালানে নয় (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে যা আসত তাই বসত: ১,০০০ টাকার গাড়িভাড়া দুই চালানে ৮০০ + ৮০০, আর মালের দামে ১,৬০০ উঠত; বাতিল চালানেও ভাগ বসত।
 */
final class TheFreightWasSharedPastItsOwnAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_shares_cannot_add_up_to_more_than_the_expense(): void
    {
        $voucher = $this->expense('1000');
        [$a, $b] = [$this->bill('M5-A'), $this->bill('M5-B')];

        $this->assertRefused(fn () => app(VoucherService::class)->replaceBillShares($voucher, [
            ['purchase_bill_id' => $a->id, 'share_amount' => '800'],
            ['purchase_bill_id' => $b->id, 'share_amount' => '800'],
        ]), '⛔ ১,০০০ টাকার খরচ চালানে ১,৬০০ হয়ে ভাগ হলো।');

        $this->assertSame(0, $voucher->billShares()->count());

        // ⓘ ঠিক খরচের সমান — চলে
        app(VoucherService::class)->replaceBillShares($voucher, [
            ['purchase_bill_id' => $a->id, 'share_amount' => '600'],
            ['purchase_bill_id' => $b->id, 'share_amount' => '400'],
        ]);
        $this->assertSame(2, $voucher->billShares()->count());
    }

    public function test_a_cancelled_or_unknown_bill_takes_no_share(): void
    {
        $voucher = $this->expense('1000');
        $dead = $this->bill('M5-DEAD');
        app(PurchaseBillService::class)->cancel($dead, 'ভুল চালান');

        $this->assertRefused(fn () => app(VoucherService::class)->replaceBillShares($voucher, [
            ['purchase_bill_id' => $dead->id, 'share_amount' => '100'],
        ]), '⛔ বাতিল চালানে খরচ ভাগ হলো।');

        $this->assertRefused(fn () => app(VoucherService::class)->replaceBillShares($voucher, [
            ['purchase_bill_id' => 987654321, 'share_amount' => '100'],
        ]), '⛔ না-থাকা চালানে খরচ ভাগ হলো।');
    }

    /** ⓘ খরচে চার্জ বাইরে যোগ হয় — সীমা খরচের অঙ্ক, চার্জসহ মোট নয় */
    public function test_a_bank_charge_does_not_widen_the_ceiling(): void
    {
        $voucher = $this->expense('1000', charge: '50');
        $bill = $this->bill('M5-CHG');

        $this->assertRefused(fn () => app(VoucherService::class)->replaceBillShares($voucher, [
            ['purchase_bill_id' => $bill->id, 'share_amount' => '1050'],
        ]), '⛔ চার্জটাও চালানের দামে ভাগ হলো।');

        app(VoucherService::class)->replaceBillShares($voucher, [['purchase_bill_id' => $bill->id, 'share_amount' => '1000']]);
        $this->assertSame(1, $voucher->billShares()->count());
    }

    public function test_one_bill_on_two_rows_is_one_share(): void
    {
        $voucher = $this->expense('1000');
        $bill = $this->bill('M5-TWICE');

        app(VoucherService::class)->replaceBillShares($voucher, [
            ['purchase_bill_id' => $bill->id, 'share_amount' => '300'],
            ['purchase_bill_id' => $bill->id, 'share_amount' => '200'],
        ]);

        $shares = $voucher->billShares()->get();
        $this->assertCount(1, $shares);
        $this->assertSame(0, bccomp((string) $shares->first()->share_amount, '500', 4));
    }

    public function test_the_expense_form_does_not_offer_a_cancelled_bill(): void
    {
        $live = $this->bill('M5-LIVE');
        $dead = $this->bill('M5-GONE');
        app(PurchaseBillService::class)->cancel($dead, 'ভুল চালান');

        $page = $this->get(route('accounts.voucher.create', ['type' => Voucher::EXPENSE]))->assertOk();

        $page->assertSee($live->fresh()->document_no);
        $page->assertDontSee($dead->fresh()->document_no);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function expense(string $amount, ?string $charge = null): Voucher
    {
        $from = $charge === null
            ? Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()
            : $this->bank();

        return app(VoucherService::class)->create(
            ['type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'amount' => $amount, 'charge_amount' => $charge ?? 0],
            app(VoucherService::class)->twoLineEntry(Voucher::EXPENSE, $from->id, StandardChart::find(StandardChart::ENTERTAINMENT)->id, $amount, null, $charge),
        );
    }

    private function bill(string $no): PurchaseBill
    {
        $payload = [
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => $no,
            'lines' => [['product_id' => Product::query()->value('id'), 'qty' => '2', 'rate' => '100']],
        ];

        return app(PurchaseBillService::class)->create($payload, $payload['lines']);
    }

    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create([
                'code' => StandardChart::BANK.'-01',
                'name_en' => 'Test bank account',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            ]);
    }

    private function assertRefused(\Closure $act, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bill_shares', $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail($why);
    }
}
