<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Widgets;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Services\DeliveryStage;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের "আজকের চালান" উইজেট — `GET /api/v1/widgets/summary` → `widgets.challans_today` (মালিকের উইজেট-নকশা ৬, ২০ অক্টোবর
 * ২০২৬; [[ChallansTodaySummary]])।
 *
 * দাবি, আসল HTTP দিয়ে (নমুনার আগের চালান থাকলেও — তাই আগে-পরের পার্থক্য):
 *  - আজকের নিশ্চিত চালান ধাপ ধরে চার ঘরে: বরাদ্দ আর প্যাক → গেট পাস বাকি, রওনা আর আংশিক → পথে, পৌঁছেছে → পৌঁছেছে,
 *    পৌঁছায়নি → ফেরত; মোট = চার ঘরের যোগ;
 *  - খসড়া, বাতিল আর গতকালের চালান গোনা হয় না;
 *  - শেষ চালান: "নাম · পয়েন্ট · শাখা", নম্বর, গাড়ি, আর চালানের পাতার লিংক;
 *  - ⛔ এক শাখা বাছা থাকলে অন্য শাখার চালান আসে না।
 *
 * ⓘ লেখা হয়েছে ২০ অক্টোবর, Mac বন্ধ থাকায় চালানো হয়নি (ec)।
 */
final class TodaysChallansCountByWhereTheGoodsAreTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $home;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        CompanyContext::set((int) $this->company->id, $this->home->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->narrowTo($this->home);
    }

    public function test_each_challan_sits_in_exactly_one_box_and_the_total_is_their_sum(): void
    {
        $before = $this->widget();

        $this->challan(DeliveryStage::ALLOCATED);
        $this->challan(DeliveryStage::PACKED);
        $this->challan(DeliveryStage::DISPATCHED);
        $this->challan(DeliveryStage::PARTIALLY_DELIVERED);
        $this->challan(DeliveryStage::DELIVERED);
        $this->challan(DeliveryStage::DELIVERED);
        $this->challan(DeliveryStage::DELIVERED);
        $this->challan(DeliveryStage::FAILED);
        // ⛔ এগুলো গোনা নয়: খসড়া, বাতিল, গতকালের
        $this->challan(null, DocumentStatus::DRAFT);
        $this->challan(DeliveryStage::CANCELLED, DocumentStatus::CANCELLED);
        $this->challan(DeliveryStage::DELIVERED, DocumentStatus::CONFIRMED, now()->subDay()->toDateString());
        $last = $this->challan(DeliveryStage::DISPATCHED, DocumentStatus::CONFIRMED, null, 'ঢাকা মেট্রো-ট 14-2231');

        $after = $this->widget();
        $grew = fn (string $box) => $after[$box] - $before[$box];

        $this->assertSame(2, $grew('gate_pending'), '⛔ গেট পাস বাকি: বরাদ্দ আর প্যাক।');
        $this->assertSame(3, $grew('moving'), '⛔ পথে: রওনা দুটো আর আংশিক একটা।');
        $this->assertSame(3, $grew('delivered'), '⛔ পৌঁছেছে: আজকের তিনটা, গতকালেরটা নয়।');
        $this->assertSame(1, $grew('returned'), '⛔ ফেরত: পৌঁছায়নি একটা।');
        $this->assertSame(9, $grew('total'), '⛔ খসড়া, বাতিল বা গতকালের চালান মোটে ঢুকেছে।');
        $this->assertSame($after['gate_pending'] + $after['moving'] + $after['delivered'] + $after['returned'], $after['total'],
            '⛔ চার ঘরের যোগ মোট নয় — কোনো চালান দুই ঘরে বা কোনোটাতেই নয়।');

        $customer = $last->customer()->with(['location.parent', 'branch'])->first();
        $this->assertSame(implode(' · ', array_filter([$customer->name(), $customer->pointName(), $customer->branch?->name()])),
            $after['latest']['customer'], '⛔ শেষ চালানের গ্রাহক "নাম · পয়েন্ট · শাখা" নয়।');
        $this->assertSame($last->document_no, $after['latest']['no']);
        $this->assertSame('ঢাকা মেট্রো-ট 14-2231', $after['latest']['vehicle']);
        $this->assertSame('/sales/challans/'.$last->id, $after['latest']['link']);
        $this->assertSame('/sales/deliveries?stage=dispatched', $after['link'], '⛔ উইজেটের লিংক ডেলিভারির পাতায় নয়।');
    }

    public function test_another_branchs_challans_stay_behind_the_wall(): void
    {
        $other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->home->id)->firstOrFail();
        $before = $this->widget();
        $this->challan(DeliveryStage::FAILED, DocumentStatus::CONFIRMED, null, null, $other);

        $this->assertSame($before['returned'], $this->widget()['returned'], '⛔ অন্য শাখার চালান এই শাখার উইজেটে।');

        $this->narrowTo($other);
        $this->assertGreaterThanOrEqual(1, $this->widget()['returned'], 'প্রস্তুতিটাই ভুল — নিজের শাখায় চালানটা আসে।');
    }

    private function challan(?string $stage, string $status = DocumentStatus::CONFIRMED, ?string $date = null, ?string $vehicle = null,
        ?Branch $branch = null): DeliveryChallan
    {
        $branch ??= $this->home;
        $challan = CompanyContext::forCompany((int) $this->company->id, fn () => DeliveryChallan::query()->create([
            'branch_id' => $branch->id,
            'document_no' => 'CHA-W6-'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT),
            'customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->orderBy('id')->firstOrFail()->id,
            'trx_date' => $date ?? now()->toDateString(),
            'own_transport' => true,
            'vehicle_no' => $vehicle,
            'total' => '100.0000',
            'status' => $status,
        ]));

        // ⓘ ধাপ সোজা বসানো — ঘটনা চলে না, তাই ট্রিপ বা গেট পাস লাগে না; উইজেট কেবল ধাপের সারি পড়ে
        if ($stage !== null) {
            DeliveryState::query()->updateOrCreate(['delivery_challan_id' => $challan->id],
                ['company_id' => $this->company->id, 'stage' => $stage, 'stage_at' => now()]);
        } else {
            DeliveryState::query()->where('delivery_challan_id', $challan->id)->delete();
        }

        return $challan;
    }

    /** @return array<string, mixed> */
    private function widget(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $widget = $this->getJson('/api/v1/widgets/summary?keys=challans_today')->assertOk()->json('widgets.challans_today');
        $this->assertIsArray($widget, 'প্রস্তুতিটাই ভুল — মালিক চালান দেখেন।');

        return $widget;
    }

    private function narrowTo(Branch $branch): void
    {
        $this->owner->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $branch->id,
            'view_all_branches' => false])->save();
        CompanyContext::set((int) $this->company->id, $branch->id);
    }
}
