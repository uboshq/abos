<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হিসাবের সেটিংসে সম্পদের সীমা যা লেখা হত তাই বসত, আর তারপর প্রতিটা সম্পদ নিবন্ধন ৫০০ — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ⛔ পাতাটায় `number` ধরনের শাখা ছিল না: "1e5" বা "abc" সোজা জমত, আর [[FixedAssetService]]-এর `bccomp()` ঐ লেখায় ভাঙত।
 * ⭐ এখন সংখ্যা না হলে কিছুই বসে না আর পাতায় ভুল দেখায়; "৫০,০০০" পড়া হয় ৫০০০০।
 */
final class TheAssetThresholdWasStoredAsTypedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_threshold_that_is_not_a_number_is_refused_and_a_comma_number_is_read(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $now = fn (string $key) => tap(app(SettingsService::class))->flush()->get($key);

        $key = 'accounts.asset.capitalisation_threshold';
        $before = (string) $now($key);

        foreach (['1e5', 'abc'] as $bad) {
            $this->put(route('accounts.settings.update'), ['settings' => [$key => $bad]])
                ->assertRedirect()->assertSessionHasErrors('settings');
            $this->assertSame($before, (string) $now($key), "সম্পদের সীমায় '{$bad}' বসে গেল।");
        }

        $this->put(route('accounts.settings.update'), ['settings' => [$key => '৫০,০০০']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('50000', (string) $now($key), '"৫০,০০০" সংখ্যা হয়ে বসল না।');
    }
}
