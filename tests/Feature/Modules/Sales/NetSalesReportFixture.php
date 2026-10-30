<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportResult;
use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;

/**
 * ⭐ নিট বিক্রির রিপোর্টের একটাই প্রস্তুতি (fe, ২১ অক্টোবর ২০২৬) — [[NetSalesIsBillsLessReturnsEverywhereTest]]-এর হুবহু:
 * চালে ১০% VAT (দামের উপরে), আসল নিশ্চিত বিল ([[SalesInvoiceService]]) আর আসল নিশ্চিত আংশিক ফেরত ([[SalesReturnService]])।
 * বিল ১০ × ১০,০০০ = ১,০০,০০০ + VAT ১০,০০০; ফেরত ১টা = ১০,০০০ + VAT ১,০০০ → নিট বিক্রি ৯০,০০০ (ভ্যাট ও ফেরত বাদে)।
 * ⓘ রিপোর্ট পড়া হয় আসল দরজা দিয়ে (ওয়েবের রিপোর্ট পাতা), হেডারে বিলের শাখা বাছা — তাই শাখা ধরে ভাগ নেই।
 */
trait NetSalesReportFixture
{
    protected const NET_LABEL = 'নিট বিক্রি (ভ্যাট ও ফেরত বাদে)';

    protected Company $company;

    protected User $owner;

    protected Warehouse $warehouse;

    protected Product $rice;

    protected function setUpNetSales(): void
    {
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->web();
        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        app(SettingsService::class)->set('sales.vat_enabled', true);
        $this->assertTrue((bool) app(SettingsService::class)->get('sales.vat_enabled', false), 'প্রস্তুতিটাই ভুল — বিক্রিতে VAT চালু হয়নি।');

        $vat = Tax::query()->create(['code' => 'NS2-VAT10', 'name_en' => 'NS2-VAT10', 'name_bn' => 'NS2-VAT10', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        $this->rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();
        $this->rice->forceFill(['tax_id' => $vat->id])->save();

        // ⓘ বাকির সীমা আর দিন এই দাবির বিষয় নয় — বিক্রি যেন আটকে না যায়
        Customer::acrossDealers()->whereIn('name_en', ['Rahim Traders', 'Niloy Store'])->get()
            ->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save());

        // ⭐ হেডারে বিলের শাখা — আসল দরজায় (মালিকের "সব শাখা" হলে রিপোর্ট শাখা ধরে ভাগ হত)
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $this->warehouse->branch_id]);
        $this->assertFalse((bool) $this->owner->fresh()->view_all_branches, 'প্রস্তুতিটাই ভুল — হেডারে একটা শাখা বাছা হয়নি।');
    }

    /**
     * নিয়মের প্রস্তুতি — বিল VAT সহ, আংশিক ফেরত VAT সহ, দুইটাই খাতায় বসা, আজকের তারিখে; প্রস্তুতিটা নিজেই প্রমাণিত।
     *
     * @return array{0: SalesInvoice, 1: SalesReturn}
     */
    protected function billAndPartReturn(string $customer = 'Rahim Traders', string $qty = '10', string $back = '1'): array
    {
        $bill = $this->bill($qty, '10000', $customer);
        $return = $this->giveBack($bill, $back);

        $this->assertSame(bcmul($qty, '11000', 4), bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা VAT সহ নয়।');
        $this->assertSame(bcmul($qty, '1000', 4), bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT নেই।');
        $this->assertSame(bcmul($back, '11000', 4), bcadd((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতটা VAT সহ নয়।');
        $this->assertSame(bcmul($back, '1000', 4), bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT নেই।');
        $this->assertContains($bill->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিল খাতায় বসেনি।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');
        $this->assertSame(1, bccomp($qty, $back, 4), 'প্রস্তুতিটাই ভুল — ফেরতটা আংশিক নয়।');
        $this->assertSame((int) $this->warehouse->branch_id, (int) $bill->branch_id, 'প্রস্তুতিটাই ভুল — বিলটা হেডারের শাখায় নয়।');

        return [$bill, $return];
    }

    protected function bill(string $qty, string $rate, string $customer = 'Rahim Traders'): SalesInvoice
    {
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::acrossDealers()->where('name_en', $customer)->firstOrFail()->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => Carbon::today()->toDateString(),
        ], [[
            'product_id' => $this->rice->id,
            'qty' => $qty, 'rate' => $rate,
        ]]))->fresh('lines');
    }

    protected function giveBack(SalesInvoice $bill, string $qty): SalesReturn
    {
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $bill->branch_id);
        $returns = app(SalesReturnService::class);

        return $returns->confirm($returns->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $bill->warehouse_id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => Carbon::today()->toDateString(),
        ], [[
            'product_id' => $bill->lines->first()->product_id,
            'sales_invoice_line_id' => $bill->lines->first()->id,
            'qty' => $qty,
        ]]))->fresh();
    }

    /**
     * আসল দরজা — রিপোর্টের ওয়েব পাতা; ফলটা পাতার নিজের `result`, আর (সারি থাকলে) পাতায় নিট বিক্রির নাম।
     * ⓘ খালি তালিকায় পাতা ঘরের নাম না-ও আঁকতে পারে — তাই "আগের" ছবিতে নাম দেখা হয় না।
     *
     * @param  array<string, mixed>  $query
     */
    protected function page(string $route, string $slug, array $query = []): ReportResult
    {
        $this->web();
        $today = Carbon::today()->toDateString();
        $response = $this->get(route($route, ['slug' => $slug, 'from' => $today, 'to' => $today, ...$query]))->assertOk();

        if ($response->viewData('result')->rows !== []) {
            $response->assertSee(self::NET_LABEL);
        }

        $result = $response->viewData('result');
        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertFalse($result->isSplitByBranch(), 'প্রস্তুতিটাই ভুল — রিপোর্ট শাখা ধরে ভাগ হলো, হেডারে একটা শাখা নেই।');

        return $result;
    }

    /** @return list<array<string, mixed>> */
    protected function rowsOf(ReportResult $result): array
    {
        return array_map(fn ($r) => (array) $r, $result->rows);
    }

    /** ⭐ মোটের সারি = সারিগুলোর যোগ, পয়সায় */
    protected function assertTotalRowAddsUp(ReportResult $result, string $column): void
    {
        $this->assertArrayHasKey($column, $result->totals, "⛔ '{$column}'-এর মোটের সারি নেই।");
        $sum = array_reduce($this->rowsOf($result), fn (string $s, array $r) => bcadd($s, (string) ($r[$column] ?? '0'), 4), '0.0000');
        $this->assertSame($sum, bcadd((string) $result->totals[$column], '0', 4), "⛔ '{$column}'-এর মোটের সারি সারিগুলোর যোগ নয়।");
    }

    protected function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 4);
    }

    protected function web(): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        $owner = $this->owner->fresh();
        $this->actingAs($owner);
        CompanyContext::set((int) $this->company->id, (int) ($owner->current_branch_id ?: $this->company->defaultBranch()?->id));
    }
}
