<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Services\LocationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিষ্ক্রিয় করলে স্তরের তালিকা থেকে উধাও — আর নয়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: *"Deactive korlei List theke Hariye zacche keno? ekhane Status
 * dewar dorkar"*। ⓘ "নিষ্ক্রিয়গুলোও দেখাও" টিক ছাঁকনির প্যানেলে লুকানো
 * ছিল, তাই মনে হত সারিটা মুছে গেছে।
 *
 * ⭐ এখন সারি থাকে, অবস্থার কলামে "নিষ্ক্রিয়" লেখা পিল (চাপলে সক্রিয় করার
 * ঠিকানা), আর ট্যাবের সংখ্যায়ও গোনা হয়।
 */
final class AnInactiveRowVanishedFromItsListTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deactivated_division_stays_on_its_list_marked_inactive(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $country = Location::query()->atLevel(Location::COUNTRY)->first()
            ?? app(LocationService::class)->create(['level' => Location::COUNTRY, 'name_en' => 'Testland']);

        $gone = app(LocationService::class)->create([
            'level' => Location::DIVISION, 'name_en' => 'Faded Division', 'parent_id' => $country->id,
        ]);

        app(LocationService::class)->deactivate($gone);

        $html = $this->get(route('master_data.location.level', ['level' => Location::DIVISION]))->assertOk()->getContent();

        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);
        $row = collect($rows[1])->first(fn (string $r) => str_contains($r, 'Faded Division'));

        $this->assertNotNull($row, 'নিষ্ক্রিয় বিভাগটা তালিকা থেকে উধাও।');

        $inactive = [__('core.state.inactive', [], 'bn'), __('core.state.inactive', [], 'en')];
        $this->assertTrue(collect($inactive)->contains(fn ($w) => str_contains(strip_tags($row), $w)), 'সারিতে "নিষ্ক্রিয়" লেখা নেই।');
        $this->assertStringContainsString(route('master_data.location.activate', $gone), $row, 'পিল চাপলে সক্রিয় করার ঠিকানা নেই।');
    }
}
