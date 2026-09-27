<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিশ্চিত বিলের সম্পাদনা-পাতা আগেই বলে দেয়: জমা দিলে খাতা আর মজুদ আবার বসবে।
 *
 * ── মালিকের সিদ্ধান্ত, ২৭ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * super admin নিশ্চিত বিলও বদলাতে পারেন ([[PurchaseBillPolicy::update]]),
 * আর জমা দিলে [[PurchaseBillService::updatePosted()]] পুরনো দাখিলা ও মাল
 * উল্টে নতুনটা বসায়। ⚠️ পাতাটা এতদিন কিছুই বলত না — খসড়া আর নিশ্চিত
 * বিলের ফর্ম দেখতে হুবহু এক। ⭐ এখন নিশ্চিত বিলে সতর্কবার্তা থাকে,
 * খসড়ায় থাকে না।
 *
 * ⓘ দুইটা দাবিই একই ব্যবহারকারী (মালিক) দিয়ে — শুধু বিলের অবস্থা বদলায়,
 * তাই বার্তা থাকা-না-থাকার কারণ অন্য কিছু হতে পারে না।
 */
final class EditingAPostedBillSaysItWillRepostTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

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

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    public function test_the_edit_page_of_a_confirmed_bill_warns_that_saving_reposts(): void
    {
        $bill = app(DirectPurchaseService::class)->complete($this->data('POSTED'), [$this->line()])['bill'];

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'প্রস্তুতি: বিলটা নিশ্চিত হয়নি।');

        $this->get(route('purchase.bill.edit', $bill))
            ->assertOk()
            ->assertSee($this->warning());
    }

    public function test_the_edit_page_of_a_draft_bill_does_not_warn(): void
    {
        $bill = app(PurchaseBillService::class)->create($this->data('DRAFT'), [$this->line()]);

        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status, 'প্রস্তুতি: বিলটা খসড়া নয়।');

        $this->get(route('purchase.bill.edit', $bill))
            ->assertOk()
            ->assertDontSee($this->warning());
    }

    /**
     * সতর্কবার্তার লেখা — মালিকের নিজের ভাষায়, কারণ পাতাটা সেই ভাষাতেই ছাপা হয়।
     *
     * ⚠️ চাবিটা না মিললে __() চাবির নামটাই ফেরত দেয় — তখন "দেখা যায় না"
     * দাবিটা কিছু না দেখেই পাস করত। তাই আগে নিশ্চিত হওয়া যে লেখাটা আসল।
     */
    private function warning(): string
    {
        $key = 'purchase::message.bill_edit_reposts';
        $text = __($key, [], $this->owner->locale ?? config('app.locale'));

        $this->assertNotSame($key, $text, 'সতর্কবার্তার চাবিটা মালিকের ভাষার ফাইলে নেই।');

        return $text;
    }

    /** @return array<string, mixed> */
    private function data(string $prefix): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => $prefix.'-'.fake()->unique()->numberBetween(1000, 9999),
        ];
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        return ['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100'];
    }
}
