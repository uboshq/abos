<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\DataScope;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\NotificationAuditLog;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ খবর শাখা আর কোম্পানির দেয়াল মানে — বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১ (মালিকের স্পেক §১৩, cloud task-এর কঠিন নিয়ম)।
 *
 * ⭐ দাবি:
 *   · এক শাখায় আটকানো কর্মী অন্য শাখার কাগজের খবর ঘণ্টায়, "আমার বিজ্ঞপ্তি"-তে বা না-পড়া গোনায় দেখেন না
 *   · খবরটা তাঁর সারিতে থাকলেও খোলা যায় না — কারণ বলা হয়, চেষ্টাটা নিরীক্ষার খাতায় যায়
 *   · নিজের শাখার কাগজের খবর খোলে, কাগজের পাতায় নিয়ে যায়
 *   · অন্য কোম্পানির খবর খোলা যায় না (৪০৪/৪০৩), আর অন্যের খবরে ৪০৩
 */
final class ANotificationLeakedPastTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_branch_limited_clerk_never_sees_or_opens_another_branchs_paper(): void
    {
        $clerk = $this->clerkLimitedTo('MMS');
        $mine = $this->claimIn('MMS');
        $theirs = $this->claimIn('NTK');

        $notify = app(NotificationService::class);
        $here = $notify->send($clerk, 'approval.rejected', 'MMS-এর দাবি ফেরত', null, route('finance.insurance.claim.show', $mine), about: $mine);
        $there = $notify->send($clerk, 'approval.rejected', 'NTK-এর দাবি ফেরত', null, route('finance.insurance.claim.show', $theirs), about: $theirs);

        $this->assertSame($this->branch('NTK')->id, (int) $there->branch_id, 'দৃশ্যটাই বানানো যায়নি — খবরে কাগজের শাখা নেই');
        $this->assertSame(1, $notify->unreadCount($clerk), '⛔ অন্য শাখার খবর না-পড়া গোনায়');

        $this->actingAs($clerk)->get(route('notifications.index'))->assertOk()
            ->assertSee('MMS-এর দাবি ফেরত')->assertDontSee('NTK-এর দাবি ফেরত');
        $this->actingAs($clerk)->get(route('notifications.settings'))->assertOk()
            ->assertDontSee('NTK-এর দাবি ফেরত');

        $this->actingAs($clerk)->get(route('notifications.open', $there))->assertOk()
            ->assertSee('data-notify-no-access', false);
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'open_denied')->where('target_id', $there->id)->count(),
            '⛔ অনুমতি ছাড়া খোলার চেষ্টা খাতায় উঠল না');

        $this->actingAs($clerk)->get(route('notifications.open', $here))
            ->assertRedirect(route('finance.insurance.claim.show', $mine));
    }

    public function test_a_paper_that_left_the_reach_after_the_notification_is_not_opened(): void
    {
        $clerk = $this->clerkLimitedTo('MMS');
        $claim = $this->claimIn('MMS');
        $note = app(NotificationService::class)->send($clerk, 'approval.rejected', 'দাবি ফেরত', null, '/x', about: $claim);

        // ⓘ কাগজটা পরে অন্য শাখায় গেল — খবরের সারিতে পুরনো শাখা, কিন্তু খোলার আগে কাগজটা আবার দেখা হয়
        $claim->forceFill(['branch_id' => $this->branch('NTK')->id])->save();

        $this->actingAs($clerk)->get(route('notifications.open', $note))->assertOk()->assertSee('data-notify-no-access', false);
    }

    public function test_another_companys_or_another_persons_notification_never_opens(): void
    {
        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $mine = app(NotificationService::class)->send($clerk, 'report_ready', 'আমার রিপোর্ট');
        $ownerNote = app(NotificationService::class)->send($this->owner, 'report_ready', 'মালিকের রিপোর্ট', evenToSelf: true);

        $this->actingAs($clerk)->get(route('notifications.open', $ownerNote))->assertForbidden();
        $this->actingAs($clerk)->post(route('notifications.archive', $ownerNote))->assertForbidden();
        $this->actingAs($clerk)->post(route('notifications.read', $ownerNote))->assertForbidden();
        $this->assertNull($ownerNote->fresh()->read_at);

        // ⓘ অন্য কোম্পানির খবর — কোম্পানির দেয়াল মডেলের নিজের
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->actingAs($this->owner);
        $foreign = CompanyContext::forCompany($beta->id, fn () => app(NotificationService::class)->send($clerk, 'report_ready', 'অন্য কোম্পানির'));

        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($clerk)->get(route('notifications.open', $foreign))->assertNotFound();
        $this->actingAs($clerk)->get(route('notifications.index'))->assertOk()
            ->assertSee('আমার রিপোর্ট')->assertDontSee('অন্য কোম্পানির');
        $this->assertNotNull($mine);
    }

    private function claimIn(string $code): InsuranceClaim
    {
        CompanyContext::set($this->company->id, $this->branch($code)->id);

        $insurer = Institution::query()->create(['company_id' => $this->company->id, 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer '.$code]);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'POL-'.$code, 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '500000', 'premium' => '1000', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString(),
        ]);

        $claim = app(InsuranceClaimService::class)->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Fire', 'claimed_amount' => '1000',
        ]);

        CompanyContext::set($this->company->id, $this->branch('MMS')->id);

        return $claim;
    }

    private function clerkLimitedTo(string $code): User
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $this->branch($code)->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch($code)->id,
        ]);
        app(DataScope::class)->forget();

        return $clerk->fresh();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
