<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\OpportunityStage;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Services\OpportunityService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বিক্রয়ের চারটা ডেস্ক-পাতা ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*
 * ([[SalesDeskApiController]])।
 *
 * দাবি: প্রতিটা দরজা ওয়েবের পাতার একই সেবার একই সারি পাঠায় — ডিপোর যাচাইয়ের DO আর তার সর্বমোট, না ছাপা কাগজ (ছাপা হয়ে
 * যাওয়াটা নয়) আর "হাতে ছাপা হলো"-তে সারি থেকে সরা, ডেলিভারির পাঁচটা কার্ড, পাইপলাইনের ধাপ আর তার সম্ভাবনা; প্রতিটার নিজের চাবি
 * ছাড়া ৪০৩।
 */
final class TheSalesDeskOpensAsPhoneScreensTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_depot_check_lists_the_web_pages_delivery_orders_with_their_whole_total(): void
    {
        $shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $ready = DeliveryOrder::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'DO-DESK-1', 'customer_id' => $shop->id, 'trx_date' => now()->toDateString(),
            'status' => DeliveryOrderStatus::ACCOUNTS_APPROVED, 'subtotal' => '1500', 'total' => '1500']);
        DeliveryOrder::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'DO-DESK-2', 'customer_id' => $shop->id, 'trx_date' => now()->toDateString(),
            'status' => DeliveryOrderStatus::DRAFT, 'subtotal' => '900', 'total' => '900']);

        $answer = $this->phone($this->owner)->getJson('/api/v1/sales/depot-check')->assertOk();

        $this->assertSame([(string) $ready->public_id], array_column($answer->json('orders'), 'id'), '⛔ খসড়া DO ডিপোর যাচাইয়ে, বা তৈরিটা নেই।');
        $this->assertSame(1, $answer->json('orders_count'));
        $this->assertSame('1,500.00', $answer->json('orders_total'));
        $this->assertSame([], $this->phone($this->owner)->getJson('/api/v1/sales/depot-check?q=nobody-here')->assertOk()->json('orders'));
    }

    public function test_the_print_queue_lists_what_is_unprinted_and_a_hand_print_takes_it_off(): void
    {
        // ⓘ একটা কাগজের এক মাপের একটাই কাজ (uq_sal_print_job_document) — তাই প্রতিটা আলাদা কাগজ
        $job = fn (string $no, string $status) => PrintJob::query()->create(['company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id, 'document_type' => 'invoice', 'document_id' => (int) substr($no, -1),
            'document_no' => $no, 'paper' => 'a4', 'status' => $status, 'failure' => $status === PrintJob::FAILED ? 'কাগজ ফুরিয়েছে' : null]);
        $waiting = $job('INV-P-1', PrintJob::WAITING);
        $failed = $job('INV-P-2', PrintJob::FAILED);
        $job('INV-P-3', PrintJob::PRINTED);

        $rows = collect($this->phone($this->owner)->getJson('/api/v1/sales/print-queue')->assertOk()->json('jobs'))->keyBy('document_no');
        $this->assertSame(['INV-P-1', 'INV-P-2'], $rows->keys()->sort()->values()->all(), '⛔ ছাপা হয়ে যাওয়া কাগজ সারিতে, বা না-ছাপাটা নেই।');
        $this->assertSame('কাগজ ফুরিয়েছে', $rows['INV-P-2']['failure']);
        $this->assertTrue($rows['INV-P-2']['failed']);

        $key = (string) \Illuminate\Support\Str::uuid();
        $first = $this->phone($this->owner)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/sales/print-queue/'.$failed->public_id.'/settle')->assertOk();
        $this->assertSame(PrintJob::PRINTED, $failed->fresh()->status);
        // ⭐ আবার পাঠানো — একই চাবিতে প্রথম উত্তর (a6-এর পর্যালোচনা)
        $this->phone($this->owner)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/sales/print-queue/'.$failed->public_id.'/settle')->assertOk()->assertExactJson($first->json());
        $this->assertSame([(string) $waiting->public_id],
            array_column($this->phone($this->owner)->getJson('/api/v1/sales/print-queue')->json('jobs'), 'id'));
    }

    public function test_delivery_performance_sends_the_web_pages_five_cards_and_the_pipeline_its_stages(): void
    {
        $perf = $this->phone($this->owner)->getJson('/api/v1/sales/delivery-performance?from=2026-10-01&to=2026-10-31')->assertOk();
        $this->assertCount(5, $perf->json('cards'), '⛔ ওয়েবের পাতার পাঁচটা কার্ড নয়।');
        $this->assertSame(['2026-10-01', '2026-10-31'], [$perf->json('from'), $perf->json('to')]);
        $this->assertSame('2026-10-01', $this->phone($this->owner)->getJson('/api/v1/sales/delivery-performance?from=2026-10-31&to=2026-10-01')
            ->json('from'), 'উল্টো তারিখ ঘুরে বসে, ওয়েবের মতো');

        app(OpportunityService::class)->ensureStages();
        $stage = OpportunityStage::query()->orderBy('id')->firstOrFail();
        $deal = Opportunity::query()->create(['company_id' => $this->company->id, 'document_no' => 'OPP-DESK-1', 'title' => 'Wholesale deal',
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'), 'stage_id' => $stage->id,
            'estimated_value' => '20000', 'probability' => 50, 'created_by' => $this->owner->id]);

        $board = $this->phone($this->owner)->getJson('/api/v1/sales/opportunities/pipeline')->assertOk();
        $column = collect($board->json('stages'))->firstWhere('name', $stage->name());
        $this->assertNotNull($column, '⛔ ধাপটা পাইপলাইনে নেই।');
        $this->assertSame([(string) $deal->public_id], array_column($column['rows'], 'id'));
        $this->assertSame(1, $column['count']);
        $this->assertSame('20,000.00', $column['total']);
    }

    public function test_each_door_asks_its_own_web_key(): void
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('sales.order.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['/api/v1/sales/depot-check', '/api/v1/sales/print-queue', '/api/v1/sales/delivery-performance',
            '/api/v1/sales/opportunities/pipeline'] as $url) {
            $this->phone($clerk)->getJson($url)->assertForbidden();
        }
    }

    private function phone(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
