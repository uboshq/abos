<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Services\BudgetGuard;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\BudgetWindow;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ছাদ গোটা প্রচারণা গুনত, কিন্তু একটা বিল বা একজন ক্রেতাকে নয় — স্পেক §১৫
 * *"Limit Controls"*: Per Invoice · Per Customer · Daily · Monthly।
 *
 * ⭐ প্রতিটা জানালার দাবি জোড়ায়: ⛔ একই জানালায় ছাড়ালে থামে, আর ⓘ
 * পাশের জানালার (অন্য বিল, অন্য ক্রেতা, গতকাল, গত মাস) খরচ গোনায় আসে না।
 * ⚠️ জোড়া না হলে *"সবসময় থামাও"* বা *"কখনো থামিও না"* — দুইটাই একটা
 * দাবি সবুজ করত।
 */
final class TheCeilingCountedTheCampaignButNotTheBillTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $offer;

    private int $buyer;

    private int $otherBuyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $ids = Customer::query()->orderBy('id')->limit(2)->pluck('id');
        $this->assertCount(2, $ids, 'দৃশ্যটাই বানানো যায়নি — ডেমোতে দুইজন ক্রেতা লাগে।');
        [$this->buyer, $this->otherBuyer] = [(int) $ids[0], (int) $ids[1]];

        $this->offer = new Promotion([
            'name_en' => 'Windowed',
            'starts_on' => Carbon::today()->subMonths(2),
            'ends_on' => Carbon::today()->addMonth(),
        ]);
        $this->offer->code = 'PROM-W-0001';
        $this->offer->type = PromotionType::VALUE_SLAB;
        $this->offer->status = PromotionStatus::ACTIVE;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $owner->id;
        $this->offer->save();
    }

    private function ceiling(string $per, string $amount): void
    {
        $budget = new PromotionBudget([
            'promotion_id' => $this->offer->id,
            'kind' => PromotionBudget::TOTAL,
            'ceiling' => $amount,
        ]);
        $budget->per = $per;
        $budget->save();
    }

    private function spent(string $worth, int $bill, ?int $customer, ?Carbon $at = null): PromotionApplication
    {
        $row = PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => $bill,
            'customer_id' => $customer,
            'benefit_kind' => BenefitKind::AMOUNT,
            'benefit_amount' => $worth,
            'worth' => $worth,
        ]);

        if ($at !== null) {
            $row->created_at = $at;
            $row->save();
        }

        return $row;
    }

    private function fits(string $worth, int $bill, ?int $customer, ?Carbon $at = null): bool
    {
        try {
            app(BudgetGuard::class)->assertRoomFor(
                $this->offer, BenefitKind::AMOUNT, $worth, '0',
                BudgetWindow::forNewLine('sales_invoice', $bill, $customer, $at),
            );

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** ⛔ প্রতি বিলে ৫০০: একই বিলে ৪০০-র পরে ২০০ থামে, অন্য বিলে চলে। */
    public function test_a_per_bill_ceiling_counts_only_that_bill(): void
    {
        $this->ceiling(PromotionBudget::PER_BILL, '500');
        $this->spent('400', 8101, $this->buyer);

        $this->assertFalse($this->fits('200', 8101, $this->buyer), 'একই বিলে ৬০০ — প্রতি বিলের ছাদ মানেনি।');
        $this->assertTrue($this->fits('200', 8102, $this->buyer), 'অন্য বিলের খরচ এই বিলের ছাদে গোনা হয়েছে।');
    }

    /** ⛔ প্রতি ক্রেতা ১,০০০: একই ক্রেতার ৯০০-র পরে ২০০ থামে, অন্য ক্রেতার চলে। */
    public function test_a_per_customer_ceiling_counts_only_that_customer(): void
    {
        $this->ceiling(PromotionBudget::PER_CUSTOMER, '1000');
        $this->spent('900', 8201, $this->buyer);

        $this->assertFalse($this->fits('200', 8202, $this->buyer), 'একই ক্রেতা দুই বিলে ছাদ পেরিয়ে গেছেন।');
        $this->assertTrue($this->fits('200', 8203, $this->otherBuyer), 'অন্য ক্রেতার খরচ এই ক্রেতার ছাদে গোনা হয়েছে।');
    }

    /** ⛔ দৈনিক ১,০০০: গতকালের ৯০০ আজকের ছাদে নেই, আজকের ৯০০ আছে। */
    public function test_a_daily_ceiling_forgets_yesterday(): void
    {
        $this->ceiling(PromotionBudget::PER_DAY, '1000');
        $this->spent('900', 8301, $this->buyer, Carbon::now()->subDay());

        $this->assertTrue($this->fits('200', 8302, $this->buyer), 'গতকালের খরচ আজকের দৈনিক ছাদে গোনা হয়েছে।');

        $this->spent('900', 8303, $this->buyer);
        $this->assertFalse($this->fits('200', 8304, $this->buyer), 'আজকের খরচ দৈনিক ছাদে গোনা হয়নি।');
    }

    /** ⛔ মাসিক ১,০০০: গত মাসের ৯০০ এই মাসে নেই, এই মাসের ৯০০ আছে। */
    public function test_a_monthly_ceiling_forgets_last_month(): void
    {
        $this->ceiling(PromotionBudget::PER_MONTH, '1000');
        $this->spent('900', 8401, $this->buyer, Carbon::now()->startOfMonth()->subDay());

        $this->assertTrue($this->fits('200', 8402, $this->buyer), 'গত মাসের খরচ এই মাসের ছাদে গোনা হয়েছে।');

        $this->spent('900', 8403, $this->buyer);
        $this->assertFalse($this->fits('200', 8404, $this->buyer), 'এই মাসের খরচ মাসিক ছাদে গোনা হয়নি।');
    }

    /** ⭐ বাতিল বিল জানালার ভিতরেও জায়গা ছেড়ে দেয়। */
    public function test_a_reversed_line_frees_its_window(): void
    {
        $this->ceiling(PromotionBudget::PER_CUSTOMER, '1000');
        $row = $this->spent('900', 8501, $this->buyer);
        $row->reversed_at = Carbon::now();
        $row->save();

        $this->assertTrue($this->fits('900', 8502, $this->buyer));
    }

    /**
     * ⭐ একই ধরনের ছাদ দুই জানালায় একসাথে থাকে — গোটা অফার ১০,০০০ আর প্রতি বিল ৫০০।
     *
     * ⚠️ বিপজ্জনক ইনপুট: অফারের মোটে জায়গা আছে, কিন্তু বিলের ছাদ ছাড়ায়।
     */
    public function test_both_windows_are_checked_together(): void
    {
        $this->ceiling(PromotionBudget::PER_OFFER, '10000');
        $this->ceiling(PromotionBudget::PER_BILL, '500');

        $this->assertFalse($this->fits('600', 8601, $this->buyer), 'বিলের ছাদ ছাড়িয়েও অফারের মোট দেখে চলে গেছে।');
        $this->assertTrue($this->fits('500', 8601, $this->buyer));
    }

    /** ⭐ পর্দা থেকে বিলের ছাদ বসানো যায়, আর গোটা অফারের ছাদের পাশে আলাদা সারি হয়। */
    public function test_the_page_sets_a_per_bill_ceiling_beside_the_offer_ceiling(): void
    {
        foreach (['offer' => '10000', 'bill' => '500'] as $per => $amount) {
            $this->post(route('promotion.budget.store', $this->offer), [
                'kind' => 'total', 'per' => $per, 'ceiling' => $amount,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, PromotionBudget::query()->where('promotion_id', $this->offer->id)->count());
        $this->assertFalse($this->fits('600', 8701, $this->buyer));
    }
}
