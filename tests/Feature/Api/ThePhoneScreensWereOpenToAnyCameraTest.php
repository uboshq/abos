<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের পর্দা আড়াল আর উইজেটের অঙ্ক — মালিকের দুই সুইচ, `/me`-তে (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬: টাকার পর্দা
 * "সাম্প্রতিক অ্যাপ"-এ ছবি হয়ে থাকত, আর উইজেটে হাতের নগদ সবার চোখে)।
 *
 * দাবি: ডিফল্টে আড়াল চালু আর অঙ্ক লুকানো; মালিক বদলালে ফোন তা-ই পায়। (কেবল মালিক বদলান — TheMoneySwitchesWereAnyonesTest।)
 */
final class ThePhoneScreensWereOpenToAnyCameraTest extends TestCase
{
    use RefreshDatabase;

    public function test_by_default_screens_are_private_and_the_widget_hides_money_and_the_owner_can_change_both(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $sr = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        Sanctum::actingAs($sr, [AuthController::APP]);
        $me = $this->getJson('/api/v1/me')->assertOk()->json();
        $this->assertTrue($me['secureScreens'], '⛔ ডিফল্টে পর্দা খোলা।');
        $this->assertFalse($me['widgetAmounts'], '⛔ ডিফল্টে উইজেটে টাকার অঙ্ক।');

        app(SettingsService::class)->set(PhoneModules::SECURE_SCREENS, false);
        app(SettingsService::class)->set(PhoneModules::WIDGET_AMOUNTS, true);
        app()->forgetInstance(SettingsService::class);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);
        $me = $this->getJson('/api/v1/me')->assertOk()->json();
        $this->assertFalse($me['secureScreens']);
        $this->assertTrue($me['widgetAmounts']);
    }
}
