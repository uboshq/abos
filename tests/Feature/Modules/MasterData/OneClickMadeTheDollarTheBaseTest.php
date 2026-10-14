<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Services\ExchangeRateService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এক ক্লিকে ডলার ভিত্তি মুদ্রা হয়ে যেত — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ মুদ্রার তালিকায় "ডিফল্ট" মানে ভিত্তি মুদ্রা, আর "ডিফল্ট করুন" বোতাম কোনো শর্ত দেখত না — লেখা হারগুলো টাকার সাপেক্ষে,
 * তাই সব রূপান্তর নীরবে উল্টে যেত। ⓘ সঙ্গে: যে তালিকায় ডিফল্ট নেই (ব্র্যান্ড), সেখানে ঠিকানাটা সরাসরি ডাকলে ৫০০ আসত।
 */
final class OneClickMadeTheDollarTheBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_base_currency_stays_once_rates_exist_and_a_list_without_default_has_no_such_door(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set('master_data.multi_currency_enabled', true);
        tap(app(SettingsService::class))->flush();
        app(MasterListService::class)->installDefaults();

        $bdt = Currency::query()->where('code', 'BDT')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();
        app(ExchangeRateService::class)->record($usd, '2026-10-01', '119.50');

        $this->post('/master-data/currencies/'.$usd->id.'/default')->assertRedirect()->assertSessionHasErrors('is_default');
        $this->assertTrue((bool) $bdt->fresh()->is_default, 'হার থাকা অবস্থায় ভিত্তি মুদ্রা বদলে গেল।');
        $this->assertFalse((bool) $usd->fresh()->is_default);

        $brand = Brand::query()->create(['code' => 'TBRND', 'name_en' => 'Test brand', 'is_active' => true]);
        $this->assertFalse(Brand::supportsDefault(), 'ব্র্যান্ডে ডিফল্ট আছে — দাবিটা কিছু মাপছে না।');
        $this->post('/master-data/brands/'.$brand->id.'/default')->assertNotFound();
    }
}
