<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Dashboard\PromotionDashboard;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ অফারের ড্যাশবোর্ড — শাখায় সীমিত মানুষ কেবল নিজের শাখার ছাড়, উপহার আর "কতবার লাগল" দেখেন (Promotion লাইন-ধরা নিরীক্ষা,
 * ১৪ অক্টোবর ২০২৬; [[PromotionDashboard]])।
 *
 * ⓘ আগে "এ মাসের ছাড়" আর উপহারের গোনা গোটা কোম্পানির — ময়মনসিংহের মানুষ নেত্রকোনার টাকা দেখতেন, অথচ উপহারের পর্দা আর অন্য
 * মডিউলের ড্যাশবোর্ড শাখা মানে। ⓘ ডেমোর আগের সারি থাকতে পারে, তাই দাবিটা পার্থক্যে: নেত্রকোনার সারি যোগে কিছু বদলায় না,
 * নিজের শাখার সারি যোগে বদলায় (পাল্টা-দাবি)।
 */
final class ThePromotionDashboardStaysInMyBranchTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $offer;

    public function test_another_branchs_discount_gift_and_use_are_not_counted_and_mine_are(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $this->offer = new Promotion(['name_en' => 'Branch dashboard offer', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $this->offer->code = 'PROM-DASH-BR';
        $this->offer->type = PromotionType::PERCENT_DISCOUNT;
        $this->offer->status = PromotionStatus::ACTIVE;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $owner->id;
        $this->offer->save();

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(DataScope::class)->forget();
        config(['abos.dashboards_v2' => true]);

        $before = $this->seenBy($clerk);

        $this->actingAs($owner);
        $this->gifted($this->applied($ntk, '500'));
        $this->assertSame($before, $this->seenBy($clerk), '⛔ নেত্রকোনার ছাড়, উপহার বা ব্যবহার ময়মনসিংহের ড্যাশবোর্ডে গোনা হলো');

        $this->actingAs($owner);
        $this->gifted($this->applied($mms, '30'));
        $after = $this->seenBy($clerk);
        $this->assertNotSame($before['discount'], $after['discount'], 'নিজের শাখার ছাড়ও গোনা হলো না — দেয়াল সবকিছু আটকাচ্ছে');
        $this->assertSame((int) $before['gifts'] + 1, (int) $after['gifts'], 'নিজের শাখার উপহার গোনা হলো না');
        $this->assertSame('1', $after['used'], 'নিজের শাখার ব্যবহার একবারই গোনার কথা');
    }

    /** @return array{discount: ?string, gifts: ?string, used: ?string} */
    private function seenBy(User $clerk): array
    {
        $this->actingAs($clerk->fresh());
        app(DataScope::class)->forget();
        $board = PromotionDashboard::dashboard();
        $stats = collect($board->stats)->keyBy('label');
        $used = collect($board->panels)->firstWhere('label', __('promotion::dashboard.used_this_month'));

        return [
            'discount' => $stats->get(__('promotion::dashboard.discount_this_month'))?->value,
            'gifts' => $stats->get(__('promotion::dashboard.gifts_this_month'))?->value,
            'used' => collect($used?->parts ?? [])->firstWhere('label', $this->offer->name())['value'] ?? null,
        ];
    }

    private function applied(Branch $branch, string $worth): PromotionApplication
    {
        return PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id, 'branch_id' => $branch->id, 'source_type' => 'sales_order', 'source_id' => random_int(900000, 999999),
            'benefit_kind' => BenefitKind::AMOUNT, 'benefit_amount' => $worth, 'worth' => $worth,
        ]);
    }

    private function gifted(PromotionApplication $applied): void
    {
        PromotionGiftIssue::query()->forceCreate([
            'company_id' => CompanyContext::id(), 'code' => 'GIFT-DASH-'.$applied->id, 'promotion_application_id' => $applied->id,
            'product_id' => Product::query()->orderBy('id')->value('id'), 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'qty' => '1', 'unit_cost' => '10', 'returned_qty' => '0', 'issued_at' => now(),
        ]);
    }
}
