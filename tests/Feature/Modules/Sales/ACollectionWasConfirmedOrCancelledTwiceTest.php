<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই আদায় দুইবার নিশ্চিত বা বাতিল হত — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ [[CollectionService::confirm()]] আর [[CollectionService::cancel()]] অবস্থা দেখত হাতের কপি থেকে, লেনদেনের বাইরে —
 * পুরনো পাতা থেকে দ্বিতীয় চাপ খাতায় আবার বসাতে বা আবার উল্টাতে যেত। ⭐ এখন আদায়ের সারিতে তালা দিয়ে অবস্থা তাজা;
 * দ্বিতীয়টা পরিষ্কার কথায় ফেরে, খাতা একবারই নড়ে।
 */
final class ACollectionWasConfirmedOrCancelledTwiceTest extends TestCase
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

    public function test_a_stale_tab_neither_confirms_nor_cancels_a_second_time(): void
    {
        $dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $bills = app(SalesInvoiceService::class);
        $bill = $bills->confirm($bills->create(
            ['customer_id' => $dealer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '500']],
        ));

        $service = app(CollectionService::class);
        $draft = $service->create(
            ['customer_id' => $dealer->id, 'trx_date' => now()->toDateString(), 'amount' => '500'],
            [['sales_invoice_id' => $bill->id, 'amount' => '500']],
        );
        $staleDraft = Collection::query()->findOrFail($draft->id);

        $service->confirm($draft);
        $once = $this->entries($draft->id);

        $this->assertGreaterThan(0, $once, 'প্রস্তুতিটাই ভুল — আদায় খাতায় বসেনি।');
        // ⓘ বিলটা ততক্ষণে শোধ, তাই দ্বিতীয়টা সাধারণত তালার আগেই "বকেয়ার বেশি" (`lines`) বলে ফেরে; বিলের বাকি থাকলে তালার
        //   পরে "খসড়া নয়" (`status`)। দুইটাই পরিষ্কার কথা — দাবি হলো খাতা একবারই নড়ে, আর কোনো ভাঙা পাতা নয়
        $this->assertContains($this->refused(fn () => $service->confirm($staleDraft)), ['status', 'lines'], '⛔ পুরনো ট্যাব থেকে দ্বিতীয় নিশ্চিত পরিষ্কার কথায় ফেরেনি।');
        $this->assertSame($once, $this->entries($draft->id), '⛔ দ্বিতীয় নিশ্চিতে খাতায় আবার দাখিলা।');

        $staleConfirmed = Collection::query()->findOrFail($draft->id);
        $service->cancel($draft->fresh(), 'প্রথম বাতিল');
        $afterCancel = $this->entries($draft->id);

        $this->assertSame('status', $this->refused(fn () => $service->cancel($staleConfirmed, 'দ্বিতীয় বাতিল')), '⛔ পুরনো পাতা থেকে দ্বিতীয় বাতিল পরিষ্কার কথায় ফেরেনি।');
        $this->assertSame($afterCancel, $this->entries($draft->id), '⛔ দ্বিতীয় বাতিলে উল্টো দাখিলা আবার বসেছে।');
    }

    private function entries(int $collectionId): int
    {
        return LedgerEntry::query()->where('source_id', $collectionId)
            ->whereIn('source_type', [Collection::drillSourceType(), Collection::drillSourceType().':reversal'])
            ->count();
    }

    private function refused(callable $act): ?string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        } catch (\Throwable $e) {
            return class_basename($e).': '.$e->getMessage();
        }

        return null;
    }
}
