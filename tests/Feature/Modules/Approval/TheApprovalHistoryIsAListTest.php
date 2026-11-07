<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ "আমি অনুমোদন দিয়েছি" আর "আমার পাঠানো" — পুরো চওড়া তালিকা (মালিক, ৭ নভেম্বর ২০২৬, নমুনা ২ "ok"; SAP-এর Approval History)।
 *
 * ⓘ মালিকের প্রশ্ন: "আমি অনুমোদন দিয়েছি eta to list akare thakar kotha evabe keno sob ki inboxer motohobe?" — অপেক্ষার কাগজে কাজ
 * হয় (বাঁয়ে তালিকা, ডানে কাগজ), দেওয়া অনুমোদন আর পাঠানো অনুরোধ হিসাবের জিনিস — তাই টেবিল, অন্য তালিকার একই টুলবার, নিচে মোট।
 */
final class TheApprovalHistoryIsAListTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        app()->setLocale('bn');

        $this->signer = User::factory()->create(['is_active' => true, 'locale' => 'bn', 'name' => 'তালিকার অনুমোদনকারী']);
        $this->signer->companies()->attach($this->company, ['is_active' => true]);
        $this->signer->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $this->signer->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ApprovalFlow::query()->where('module', 'accounts')->where('action', 'journal')->delete();
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
        ApprovalEngine::flowsChanged();
    }

    public function test_what_i_approved_is_a_full_width_list_with_my_decision_and_the_shared_toolbar(): void
    {
        $yes = $this->ask('700');
        $no = $this->ask('300');
        app(ApprovalEngine::class)->approve($yes, $this->signer, 'ঠিক আছে');
        app(ApprovalEngine::class)->reject($no, $this->signer, 'রসিদ নেই');

        $page = $this->xpath($this->actingAs($this->signer)->get(route('approval.inbox.index', ['tab' => 'done']))->assertOk()->getContent());

        $list = $page->query('//*[@data-inbox-history="done"]')->item(0);
        $this->assertNotNull($list, '⛔ "আমি অনুমোদন দিয়েছি" এখনো ইনবক্সের মতো — পুরো চওড়া তালিকা নেই।');
        $this->assertSame(0, $page->query('//*[@data-inbox-detail]')->length, '⛔ তালিকার পাশে আবার কাগজের ডান দিক — হিসাবের ট্যাবে বাঁয়ে-ডানে নয়।');
        $this->assertGreaterThan(0, $page->query('.//table', $list)->length, '⛔ তালিকায় টেবিল নেই।');

        foreach ([$yes, $no] as $approval) {
            $this->assertSame(1, $page->query('.//a[@data-history-open][@href="'.route('approval.inbox.show', $approval->id).'"]', $list)->length,
                "⛔ কাগজ {$approval->id}-এর নম্বরে চাপলে কাগজ খোলে না, বা সারিটাই নেই।");
        }
        $this->assertSame(1, $page->query('.//*[@data-history-decision="approved"]', $list)->length, '⛔ "অনুমোদন" সিদ্ধান্তটা সারিতে নেই।');
        $this->assertSame(1, $page->query('.//*[@data-history-decision="rejected"]', $list)->length, '⛔ "ফেরত" সিদ্ধান্তটা সারিতে নেই।');
        $text = preg_replace('/\s+/u', ' ', (string) $list->textContent);
        $this->assertStringContainsString('রসিদ নেই', (string) $text, '⛔ আমার মন্তব্য সারিতে নেই।');
        $this->assertStringContainsString('1,000.00', (string) $text, '⛔ নিচে মোট টাকা (৭০০ + ৩০০) নেই।');

        // ⓘ অন্য সব তালিকার একই টুলবার — খোঁজা, সাজানো, রপ্তানি …
        $this->assertGreaterThan(0, $page->query('.//*[@data-list-toolbar] | .//input[@type="search"][@name="q"]', $list)->length, '⛔ তালিকায় খোঁজার ঘর নেই।');
        $this->assertGreaterThan(0, $page->query('.//select[@name="sort"] | .//*[@name="sort"]', $list)->length, '⛔ তালিকায় সাজানোর ঘর নেই।');

        // ⓘ অপেক্ষার ট্যাব আগের মতোই বাঁয়ে তালিকা, ডানে কাগজ
        $waiting = $this->xpath($this->actingAs($this->signer)->get(route('approval.inbox.index'))->assertOk()->getContent());
        $this->assertSame(0, $waiting->query('//*[@data-inbox-history]')->length, '⛔ "অপেক্ষায়" ট্যাবও তালিকা হয়ে গেল — সেখানে কাজ হয়, বাঁয়ে-ডানে থাকার কথা।');
        $this->assertSame(1, $waiting->query('//*[@data-inbox-list]')->length, '⛔ "অপেক্ষায়" ট্যাবের বাঁয়ের তালিকা হারাল।');
    }

    public function test_what_i_sent_is_the_same_list(): void
    {
        $open = $this->ask('450');

        $page = $this->xpath($this->actingAs($this->owner)->get(route('approval.inbox.index', ['tab' => 'sent']))->assertOk()->getContent());
        $list = $page->query('//*[@data-inbox-history="sent"]')->item(0);
        $this->assertNotNull($list, '⛔ "আমার পাঠানো" এখনো ইনবক্সের মতো — পুরো চওড়া তালিকা নেই।');
        $this->assertSame(1, $page->query('.//a[@data-history-open][@href="'.route('approval.inbox.show', $open->id).'"]', $list)->length,
            '⛔ আমার পাঠানো অনুরোধটা তালিকায় নেই।');
    }

    private function ask(string $amount): Approval
    {
        $this->actingAs($this->owner);
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $draft = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'তালিকার পরীক্ষা', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]],
        );
        $asked = app(VoucherWriter::class)->post($draft);
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — অনুমোদন চাওয়া হয়নি।');

        return $asked->fresh();
    }

    private function xpath(string|false $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.(string) $html);

        return new DOMXPath($dom);
    }
}
