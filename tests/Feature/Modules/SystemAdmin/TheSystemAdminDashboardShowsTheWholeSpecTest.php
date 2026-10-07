<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\User;
use App\Modules\MasterData\Models\Unit;
use App\Modules\SystemAdmin\Dashboard\SystemAdminDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * সিস্টেম প্রশাসনের ড্যাশবোর্ড — নকশার বাকিটা (৬ অক্টোবর ২০২৬): পেছনের কাজ, দ্বিতীয় ধাপ, ব্যবহারকারী ও অনুমতির বদল,
 * ব্যবস্থার ভুল।
 *
 * ⓘ প্রতিটা সংখ্যা আগে-পরে মাপা, বাড়তিটা ঠিক যে সারিগুলো গোনার কথা।
 * ⛔ পেছনের কাজ কেবল সুপার অ্যাডমিন দেখেন (টেবিলে company_id নেই)। ⛔ অন্য কোম্পানির ব্যবহারকারী, বসানো-কিন্তু-নিশ্চিত-না
 * দ্বিতীয় ধাপ, আট দিন আগের বা অন্য ধরনের বা অন্য কোম্পানির দাগ, দশ দিন আগের বা কোম্পানিহীন বা অন্য কোম্পানির ভুল — কোনোটা
 * গোনায় নেই। ⛔ সুইচ বন্ধে নতুন কিছু নেই।
 */
final class TheSystemAdminDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_jobs_two_step_access_changes_and_errors_count_exactly_their_rows(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        config(['abos.dashboards_v2' => false]);
        $old = SystemAdminDashboard::dashboard();
        $this->assertNull($this->stat($old, 'jobs_failed'), '⛔ সুইচ বন্ধে পেছনের কাজ।');
        $this->assertSame([], $old->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');

        config(['abos.dashboards_v2' => true]);
        $before = SystemAdminDashboard::dashboard();
        $this->assertNotNull($this->stat($before, 'jobs_queued'), 'সুপার অ্যাডমিন পেছনের কাজ দেখছেন না।');

        // পেছনের কাজ — একটা অপেক্ষায়, একটা ব্যর্থ
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        // দ্বিতীয় ধাপ — চালু একজন; বসানো কিন্তু নিশ্চিত নয় একজন; অন্য কোম্পানির চালু একজন (গোনায় নেই)
        $user = function (Company $in, bool $secret, bool $confirmed): User {
            $u = User::factory()->create(['current_company_id' => $in->id]);
            $u->forceFill(['mfa_secret' => $secret ? 'SECRETDASH' : null, 'mfa_confirmed_at' => $confirmed ? now() : null])->saveQuietly();
            $u->companies()->attach($in->id);

            return $u;
        };
        $user($company, true, true);
        $user($company, true, false);
        $user($other, true, true);

        // বদলের দাগ — গোনায় একটা `roles_changed`; বাদ — আট দিন আগের, অন্য ধরনের, অন্য কোম্পানির
        $trail = fn (int $companyId, string $type, string $action, Carbon $at) => AuditTrail::query()->forceCreate([
            'company_id' => $companyId, 'user_id' => $owner->id, 'action' => $action,
            'auditable_type' => $type, 'auditable_id' => $owner->id, 'created_at' => $at,
        ]);
        $trail($company->id, User::class, 'roles_changed', now());
        $trail($company->id, User::class, 'roles_changed', Carbon::today()->subDays(8));
        $trail($company->id, Unit::class, 'roles_changed', now());
        $trail($other->id, User::class, 'roles_changed', now());

        // ভুল — এই সপ্তাহে একটা কেউ দেখেননি, একটা দেখা; বাদ — দশ দিন আগের, কোম্পানিহীন, অন্য কোম্পানির
        $error = fn (?int $companyId, Carbon $seen, bool $acknowledged) => ErrorEvent::query()->forceCreate([
            'company_id' => $companyId, 'fingerprint' => sha1((string) Str::uuid()), 'class' => 'RuntimeException', 'message' => 'dash',
            'times' => 3, 'first_seen_at' => $seen, 'last_seen_at' => $seen,
            'acknowledged_at' => $acknowledged ? now() : null, 'acknowledged_by' => $acknowledged ? $owner->id : null,
        ]);
        $error($company->id, now(), false);
        $error($company->id, now(), true);
        $error($company->id, Carbon::today()->subDays(10), false);
        $error(null, now(), false);
        $error($other->id, now(), false);

        $after = SystemAdminDashboard::dashboard();

        $this->assertSame(1, $this->number($after, 'jobs_queued') - $this->number($before, 'jobs_queued'), '⛔ অপেক্ষার কাজের গোনা ভুল।');
        $this->assertSame(1, $this->number($after, 'jobs_failed') - $this->number($before, 'jobs_failed'), '⛔ ব্যর্থ কাজের গোনা ভুল।');
        $this->assertSame(Stat::BAD, $this->stat($after, 'jobs_failed')->tone);

        $two = fn (DashboardDefinition $d) => array_column($this->panel($d, 'two_step')->parts, 'value', 'label');
        $this->assertSame(1, (int) $two($after)[__('system_admin::dashboard.two_step_on')] - (int) $two($before)[__('system_admin::dashboard.two_step_on')],
            '⛔ দ্বিতীয় ধাপ চালু ভুল — নিশ্চিত-না-করা বা অন্য কোম্পানির মানুষও গোনা।');
        $this->assertSame(1, (int) $two($after)[__('system_admin::dashboard.two_step_off')] - (int) $two($before)[__('system_admin::dashboard.two_step_off')]);
        $this->assertSame('donut', $this->panel($after, 'two_step')->chart);
        $this->assertSame(
            User::query()->whereHas('companies', fn ($q) => $q->whereKey($company->id))->count(),
            array_sum(array_map('intval', $two($after))),
            'দ্বিতীয় ধাপের যোগফল এই কোম্পানির ব্যবহারকারীর সমান নয়।',
        );

        $changes = fn (?DashboardDefinition $d) => $d === null || ($p = collect($d->panels)->firstWhere('label', __('system_admin::dashboard.access_changes'))) === null
            ? [] : array_column($p->parts, 'value', 'label');
        $roles = AuditTrail::actionInWords('roles_changed');
        $this->assertSame(1, (int) ($changes($after)[$roles] ?? 0) - (int) ($changes($before)[$roles] ?? 0),
            '⛔ রোল বদলের গোনা ভুল — পুরনো, অন্য ধরনের বা অন্য কোম্পানির দাগও গোনা।');
        $this->assertNotNull($this->panel($after, 'access_changes')->range, '⛔ সময়ের চার্টে তারিখের পরিসর নেই।');

        $errors = fn (DashboardDefinition $d) => array_column($this->panel($d, 'errors_week')->parts, 'value', 'label');
        $this->assertSame(1, (int) $errors($after)[__('system_admin::dashboard.errors_unseen')] - (int) $errors($before)[__('system_admin::dashboard.errors_unseen')],
            '⛔ না-দেখা ভুল ভুল গোনা — পুরনো, কোম্পানিহীন বা অন্য কোম্পানির ভুলও।');
        $this->assertSame(1, (int) $errors($after)[__('system_admin::dashboard.errors_seen')] - (int) $errors($before)[__('system_admin::dashboard.errors_seen')]);
        $this->assertNotNull($this->panel($after, 'errors_week')->range);

        // ⭐ প্রথম চার্ট আগের জায়গাতেই
        $this->assertSame(__('system_admin::dashboard.who_gets_in'), $after->panels[0]->label);

        // ⛔ একই দাবি সুপার অ্যাডমিন নন এমন মানুষের চোখে — পেছনের কাজ নেই
        $plain = User::factory()->create(['current_company_id' => $company->id]);
        $plain->companies()->attach($company->id);
        $this->actingAs($plain);
        $this->assertNull($this->stat(SystemAdminDashboard::dashboard(), 'jobs_queued'), '⛔ সুপার অ্যাডমিন নন, তবু গোটা সার্ভারের কাজ দেখছেন।');

        $this->actingAs($owner);
        config(['abos.dashboards_v2' => false]);
        $off = SystemAdminDashboard::dashboard();
        $this->assertSame([], $off->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');
        $this->assertCount(count($old->stats), $off->stats, '⛔ সুইচ বন্ধে ঘরের সংখ্যা বদলেছে।');
    }

    private function stat(DashboardDefinition $d, string $key): ?Stat
    {
        return collect($d->stats)->firstWhere('label', __('system_admin::dashboard.'.$key));
    }

    private function number(DashboardDefinition $d, string $key): int
    {
        $stat = $this->stat($d, $key);
        $this->assertNotNull($stat, "'{$key}' ঘরটা নেই।");

        return (int) $stat->value;
    }

    private function panel(DashboardDefinition $d, string $key): Breakdown
    {
        $panel = collect($d->panels)->firstWhere('label', __('system_admin::dashboard.'.$key));
        $this->assertInstanceOf(Breakdown::class, $panel, "'{$key}' চার্টটা নেই।");

        return $panel;
    }
}
