<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalLabel;
use App\Core\Module\ModuleRegistry;
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
 * দফা ৪ — সইয়ের কাজের নাম বাংলায়, কোড কখনো পর্দায় নয় (অনুমোদন মডিউলের নকশা, মালিক ২১ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে ঘোষণার বাইরের কাজ কাঁচা যেত: ইনবক্সে "accounts · journal", "sales · invoice"।
 * দাবি:
 *   · পাহারা: প্রতিটা মডিউলের প্রতিটা ঘোষিত কাজ, ভাউচারের প্রতিটা ধরন, পুরনো "sales · invoice" আর একটা অচেনা কাজ — কোনোটার
 *     নামে ছোট হাতের ইংরেজি কোড নেই;
 *   · ইনবক্স আর কাগজের পাতায় জাবেদা ভাউচারের সই "জাবেদা ভাউচার" নামে, "accounts · journal" নয়।
 */
final class TheApprovalWorkHasABengaliNameTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '/\b[a-z]{3,}(_[a-z]+)*\b/';

    public function test_no_approval_work_is_named_by_its_code(): void
    {
        $this->seed(DemoSeeder::class);
        app()->setLocale('bn');
        $labels = app(ApprovalLabel::class);

        $pairs = [];
        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach (array_keys($module->approvals) as $action) {
                $pairs[] = [$module->code, (string) $action];
            }
        }
        $this->assertGreaterThan(20, count($pairs), 'প্রস্তুতিটাই ভুল — ঘোষিত কাজ পাওয়া যায়নি।');

        foreach (Voucher::TYPES as $type) {
            $pairs[] = ['accounts', $type];
        }
        $pairs[] = ['sales', 'invoice'];
        $pairs[] = ['sales', 'never_declared_action'];
        $pairs[] = ['no_such_module', 'whatever'];

        $raw = [];
        foreach ($pairs as [$module, $action]) {
            $name = $labels->of($module, $action);
            if ($name === '' || preg_match(self::CODE, $name) === 1) {
                $raw[] = "{$module}.{$action} → \"{$name}\"";
            }
        }
        $this->assertSame([], $raw, "⛔ কাজের নামে কাঁচা কোড:\n".implode("\n", $raw));

        $this->assertSame((string) __('accounts::voucher.journal'), $labels->of('accounts', 'journal'),
            '⛔ জার্নাল ভাউচারের কাজ নিজের নামে নয় (ভাউচারের নাম বাদ পড়েছে)');
        $this->assertSame('বিক্রয়ের বিল', $labels->of('sales', 'invoice'), '⛔ বিক্রয়ের বিলের কাজ নিজের নামে নয় — সাধারণ "…-এর কাগজ" দেখায়');
    }

    public function test_the_inbox_and_the_paper_name_a_journal_in_bengali(): void
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
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'নামের পরীক্ষা', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );
        $asked = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — সই চাওয়া হয়নি।');
        $this->assertSame(['accounts', 'journal'], [(string) $asked->module, (string) $asked->action], 'প্রস্তুতিটাই ভুল — অন্য কাজে সই চাওয়া।');

        app()->setLocale('bn');
        $name = (string) __('accounts::voucher.journal');

        foreach ([route('approval.inbox.index'), route('approval.inbox.show', $asked->id)] as $url) {
            $page = $this->actingAs($signer->fresh())->get($url)->assertOk();
            $page->assertSee($name);
            $page->assertDontSee('accounts · journal', false);
        }
    }
}
