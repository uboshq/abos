<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportFilters;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * রিপোর্টের ছাঁকনিতে অন্যের নম্বর — রিপোর্ট সেন্টার ধাপ ১ (সমন্বয়কের শর্ত খ, গ)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * রিপোর্টের পর্দা আঁকত কেবল তারিখ আর শাখা; গুদাম, পণ্য, ব্র্যান্ড… চলত হাতে লেখা ঠিকানায়, আর যেকোনো নম্বর সোজা
 * কোয়েরিতে বসত — অন্য কোম্পানির গুদামের নম্বর, বা যে গুদাম আমার দেখার নাগালের বাইরে।
 *
 * ── ⭐ এখন ([[ReportFilters::resolve()]]) ─────────────────────────────
 * প্রতিটা মান উৎস দিয়ে মেলে — যে মডিউলের জিনিস সে-ই উৎস ঘোষণা করে। তালিকার বাইরে হলে রিপোর্টই ফেরে, ছাঁকনি ফেলে
 * গোটা তালিকা নয়; আর বাছাই-ঘরের লেখা ("কোড · নাম") সার্ভারেই নম্বরে বদলায়।
 *
 * ⓘ প্রতিটা দাবি একই মানুষ, একই রিপোর্ট — বদলায় কেবল নম্বরটা, বা তাঁর সীমা (একই মানুষ দুইবার)।
 */
final class AReportFilterTookSomeoneElsesNumberTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = 'inventory.stock_position';

    private User $owner;

    private Warehouse $mine;

    private Warehouse $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->mine = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();
        $this->other = Warehouse::query()->where('code', 'WH-NTK')->firstOrFail();
    }

    /** ⭐ নিজের গুদাম চলে; ⛔ অন্য কোম্পানির গুদামের নম্বর — রিপোর্টই ফেরে, গোটা তালিকা নয়। */
    public function test_another_companys_warehouse_is_refused_not_dropped(): void
    {
        $theirs = $this->warehouseOfAnotherCompany();

        $this->assertSame((int) $this->mine->id, $this->resolved(['warehouse_id' => (string) $this->mine->id])['warehouse_id']);
        $this->assertSame('warehouse_id', $this->refused(['warehouse_id' => (string) $theirs]),
            '⛔ অন্য কোম্পানির গুদামের নম্বর রিপোর্টে বসে গেছে।');
    }

    /** ⛔ একই মানুষ, সীমা ছাড়া দুইটাই চলে; গুদামের সীমা বসলে নাগালের বাইরেরটা ফেরে, নিজেরটা চলে। */
    public function test_a_warehouse_outside_the_users_reach_is_refused(): void
    {
        /*
         * ⓘ একজন সাধারণ কর্মী, রিপোর্টের চাবিসহ — মালিক নন: ৩ অক্টোবর ২০২৬ থেকে মালিকের (super_admin) কোনো
         * ডেটার সীমা খাটে না, তাই তাঁকে দিয়ে সীমা মাপলে দাবিটা কিছুই প্রমাণ করত না।
         */
        $staff = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $staff->givePermissionTo('inventory.report');
        $staff = $staff->fresh();
        $this->actingAs($staff);

        $this->assertSame((int) $this->other->id, $this->resolved(['warehouse_id' => (string) $this->other->id])['warehouse_id'],
            'দৃশ্যটাই বানানো যায়নি — সীমা ছাড়াই দ্বিতীয় গুদামটা চলল না।');

        UserDataScope::query()->create([
            'company_id' => CompanyContext::id(),
            'user_id' => $staff->id,
            'scope_type' => UserDataScope::WAREHOUSE,
            'scope_id' => $this->mine->id,
        ]);
        app(DataScope::class)->forget();
        app()->forgetInstance(ReportFilters::class);

        $this->assertSame('warehouse_id', $this->refused(['warehouse_id' => (string) $this->other->id]),
            '⛔ নাগালের বাইরের গুদাম রিপোর্টে বসে গেছে।');
        $this->assertSame((int) $this->mine->id, $this->resolved(['warehouse_id' => (string) $this->mine->id])['warehouse_id']);
    }

    /** ⭐ বাছাই-ঘর লেখা পাঠায় — "কোড · নাম", বা কেবল কোড; সার্ভার নম্বরে বদলায় (শর্ত গ)। ⛔ অচেনা লেখা ফেরে। */
    public function test_the_picker_text_becomes_the_number(): void
    {
        $label = app(ReportFilters::class)->for('warehouse_id')->options()[(int) $this->mine->id];

        $this->assertStringStartsWith('WH-MMS · ', $label);
        $this->assertSame((int) $this->mine->id, $this->resolved(['warehouse_id' => $label])['warehouse_id']);
        $this->assertSame((int) $this->mine->id, $this->resolved(['warehouse_id' => 'WH-MMS'])['warehouse_id']);
        $this->assertSame('warehouse_id', $this->refused(['warehouse_id' => 'WH-NOWHERE']));
    }

    /** ⛔ অন্য কোম্পানির মানুষ বিক্রয়কর্মীর ছাঁকনিতে নেই (শর্ত খ) — তালিকাতেও না, নম্বরেও না। */
    public function test_a_salesman_of_another_company_is_not_on_the_list(): void
    {
        $stranger = User::query()->create([
            'name' => 'অচেনা',
            'email' => 'stranger.rf@abos.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $source = app(ReportFilters::class)->for('salesman_id');

        $this->assertArrayHasKey((int) $this->owner->id, $source->options());
        $this->assertArrayNotHasKey((int) $stranger->id, $source->options());
        $this->assertNull($source->resolve((string) $stranger->id));
    }

    /**
     * ⭐ পর্দায় ঘরগুলো আঁকা — রিপোর্ট যা ঘোষণা করে তার প্রতিটা, নিজের নাগালের তালিকাসহ; ⛔ আর ঠিকানায় অন্যের নম্বর
     * এলে পাতা কারণসহ ফেরে, গোটা তালিকা দেখায় না।
     */
    public function test_the_report_page_draws_the_pickers_and_turns_a_stranger_away(): void
    {
        $theirs = $this->warehouseOfAnotherCompany();

        $page = $this->get(route('inventory.report.show', 'stock-position'))->assertOk();

        foreach (['warehouse_id', 'product_id', 'brand_id', 'category_id'] as $key) {
            $page->assertSee('name="'.$key.'"', false);
        }

        $page->assertSee('<option value="WH-MMS · ', false);
        $page->assertDontSee('WH-FM', false);

        $this->get(route('inventory.report.show', ['stock-position', 'warehouse_id' => $theirs]))
            ->assertRedirect()
            ->assertSessionHasErrors('warehouse_id');
    }

    /** ⓘ ঘোষিত প্রতিটা চাবির বাংলা নাম আছে — নাহলে পর্দায় ইংরেজি বা চাবিটাই দেখাত। */
    public function test_every_declared_filter_has_a_bangla_name(): void
    {
        $sources = app(ReportFilters::class)->sources();

        foreach (['warehouse_id', 'product_id', 'brand_id', 'category_id', 'location_id', 'supplier_id', 'customer_id', 'salesman_id'] as $key) {
            $this->assertArrayHasKey($key, $sources, "⛔ '{$key}'-এর উৎস কোনো মডিউল ঘোষণা করেনি।");

            $bn = __($sources[$key]->label(), [], 'bn');
            $this->assertNotSame(__($sources[$key]->label(), [], 'en'), $bn, "⛔ '{$key}'-এর বাংলা নাম নেই।");
            $this->assertNotSame($sources[$key]->label(), $bn);
        }
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function warehouseOfAnotherCompany(): int
    {
        $fmart = Company::query()->where('code', 'FMART')->firstOrFail();

        return CompanyContext::forCompany($fmart->id, fn () => (int) app(WarehouseService::class)->create([
            'code' => 'WH-FM',
            'name_en' => 'Fmart Store',
            'name_bn' => 'এফমার্ট গুদাম',
            'branch_id' => Branch::query()->where('company_id', $fmart->id)->value('id'),
        ])->id);
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    private function resolved(array $filters): array
    {
        $report = app(ReportEngine::class)->get(self::REPORT);

        return app(ReportFilters::class)->resolve($report, $filters);
    }

    /** @param  array<string, string>  $filters */
    private function refused(array $filters): string
    {
        try {
            app(ReportEngine::class)->run(self::REPORT, $filters);
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('⛔ কিছুই ফেরেনি — অন্যের নম্বর রিপোর্টে বসে গেছে।');
    }
}
