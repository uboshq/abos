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
 * দফা ১২ — ফেরানো-কঠিন প্রতিটা কাজের আগে জিজ্ঞাসা (অনুমোদন মডিউলের নকশা, মালিক ২১ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে কেবল সই দেওয়ায় "নিশ্চিত?" ছিল; প্রত্যাখ্যান, সংশোধনে ফেরত, অন্যকে দেওয়া, তুলে নেওয়া, একসাথে সই, ছক মোছা আর
 * হস্তান্তর থামানো — এক ক্লিকেই হয়ে যেত।
 * দাবি:
 *   · গঠন: অনুমোদনের পর্দার প্রতিটা POST ফর্ম যার ঠিকানা এই কাজগুলোর একটা, তার নিজের ট্যাগে `data-confirm` (ভাষার চাবিসহ);
 *   · আচরণ: সইদাতার কাগজের পাতায় প্রত্যাখ্যানের ফর্ম "চূড়ান্ত" সতর্কতা নিয়ে আঁকা হয়।
 */
final class TheApprovalAsksBeforeEveryHardActionTest extends TestCase
{
    use RefreshDatabase;

    /** ঠিকানা → যে জিজ্ঞাসা থাকতেই হবে */
    private const MUST_ASK = [
        'approval.inbox.approve' => null,
        'approval.inbox.reject' => 'confirm_reject',
        'approval.inbox.send_back' => 'confirm_send_back',
        'approval.inbox.forward' => 'confirm_forward',
        'approval.inbox.withdraw' => 'confirm_withdraw',
        'approval.inbox.bulk' => 'confirm_bulk',
        'approval.flow.destroy' => 'confirm_flow_delete',
        'approval.delegation.destroy' => 'confirm_delegation_stop',
        'approval.limit.destroy' => 'limit_remove_confirm',
    ];

    public function test_every_hard_action_form_carries_its_question(): void
    {
        $views = glob(app_path('Modules/Approval/Resources/views/**/*.blade.php')) ?: [];
        $views = array_merge($views, glob(app_path('Modules/Approval/Resources/views/*/partials/*.blade.php')) ?: []);
        $this->assertGreaterThan(5, count($views), 'প্রস্তুতিটাই ভুল — অনুমোদনের পর্দা পাওয়া যায়নি।');

        $seen = [];
        $missing = [];
        foreach (array_unique($views) as $file) {
            $html = (string) file_get_contents($file);
            // ⓘ ট্যাগের ভেতরের {{ … }} এড়িয়ে — নইলে `$approval->id`-এর `>`-এ ট্যাগ কেটে যেত
            preg_match_all('/<form\b(?:\{\{.*?\}\}|[^>{]|\{(?!\{))*>/s', $html, $forms);
            foreach ($forms[0] as $tag) {
                // ⓘ একটা ফর্মের ঠিকানা শর্তসাপেক্ষ হতে পারে (সংশোধনে ফেরত, নইলে প্রত্যাখ্যান) — তার যেকোনো একটার জিজ্ঞাসা থাকলেই চলে
                $routes = array_filter(self::MUST_ASK, fn ($key, string $route) => str_contains($tag, "route('".$route."'"), ARRAY_FILTER_USE_BOTH);
                if ($routes === []) {
                    continue;
                }
                foreach (array_keys($routes) as $route) {
                    $seen[$route] = true;
                }
                $asks = str_contains($tag, 'data-confirm') && (in_array(null, $routes, true)
                    || array_filter($routes, fn ($key) => $key !== null && str_contains($tag, 'approval::message.'.$key)) !== []);
                if (! $asks) {
                    $missing[] = basename(dirname($file)).'/'.basename($file).' → '.implode(', ', array_keys($routes));
                }
            }
        }

        $this->assertSame([], $missing, "⛔ জিজ্ঞাসা ছাড়াই ফেরানো-কঠিন কাজ:\n".implode("\n", $missing));
        foreach (array_keys(self::MUST_ASK) as $route) {
            if ($route === 'approval.inbox.approve' || $route === 'approval.inbox.send_back') {
                continue; // ⓘ সই আর সংশোধনে-ফেরতের ফর্মের ঠিকানা শর্তসাপেক্ষ লেখা — নিচের আচরণ-দাবি দেখে
            }
            $this->assertArrayHasKey($route, $seen, "প্রস্তুতিটাই ভুল — {$route}-এর ফর্ম পাওয়া যায়নি, পাহারা অন্ধ।");
        }

        foreach (['bn', 'en'] as $locale) {
            foreach (array_filter(self::MUST_ASK) as $key) {
                $this->assertNotSame('approval::message.'.$key, __('approval::message.'.$key, [], $locale), "⛔ {$locale}-এ জিজ্ঞাসার লেখা নেই: {$key}");
            }
        }
    }

    public function test_the_signers_page_warns_that_a_rejection_is_final(): void
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
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'জিজ্ঞাসার পরীক্ষা', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );
        $asked = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — সই চাওয়া হয়নি।');

        $page = $this->actingAs($signer->fresh())->get(route('approval.inbox.show', $asked->id))->assertOk();
        $page->assertSee('data-confirm="'.e(__('approval::message.confirm_reject', [], 'bn')).'"', false);
        $page->assertSee('data-confirm="'.e(__('approval::message.confirm_send_back', [], 'bn')).'"', false);
    }
}
