<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Dashboard\PromotionDashboard;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রোমোশন ড্যাশবোর্ড — কোন অফার এ মাসে কতবার লাগল, আর চালু অফার কবে ফুরাবে (নতুন ড্যাশবোর্ড, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ প্রতিটা চালু অফার ঠিক একটা দিনের ভাগে; শুরু না হওয়া আর থামানো অফার গোনায় নেই।
 * ⛔ ফিরিয়ে নেওয়া অফার (`reversed_at`) "কতবার লাগল"-এ নেই; আগের মাসেরটাও নয়।
 * ⛔ সুইচ বন্ধে চার্ট নেই।
 */
final class TheDashboardTellsWhichOffersRanAndWhichEndSoonTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_offers_used_this_month_and_live_offers_by_days_left(): void
    {
        $this->company = Company::create(['code' => 'PRM', 'name_en' => 'Promo Co']);
        CompanyContext::set($this->company->id);
        $owner = User::factory()->create(['current_company_id' => $this->company->id]);
        $owner->companies()->attach($this->company->id);
        $this->actingAs($owner);

        $week = $this->offer('P-7', PromotionStatus::ACTIVE, -5, 3);
        $this->offer('P-15', PromotionStatus::ACTIVE, -5, 10);
        $this->offer('P-30', PromotionStatus::ACTIVE, -5, 20);
        $later = $this->offer('P-60', PromotionStatus::ACTIVE, -5, 60);
        $this->offer('P-SOON', PromotionStatus::APPROVED, 2, 40);   // শুরু হয়নি
        $this->offer('P-PAUSE', PromotionStatus::PAUSED, -5, 3);     // থামানো

        foreach ([[$week, null, now()], [$week, null, now()], [$week, now(), now()], [$later, null, now()], [$later, null, now()->subMonthNoOverflow()->startOfMonth()]] as $i => [$offer, $reversed, $at]) {
            PromotionApplication::query()->forceCreate([
                'company_id' => $this->company->id, 'promotion_id' => $offer->id, 'source_type' => 'test', 'source_id' => $i + 1,
                'benefit_kind' => 'amount', 'benefit_amount' => '10', 'worth' => '10', 'reversed_at' => $reversed,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        config(['abos.dashboards_v2' => true]);
        $panels = collect(PromotionDashboard::dashboard()->panels);

        $ending = $panels->firstWhere('label', __('promotion::dashboard.ending_soon'));
        $this->assertNotNull($ending, '"শেষ হতে যাচ্ছে" চার্ট নেই।');
        $this->assertSame(['1', '1', '1', '1'], array_column($ending->parts, 'value'),
            '⛔ দিনের ভাগ ভুল — শুরু না হওয়া বা থামানো অফার গোনায়, বা কোনো অফার দুই ভাগে।');

        $used = $panels->firstWhere('label', __('promotion::dashboard.used_this_month'));
        $this->assertNotNull($used, '"কতবার লাগল" চার্ট নেই।');
        $this->assertSame([$week->name() => '2', $later->name() => '1'], array_column($used->parts, 'value', 'label'),
            '⛔ ফিরিয়ে নেওয়া বা আগের মাসের অফারও গোনা।');

        config(['abos.dashboards_v2' => false]);
        $this->assertSame([], PromotionDashboard::dashboard()->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');
    }

    private function offer(string $code, PromotionStatus $status, int $startsIn, int $endsIn): Promotion
    {
        return Promotion::query()->forceCreate([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => 'Offer '.$code, 'name_bn' => 'অফার '.$code,
            'type' => PromotionType::PERCENT_DISCOUNT->value, 'status' => $status->value,
            'starts_on' => now()->addDays($startsIn)->toDateString(), 'ends_on' => now()->addDays($endsIn)->toDateString(),
        ]);
    }
}
