<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ লম্বা টানার মাঝে বদলানো সারি ফোনে কোনোদিন পৌঁছাত না (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[SyncService::recordSuccessfulPull()]])।
 *
 * ⚠️ জলচিহ্ন বসত "পুরোটা পেয়েছি"-র মুহূর্তে। পালা শুরু ১০:০০-এ, গ্রাহক বদলাল ১০:০১-এ, "পুরোটা পেয়েছি" এল ১০:০৩-এ — পরের টানা
 * শুরু ১০:০২ থেকে, তাই ১০:০১-এর বদল চিরতরে বাদ। ⭐ এখন জলচিহ্ন পালার শুরু (১০:০০)।
 */
final class AChangeDuringALongPullWasLostTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_row_changed_between_the_first_page_and_pull_complete_comes_in_the_next_pull(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        $sync = app(SyncService::class);

        $start = Carbon::parse(now()->toDateString().' 10:00:00');
        Carbon::setTestNow($start);
        $sync->pull($owner, 'phone-long', 'customer', 1000, null, true);

        Carbon::setTestNow($start->copy()->addMinute());
        $shop = Customer::query()->orderBy('id')->firstOrFail();
        $shop->update(['name_en' => 'Renamed During The Pull']);

        Carbon::setTestNow($start->copy()->addMinutes(3));
        $sync->recordSuccessfulPull('phone-long', 'customer', $owner);

        Carbon::setTestNow($start->copy()->addMinutes(4));
        $next = $sync->pull($owner, 'phone-long', 'customer', 1000, null, true);

        $ids = array_column($next['records'], 'entityId');
        $this->assertContains((string) $shop->public_id, $ids, '⛔ টানার মাঝে বদলানো গ্রাহক পরের টানায় এল না — চিরতরে বাদ');
    }
}
