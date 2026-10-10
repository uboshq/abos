<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রকল্পের খতিয়ান আগের জের থেকে শুরু হয় — অডিট (সমন্বয়ক, ১০ অক্টোবর ২০২৬); খতিয়ান, নগদ বই আর ব্যাংক বইয়ের একই নিয়ম (801247e2)।
 *
 * দাবি — একই প্রকল্প: গত মাসে ১,২০০ খরচ, এ মাসে ৫০০; এ মাসের খতিয়ানের প্রথম সারির জের ১,৭০০, ৫০০ নয়। অন্য প্রকল্পের খরচ
 * এই শুরুর জেরে ঢোকে না।
 */
final class TheProjectLedgerStartsFromItsBalanceTest extends TestCase
{
    use RefreshDatabase;

    private CostCenter $project;

    private CostCenter $other;

    private Account $expense;

    private Account $against;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->project = CostCenter::query()->create(['code' => 'PRJ-OPEN', 'name_en' => 'Open project', 'name_bn' => 'চলমান প্রকল্প', 'is_active' => true]);
        $this->other = CostCenter::query()->create(['code' => 'PRJ-ELSE', 'name_en' => 'Other project', 'name_bn' => 'অন্য প্রকল্প', 'is_active' => true]);

        // ⓘ কেবল খরচের সারিতে প্রকল্প — উল্টো দিক প্রকল্পহীন, তাই খতিয়ানে নিট খরচটাই জমে; টাকার বাক্স ছোঁয় না
        $this->expense = Account::query()->where('code', StandardChart::BANK_CHARGES)->postable()->firstOrFail();
        $this->against = Account::query()->where('code', StandardChart::VEHICLE_HIRE)->postable()->firstOrFail();
    }

    public function test_this_months_project_ledger_carries_last_months_spend(): void
    {
        $from = now()->startOfMonth();
        $this->spend($this->project, '1200', $from->copy()->subDays(10)->toDateString());
        $this->spend($this->other, '9999', $from->copy()->subDays(10)->toDateString());
        $this->spend($this->project, '500', now()->toDateString());

        $rows = app(ReportEngine::class)->run('accounts.project_ledger', [
            'from' => $from->toDateString(), 'to' => now()->toDateString(), 'cost_center_id' => $this->project->id,
        ])->rows;

        $this->assertNotEmpty($rows, 'প্রস্তুতিটাই ভুল — এ মাসের সারি নেই।');
        $this->assertSame(0, bccomp((string) $rows[0]['balance'], '1700', 2),
            '⛔ প্রকল্পের খতিয়ান শূন্য থেকে শুরু — প্রথম সারির জের '.$rows[0]['balance'].', হওয়ার কথা আগের ১,২০০ + ৫০০।');
    }

    private function spend(CostCenter $centre, string $amount, string $date): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'প্রকল্পের খরচ'],
            [
                ['account_id' => $this->expense->id, 'debit' => $amount, 'credit' => '0', 'cost_center_id' => $centre->id, 'narration' => 'প্রকল্পের খরচ'],
                ['account_id' => $this->against->id, 'debit' => '0', 'credit' => $amount, 'narration' => 'প্রকল্পের খরচ'],
            ],
        ));
    }
}
