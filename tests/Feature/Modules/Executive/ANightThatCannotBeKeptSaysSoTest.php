<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Inventory\Services\StockFacts;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * রাতের হিসাব লেখা না গেলে আদেশটা সেটা বলে — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ ঐ রাতের হিসাব পরে আর লেখা যায় না (আদেশ সবসময় "আজ" লেখে), তাই এক কোম্পানির ভুলে বাকিরা থামলে বা
 * চুপচাপ বাদ পড়লে ইতিহাসে চিরকালের ফাঁক থাকে, আর কেউ জানে না।
 */
final class ANightThatCannotBeKeptSaysSoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
    }

    public function test_one_companys_error_does_not_stop_the_others_or_the_pruning_and_the_run_fails(): void
    {
        $companies = Company::query()->where('is_active', true)->orderBy('id')->get();
        $this->assertGreaterThan(1, $companies->count(), 'প্রস্তুতিটাই ভুল — একটার বেশি চালু কোম্পানি নেই।');
        $first = (int) $companies->first()->id;

        // ⓘ প্রথম কোম্পানিতে মজুদের সংখ্যা ভুলে থামে — বাকিদের বেলায় আসল ক্লাস
        $this->app->bind(StockFacts::class, function () use ($first): StockFacts {
            if (CompanyContext::id() === $first) {
                throw new RuntimeException('test: stock facts broke in the first company');
            }

            return new StockFacts;
        });

        $old = Carbon::today()->subYears(4)->toDateString();
        DB::table('executive_snapshots')->insert([
            'company_id' => $first, 'place' => 0, 'taken_on' => $old, 'sales' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('abos:owner-snapshot')->assertFailed();

        $today = Carbon::today()->toDateString();

        foreach ($companies->slice(1) as $company) {
            $this->assertTrue(
                DB::table('executive_snapshots')->where('company_id', $company->id)->where('place', 0)->where('taken_on', $today)->exists(),
                "⛔ প্রথম কোম্পানির ভুলে {$company->code}-এর আজ রাতের হিসাব লেখা হয়নি।",
            );
        }

        $this->assertFalse(DB::table('executive_snapshots')->where('taken_on', $old)->exists(),
            '⛔ এক কোম্পানির ভুলে পুরনো সারি সরানোও থেমে গেছে।');
    }

    public function test_a_company_skipped_for_want_of_a_super_admin_fails_the_run_and_is_named(): void
    {
        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        // ⓘ ফ্যামিলি মার্টে কোনো super_admin নেই
        DB::table('model_has_roles')
            ->where('company_id', $beta->id)
            ->whereIn('role_id', DB::table('roles')->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->pluck('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->artisan('abos:owner-snapshot')
            ->expectsOutputToContain($beta->code)
            ->assertFailed();

        $today = Carbon::today()->toDateString();
        $this->assertTrue(DB::table('executive_snapshots')->where('company_id', $alpha->id)->where('place', 0)->where('taken_on', $today)->exists(),
            'প্রস্তুতিটাই ভুল — ট্রেড ডিপোর হিসাবও লেখা হয়নি।');
        $this->assertFalse(DB::table('executive_snapshots')->where('company_id', $beta->id)->where('taken_on', $today)->exists(),
            'প্রস্তুতিটাই ভুল — super_admin ছাড়াই ফ্যামিলি মার্টের হিসাব লেখা হলো।');
    }
}
