<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ কুপনের ক্রেডিট নোট বিলের শাখায় — কুপন কাটা মানুষের হেডারের শাখায় নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, বিক্রয় ২;
 * [[SalesCouponPapers::redeemed()]])।
 *
 * ⓘ বিল প্রধান শাখার; মালিক "সব শাখা" দেখছেন, কিন্তু হেডারের চলতি শাখা আরেকটা। আগে নোট আর তার দাখিলা সেই শাখায় বসত — বিলের শাখায়
 * গ্রাহকের পাওনা কমত না, অন্য শাখায় একটা ঋণাত্মক পাওনা জন্মাত।
 */
final class ACouponNoteLandsInTheBillsBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_coupons_credit_note_and_its_entries_sit_in_the_bills_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        $elsewhere = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->whereKeyNot($home->id)->orderBy('id')->firstOrFail();
        CompanyContext::set($company->id, $home->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->givePermissionTo([Permission::findOrCreate('promotion.coupon', 'web'), Permission::findOrCreate('promotion.apply', 'web')]);
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $bill = app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => $biscuit->id, 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
        $this->assertSame((int) $home->id, (int) $bill->branch_id);

        $offer = new Promotion(['name_en' => 'Branch coupon', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $offer->code = 'PROM-BR-1';
        $offer->type = PromotionType::COUPON;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $owner->id;
        $offer->save();
        PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'kind' => BenefitKind::AMOUNT, 'amount' => '100']);
        (new PromotionCoupon)->forceFill(['promotion_id' => $offer->id, 'code' => 'BRANCH-IT', 'max_uses' => 5, 'used_count' => 0,
            'is_active' => true, 'issued_by' => $owner->id])->save();

        // ⓘ "সব শাখা" দেখছেন, চলতি শাখা আরেকটা — মিডলওয়্যার প্রসঙ্গে সেটাই বসায়
        $owner->forceFill(['view_all_branches' => true, 'current_branch_id' => $elsewhere->id])->save();
        CompanyContext::set($company->id, $elsewhere->id);

        $this->actingAs($owner->fresh())->postJson(route('promotion.coupon.redeem'), [
            'code' => 'BRANCH-IT', 'source_type' => 'sales_invoice', 'source_id' => $bill->id,
            'source_line_id' => $bill->lines()->value('id'), 'product_id' => $biscuit->id, 'qty' => '2', 'value' => '20',
        ])->assertOk();

        $note = Note::acrossBranches()->where('against_no', $bill->document_no)->firstOrFail();
        $this->assertSame((int) $home->id, (int) $note->branch_id, '⛔ কুপনের নোট হেডারের শাখায় বসল, বিলের শাখায় নয়');
        $this->assertSame([(int) $home->id], LedgerEntry::query()->where('source_type', 'note')->where('source_id', $note->id)
            ->pluck('branch_id')->map(fn ($b) => (int) $b)->unique()->values()->all(), '⛔ নোটের দাখিলা বিলের শাখায় নয়');
    }
}
