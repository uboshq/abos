<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use App\Modules\Promotion\Services\BudgetGuard;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * রিপোর্টগুলো খাতায় যা আছে তা-ই গোনে — স্পেক §১৭।
 *
 * ── ⚠️ কী ধরা হচ্ছে ─────────────────────────────────────────────────
 * ⓘ রিপোর্ট কখনো লাল হয় না — ভুল সংখ্যাও দেখতে বিশ্বাসযোগ্য। ⛔ বাতিল
 * বিলের সুবিধা ব্যবহারে ঢুকে গেলে অফারটা বেশি খরচের দেখাত, আর বাজেটের
 * রিপোর্ট পাহারার চেয়ে আলাদা কথা বললে মালিক ভুল সিদ্ধান্ত নিতেন।
 *
 * ⭐ চাবির দাবিটা **একই মানুষ** — কেবল চাবিটা বদলায়। ⓘ দুইজন আলাদা
 * মানুষ হলে ৪০৩-টা সদস্যপদ বা সুইচের কারণেও আসতে পারত।
 */
final class TheReportsCountedWhatTheBooksKeptTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Promotion $offer;

    private Customer $customer;

    private Product $product;

    private PromotionGiftIssue $gift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        $this->offer = $this->offer('PROM-R-0001', PromotionStatus::ACTIVE,
            Carbon::today()->subDay(), Carbon::today()->addWeek());

        /* ⓘ চলতি সুবিধা — ব্যবহারে গোনা হবে */
        $this->applied(BenefitKind::AMOUNT, '250');

        /* ⛔ বাতিল বিলের সুবিধা — ব্যবহারে নয়, কেবল ফেরানোর রিপোর্টে */
        $this->applied(BenefitKind::AMOUNT, '900', [
            'reversed_at' => now(),
            'reversed_by' => $this->owner->id,
        ]);

        /* ⭐ হাতে বদলানো — ১০০ থেকে ৬০ */
        $this->applied(BenefitKind::PERCENT, '60', [
            'benefit_amount' => '10',
            'was_overridden' => true,
            'original_worth' => '100',
            'override_reason' => 'Dealer asked twice',
            'overridden_by' => $this->owner->id,
            'overridden_at' => now(),
        ]);

        /* ⓘ উপহার — ৫ দেওয়া, ২ ফেরত, একক খরচ ৪০ */
        $goods = $this->applied(BenefitKind::GOODS, '200', ['product_id' => $this->product->id]);

        $warehouse = Warehouse::query()->create([
            'code' => 'RPTWH', 'name_en' => 'Report store', 'name_bn' => 'রিপোর্টের গুদাম', 'is_active' => true,
        ]);

        $this->gift = PromotionGiftIssue::query()->forceCreate([
            'company_id' => $this->company->id,
            'code' => 'GIFT-R-0001',
            'promotion_application_id' => $goods->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $warehouse->id,
            'qty' => '5',
            'returned_qty' => '2',
            'unit_cost' => '40',
            'issued_by' => $this->owner->id,
            'issued_at' => now(),
        ]);

        PromotionBudget::query()->create([
            'promotion_id' => $this->offer->id,
            'kind' => PromotionBudget::TOTAL,
            'ceiling' => '10000',
            'warn_at_percent' => 80,
        ]);

        $this->offer('PROM-R-0002', PromotionStatus::EXPIRED,
            Carbon::today()->subDays(20), Carbon::today()->subDays(2));

        $this->offer('PROM-R-0003', PromotionStatus::CANCELLED,
            Carbon::today()->subDays(5), Carbon::today()->addDays(5));
    }

    private function offer(string $code, PromotionStatus $status, Carbon $from, Carbon $to): Promotion
    {
        $offer = new Promotion(['name_en' => 'Report '.$code, 'starts_on' => $from, 'ends_on' => $to]);
        $offer->code = $code;
        $offer->type = PromotionType::FIXED_DISCOUNT;
        $offer->status = $status;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer;
    }

    /** @param array<string, mixed> $extra */
    private function applied(BenefitKind $kind, string $worth, array $extra = []): PromotionApplication
    {
        return PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => random_int(10000, 99999),
            'customer_id' => $this->customer->id,
            'benefit_kind' => $kind,
            'benefit_amount' => $worth,
            'worth' => $worth,
            'applied_by' => $this->owner->id,
            ...$extra,
        ]);
    }

    private function report(string $key): ReportResult
    {
        return app(ReportEngine::class)->run($key, $this->range());
    }

    /** @return array{from: string, to: string} */
    private function range(): array
    {
        return [
            'from' => Carbon::today()->subDays(30)->toDateString(),
            'to' => Carbon::today()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function rowFor(ReportResult $result, string $column, string $value): array
    {
        foreach ($result->rows as $row) {
            if ((string) ($row[$column] ?? '') === $value) {
                return $row;
            }
        }

        $this->fail("রিপোর্টে {$column} = {$value} সারিটা নেই।");
    }

    /**
     * ⛔ বাতিল বিলের সুবিধা ব্যবহারে গোনা হয় না — কিন্তু হারিয়েও যায় না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: ৯০০ টাকার বাতিল সারি, বাকি তিনটার চেয়ে বড়।
     * ⓘ পাল্টা-দাবি: ঐ ৯০০ ফেরানোর রিপোর্টে আছে — নাহলে *"সব বাদ দাও"*
     * লিখেও প্রথম অর্ধেকটা সবুজ হত।
     */
    public function test_a_reversed_application_is_left_out_of_utilization(): void
    {
        $row = $this->rowFor($this->report('promotion.utilization'), 'promotion_code', 'PROM-R-0001');

        $this->assertSame(0, bccomp((string) $row['worth'], '510', 4),
            'ব্যবহারে '.$row['worth'].' — ২৫০ + ৬০ + ২০০ = ৫১০ হওয়ার কথা; বাতিল ৯০০ ঢুকে গেছে কি?');
        $this->assertSame(3, (int) $row['application_count']);

        $reversed = $this->rowFor($this->report('promotion.reversals'), 'promotion_code', 'PROM-R-0001');
        $this->assertSame(0, bccomp((string) $reversed['worth'], '900', 4),
            'বাতিল সুবিধাটা ফেরানোর রিপোর্টে নেই — তাহলে সেটা কোথাও দেখা যায় না।');
    }

    /** ⭐ হাতে বদলের রিপোর্ট আগের ও পরের অঙ্ক, কারণ আর কে — সব দেখায়। */
    public function test_the_override_report_shows_before_and_after(): void
    {
        $row = $this->rowFor($this->report('promotion.overrides'), 'reason', 'Dealer asked twice');

        $this->assertSame(0, bccomp((string) $row['original_worth'], '100', 4));
        $this->assertSame(0, bccomp((string) $row['worth_after'], '60', 4));
        $this->assertSame(0, bccomp((string) $row['change_amount'], '-40', 4));
        $this->assertSame($this->owner->name, $row['changed_by_name']);
    }

    /**
     * ⛔ উপহারের খরচ জমে থাকা একক-খরচে, ফেরত বাদে — (৫ − ২) × ৪০ = ১২০।
     *
     * ⓘ ফেরত না বাদ দিলে ২০০ আসত; ⚠️ তাই ১২০ দুটো ভুলই আলাদা করে ধরে।
     */
    public function test_gift_cost_is_what_the_buyer_kept_at_the_frozen_cost(): void
    {
        $row = $this->rowFor($this->report('promotion.gifts'), 'gift_code', 'GIFT-R-0001');

        $this->assertSame(0, bccomp((string) $row['qty_net'], '3', 4));
        $this->assertSame(0, bccomp((string) $row['cost'], '120', 4));
        $this->assertSame(0, bccomp($this->gift->fresh()->cost(), (string) $row['cost'], 4),
            'রিপোর্ট আর মডেলের cost() দুই কথা বলছে।');
    }

    /**
     * ⭐ বাজেটের রিপোর্ট পাহারার সাথে একই কথা বলে।
     *
     * ⚠️ আলাদা হিসাব হলে রিপোর্ট বলত *"অনেক বাকি"* অথচ পাহারা বিল থামাত।
     */
    public function test_the_budget_report_says_what_the_guard_says(): void
    {
        $row = $this->rowFor($this->report('promotion.budgets'), 'promotion_code', 'PROM-R-0001');

        $guard = app(BudgetGuard::class)->used($this->offer, PromotionBudget::TOTAL);

        $this->assertSame(0, bccomp((string) $row['used'], $guard, 4),
            'রিপোর্টে '.$row['used'].', পাহারায় '.$guard.'।');
        $this->assertSame(0, bccomp((string) $row['used'], '510', 4));
    }

    /**
     * ⭐ প্রতিটা রিপোর্ট পাতা খোলে, আর তার বীজ-সারিটা দেখা যায়।
     *
     * ⓘ কেবল ২০০ যথেষ্ট নয় — খালি তালিকায় সারি আঁকার কোডটা চলেই না।
     */
    public function test_every_report_page_opens_with_its_row(): void
    {
        $markers = [
            'register' => 'PROM-R-0001',
            'active' => 'PROM-R-0001',
            'expired' => 'PROM-R-0002',
            'utilization' => 'PROM-R-0001',
            'by-customer' => $this->customer->code,
            'by-product' => $this->product->code,
            'discounts' => 'PROM-R-0001',
            'gifts' => 'GIFT-R-0001',
            'gift-stock' => $this->product->code,
            'budgets' => 'PROM-R-0001',
            'overrides' => 'Dealer asked twice',
            'reversals' => 'PROM-R-0001',
            'cancelled-offers' => 'PROM-R-0003',
        ];

        foreach ($markers as $slug => $marker) {
            $this->actingAs($this->owner)
                ->get(route('promotion.report.show', ['slug' => $slug, ...$this->range()]))
                ->assertOk()
                ->assertSee($marker);
        }
    }

    /** ⛔ চলতি অফারের পাতায় মেয়াদ শেষ বা বাতিল অফার আসে না। */
    public function test_the_running_list_holds_only_what_runs(): void
    {
        $this->actingAs($this->owner)
            ->get(route('promotion.report.show', ['slug' => 'active']))
            ->assertOk()
            ->assertSee('PROM-R-0001')
            ->assertDontSee('PROM-R-0002')
            ->assertDontSee('PROM-R-0003');
    }

    /** ⭐ একই মানুষ: `report` চাবি ছাড়া দরজা বন্ধ, চাবি পেলে খোলে। */
    public function test_the_same_person_needs_the_report_key(): void
    {
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('promotion.view', 'web'));

        $this->actingAs($clerk)
            ->get(route('promotion.report.show', ['slug' => 'utilization']))
            ->assertForbidden();

        $clerk->givePermissionTo(Permission::findOrCreate('promotion.report', 'web'));

        $this->actingAs($clerk->fresh())
            ->get(route('promotion.report.show', ['slug' => 'utilization', ...$this->range()]))
            ->assertOk()
            ->assertSee('PROM-R-0001');
    }
}
