<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ অনুমোদনের খবর চাপলে সাধারণ লেখক ৪০৩ পেতেন (২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * "অনুমোদিত", "প্রত্যাখ্যাত", "সংশোধনে ফেরত" — তিনটা খবরই যেত যিনি কাগজটা চেয়েছিলেন তাঁর কাছে, আর তিনটার লিংকই ছিল
 * অনুমোদনের তালিকা (`approval.inbox.index`)। ⚠️ তালিকা খোলে কেবল `approval.decide` বা `approval.report` থাকলে — অর্থাৎ
 * যিনি নিজে সই দেন না, সেই সাধারণ লেখক নিজের কাগজের ফল জানতে খবর চাপলেই ৪০৩। সময় ফুরোনোর তাগাদা আর উপরে পাঠানোর
 * খবরও (`abos:approvals-due`) তালিকাতেই যেত, যেখানে উপরে-পাঠানোর গন্তব্য মানুষটা কাগজটা খুঁজে বের করতেন।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * খবরের লিংক ঐ অনুরোধের নিজের পাতা (`approval.inbox.show`): খবর খোলার দরজা দিয়ে চাপলে সেখানে যায়, খবরটা পড়া হিসেবে
 * বসে, আর পাতাটা লেখককে নিজের বলে খোলে (২০০, কাগজের নম্বরসহ)। উপরে পাঠানোর খবরও গন্তব্যের মানুষটাকে ঐ পাতাতেই খোলে।
 */
final class AnApprovalNoticeOpensItsOwnApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $signer;

    private User $writer;

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

        $this->signer = $this->person(['approval.view', 'approval.decide', 'accounts.voucher.view', 'accounts.voucher.update']);
        // ⓘ সাধারণ লেখক — সই দেন না, অনুমোদনের রিপোর্টও দেখেন না
        $this->writer = $this->person(['approval.view', 'accounts.voucher.view', 'accounts.voucher.create', 'accounts.voucher.update']);

        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_an_approved_request_opens_its_own_page_for_its_writer(): void
    {
        [$voucher, $asked] = $this->waiting();

        $this->as($this->signer);
        app(ApprovalEngine::class)->approve($asked, $this->signer);

        $this->opensItsOwnPage($asked, 'approval.approved', $voucher);
    }

    public function test_a_rejected_request_opens_its_own_page_for_its_writer(): void
    {
        [$voucher, $asked] = $this->waiting();

        $this->as($this->signer);
        app(ApprovalEngine::class)->reject($asked, $this->signer, 'অঙ্কটা মেলে না');

        $this->opensItsOwnPage($asked, 'approval.rejected', $voucher);
    }

    public function test_a_returned_request_opens_its_own_page_for_its_writer(): void
    {
        [$voucher, $asked] = $this->waiting();

        $this->as($this->signer);
        app(ApprovalEngine::class)->sendBack($asked, $this->signer, 'খাতটা ভুল — অফিস খরচে দিন');

        $this->opensItsOwnPage($asked, 'approval.returned', $voucher);
    }

    /**
     * ⭐ উপরে পাঠানো — গন্তব্যের মানুষটা ধাপে নেই, তালিকা খোলার চাবিও নেই; খবরটা তাঁকে সেই অনুরোধের পাতাতেই খোলে, যেখানে
     * তিনি সই দেন ([[ApprovalEngine::canDecide()]]-এর উপরে-পাঠানোর পথ)।
     */
    public function test_a_late_request_opens_its_own_page_for_the_one_it_went_up_to(): void
    {
        $clerk = $this->person([]);
        $boss = $this->person([]);
        $chief = $this->person([]);

        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'customer', 'action' => 'credit_limit',
            'document_type' => 'Customer', 'threshold_amount' => '0', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $boss->id,
            'requires_all' => false, 'sla_hours' => 4, 'escalate_to_type' => 'user', 'escalate_to_id' => $chief->id,
        ]);

        $customer = Customer::query()->firstOrFail();
        $asked = app(ApprovalEngine::class)->request($customer, 'customer', 'credit_limit', '50000', null, 'test', $clerk->id);
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — অনুরোধটাই বসেনি।');
        $asked->update(['requested_at' => now()->subHours(9), 'due_at' => now()->subHours(5)]);

        $this->app['auth']->forgetGuards();
        $this->artisan('abos:approvals-due')->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $note = Notification::query()->where('user_id', $chief->id)->where('type', 'approval.escalated')->first();
        $this->assertNotNull($note, 'প্রস্তুতিটাই ভুল — উপরে পাঠানোর খবর গন্তব্যে যায়নি।');
        $this->assertSame(route('approval.inbox.show', $asked->id), $note->url,
            '⛔ উপরে পাঠানোর খবর অনুমোদনের তালিকায় নিয়ে যায়, অনুরোধের নিজের পাতায় নয়।');

        $this->as($chief);
        $this->assertSame(403, $this->call('GET', route('approval.inbox.index'))->status(),
            'প্রস্তুতিটাই ভুল — গন্তব্যের মানুষটা তালিকা খুলতে পারেন, তাহলে পুরনো লিংকও কাজ করত।');

        $page = $this->followTheNotice($note, $asked);
        $this->assertStringContainsString($customer->drillDocumentNo(), (string) $page->getContent(),
            '⛔ অনুরোধের পাতায় কাগজের নম্বর নেই — গন্তব্যের মানুষটা জানেন না কোন কাগজে সই দেবেন।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function opensItsOwnPage(Approval $asked, string $type, Voucher $voucher): void
    {
        $note = Notification::query()->where('user_id', $this->writer->id)->where('type', $type)->first();
        $this->assertNotNull($note, "প্রস্তুতিটাই ভুল — লেখক {$type} খবরটাই পাননি।");
        $this->assertSame(route('approval.inbox.show', $asked->id), $note->url,
            "⛔ {$type} খবর অনুমোদনের তালিকায় নিয়ে যায়, অনুরোধের নিজের পাতায় নয় — সাধারণ লেখক সেখানে ৪০৩ পান।");

        $this->as($this->writer);
        $this->assertSame(403, $this->call('GET', route('approval.inbox.index'))->status(),
            'প্রস্তুতিটাই ভুল — লেখক তালিকা খুলতে পারেন, তাহলে পুরনো লিংকও কাজ করত।');

        $page = $this->followTheNotice($note, $asked);
        $this->assertStringContainsString(Voucher::acrossBranches()->findOrFail($voucher->id)->drillDocumentNo(), (string) $page->getContent(),
            "⛔ {$type} খবর খুলে লেখক নিজের কাগজের নম্বর দেখেন না।");
    }

    /** খবর খোলার দরজা দিয়ে — চাপলে অনুরোধের পাতায়, খবর পড়া, আর পাতা খোলে */
    private function followTheNotice(Notification $note, Approval $asked): TestResponse
    {
        $opened = $this->call('GET', route('notifications.open', $note));
        $this->assertSame(302, $opened->status(), '⛔ খবর খোলার দরজা গন্তব্যে পাঠায়নি (না-খোলার পাতা, বা ভুল)।');
        $this->assertSame(route('approval.inbox.show', $asked->id), $opened->headers->get('Location'),
            '⛔ খবর খুলে অনুরোধের নিজের পাতায় যায় না।');
        $this->assertNotNull($note->fresh()->read_at, '⛔ খবর খুলে পড়া হিসেবে বসেনি — ঘণ্টায় ঝুলে থাকে।');

        $page = $this->call('GET', route('approval.inbox.show', $asked->id));
        $this->assertSame(200, $page->status(), '⛔ খবরের মানুষটা অনুরোধের পাতা খুলতে পারেন না।');

        return $page;
    }

    /** @return array{0: Voucher, 1: Approval} */
    private function waiting(): array
    {
        $accounts = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get();
        $this->assertCount(2, $accounts, 'প্রস্তুতিটাই ভুল — দুইটা খরচের খাত নেই।');

        $this->as($this->writer);
        [$voucher] = app(VoucherWriter::class)->store(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সইয়ের অপেক্ষায়', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $accounts[0]->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $accounts[1]->id, 'debit' => '0', 'credit' => '500']],
            asDraft: false,
        );

        $asked = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->sole();
        $this->assertSame(Approval::PENDING, $asked->status, 'প্রস্তুতিটাই ভুল — সই চাওয়ার কথা।');

        return [$voucher, $asked];
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
