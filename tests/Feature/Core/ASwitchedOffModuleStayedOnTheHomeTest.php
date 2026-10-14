<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\ActivityRegistry;
use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\Widget;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\BranchModule;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বন্ধ মডিউল হোমেও নেই — মেনুর নিয়মটাই।
 *
 * ── ⛔ কী ভেঙেছিল (ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬) ──────────
 * হোমের ঘর ([[DashboardRegistry]]) আর "সাম্প্রতিক ঘটনা"
 * ([[ActivityRegistry]]) কেবল অনুমতি দেখত, মডিউলের সুইচ নয়। HR বন্ধ
 * করা কোম্পানির হোমে "আজ উপস্থিত ০ / ৫", আর শাখায় হিসাব বন্ধ থাকলেও
 * সর্বশেষ ভাউচারগুলো — ক্লিকে ৪০৪।
 */
final class ASwitchedOffModuleStayedOnTheHomeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** @return list<string> হোমের সব ঘরের লেবেল */
    private function widgetLabels(): array
    {
        $out = [];

        foreach (app(DashboardRegistry::class)->forUser($this->owner) as $widgets) {
            foreach ($widgets as $widget) {
                /** @var Widget $widget */
                $out[] = $widget->label;
            }
        }

        return $out;
    }

    public function test_a_module_switched_off_for_the_company_leaves_the_home_widgets(): void
    {
        $present = __('hr::dashboard.present_today');

        $this->assertContains($present, $this->widgetLabels(), 'পরীক্ষার আগেই HR-এর ঘরটা হোমে নেই।');

        app(SettingsService::class)->set('hr.enabled', false);

        $this->assertNotContains($present, $this->widgetLabels(),
            'HR বন্ধ, তবু হোমে "আজ উপস্থিত" — ক্লিকে ৪০৪।');
    }

    public function test_a_module_switched_off_in_the_branch_leaves_the_happenings(): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'home happening'],
            [
                ['account_id' => Account::query()->money()->postable()->active()->firstOrFail()->id, 'debit' => '700', 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'debit' => '0', 'credit' => '700'],
            ],
        );
        $voucher = app(VoucherService::class)->post($voucher);

        $titles = fn (): array => array_map(fn ($h) => $h->title, app(ActivityRegistry::class)->forUser($this->owner, 50));

        $this->assertNotEmpty(array_filter($titles(), fn (string $t) => str_contains($t, (string) $voucher->document_no)),
            'পরীক্ষার আগেই ভাউচারটা হোমের ঘটনায় নেই।');

        BranchModule::create([
            'branch_id' => CompanyContext::branchId(),
            'module' => 'accounts',
            'is_enabled' => false,
        ]);

        $this->assertEmpty(array_filter($titles(), fn (string $t) => str_contains($t, (string) $voucher->document_no)),
            'এই শাখায় হিসাব বন্ধ, তবু হোমে তার ভাউচার — ক্লিকে ৪০৪।');
    }
}
