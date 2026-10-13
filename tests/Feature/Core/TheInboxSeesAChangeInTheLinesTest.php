<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সইকারীর পাতা বলে কাগজ সই চাওয়ার পরে বদলেছে কি না — কাগজের ছাপ মিলিয়ে, ঘড়ি দেখে নয় (হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে পাতা দেখত কেবল কাগজের মাথার `updated_at`: সারির বদল (খাত, অঙ্ক) মাথা ছোঁয় না, তাই সইকারী "কিছু বদলায়নি" ভেবে সই দিতেন।
 * ⓘ ছাপ ছাড়া পুরনো অনুরোধে আগের ঘড়ির নিয়মই থাকে।
 */
final class TheInboxSeesAChangeInTheLinesTest extends TestCase
{
    use RefreshDatabase;

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

        $this->signer = User::factory()->create(['is_active' => true]);
        $this->signer->companies()->attach($company, ['is_active' => true]);
        $this->signer->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($company->id, fn () => $this->signer->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $flow = ApprovalFlow::create(['company_id' => $company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_a_line_changed_after_asking_shows_the_warning_and_an_untouched_paper_does_not(): void
    {
        [$a, $b, $c] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(3)->get()->all();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ইনবক্সের ছাপ',
                'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );
        $asked = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($asked, 'প্রস্তুতি — সই চাওয়ার কথা');

        $warning = __('approval::message.changed_since_asked');

        $this->actingAs($this->signer->fresh())->get(route('approval.inbox.show', $asked->id))->assertOk()->assertDontSee($warning);

        // ⓘ সারির খাত বদল — মাথার `updated_at` ছোঁয়া হয় না
        DB::table('voucher_lines')->where('voucher_id', $voucher->id)->where('account_id', $a->id)->update(['account_id' => $c->id]);

        $this->actingAs($this->signer->fresh())->get(route('approval.inbox.show', $asked->id))->assertOk()->assertSee($warning);
    }
}
