<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * দফা ১৬ — অনুমোদনের পর্দার তারিখ কথায়: "18 অক্টোবর 2026, 19:25", বাকি সফটওয়্যারের মতো (মালিক, ২১ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে ইনবক্স, আমার অনুরোধ আর কাগজের পাতা `format('d M Y, H:i')` লিখত — বাংলা পর্দায় "18 Oct 2026, 19:25"।
 * ⓘ মাসের নাম পর্দার ভাষায়, অঙ্ক সবসময় ইংরেজিতে (মালিকের নিয়ম "খ")।
 */
final class TheApprovalDatesReadInWordsTest extends TestCase
{
    use RefreshDatabase;

    private const ASKED = '2026-10-18 19:25:00';

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $this->signer = User::factory()->create(['is_active' => true, 'locale' => 'bn']);
        $this->signer->companies()->attach($company, ['is_active' => true]);
        $this->signer->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($company->id, fn () => $this->signer->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $flow = ApprovalFlow::create(['company_id' => $company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_the_inbox_and_the_paper_say_the_month_in_words_with_english_digits(): void
    {
        $asked = $this->askedAt(self::ASKED);

        $inbox = $this->actingAs($this->signer->fresh())->get(route('approval.inbox.index'))->assertOk();
        $inbox->assertSee('18 অক্টোবর 2026, 19:25');
        $inbox->assertDontSee('18 Oct 2026', false);

        $page = $this->actingAs($this->signer->fresh())->get(route('approval.inbox.show', $asked->id))->assertOk();
        $page->assertSee('18 অক্টোবর 2026, 19:25');
        $page->assertDontSee('18 Oct 2026', false);
        $page->assertDontSee('১৮ অক্টোবর', false);
    }

    public function test_the_helper_keeps_english_digits_in_both_languages(): void
    {
        app()->setLocale('bn');
        $this->assertSame('18 অক্টোবর 2026, 19:25', DateFormat::spoken(self::ASKED));

        app()->setLocale('en');
        $this->assertSame('18 October 2026, 19:25', DateFormat::spoken(self::ASKED));
        $this->assertSame('', DateFormat::spoken(null));
    }

    /** একটা জার্নাল, সইয়ের অপেক্ষায়, সই চাওয়ার সময় হাতে বসানো */
    private function askedAt(string $when): Approval
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'তারিখের পরীক্ষা',
                'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );
        $asked = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — সই চাওয়া হয়নি।');

        $asked->forceFill(['requested_at' => $when])->save();

        return $asked->fresh();
    }
}
