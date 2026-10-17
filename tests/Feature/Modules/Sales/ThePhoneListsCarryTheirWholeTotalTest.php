<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের DO আর দরপত্রের তালিকা পুরো ছাঁকনির মোট আর গোনা পাঠায়, পাতার নয় — মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary/grand total"।
 *
 * ⓘ সার্ভার পাতায় ৫০টা পাঠায়; ফোন নিজে যোগ করলে ৫১তম সারি মোটের বাইরে থাকত। ⛔ বাতিল, ফেরত আর পুরনো সংস্করণ সারিতে থাকে, মোটে নয়।
 */
final class ThePhoneListsCarryTheirWholeTotalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** @var array<string, mixed> */
    private array $body;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        $this->body = ['customer' => (string) Customer::query()->where('name_en', 'Rahim Traders')->value('public_id'), 'lines' => [
            ['product' => (string) Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('public_id'), 'qty' => '2'],
        ]];
        $this->phone();
    }

    public function test_the_delivery_order_list_totals_every_page_and_leaves_out_cancelled_and_rejected(): void
    {
        $this->paper('/api/v1/sales/delivery-orders', DeliveryOrder::class, 52, [
            DeliveryOrderStatus::CANCELLED, DeliveryOrderStatus::REJECTED,
        ]);

        $answer = $this->getJson('/api/v1/sales/delivery-orders')->assertOk();

        $this->assertCount(50, $answer->json('orders'), 'পরীক্ষার ছকটাই ভুল: প্রথম পাতায় ৫০টা আসার কথা।');
        $this->assertSame(52, $answer->json('count'), '⛔ গোনা পাতার, পুরো তালিকার নয়।');
        $this->assertSame('5000.00', $answer->json('total'), '⛔ মোটে পাতার বাইরের সারি নেই, বা বাতিল/ফেরত ঢুকেছে (৫০ × ১০০)।');
    }

    public function test_the_quotation_list_totals_every_page_and_leaves_out_cancelled_rejected_and_revised(): void
    {
        $this->paper('/api/v1/sales/quotations', SalesQuotation::class, 53, [
            SalesQuotation::CANCELLED, SalesQuotation::REJECTED, SalesQuotation::REVISED,
        ]);

        $answer = $this->getJson('/api/v1/sales/quotations')->assertOk();

        $this->assertCount(50, $answer->json('quotations'), 'পরীক্ষার ছকটাই ভুল: প্রথম পাতায় ৫০টা আসার কথা।');
        $this->assertSame(53, $answer->json('count'), '⛔ গোনা পাতার, পুরো তালিকার নয়।');
        $this->assertSame('5000.00', $answer->json('total'), '⛔ মোটে পাতার বাইরের সারি নেই, বা বাতিল/ফেরত/পুরনো সংস্করণ ঢুকেছে (৫০ × ১০০)।');
    }

    public function test_a_status_filter_narrows_the_total_with_the_rows(): void
    {
        $this->paper('/api/v1/sales/delivery-orders', DeliveryOrder::class, 3, [DeliveryOrderStatus::CANCELLED]);

        $answer = $this->getJson('/api/v1/sales/delivery-orders?status='.DeliveryOrderStatus::CANCELLED)->assertOk();

        $this->assertSame(1, $answer->json('count'));
        $this->assertSame('0.00', $answer->json('total'), '⛔ বাতিলের ছাঁকনিতে মোট বাতিলটাকেই গুনেছে।');
    }

    /**
     * একটা কাগজ ফোন দিয়ে লেখা, তারপর একই সারি টেবিলে কপি — মোট $count টা, প্রতিটা ১০০ টাকা; শেষের কয়টা $closed অবস্থায়।
     *
     * @param  class-string<DeliveryOrder|SalesQuotation>  $model
     * @param  list<string>  $closed
     */
    private function paper(string $url, string $model, int $count, array $closed): void
    {
        $id = $this->postJson($url, $this->body)->assertCreated()->json('id');
        $first = $model::query()->where('public_id', $id)->firstOrFail();
        $table = $first->getTable();
        DB::table($table)->where('id', $first->id)->update(['total' => '100.0000']);
        $row = (array) DB::table($table)->where('id', $first->id)->first();
        unset($row['id']);

        for ($i = 1; $i < $count; $i++) {
            DB::table($table)->insert(array_merge($row, [
                'public_id' => (string) Str::uuid7(),
                'document_no' => $row['document_no'].'-'.$i,
            ]));
        }

        $ids = DB::table($table)->where('company_id', $first->company_id)->orderByDesc('id')->limit(count($closed))->pluck('id')->all();
        foreach ($closed as $n => $status) {
            DB::table($table)->where('id', $ids[$n])->update(['status' => $status]);
        }
    }

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
    }
}
