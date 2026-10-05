<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDelegation;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Dashboard\ApprovalDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * অনুমোদনের ড্যাশবোর্ড — মালিকের পুরো নকশা (৬ অক্টোবর ২০২৬): আজ অনুমোদিত, আজ নাকচ, সিদ্ধান্তে গড় সময়, সময় পার হয়ে
 * যাওয়া, আজ চালু সইয়ের ভার, আর আমার সইয়ের অপেক্ষায় মডিউল ধরে।
 *
 * ⓘ দাবি, একই মালিক দুইবার (সুইচ বন্ধ, তারপর চালু): বন্ধে নতুন একটা ঘরও নেই, প্রথম চার্ট জায়গায়; চালুতে আসল ইঞ্জিনে
 * সই আর নাকচ দিলে প্রতিটা সংখ্যা ঠিক ততটা নড়ে — গতকালের সিদ্ধান্ত, সময়সীমা না পেরোনো অনুরোধ, মেয়াদ শেষ বা প্রত্যাহার
 * হওয়া ভার নড়ায় না — আর গড় সময় ও সময় পারের সংখ্যা সারির কাঁচা হিসাবের সাথে হুবহু মেলে।
 */
final class TheApprovalDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private const STATS = ['approved_today', 'rejected_today', 'average_time', 'overdue', 'delegations_today'];

    public function test_every_new_figure_moves_with_real_decisions_and_matches_the_rows(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        // ── সুইচ বন্ধ: নতুন একটা ঘরও নেই, প্রথম চার্ট জায়গায় ──
        config(['abos.dashboards_v2' => false]);
        $off = ApprovalDashboard::dashboard();
        foreach (self::STATS as $key) {
            $this->assertNull($this->stat($off, $key), "⛔ সুইচ বন্ধেও '{$key}'।");
        }
        $this->assertNull($this->panel($off, 'my_queue'), '⛔ সুইচ বন্ধেও "আমার সইয়ের অপেক্ষায়"।');
        $this->assertSame(__('approval::dashboard.how_long'), $off->panels[0]->label, '⛔ প্রথম চার্ট সরে গেছে।');

        config(['abos.dashboards_v2' => true]);
        $before = ApprovalDashboard::dashboard();
        $this->assertSame(__('approval::dashboard.how_long'), $before->panels[0]->label, '⛔ সুইচ চালুতে প্রথম চার্ট সরে গেছে — হোম এটাই দেখায়।');

        // ── আসল কাগজ ──
        $requester = User::factory()->create(['current_company_id' => $company->id]);
        $requester->companies()->attach($company->id);

        $make = function (int $hoursAgo, array $extra = []) use ($company, $requester, $owner): Approval {
            return Approval::query()->create([
                // ⓘ সত্যিকারের একটা মডেল — ইঞ্জিন শেষ সইয়ে কাগজটা খুঁজে পড়ে
                'company_id' => $company->id, 'approvable_type' => $company->getMorphClass(), 'approvable_id' => $company->id,
                'module' => 'approval', 'action' => 'spec_check', 'amount' => '100', 'status' => Approval::PENDING,
                'current_level' => 1, 'requested_by' => $requester->id, 'requested_at' => now()->subHours($hoursAgo),
                'assigned_to' => $owner->id, ...$extra,
            ]);
        };

        $yes = $make(10);
        $no = $make(30);
        $make(5, ['due_at' => now()->subHour()]);         // সময় পার
        $make(1, ['due_at' => now()->addDay()]);          // সময় আছে
        $make(2);                                         // সময়সীমা নেই
        // গতকালের সিদ্ধান্ত — "আজ"-এ নেই
        $make(50, ['status' => Approval::APPROVED, 'decided_at' => now()->subDay(), 'assigned_to' => null]);

        $engine = app(ApprovalEngine::class);
        $engine->approve($yes, $owner);
        $engine->reject($no, $owner, 'দরকার নেই');
        $this->assertSame(Approval::APPROVED, $yes->fresh()->status, 'প্রস্তুতিটাই ভুল — সই পড়েনি।');
        $this->assertSame(Approval::REJECTED, $no->fresh()->status, 'প্রস্তুতিটাই ভুল — নাকচ পড়েনি।');

        // ভার: একটা চালু, একটা মেয়াদ শেষ, একটা প্রত্যাহার
        foreach ([[-1, 1, null], [-5, -1, null], [-1, 1, now()]] as [$from, $to, $revoked]) {
            ApprovalDelegation::query()->create([
                'company_id' => $company->id, 'from_user_id' => $requester->id, 'to_user_id' => $owner->id,
                'starts_on' => Carbon::today()->addDays($from)->toDateString(), 'ends_on' => Carbon::today()->addDays($to)->toDateString(),
                'revoked_at' => $revoked, 'created_by' => $owner->id,
            ]);
        }

        $after = ApprovalDashboard::dashboard();
        $this->assertSame(__('approval::dashboard.how_long'), $after->panels[0]->label);

        $delta = fn (string $key) => (int) $this->stat($after, $key)->value - (int) $this->stat($before, $key)->value;

        $this->assertSame(1, $delta('approved_today'), '⛔ "আজ অনুমোদিত" একটা আসল সইয়ে ১ নড়েনি (বা গতকালেরটাও গুনেছে)।');
        $this->assertSame(1, $delta('rejected_today'), '⛔ "আজ নাকচ" একটা আসল নাকচে ১ নড়েনি।');
        $this->assertSame(1, $delta('overdue'), '⛔ "সময় পার" কেবল সময় পেরোনো অনুরোধটা গোনেনি।');
        $this->assertSame(1, $delta('delegations_today'), '⛔ "আজ চালু ভার" মেয়াদ শেষ বা প্রত্যাহার হওয়া ভারও গুনেছে।');

        // ── সারির কাঁচা হিসাবের সাথে হুবহু ──
        $this->assertSame((string) Approval::query()->where('status', Approval::PENDING)->whereNotNull('due_at')
            ->where('due_at', '<', now())->count(), $this->stat($after, 'overdue')->value, '⛔ "সময় পার" সারির সাথে মেলে না।');

        $this->assertSame((string) ApprovalDelegation::query()->active()->count(), $this->stat($after, 'delegations_today')->value);

        $month = Carbon::today()->startOfMonth();
        $decided = Approval::query()->whereIn('status', [Approval::APPROVED, Approval::REJECTED])
            ->whereBetween('decided_at', [$month, $month->copy()->endOfMonth()])->get();
        $seconds = $decided->avg(fn (Approval $a) => Carbon::parse($a->requested_at)->diffInSeconds(Carbon::parse($a->decided_at), true));
        $hours = $seconds / 3600;
        $expected = $hours < 48
            ? __('approval::dashboard.hours', ['n' => number_format(round($hours, 1), 1)])
            : __('approval::dashboard.days', ['n' => number_format(round($hours / 24, 1), 1)]);
        $this->assertSame($expected, $this->stat($after, 'average_time')->value, '⛔ গড় সময় সারির জমা থেকে সিদ্ধান্তের গড়ের সাথে মেলে না।');
        $this->assertSame(__('approval::dashboard.average_time_hint', ['count' => $decided->count()]), $this->stat($after, 'average_time')->hint);

        // ── আমার সইয়ের অপেক্ষায়: ইনবক্সের একই তালিকা, মডিউলের নিজের নামে, আড়াআড়ি দণ্ড ──
        $queue = $this->panel($after, 'my_queue');
        $this->assertNotNull($queue, '⛔ "আমার সইয়ের অপেক্ষায়" চার্ট নেই।');
        $this->assertSame('hbars', $queue->kind());

        $registry = app(\App\Core\Module\ModuleRegistry::class);
        $name = $registry->get('approval')->name[app()->getLocale()] ?? $registry->get('approval')->name['en'];
        $was = (int) (array_column($this->panel($before, 'my_queue')?->parts ?? [], 'value', 'label')[$name] ?? 0);
        $now = (int) (array_column($queue->parts, 'value', 'label')[$name] ?? 0);
        $this->assertSame(3, $now - $was, '⛔ আমার হাতে দেওয়া তিনটা অপেক্ষমাণ অনুরোধ "অনুমোদন" দণ্ডে আসেনি (বা সিদ্ধান্ত হওয়াগুলোও এসেছে)।');

        $inbox = $engine->pendingQueryFor($owner)->count();
        $this->assertSame($inbox, array_sum(array_map('intval', array_column($queue->parts, 'value'))), '⛔ দণ্ডের যোগফল ইনবক্সের সংখ্যা নয়।');
        $this->assertSame(__('approval::dashboard.my_queue_hint', ['count' => $inbox]), $queue->hint);
    }

    private function stat(DashboardDefinition $def, string $key): ?Stat
    {
        return collect($def->stats)->firstWhere('label', __('approval::dashboard.'.$key));
    }

    private function panel(DashboardDefinition $def, string $key): ?Breakdown
    {
        return collect($def->panels)->firstWhere('label', __('approval::dashboard.'.$key));
    }
}
