<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Reports\DeliveryReports;
use App\Modules\Sales\Services\DeliveryStage;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ খ — চালানের অবস্থা (বিক্রয় পরিকল্পনা সংস্করণ ২ §৯, ৬ অক্টোবর ২০২৬, [[DeliveryReports::CHALLAN_STATUS]])।
 *
 * ⛔ প্রতিটা ধাপের সময় ধাপের খাতা থেকে, একই ধাপ দুবার এলে প্রথমবারেরটা; বাতিল গেট পাস নয়; খসড়া চালান নয়; অন্য শাখা বাছলে নয়।
 */
final class TheChallanStatusShowsEveryStageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ ডেমোর নিজের চালান এই দিনের বাইরে
        DeliveryChallan::query()->update(['trx_date' => now()->subYear()->toDateString()]);
        $this->t0 = now()->subDays(2)->startOfHour();
    }

    public function test_each_stage_shows_when_it_first_happened(): void
    {
        // ⓘ A — পুরো পথ: তৈরি t0, প্যাক +২, গেট পাস +৫, রওনা +৫, পৌঁছায়নি +৯, আবার রওনা +২০, পৌঁছেছে +২৪
        $a = $this->challan('CS-A', DeliveryStage::DELIVERED);
        $this->event($a, DeliveryStage::PACKED, 2);
        $this->gate($a, 5, GatePass::ISSUED, $this->event($a, DeliveryStage::DISPATCHED, 5));
        $this->event($a, DeliveryStage::FAILED, 9);
        $this->event($a, DeliveryStage::DISPATCHED, 20);
        $this->event($a, DeliveryStage::DELIVERED, 24);

        // ⓘ B — কেবল প্যাক, গেট পাস বাতিল
        $b = $this->challan('CS-B', DeliveryStage::PACKED);
        $this->gate($b, 4, GatePass::CANCELLED, $this->event($b, DeliveryStage::PACKED, 3));

        // ⛔ খসড়া চালান
        $this->challan('CS-DRAFT', DeliveryStage::PENDING, DocumentStatus::DRAFT);

        // ⓘ অন্য শাখার
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '!=', $this->company->defaultBranch()?->id)->orderBy('id')->firstOrFail();
        $this->challan('CS-OTHER', DeliveryStage::PENDING, branch: (int) $other->id);

        $rows = $this->rows();

        $this->assertSame(['CS-A', 'CS-B', 'CS-OTHER'], $rows->keys()->sort()->values()->all(), '⛔ খসড়া চালান দেখাল');

        $this->assertSame(__('sales::delivery.stage.delivered'), $rows['CS-A']['stage']);
        $this->assertSame($this->at(2), (string) $rows['CS-A']['packed_at']);
        $this->assertSame($this->at(5), (string) $rows['CS-A']['gate_at']);
        $this->assertSame($this->at(5), (string) $rows['CS-A']['dispatched_at'], '⛔ দ্বিতীয় রওনা নিল — প্রথমটা হওয়ার কথা');
        $this->assertSame($this->at(24), (string) $rows['CS-A']['arrived_at']);
        $this->assertSame(5, (int) $rows['CS-A']['hours_to_dispatch']);

        $this->assertSame(__('sales::delivery.stage.packed'), $rows['CS-B']['stage']);
        $this->assertNull($rows['CS-B']['gate_at'], '⛔ বাতিল গেট পাস দেখাল');
        $this->assertNull($rows['CS-B']['dispatched_at']);
        $this->assertNull($rows['CS-B']['hours_to_dispatch']);

        $mine = $this->rows(['branch_id' => $this->company->defaultBranch()?->id]);
        $this->assertFalse($mine->has('CS-OTHER'), '⛔ অন্য শাখার চালান দেখাল');

        $this->get(route('sales.report.show', ['slug' => 'challan-status', 'from' => now()->subDays(5)->toDateString()]))
            ->assertOk()->assertSee('CS-A');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function rows(array $extra = []): \Illuminate\Support\Collection
    {
        $result = app(ReportEngine::class)->run(DeliveryReports::CHALLAN_STATUS,
            ['from' => now()->subDays(5)->toDateString(), 'to' => now()->toDateString(), ...$extra]);

        return collect($result->rows)->map(fn ($r) => (array) $r)->keyBy('document_no');
    }

    private function at(int $hours): string
    {
        return $this->t0->copy()->addHours($hours)->toDateTimeString();
    }

    private function challan(string $no, string $stage, string $status = DocumentStatus::CONFIRMED, ?int $branch = null): DeliveryChallan
    {
        $challan = DeliveryChallan::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $branch ?? $this->company->defaultBranch()?->id,
            'document_no' => $no, 'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->subDays(2)->toDateString(), 'total' => '0', 'status' => $status,
            'created_at' => $this->t0, 'updated_at' => $this->t0,
        ]);

        DeliveryState::query()->updateOrCreate(['delivery_challan_id' => $challan->id],
            ['company_id' => $this->company->id, 'stage' => $stage, 'stage_at' => now()]);

        return $challan;
    }

    private function event(DeliveryChallan $challan, string $to, int $hours): int
    {
        $at = $this->t0->copy()->addHours($hours);

        return (int) DeliveryEvent::query()->forceCreate([
            'company_id' => $this->company->id, 'delivery_challan_id' => $challan->id, 'to_stage' => $to,
            'source' => DeliveryStage::BY_HAND, 'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ])->id;
    }

    private function gate(DeliveryChallan $challan, int $hours, string $status, int $eventId): void
    {
        GatePass::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $challan->branch_id, 'document_no' => 'GP-'.$challan->document_no,
            'delivery_challan_id' => $challan->id, 'delivery_event_id' => $eventId, 'issued_at' => $this->t0->copy()->addHours($hours), 'status' => $status,
        ]);
    }
}
