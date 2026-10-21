<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\SignedButNotPosted;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ নতুন বার (ওয়েবের মান ধাপ ২) আগের বারের কোনো কাজ হারায়নি — প্রতিটা আছে বারে, ছবির মেনুতে, বা রেলে (fe, ২১ অক্টোবর ২০২৬)।
 *
 * ⛔ কী হয়েছিল: "সই হয়েছে, পোস্ট হয়নি" — মালিকের নিজের চাওয়া আইকন (১১ অক্টোবর: *"khuje ber korle somoy lage tai ekta icon"*) —
 * বার থেকে ঘণ্টার প্যানেলের ভেতরে চলে গিয়েছিল, আর মালিক নতুন বারে খুঁজে পাননি। কোনো পরীক্ষা লাল হয়নি, কারণ কেউ বলেনি
 * আগের বারে কী কী ছিল। ⭐ এখন তালিকাটা এখানে, নাম ধরে: ccc065138-এর বারের প্রতিটা কাজের বোতাম, আর আজ সেটা কোথায়।
 */
final class TheBarKeptEveryTaskItHadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ccc065138-এর বারের প্রতিটা কাজ → আজ কোথায় (bar · menu · rail) আর কোন চিহ্নে।
     * ⓘ কেউ একটা সরালে এখানে নতুন জায়গা লিখতে হবে — নিঃশব্দে হারানো যাবে না।
     */
    private const TASKS = [
        'মেনু গুটানো-খোলা' => ['bar', '$store.sidebar.toggle()'],
        'চলতি মডিউল' => ['rail', 'data-active-module'],
        // ⓘ ২১ অক্টোবর ২০২৬: ⊞ আবার বারে, "≡"-এর পরে (মালিক; [[shell.launcher]]) — আগে রেলের মডিউল-প্যানেলে (`data-site-map`)
        '⊞ সব মডিউল' => ['bar', 'data-module-launcher-sheet'],
        '＋ তৈরি' => ['bar', 'data-shell-create'],
        'অনুমোদন' => ['bar', 'data-shell-approvals'],
        'সই হয়েছে, পোস্ট হয়নি' => ['bar', 'data-signed-unposted-bar'],
        '🔔 বিজ্ঞপ্তি' => ['bar', 'data-notification-bell'],
        'কোম্পানি · শাখা' => ['bar', 'data-company-branch'],
        'ছবি' => ['bar', 'data-profile-menu'],
        'পূর্ণপর্দা' => ['menu', 'data-menu-fullscreen'],
        'ভাষা' => ['menu', 'data-menu-language'],
        'রং' => ['menu', 'data-menu-theme'],
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
    }

    public function test_every_task_of_the_old_bar_still_has_a_home(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $html = (string) $this->actingAs($owner)->get(route('module.dashboard', ['module' => 'accounts']))->assertOk()->getContent();

        $places = [
            'bar' => $this->between($html, '<header data-topbar', '</header>'),
            'menu' => $this->between($html, 'data-profile-menu', '</header>'),
            'rail' => $this->between($html, '<aside', '</aside>'),
        ];
        // ⓘ ছবির মেনু বারের ভেতরে — তাই "বার" মানে মেনুর আগের অংশ, যাতে মেনুর জিনিস বারে গোনা না হয়
        $places['bar'] = substr($places['bar'], 0, (int) strpos($places['bar'], 'data-profile-menu') + strlen('data-profile-menu'));

        $lost = [];
        foreach (self::TASKS as $task => [$where, $mark]) {
            if (! str_contains($places[$where], $mark)) {
                $lost[] = "{$task} — {$where}-এ {$mark} নেই";
            }
        }

        $this->assertSame([], $lost, "⛔ আগের বারের কাজ হারিয়েছে:\n".implode("\n", $lost));
    }

    /** ⭐ বারে, অনুমোদনের ঠিক পরে, ঘণ্টার আগে — সংখ্যাটা SignedButNotPosted-এর; শূন্যে ব্যাজ নেই, আইকন থাকে */
    public function test_signed_but_unposted_sits_in_the_bar_with_its_count(): void
    {
        $first = $this->person(['approval.decide']);
        $second = $this->person(['approval.decide']);
        $writer = $this->person(['accounts.voucher.create', 'accounts.voucher.update', 'approval.decide']);
        $idle = $this->person(['approval.decide']);

        // ⓘ দুই ধাপের সই; শেষ সইয়ের আগে মাস বন্ধ — কাগজ সই-শেষ, পোস্ট আটকে ([[SignedPaperFinisher]])
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $first->id]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 2, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $second->id]);
        $this->app->forgetInstance(ApprovalEngine::class);

        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $this->as($writer);
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'বারের পরখ', 'branch_id' => $this->company->defaultBranch()->id],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );
        $approval = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($approval, 'প্রস্তুতিটাই ভুল — ভাউচার সই ছাড়াই পাকা হলো।');

        $this->as($first);
        app(ApprovalEngine::class)->approve($approval->fresh(), $first);
        PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'reason' => 'পরখ', 'locked_by' => $first->id, 'locked_at' => now()]);
        $this->as($second);
        app(ApprovalEngine::class)->approve($approval->fresh(), $second);
        $this->assertTrue(Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id)->isDraft(), 'প্রস্তুতিটাই ভুল — বন্ধ মাসেও পোস্ট হলো।');

        foreach ([[$writer, true], [$idle, false]] as [$who, $stuck]) {
            $this->as($who);
            $bar = $this->between((string) $this->get(route('dashboard'))->assertOk()->getContent(), '<header data-topbar', 'data-profile-menu');
            $count = app(SignedButNotPosted::class)->count($who);
            $this->assertSame($stuck, $count > 0, 'প্রস্তুতিটাই ভুল — আটকে থাকা কাগজের সংখ্যা প্রত্যাশামতো নয় ('.$count.')।');

            $this->assertMatchesRegularExpression('/data-signed-unposted-bar="'.$count.'"/', $bar, "⛔ বারে \"সই হয়েছে, পোস্ট হয়নি\" নেই, বা সংখ্যা {$count} নয়।");
            $this->assertSame($stuck ? 1 : 0, preg_match('/data-signed-unposted-bar="\d+".*?data-signed-unposted-count[^>]*>\s*'.$count.'\s*</s', $bar),
                $stuck ? "⛔ আটকে থাকা কাগজ {$count}, অথচ ব্যাজে সেই সংখ্যা নেই।" : '⛔ কিছু আটকে নেই, তবু ব্যাজ।');
            $this->assertStringContainsString('title="'.e(__('core.notice.signed_unposted')).'"', $bar);
            $this->assertLessThan(strpos($bar, 'data-notification-bell'), strpos($bar, 'data-signed-unposted-bar'), '⛔ আইকন ঘণ্টার পরে — অনুমোদনের পাশে বসার কথা।');
            if (str_contains($bar, 'data-shell-approvals')) {
                $this->assertLessThan(strpos($bar, 'data-signed-unposted-bar'), strpos($bar, 'data-shell-approvals'), '⛔ আইকন অনুমোদনের আগে।');
            }
        }
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "প্রস্তুতিটাই ভুল — পাতায় {$from} নেই।");
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
