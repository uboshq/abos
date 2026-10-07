<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই ক্রয়-বিল বা মাল গ্রহণ দুই ট্যাব থেকে নিশ্চিত হত — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ [[PurchaseBillService::confirm()]] আর [[PurchaseReceiptService::confirm()]] "খসড়া কি না" দেখত হাতের কপি থেকে।
 * ⭐ এখন সারিতে তালা দিয়ে অবস্থা তাজা — দ্বিতীয়টা পরিষ্কার কথায় ফেরে, মাল আর খাতা একবারই নড়ে।
 */
final class ThePurchaseBillWasConfirmedFromTwoTabsTest extends TestCase
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

    public function test_a_stale_tab_does_not_confirm_the_bill_again(): void
    {
        $service = app(PurchaseBillService::class);
        $draft = $service->create($this->paperHead(), [['product_id' => $this->product(), 'qty' => '10', 'rate' => '100']]);
        $stale = PurchaseBill::query()->findOrFail($draft->id);

        $service->confirm($draft);

        $this->assertOnce(fn () => $service->confirm($stale), 'ক্রয়-বিল');
    }

    public function test_a_stale_tab_does_not_confirm_the_goods_receipt_again(): void
    {
        $service = app(PurchaseReceiptService::class);
        $draft = $service->create($this->paperHead(), [['product_id' => $this->product(), 'received_qty' => '10', 'rate' => '100']]);
        $stale = PurchaseReceipt::query()->findOrFail($draft->id);

        $service->confirm($draft);

        $this->assertOnce(fn () => $service->confirm($stale), 'মাল গ্রহণ');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function assertOnce(callable $again, string $what): void
    {
        $moves = StockMovement::query()->count();
        $entries = LedgerEntry::query()->count();

        $said = null;

        try {
            $again();
        } catch (ValidationException $e) {
            $said = array_key_first($e->errors());
        } catch (\Throwable $e) {
            $said = class_basename($e).': '.$e->getMessage();
        }

        $this->assertSame('status', $said, "⛔ পুরনো ট্যাব থেকে দ্বিতীয় {$what} নিশ্চিত পরিষ্কার কথায় ফেরেনি: ".var_export($said, true));
        $this->assertSame($moves, StockMovement::query()->count(), "⛔ দ্বিতীয় {$what} নিশ্চিতে মাল আবার ঢুকেছে।");
        $this->assertSame($entries, LedgerEntry::query()->count(), "⛔ দ্বিতীয় {$what} নিশ্চিতে খাতায় আবার দাখিলা।");
    }

    /** @return array<string, mixed> */
    private function paperHead(): array
    {
        return [
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ];
    }

    private function product(): int
    {
        return (int) Product::query()->orderBy('id')->value('id');
    }
}
