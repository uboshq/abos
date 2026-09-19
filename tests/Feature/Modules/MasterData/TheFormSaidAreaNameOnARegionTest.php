<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লোকেশনের ফর্ম — নামের ঘর স্তরের নাম বলে।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * স্তরের নাম বদলানোর পরে (06e0d8cd: Area → Region, Territory → Area)
 * "নতুন Region" ফর্মে লেখা থাকত *"Area name (English)"* — ঘরের লেখাটা
 * ছিল স্থির, আর "এলাকা" অর্থে "Area" লেখা। মালিক দাগিয়ে দিলেন: *"eigulo
 * sob ek sathe change howar kotha"*।
 *
 * ⭐ এখন ঘরের লেখা স্তরের নাম থেকে বানানো হয়, তাই স্তরের নাম বদলালে এটাও
 * একসাথে বদলায়।
 */
final class TheFormSaidAreaNameOnARegionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_name_boxes_say_the_level_being_made(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        foreach ([Location::COUNTRY, Location::DIVISION] as $level) {
            $html = $this->get(route('master_data.location.create', ['level' => $level]))->assertOk()->getContent();

            $levelName = __('master_data::level.'.$level);
            $expected = __('master_data::field.location_name_en', ['level' => $levelName]);

            $this->assertStringContainsString(e($expected), $html, "{$level}: নামের ঘর \"{$expected}\" বলছে না।");
            $this->assertStringContainsString($levelName, $expected, 'ঘরের লেখায় স্তরের নামই নেই।');
        }
    }
}
