<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\SignedPaperFinisher;
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
 * ⭐ শেষ সই পড়লে কাগজ নিজেই পোস্ট — মালিক, ১১ অক্টোবর ২০২৬: *"Approval er pore Vautcher inv, & challan & others post korte hoy
 * tookhon khuje ber korle somoy lage"* → ক।
 *
 * ⓘ লাইভে RCV-0052: সই ১৩:৩৫, কেউ হাতে পোস্ট চাপলেন ১৩:৪১ — প্রতিটা সইয়ের পরে কাগজ খুঁজে বের করতে হত।
 *
 * দাবি:
 *  - শেষ সইয়ে খসড়া ভাউচার নিজেই পাকা হয়, খাতায় বসে — কেউ "পোস্ট" চাপেন না।
 *  - ⛔ পোস্ট আটকালে (মাস বন্ধ) ভাউচার খসড়াই থাকে, সই থাকে, আর লেখক কারণসহ খবর পান — চুপচাপ পড়ে থাকে না।
 */
final class ASignedPaperPostsItselfTest extends TestCase
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

    public function test_the_last_signature_posts_the_voucher_without_anyone_pressing_post(): void
    {
        [$voucher, $approval] = $this->sentForSignature();

        $this->as($this->signer);
        app(ApprovalEngine::class)->approve($approval, $this->signer);

        $voucher = Voucher::query()->withoutGlobalScope('user-branch')->findOrFail($voucher->id);
        $this->assertTrue($voucher->isPosted(), '⛔ শেষ সইয়ের পরেও ভাউচার খসড়া — আবার কাউকে "পোস্ট" চাপতে হবে।');
        $this->assertSame(1, DB::table('approvals')->where('id', $approval->id)->where('status', Approval::APPROVED)->count());
    }

    public function test_a_post_that_is_refused_keeps_the_draft_and_tells_the_writer(): void
    {
        [$voucher, $approval] = $this->sentForSignature();

        // ⓘ সইয়ের অপেক্ষার মাঝে মাসটা বন্ধ হলো — পোস্ট আটকাবে
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => $this->signer->id, 'locked_at' => now(),
        ]);

        $this->as($this->signer);
        app(ApprovalEngine::class)->approve($approval, $this->signer);

        $voucher = Voucher::query()->withoutGlobalScope('user-branch')->findOrFail($voucher->id);
        $this->assertTrue($voucher->isDraft(), '⛔ বন্ধ মাসে ভাউচার পাকা হয়ে গেল।');
        $this->assertSame(Approval::APPROVED, $approval->fresh()->status, '⛔ পোস্ট আটকানোয় সইটাই মুছে গেল।');

        $this->assertTrue(
            DB::table('notifications')->where('user_id', $this->writer->id)->where('type', SignedPaperFinisher::NOTICE)->exists(),
            '⛔ ভাউচার আটকে রইল, অথচ লেখক কোনো খবর পেলেন না।',
        );

        // ⭐ টপবারের "সই হয়েছে, পোস্ট হয়নি" — লেখক আর সইকারী দুজনেই তালিকায় পান; অচেনা কেউ নয়
        foreach ([$this->writer, $this->signer] as $person) {
            $this->as($person);
            Cache::flush();
            $this->assertStringContainsString($voucher->document_no, $this->listed(), '⛔ আটকে থাকা কাগজ তালিকায় নেই।');
            $this->assertStringContainsString('data-signed-unposted-count', $this->get(route('dashboard'))->getContent(),
                '⛔ আটকে থাকা কাগজের সংখ্যা টপবারে নেই।');
        }

        $stranger = $this->person(['accounts.voucher.create']);
        $this->as($stranger);
        Cache::flush();
        $this->assertStringNotContainsString($voucher->document_no, $this->listed(), '⛔ তালিকায় এমন কাগজ যা এই মানুষের দেখার কথা নয়, বা যা আর আটকে নেই।');

        // ⓘ হাতে পোস্ট হলে নিজে থেকেই তালিকা থেকে নামে — আলাদা কোনো খাতা নেই
        PeriodLock::query()->delete();
        $this->as($this->writer);
        app(VoucherWriter::class)->post(Voucher::query()->withoutGlobalScope('user-branch')->findOrFail($voucher->id));
        Cache::flush();
        $this->assertStringNotContainsString($voucher->document_no, $this->listed(), '⛔ তালিকায় এমন কাগজ যা এই মানুষের দেখার কথা নয়, বা যা আর আটকে নেই।');
    }

    /** @return array{0: Voucher, 1: Approval} লেখকের খসড়া, সইয়ের অপেক্ষায় */
    private function sentForSignature(): array
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        $this->as($this->writer);
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সইয়ের পরে নিজে পোস্ট',
                'branch_id' => $this->company->defaultBranch()->id],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );

        $stopping = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($stopping, '⛔ পরীক্ষার ভিত্তি নেই — ভাউচার সই ছাড়াই পাকা হলো।');
        $this->assertTrue($voucher->fresh()->isDraft());

        return [$voucher, $stopping];
    }

    /** তালিকার অংশটুকু — ⓘ উপরের ঘণ্টায় "সই হয়েছে, পোস্ট হয়নি" খবরে কাগজের নম্বর থাকে, তাই পুরো পাতা নয় */
    private function listed(): string
    {
        $html = $this->get(route('approval.inbox.signed_unposted'))->assertOk()->getContent();

        return substr($html, (int) strpos($html, '<main'));
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
