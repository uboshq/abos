<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই বিল দুই ট্যাব থেকে নিশ্চিত হত — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ [[SalesInvoiceService::confirm()]] "খসড়া কি না" দেখত হাতের কপি থেকে, লেনদেনের বাইরে। দুই ট্যাবে খোলা একই বিল
 * নিশ্চিত হলে দ্বিতীয়টাও ঢুকত আর মাল আবার বের করতে যেত। ⭐ এখন বিলের সারিতে তালা দিয়ে অবস্থা তাজা — দ্বিতীয়টা
 * পরিষ্কার কথায় ফেরে, মাল আর খাতা একবারই নড়ে।
 */
final class TheSameBillWasConfirmedFromTwoTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_second_tab_is_refused_and_the_goods_leave_once(): void
    {
        $service = app(SalesInvoiceService::class);
        $draft = $service->create(
            [
                'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '120']],
        );

        $stale = SalesInvoice::query()->findOrFail($draft->id);

        $service->confirm($draft);

        $moves = StockMovement::query()->count();
        $entries = LedgerEntry::query()->count();

        $said = null;

        try {
            $service->confirm($stale);
        } catch (ValidationException $e) {
            $said = array_key_first($e->errors());
        } catch (\Throwable $e) {
            $said = class_basename($e).': '.$e->getMessage();
        }

        $this->assertSame('status', $said, '⛔ পুরনো ট্যাব থেকে দ্বিতীয় নিশ্চিত পরিষ্কার কথায় ফেরেনি: '.var_export($said, true));
        $this->assertSame($moves, StockMovement::query()->count(), '⛔ দ্বিতীয় নিশ্চিতে মাল আবার নড়েছে।');
        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ দ্বিতীয় নিশ্চিতে খাতায় আবার দাখিলা।');
        $this->assertSame(DocumentStatus::CONFIRMED, $draft->fresh()->status);
    }
}
