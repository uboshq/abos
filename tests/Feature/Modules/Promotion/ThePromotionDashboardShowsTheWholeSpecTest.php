<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Dashboard\PromotionDashboard;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Models\PromotionCouponRedemption;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * অফারের ড্যাশবোর্ড — নকশার বাকিটা (৬ অক্টোবর ২০২৬): এ মাসের ছাড় আর উপহার, কুপন দেওয়া বনাম ভাঙানো,
 * বাজেট বনাম খরচ, চালু অফারের তালিকা।
 *
 * ⓘ প্রতিটা সংখ্যা আগে-পরে মাপা (ডেমোর নিজের সারি থাকতে পারে), আর বাড়তিটা ঠিক যে সারিগুলো গোনার কথা তাদের সমান।
 * ⛔ ফিরিয়ে নেওয়া ছাড়, আগের মাসের ছাড়, উপহারের অফার "ছাড়"-এ নেই; পুরো ফেরত আসা উপহার আর আগের মাসের উপহার নেই;
 * ফিরিয়ে নেওয়া ভাঙানো নেই; পরিমাণের ছাদ (পিস) বাজেটের চার্টে নেই; থামানো অফার চালুর তালিকায় নেই।
 * ⛔ সুইচ বন্ধে নতুন কিছুই নেই।
 */
final class ThePromotionDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_discount_gifts_coupons_budget_and_live_list_count_exactly_their_rows(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        config(['abos.dashboards_v2' => false]);
        $old = PromotionDashboard::dashboard();
        $this->assertNull($this->stat($old, __('promotion::dashboard.discount_this_month')), '⛔ সুইচ বন্ধে ছাড়ের ঘর।');
        $this->assertNull($this->stat($old, __('promotion::dashboard.gifts_this_month')), '⛔ সুইচ বন্ধে উপহারের ঘর।');
        $this->assertSame([], $old->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');
        $this->assertSame([], $old->listings, '⛔ সুইচ বন্ধ, তবু তালিকা।');

        config(['abos.dashboards_v2' => true]);
        $before = PromotionDashboard::dashboard();

        $live = $this->offer('DSP-LIVE', PromotionStatus::ACTIVE, -3, 9);
        $this->offer('DSP-PAUSE', PromotionStatus::PAUSED, -3, 9);

        $now = Carbon::now();
        $lastMonth = Carbon::today()->startOfMonth()->subDays(3);

        // ছাড়: গোনায় ১৫০ + ৫০.২৫; বাদ — ফিরিয়ে নেওয়া, আগের মাস, উপহারের অফার
        $this->apply($live, 'amount', '150', $now);
        $this->apply($live, 'percent', '50.25', $now);
        $this->apply($live, 'amount', '999', $now, reversed: true);
        $this->apply($live, 'amount', '777', $lastMonth);
        $goods = $this->apply($live, 'goods', '300', $now);

        // উপহার: গোনায় একটা; বাদ — পুরো ফেরত, আগের মাস
        $this->gift($goods, 'G-1', '2', '0', $now);
        $this->gift($goods, 'G-2', '1', '1', $now);
        $this->gift($goods, 'G-3', '1', '0', $lastMonth);

        // কুপন: দুইটা দেওয়া; একবার ভাঙানো, একবার ভাঙিয়ে ফিরিয়ে নেওয়া
        $c1 = $this->coupon($live, 'DSPC-1');
        $this->coupon($live, 'DSPC-2');
        $this->redeem($c1, $live, $now, reversed: false);
        $this->redeem($c1, $live, $now, reversed: true);

        // বাজেট: টাকার ছাদ আসে, পিসের ছাদ আসে না
        PromotionBudget::query()->forceCreate(['company_id' => $this->company->id, 'promotion_id' => $live->id, 'kind' => 'total', 'per' => 'offer', 'ceiling' => '1300', 'warn_at_percent' => 80]);
        PromotionBudget::query()->forceCreate(['company_id' => $this->company->id, 'promotion_id' => $live->id, 'kind' => 'quantity', 'per' => 'offer', 'ceiling' => '40', 'warn_at_percent' => 80]);

        $after = PromotionDashboard::dashboard();

        $this->assertSame('200.25', bcsub($this->plain($after, 'discount_this_month'), $this->plain($before, 'discount_this_month'), 2),
            '⛔ এ মাসের ছাড় ভুল — ফিরিয়ে নেওয়া, আগের মাস বা উপহারও গোনা, বা কোনোটা বাদ।');
        $this->assertSame(1, (int) $this->plain($after, 'gifts_this_month') - (int) $this->plain($before, 'gifts_this_month'),
            '⛔ উপহার ভুল — পুরো ফেরত বা আগের মাসেরটাও গোনা।');

        $label = Carbon::today()->translatedFormat('M');
        $coupons = $this->series($after, __('promotion::dashboard.coupons'));
        $was = $this->series($before, __('promotion::dashboard.coupons'));
        $this->assertNotNull($coupons, 'কুপনের চার্ট নেই।');
        $this->assertNotNull($coupons->range, '⛔ সময়ের চার্টে তারিখের পরিসর নেই।');
        $this->assertSame('line', $coupons->chart);
        $nowPoint = collect($coupons->points)->firstWhere('label', $label);
        $wasPoint = collect($was->points)->firstWhere('label', $label);
        $this->assertSame(2, (int) $nowPoint['first'] - (int) $wasPoint['first'], '⛔ দেওয়া কুপনের গোনা ভুল।');
        $this->assertSame(1, (int) $nowPoint['second'] - (int) $wasPoint['second'], '⛔ ফিরিয়ে নেওয়া ভাঙানোও গোনা।');

        $budget = $this->series($after, __('promotion::dashboard.budget_used'));
        $this->assertNotNull($budget, 'বাজেটের চার্ট নেই।');
        $mine = array_values(array_filter($budget->points, fn ($p) => str_starts_with($p['label'], 'DSP-LIVE')));
        $this->assertCount(1, $mine, '⛔ পিসের ছাদও টাকার চার্টে, বা ছাদটা নেই।');
        // ⓘ গোটা অফারের ছাদ — সব মাস, সব ধরন, ফিরিয়ে নেওয়া বাদ: ১৫০ + ৫০.২৫ + ৭৭৭ + ৩০০ (পাহারার একই হিসাব)
        $this->assertSame(['DSP-LIVE', '1300.00', '1277.25'], [$mine[0]['label'], $mine[0]['first'], $mine[0]['second']]);

        $list = collect($after->listings)->firstWhere('label', __('promotion::dashboard.live_list'));
        $this->assertInstanceOf(Listing::class, $list);
        $codes = $list->rows->pluck('code')->all();
        $this->assertContains('DSP-LIVE', $codes, 'চালু অফার তালিকায় নেই।');
        $this->assertNotContains('DSP-PAUSE', $codes, '⛔ থামানো অফার চালুর তালিকায়।');
        $this->assertSame(min(10, Promotion::query()->liveOn(Carbon::today())->count()), $list->rows->count(), '⛔ তালিকা আর "এখন চলছে" ঘর আলাদা কথা বলে।');

        // ⭐ প্রথম চার্ট আগের জায়গাতেই
        $this->assertSame(__('promotion::dashboard.used_this_month'), $after->panels[0]->label);
        $this->assertNotNull($after->panels[0]->range);

        config(['abos.dashboards_v2' => false]);
        $off = PromotionDashboard::dashboard();
        $this->assertSame([], $off->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');
        $this->assertSame([], $off->listings, '⛔ সুইচ বন্ধ, তবু তালিকা।');
        $this->assertCount(count($old->stats), $off->stats, '⛔ সুইচ বন্ধে ঘরের সংখ্যা বদলেছে।');
    }

    private function offer(string $code, PromotionStatus $status, int $startsIn, int $endsIn): Promotion
    {
        return Promotion::query()->forceCreate([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => 'Offer '.$code, 'name_bn' => 'অফার '.$code,
            'type' => PromotionType::PERCENT_DISCOUNT->value, 'status' => $status->value,
            'starts_on' => now()->addDays($startsIn)->toDateString(), 'ends_on' => now()->addDays($endsIn)->toDateString(),
        ]);
    }

    private static int $source = 900000;

    private function apply(Promotion $offer, string $kind, string $worth, Carbon $at, bool $reversed = false): PromotionApplication
    {
        return PromotionApplication::query()->forceCreate([
            'company_id' => $this->company->id, 'promotion_id' => $offer->id, 'source_type' => 'test', 'source_id' => ++self::$source,
            'benefit_kind' => $kind, 'benefit_amount' => $kind === 'goods' ? '4' : '10', 'worth' => $worth,
            'reversed_at' => $reversed ? $at : null, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function gift(PromotionApplication $app, string $code, string $qty, string $returned, Carbon $at): void
    {
        PromotionGiftIssue::query()->forceCreate([
            'company_id' => $this->company->id, 'code' => 'DSP-'.$code, 'promotion_application_id' => $app->id,
            'product_id' => Product::query()->value('id'), 'warehouse_id' => Warehouse::query()->value('id'),
            'qty' => $qty, 'returned_qty' => $returned, 'unit_cost' => '0', 'issued_at' => $at,
        ]);
    }

    private function coupon(Promotion $offer, string $code): PromotionCoupon
    {
        return PromotionCoupon::query()->forceCreate([
            'company_id' => $this->company->id, 'promotion_id' => $offer->id, 'code' => $code, 'max_uses' => 5, 'is_active' => true,
        ]);
    }

    private function redeem(PromotionCoupon $coupon, Promotion $offer, Carbon $at, bool $reversed): void
    {
        $app = $this->apply($offer, 'goods', '0', Carbon::today()->startOfMonth()->subMonths(2), reversed: true);
        PromotionCouponRedemption::query()->forceCreate([
            'company_id' => $this->company->id, 'coupon_id' => $coupon->id, 'promotion_application_id' => $app->id,
            'source_type' => 'test', 'source_id' => $app->source_id, 'redeemed_at' => $at, 'reversed_at' => $reversed ? $at : null,
        ]);
    }

    private function stat(\App\Core\Engines\Dashboard\DashboardDefinition $d, string $label): ?Stat
    {
        return collect($d->stats)->firstWhere('label', $label);
    }

    private function plain(\App\Core\Engines\Dashboard\DashboardDefinition $d, string $key): string
    {
        $stat = $this->stat($d, __('promotion::dashboard.'.$key));
        $this->assertNotNull($stat, "'{$key}' ঘরটা নেই।");

        return str_replace(',', '', (string) $stat->value);
    }

    private function series(\App\Core\Engines\Dashboard\DashboardDefinition $d, string $label): ?Series
    {
        return collect($d->panels)->first(fn ($p) => $p instanceof Series && $p->label === $label);
    }
}
