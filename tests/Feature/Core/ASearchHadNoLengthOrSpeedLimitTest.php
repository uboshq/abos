<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Engines\Search\SearchHit;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⓘ খোঁজার লেখার দৈর্ঘ্য আর দরজার গতি — দুটোরই সীমা ছিল না; ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬
 * ([[SearchEngine::MAX_TERM]], `routes/web.php`)।
 *
 * ⓘ প্রতিটা খোঁজা ~৭০টা টেবিলে পূর্ণ `LIKE '%…%'`। হাজার অক্ষরের লেখা আর থামাহীন অনুরোধ — দুটোই ডাটাবেজকে বসিয়ে দিত।
 */
final class ASearchHadNoLengthOrSpeedLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_a_long_term_is_cut_to_the_limit_before_it_reaches_the_tables(): void
    {
        $name = 'Longname '.str_repeat('q', SearchEngine::MAX_TERM - 9);
        Customer::query()->create(['company_id' => $this->company->id, 'code' => 'LNG-1', 'name_en' => $name, 'is_active' => true]);

        // ⓘ প্রথম ১০০ অক্ষর নামটাই; বাকিটা কাটা পড়ে — আগে পুরো লেখাটা টেবিলে যেত, আর কিছুই মিলত না
        $hits = app(SearchEngine::class)->search($name.str_repeat('z', 400), $this->owner);

        $this->assertContains('LNG-1', array_map(fn (SearchHit $hit) => $hit->documentNo, $hits),
            '⛔ ১০০ অক্ষরের পরের লেখাও খোঁজায় গেল — সীমা নেই।');
    }

    public function test_the_search_door_has_a_speed_limit(): void
    {
        $this->actingAs($this->owner);

        // ⓘ এক অক্ষর — ডাটাবেজ ছোঁয় না, কেবল দরজা গোনে
        for ($i = 0; $i < 120; $i++) {
            $this->getJson(route('search', ['q' => 'x']))->assertOk();
        }

        $this->getJson(route('search', ['q' => 'x']))->assertStatus(429);
    }
}
