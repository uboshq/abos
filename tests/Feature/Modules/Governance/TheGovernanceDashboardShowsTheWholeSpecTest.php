<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Modules\Governance\Dashboard\GovernanceDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * নিরীক্ষার ড্যাশবোর্ড — নকশার বাকিটা (৬ অক্টোবর ২০২৬): এ মাসে কাগজ কীভাবে বেরোল, আর শেষ ঢোকার চেষ্টা আইপিসহ।
 *
 * ⓘ কাগজের প্রতিটা ভাগ আগে-পরে মাপা; বাড়তিটা ঠিক এ মাসের এই কোম্পানির সারিগুলো।
 * ⛔ আগের মাসের কাগজ, আর অন্য কোম্পানির কাগজ গোনায় নেই। ⛔ অন্য কোম্পানির ঢোকার চেষ্টা (আর তার আইপি) তালিকায় নেই,
 * যদিও সেটাই সবচেয়ে নতুন। ⛔ সুইচ বন্ধে নতুন চার্ট বা তালিকা নেই — পুরনো "সর্বশেষ বদল" তালিকা হুবহু থাকে।
 */
final class TheGovernanceDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_papers_this_month_and_the_ip_of_each_latest_login_stay_inside_the_company(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        config(['abos.dashboards_v2' => false]);
        $old = GovernanceDashboard::dashboard();
        $this->assertNull($this->papers($old), '⛔ সুইচ বন্ধে কাগজের চার্ট।');
        $this->assertCount(1, $old->listings, '⛔ সুইচ বন্ধে পুরনো তালিকার পাশে নতুন তালিকা।');

        config(['abos.dashboards_v2' => true]);
        $before = $this->parts(GovernanceDashboard::dashboard());

        $paper = fn (int $companyId, string $how, Carbon $at) => DocumentDelivery::query()->forceCreate([
            'company_id' => $companyId, 'document_type' => 'test', 'document_id' => 1, 'document_no' => 'T-1',
            'paper' => 'a4', 'how' => $how, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $paper($company->id, 'printed', now());
        $paper($company->id, 'printed', now());
        $paper($company->id, 'downloaded', now());
        $paper($company->id, 'shared', Carbon::today()->startOfMonth()->subDays(2));   // আগের মাস
        $paper($other->id, 'opened', now());                                            // অন্য কোম্পানি

        $after = GovernanceDashboard::dashboard();
        $now = $this->parts($after);
        $grew = fn (string $how) => (int) $now[__('governance::dashboard.paper_'.$how)] - (int) $before[__('governance::dashboard.paper_'.$how)];

        $this->assertSame([2, 1, 0, 0], [$grew('printed'), $grew('downloaded'), $grew('shared'), $grew('opened')],
            '⛔ কাগজের গোনা ভুল — আগের মাস বা অন্য কোম্পানির সারিও গোনা।');
        $this->assertSame(DocumentDelivery::query()->where('created_at', '>=', Carbon::today()->startOfMonth())->count(), array_sum(array_map('intval', $now)),
            'যোগফল এ মাসের এই কোম্পানির কাগজের সমান নয়।');
        $this->assertSame('columns', $this->papers($after)->chart);
        $this->assertNotNull($this->papers($after)->range, '⛔ সময়ের চার্টে তারিখের পরিসর নেই।');

        // ⓘ ঢোকার চেষ্টা — নিজেরটা এক মিনিট পরে, অন্য কোম্পানিরটা দুই মিনিট পরে (দেয়াল না থাকলে ওটাই প্রথমে বসত)
        LoginAttempt::query()->forceCreate([
            'company_id' => $company->id, 'user_id' => $owner->id, 'identifier' => $owner->email, 'succeeded' => true,
            'ip_address' => '203.0.113.7', 'created_at' => now()->addMinute(),
        ]);
        LoginAttempt::query()->forceCreate([
            'company_id' => $other->id, 'user_id' => null, 'identifier' => 'someone@fmart.test', 'succeeded' => false,
            'reason' => LoginAttempt::WRONG_PASSWORD, 'ip_address' => '198.51.100.9', 'created_at' => now()->addMinutes(2),
        ]);

        $list = collect(GovernanceDashboard::dashboard()->listings)->firstWhere('label', __('governance::dashboard.latest_logins'));
        $this->assertInstanceOf(Listing::class, $list, 'শেষ ঢোকার তালিকা নেই।');
        $ip = collect($list->columns)->firstWhere('key', 'ip');
        $this->assertNotNull($ip, 'আইপির ঘর নেই।');
        $ips = $list->rows->map(fn ($row) => ($ip['render'])($row))->all();
        $this->assertSame('203.0.113.7', $ips[0], 'নিজের সবশেষ ঢোকা প্রথমে নয়, বা আইপি নেই।');
        $this->assertNotContains('198.51.100.9', $ips, '⛔ অন্য কোম্পানির চেষ্টার আইপি দেখা গেছে।');

        // ⓘ আসল পাতাতেও — চালুতে আইপি দেখা যায়
        $this->get(route('module.dashboard', ['module' => 'governance']))->assertOk()
            ->assertSee('203.0.113.7')->assertDontSee('198.51.100.9');

        config(['abos.dashboards_v2' => false]);
        $off = GovernanceDashboard::dashboard();
        $this->assertSame([], $off->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');
        $this->assertCount(1, $off->listings, '⛔ সুইচ বন্ধে নতুন তালিকা।');
    }

    private function papers(DashboardDefinition $d): ?Breakdown
    {
        return collect($d->panels)->firstWhere('label', __('governance::dashboard.papers_this_month'));
    }

    /** @return array<string, string> */
    private function parts(DashboardDefinition $d): array
    {
        $panel = $this->papers($d);
        $this->assertNotNull($panel, 'কাগজের চার্ট নেই।');

        return array_column($panel->parts, 'value', 'label');
    }
}
