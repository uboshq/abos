<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsDashboard;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লাভ-ক্ষতির অঙ্ক লাভ-ক্ষতির চাবিতেই — অ্যাকাউন্টস অডিট ম১৩/ম১৮ (সমন্বয়ক, ১০ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে রেওয়ামিল, খাত-ধরে আয় আর খরচ, আর ড্যাশবোর্ডের আয়-ব্যয়, ব্যাংকের জের, নিট লাভ আর রেওয়ামিলের প্যানেল খুলত কেবল
 * accounts.view বা accounts.report-এ — যাঁর লাভ-ক্ষতির চাবি নেই, তিনিও পাশের দরজা দিয়ে মুনাফা জেনে যেতেন।
 *
 * দাবি — একই মানুষ, আগে চাবি ছাড়া, পরে চাবি দিয়ে: তিন রিপোর্ট ৪০৩ থেকে ২০০; ড্যাশবোর্ডে ব্যাংকের জের আর রেওয়ামিলের
 * প্যানেল নেই থেকে আছে; রিপোর্টের সংজ্ঞাও একই চাবি চায় (রিপোর্ট-কেন্দ্র আর ফোন সেটা পড়ে); নতুন কোম্পানির হিসাবরক্ষক চাবিটা পান।
 */
final class TheProfitFiguresNeedTheFinalAccountsKeyTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = ['trial-balance', 'expense-by-head', 'income-by-head'];

    public function test_the_profit_figures_open_only_with_the_final_accounts_key(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        config(['abos.dashboards_v2' => true]);

        $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        $user->givePermissionTo(['accounts.view', 'accounts.report']);
        $this->actingAs($user);

        foreach (self::SLUGS as $slug) {
            $this->get(route('accounts.report.show', ['slug' => $slug]))->assertForbidden();
        }
        $this->assertNull($this->panel('trial_balance'), '⛔ চাবি ছাড়াই ড্যাশবোর্ডে রেওয়ামিল।');
        // ⓘ ব্যাংকের জের আর মাসের নিট লাভের বাক্স নিজের চাবি চায় — ড্যাশবোর্ড চাবি না থাকলে বাক্সটা আঁকে না
        foreach (['bank_balance', 'net_profit_month'] as $label) {
            $this->assertSame('accounts.report.final', $this->stat($label)?->permission, "⛔ ড্যাশবোর্ডের {$label} বাক্স চূড়ান্ত চাবি চায় না।");
        }

        foreach (['accounts.trial_balance', 'accounts.expense_by_head', 'accounts.income_by_head'] as $key) {
            $this->assertContains('accounts.report.final', app(ReportEngine::class)->get($key)->permissions(), "⛔ {$key}-এর সংজ্ঞা চূড়ান্ত চাবি চায় না।");
        }

        // ⓘ মানচিত্রও (অর্থের পরিকল্পনার পাতা) চাবি ছাড়া লিংক দেখায় না — চাপলে ৪০৩-এ যাওয়ার বদলে লিংকই নেই
        $this->assertTrue($this->map($user)->isEmpty(), '⛔ চাবি ছাড়াই মানচিত্রে রেওয়ামিলের লিংক।');

        // ⭐ একই মানুষ, চাবি দিলে
        $user->givePermissionTo('accounts.report.final');
        $this->actingAs($user->fresh());
        foreach (self::SLUGS as $slug) {
            $this->get(route('accounts.report.show', ['slug' => $slug]))->assertOk();
        }
        $this->assertNotNull($this->panel('trial_balance'), 'চাবি দিয়েও রেওয়ামিলের প্যানেল নেই — দাবিটা কিছুই মাপছে না।');
        $this->assertFalse($this->map($user->fresh())->isEmpty(), 'চাবি দিয়েও মানচিত্রে লিংক নেই — দাবিটা কিছুই মাপছে না।');

        $this->assertContains('accounts.report.final', (require base_path('app/Modules/Accounts/module.php'))['role_templates']['Accountant'],
            '⛔ নতুন কোম্পানির হিসাবরক্ষক চাবিটা পান না।');
    }

    private function map(User $user): \App\Core\Engines\Map\DrawnMap
    {
        return app(\App\Core\Engines\Map\MapEngine::class)->draw([[
            'no' => '১', 'title' => 'হিসাব', 'items' => [['রেওয়ামিল', 'accounts.report.show:trial-balance', null]],
        ]], $user);
    }

    private function stat(string $key): ?object
    {
        $board = AccountsDashboard::dashboard();
        $all = [...$board->stats, ...collect($board->panels)->flatMap(fn ($p) => is_array($p->stats ?? null) ? $p->stats : [])->all()];

        return collect($all)->first(fn ($s) => ($s->label ?? null) === __('accounts::dashboard.'.$key));
    }

    private function panel(string $key): ?object
    {
        return collect(AccountsDashboard::dashboard()->panels)->firstWhere('label', __('accounts::dashboard.'.$key));
    }
}
