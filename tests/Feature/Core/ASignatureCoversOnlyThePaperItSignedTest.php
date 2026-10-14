<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\SignedButNotPosted;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ একটা সই কেবল সেই কাগজটাকে ঢাকে যেটায় সই দেওয়া হয়েছিল — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ নিরীক্ষকের ভয়: শেষ সইয়ের নিজে-পোস্ট আটকালে ([[SignedPaperFinisher]]) সই APPROVED থাকে, কাগজ খসড়া থাকে; লেখক
 * তারপর খাত বদলে পোস্ট চাপলে একই অঙ্কের অন্য একটা কাগজ পুরনো সই নিয়ে খাতায় উঠত।
 *
 * দাবি:
 *  - সইয়ের পরে খাত বদলালে পোস্ট নতুন সই চায় — পুরনো সই আর ঢাকে না, আর নতুন অনুরোধ পুরনোটার কথা বলে।
 *  - কেবল বিবরণ বদলালেও নতুন সই — মালিকের নিয়ম (২৪ সেপ্টেম্বর ২০২৬): *"যেকোনো ঘর বদলালেই"*।
 *  - "সই হয়েছে, পোস্ট হয়নি" তালিকা সত্যি বলে: বদলে যাওয়া কাগজ আর ওখানে নেই, পুরনো সইয়ের জোরে তো নয়ই।
 *  - ছাপ ছাড়া পুরনো সই (লাইভে আগে থেকে বসা) আজকের মতোই কেবল অঙ্ক মেলায় — ডিপ্লয়ের দিন কিছু ভাঙে না।
 */
final class ASignatureCoversOnlyThePaperItSignedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $this->signer = $this->person(['approval.decide']);
        $this->writer = $this->person(['accounts.voucher.create']);

        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_an_account_changed_after_a_stuck_auto_post_asks_for_a_new_signature(): void
    {
        [$voucher, $signed] = $this->signedButStuck();

        $this->as($this->writer);
        $this->rewrite($voucher, account: $this->accounts()[2]);

        $asked = app(VoucherWriter::class)->post($this->reread($voucher));

        $this->assertNotNull($asked, '⛔ সইয়ের পরে খাত বদলানো ভাউচার পুরনো সই নিয়েই খাতায় উঠল।');
        $this->assertTrue($this->reread($voucher)->isDraft(), '⛔ বদলানো ভাউচার নতুন সই ছাড়াই পাকা হলো।');
        $this->assertSame(Approval::PENDING, $asked->status);
        $this->assertNotSame((int) $signed->id, (int) $asked->id, '⛔ নতুন সই চাওয়া হয়নি — পুরনোটাই ফেরত এল।');
        $this->assertSame((int) $signed->id, (int) ($asked->payload['supersedes'] ?? 0),
            '⛔ নতুন অনুরোধ বলে না কোন সইয়ের জায়গা নিল — সইকারী একই কাগজ দ্বিতীয়বার দেখে বুঝবেন না কেন।');
        $this->assertNotNull($asked->state_hash, '⛔ নতুন অনুরোধে কাগজের ছাপ নেই — পরের বদলটা আর ধরা পড়ত না।');

        // ⭐ নতুন সইয়ের পরে এবার নিজেই পোস্ট — সুখী পথটা অটুট
        $this->as($this->signer);
        app(ApprovalEngine::class)->approve($asked, $this->signer);
        $this->assertTrue($this->reread($voucher)->isPosted(), '⛔ নতুন সইয়ের পরেও বদলানো ভাউচার খসড়া রইল।');
    }

    public function test_a_narration_only_change_also_needs_a_new_signature(): void
    {
        [$voucher] = $this->signedButStuck();

        $this->as($this->writer);
        $this->rewrite($voucher, narration: 'কেবল বিবরণ বদলানো');

        $this->assertNotNull(app(VoucherWriter::class)->post($this->reread($voucher)),
            '⛔ মালিকের নিয়ম "যেকোনো ঘর বদলালেই" — বিবরণ বদলানো ভাউচার পুরনো সই নিয়ে পাকা হলো।');
        $this->assertTrue($this->reread($voucher)->isDraft());
    }

    public function test_the_signed_unposted_list_drops_a_paper_changed_after_its_signature(): void
    {
        [$voucher] = $this->signedButStuck();

        $this->as($this->writer);
        Cache::flush();
        $this->assertContains($voucher->document_no, app(SignedButNotPosted::class)->papers($this->writer),
            '⛔ পরীক্ষার ভিত্তি নেই — আটকে থাকা সই-হওয়া ভাউচার তালিকায় নেই।');

        $this->rewrite($voucher, account: $this->accounts()[2]);

        $this->assertNotContains($voucher->document_no, app(SignedButNotPosted::class)->papers($this->writer),
            '⛔ "সই হয়েছে, পোস্ট হয়নি" — অথচ সইয়ের পরে কাগজটা বদলেছে; পোস্ট চাপলে নতুন সই লাগবে, তালিকাটা মিথ্যা বলছে।');

        // ⓘ নতুন সই চাওয়ার পরেও পুরনো সইয়ের জোরে তালিকায় ফেরে না — নতুনটা এখনো অপেক্ষায়
        app(VoucherWriter::class)->post($this->reread($voucher));
        $this->assertNotContains($voucher->document_no, app(SignedButNotPosted::class)->papers($this->writer),
            '⛔ নতুন সই অপেক্ষায়, অথচ পুরনো (বাতিল) সইয়ের জোরে কাগজটা "সই হয়েছে" তালিকায়।');
    }

    public function test_an_unchanged_stuck_paper_stays_listed_and_posts_on_its_own_signature(): void
    {
        [$voucher, $signed] = $this->signedButStuck();

        $this->as($this->writer);
        $this->assertContains($voucher->document_no, app(SignedButNotPosted::class)->papers($this->writer));

        PeriodLock::query()->delete();
        $this->assertNull(app(VoucherWriter::class)->post($this->reread($voucher)),
            '⛔ কাগজ এক চুলও বদলায়নি, তবু পোস্ট আবার সই চাইল।');
        $this->assertTrue($this->reread($voucher)->isPosted());
        $this->assertSame(1, Approval::query()->where('approvable_id', $voucher->id)->where('approvable_type', $voucher->getMorphClass())->count(),
            '⛔ বদলহীন কাগজের জন্য দ্বিতীয় অনুরোধ লেখা হলো।');
        $this->assertSame(Approval::APPROVED, $signed->fresh()->status);
    }

    public function test_a_signature_from_before_fingerprints_keeps_todays_amount_rule(): void
    {
        [$voucher, $signed] = $this->signedButStuck();

        // ⓘ ছাপের আগের যুগের সই — লাইভে আগে থেকে বসে থাকা সারির মতো
        DB::table('approvals')->where('id', $signed->id)->update(['state_hash' => null]);

        $this->as($this->writer);
        $this->rewrite($voucher, account: $this->accounts()[2]);
        PeriodLock::query()->delete();

        $this->assertNull(app(VoucherWriter::class)->post($this->reread($voucher)),
            '⛔ ছাপ ছাড়া পুরনো সই হঠাৎ বাতিল — ডিপ্লয়ের দিন প্রতিটা অপেক্ষমাণ কাগজ নতুন সই চাইত।');
        $this->assertTrue($this->reread($voucher)->isPosted());
    }

    /** @return array{0: Voucher, 1: Approval} সই হয়েছে, কিন্তু বন্ধ মাসে নিজে-পোস্ট আটকে খসড়া */
    private function signedButStuck(): array
    {
        [$a, $b] = $this->accounts();

        $this->as($this->writer);
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সইয়ের পরে বদল',
                'branch_id' => $this->company->defaultBranch()->id],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );

        $approval = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($approval, '⛔ পরীক্ষার ভিত্তি নেই — ভাউচার সই ছাড়াই পাকা হলো।');

        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => $this->signer->id, 'locked_at' => now(),
        ]);

        $this->as($this->signer);
        app(ApprovalEngine::class)->approve($approval, $this->signer);

        $this->assertTrue($this->reread($voucher)->isDraft(), '⛔ পরীক্ষার ভিত্তি নেই — বন্ধ মাসে ভাউচার পাকা হলো।');
        $this->assertSame(Approval::APPROVED, $approval->fresh()->status);

        return [$this->reread($voucher), $approval->fresh()];
    }

    /** খসড়াটা লেখকের হাতে আবার লেখা — একই অঙ্ক; মাস খুলে দেওয়া হয়, যাতে আটকানোর একমাত্র কারণ সই হয় */
    private function rewrite(Voucher $voucher, ?Account $account = null, ?string $narration = null): void
    {
        PeriodLock::query()->delete();
        $fresh = $this->reread($voucher)->load('lines');

        $lines = $fresh->lines->map(fn ($l, $i) => [
            'account_id' => ($i === 0 && $account !== null) ? $account->id : $l->account_id,
            'debit' => (string) $l->debit, 'credit' => (string) $l->credit,
        ])->values()->all();

        app(VoucherService::class)->update($fresh, $narration === null ? [] : ['narration' => $narration], $lines);
        Cache::flush();
    }

    /** @return list<Account> */
    private function accounts(): array
    {
        return Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(3)->get()->all();
    }

    private function reread(Voucher $voucher): Voucher
    {
        return Voucher::query()->withoutGlobalScope('user-branch')->findOrFail($voucher->id);
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
}
