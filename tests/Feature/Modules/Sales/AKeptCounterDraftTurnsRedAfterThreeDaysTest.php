<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * কাউন্টারে রাখা খসড়া ৩ দিন পড়ে থাকলে লাল — বিক্রয় পরিকল্পনা §৪.৩ (৬ অক্টোবর ২০২৬); আদেশের "পুরনো খসড়া"-র একই সীমা, লেখার সময় থেকে।
 *
 * দাবি — একই মানুষের দুই খসড়া: চার দিন আগে লেখা লাল (ওয়েবের তালিকায় আর ফোনে), আজকেরটা নয়।
 */
final class AKeptCounterDraftTurnsRedAfterThreeDaysTest extends TestCase
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

    public function test_an_old_draft_is_red_on_the_list_and_on_the_phone_and_a_new_one_is_not(): void
    {
        $old = $this->keep(Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail());
        $old->forceFill(['created_at' => now()->subDays(4)])->saveQuietly();
        $new = $this->keep(Customer::query()->where('name_en', '!=', 'Rahim Traders')->orderBy('id')->firstOrFail());

        $html = $this->get(route('sales.direct.drafts'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-stale-draft'), '⛔ খসড়ার তালিকায় ঠিক একটা লাল চিহ্ন নয় — পুরনোটা লাল, নতুনটা নয়।');
        $this->assertStringContainsString(__('sales::order_status.stale', ['days' => 4]), $html);

        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $drafts = collect($this->getJson('/api/v1/sales/direct/drafts')->assertOk()->json('drafts'))->keyBy('no');
        $this->assertTrue($drafts[$old->document_no]['stale'], '⛔ ফোনে পুরনো খসড়া লাল নয়।');
        $this->assertSame(4, $drafts[$old->document_no]['age_days']);
        $this->assertFalse($drafts[$new->document_no]['stale'], '⛔ ফোনে আজকের খসড়াও লাল।');
    }

    private function keep(Customer $customer): SalesInvoice
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'own_transport' => '1',
            'save_as_draft' => '1',
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        ])->assertSessionHasNoErrors();

        return SalesInvoice::query()->latest('id')->firstOrFail();
    }
}
