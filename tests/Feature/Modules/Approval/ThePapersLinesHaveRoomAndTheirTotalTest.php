<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * দফা ৯ — কাগজের লাইন জায়গা পায়, সর্বমোট দেখা যায় (অনুমোদন মডিউলের নকশা, মালিক ২১ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে পাতা তিন কলামে ছিল (`16rem 1fr 20rem`): ১৩৬৬-এ মাঝের কাগজের কলাম ~৩২০px — সারির টেবিল কেটে যেত, তাই "সর্বমোট"
 * সারিটা ফাঁকা দেখাত, আর পক্ষের মোবাইল আর পয়েন্ট একটার উপরে আরেকটা উঠত।
 * দাবি: পাতা দুই কলামে (কাগজ চওড়া, ডানে কাজ, তার নিচে যাত্রাপথ); কাগজের টেবিল সরার ঘরে মোড়া; মোটের সারিতে ডেবিট আর
 * ক্রেডিট দুটোরই অঙ্ক; তথ্যের ঘর লম্বা লেখা ভাঙে।
 */
final class ThePapersLinesHaveRoomAndTheirTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_paper_has_a_wide_column_and_a_filled_total(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $signer = User::factory()->create(['is_active' => true, 'locale' => 'bn']);
        $signer->companies()->attach($company, ['is_active' => true]);
        $signer->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($company->id, fn () => $signer->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $flow = ApprovalFlow::create(['company_id' => $company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);

        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'জায়গার পরীক্ষা', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => '1234.5', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '1234.5']],
        );
        $asked = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — সই চাওয়া হয়নি।');

        $html = $this->actingAs($signer->fresh())->get(route('approval.inbox.show', $asked->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-signing-sheet', $html, 'প্রস্তুতিটাই ভুল — সইদাতা কাগজের সারি দেখছেন না।');

        // ⓘ দুই কলাম, তিন নয় — কাগজ চওড়া, পাশে সরু যাত্রাপথ (অনুমোদন পর্দা ভাগ ২, ২৭ অক্টোবর ২০২৬: পাতার চওড়া ধরে, পর্দার নয় —
        // ইনবক্সের ডান দিকেও একই ফাইল)
        $this->assertStringContainsString('@lg:grid-cols-[minmax(0,1fr)_13rem]', $html, '⛔ পাতা দুই কলামে নয়।');
        $this->assertStringNotContainsString('lg:grid-cols-[16rem_1fr_20rem]', $html, '⛔ পুরনো তিন কলাম — কাগজের কলাম সরু।');

        // ⓘ যাত্রাপথ কাজের নিচে (ডান কলামে), কাগজের আগে নয়
        $sheetAt = strpos($html, 'data-signing-sheet');
        $journeyAt = strpos($html, '<aside data-boxed');
        $this->assertNotFalse($journeyAt, 'প্রস্তুতিটাই ভুল — যাত্রাপথ নেই।');
        $this->assertGreaterThan($sheetAt, $journeyAt, '⛔ যাত্রাপথ এখনো কাগজের আগে, নিজের সরু কলামে।');

        // ⓘ টেবিল সরার ঘরে, আর মোটের সারিতে দুই দিকের অঙ্কই
        $this->assertMatchesRegularExpression('/min-w-0 overflow-x-auto">\s*<div[^>]*>|min-w-0 overflow-x-auto">/', $html, '⛔ কাগজের টেবিল সরার ঘরে নেই — চওড়া হলে কেটে যায়।');
        $foot = substr($html, (int) strpos($html, '<tfoot', $sheetAt));
        $foot = substr($foot, 0, (int) strpos($foot, '</tfoot>'));
        $this->assertNotSame('', $foot, '⛔ কাগজের টেবিলে মোটের সারিই নেই।');
        $this->assertSame(2, substr_count($foot, '1,234.50'), '⛔ মোটের সারিতে ডেবিট আর ক্রেডিট দুটোর অঙ্ক নেই: '.strip_tags($foot));

        // ⓘ লম্বা লেখা ভাঙে, পাশের ঘরে ওঠে না
        $this->assertStringContainsString('min-w-0 text-sm break-words', $html, '⛔ তথ্যের ঘর লম্বা লেখা ভাঙে না।');
    }
}
