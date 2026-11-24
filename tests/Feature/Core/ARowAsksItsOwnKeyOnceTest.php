<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Contracts\RowFreePolicy;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Policies\VoucherPolicy;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Policies\CustomerPolicy;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Policies\PurchaseBillPolicy;
use App\Modules\Sales\Policies\SalesInvoicePolicy;
use App\Modules\Supplier\Policies\SupplierPolicy;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ⭐ ভাউচার আর ক্রয় বিলের সারির মেনু নিজের কাগজের প্রশ্ন একবার করে, উত্তর আগেরটাই (২৪ নভেম্বর ২০২৬)।
 *
 * দাবি: ভাউচারের "দেখা" একবার, না হলে ছাপা-PDF-পাঠানো তিনটাই নেই; বিলের "বদল" একবার, আর সম্পাদনা/নিশ্চিত প্রতিটা অবস্থায় নীতিরই উত্তর;
 * সারি-দেখা পাঁচ নীতির একটাও "সারি দেখে না" বলে না।
 */
final class ARowAsksItsOwnKeyOnceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_voucher_row_asks_view_once_and_prints_only_for_those_who_may(): void
    {
        $voucher = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'x'],
            [['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '10', 'party_type' => 'customer', 'party_id' => \App\Modules\Customer\Models\Customer::query()->value('id')],
                ['account_id' => StandardChart::find('4300')->id, 'credit' => '10']]);
        $asked = $this->count(Voucher::class, 'view');
        $html = view('accounts::voucher.partials.row-menu', ['voucher' => $voucher])->render();

        $this->assertSame(1, $asked());
        $this->assertStringContainsString(route('accounts.voucher.print', $voucher), $html);
        $this->assertStringContainsString('download=1', $html);

        $this->actingAs($this->stranger());
        $html = view('accounts::voucher.partials.row-menu', ['voucher' => $voucher])->render();
        $this->assertStringNotContainsString(route('accounts.voucher.print', $voucher), $html, '⛔ চাবি নেই, তবু ছাপা।');
    }

    public function test_a_bill_row_asks_update_once_and_answers_the_policy_in_every_state(): void
    {
        // ⓘ ডেমোতে কোনো ক্রয় বিল নেই — নিজেরটা
        $bill = app(\App\Modules\Purchase\Services\PurchaseBillService::class)->create(
            ['supplier_id' => \App\Modules\Supplier\Models\Supplier::query()->whereIn('id', \App\Modules\Supplier\Models\Supplier::onlySuppliersIds())->value('id'),
                'warehouse_id' => \App\Modules\Inventory\Models\Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'ROW-1'],
            [['product_id' => \App\Modules\Inventory\Models\Product::query()->value('id'), 'qty' => '1', 'rate' => '10']],
        );
        $edit = route('purchase.bill.edit', $bill);

        foreach ([$this->owner, $this->stranger()] as $user) {
            $this->actingAs($user);
            foreach ([DocumentStatus::DRAFT, DocumentStatus::CONFIRMED, DocumentStatus::CANCELLED] as $status) {
                $bill->status = $status;
                $may = $user->can('update', $bill);
                $asked = $this->count(PurchaseBill::class, 'update');
                $html = view('purchase::bill.partials.row-menu', ['bill' => $bill])->render();
                $this->assertSame(1, $asked(), $status);
                $this->assertSame($may, str_contains($html, 'href="'.$edit.'"'), "⛔ {$status}: সম্পাদনার উত্তর নীতির নয়।");
            }
        }
    }

    public function test_no_policy_that_looks_at_its_row_says_it_does_not(): void
    {
        foreach ([CustomerPolicy::class, SupplierPolicy::class, SalesInvoicePolicy::class, VoucherPolicy::class, PurchaseBillPolicy::class] as $policy) {
            $this->assertFalse(is_subclass_of($policy, RowFreePolicy::class), $policy);
        }
    }

    private function count(string $class, string $ability): \Closure
    {
        $n = 0;
        Gate::after(function ($u, string $asked, $result, array $arguments = []) use (&$n, $class, $ability) {
            $n += $asked === $ability && ($arguments[0] ?? null) instanceof $class ? 1 : 0;

            return null;
        });

        return function () use (&$n): int {
            return $n;
        };
    }

    private function stranger(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => CompanyContext::id()]);
        $user->companies()->attach(CompanyContext::id(), ['is_active' => true]);

        return $user;
    }
}
