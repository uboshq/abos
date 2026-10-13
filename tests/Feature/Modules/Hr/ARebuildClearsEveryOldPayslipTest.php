<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ আবার-বানানো সব পুরনো বেতনশিট মোছে, হাজারের বেশি হলেও — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ২; [[PayrollService::rebuild()]])।
 *
 * ⓘ `each()` অফসেট ধরে হাজারে হাজারে পড়ত আর পড়তে পড়তেই মুছত: প্রথম হাজার মোছার পর দ্বিতীয় পাতা কিছু পেত না। হাজারের বেশি
 * শিট থাকলে বাকিগুলো নতুনের পাশে টিকে থাকত — তাঁদের বেতন মোটে আর খাতায় দুইবার।
 */
final class ARebuildClearsEveryOldPayslipTest extends TestCase
{
    use RefreshDatabase;

    private const EXTRA = 1001;

    public function test_a_rebuild_over_more_than_a_thousand_slips_leaves_none_of_the_old_ones(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SalaryHeadService::class)->installDefaults();

        // ⓘ ডেমোর কর্মী বাদ — রানে কেবল এই একজন থাকবেন
        Employee::query()->delete();

        $stays = app(EmployeeService::class)->create(['code' => 'EMP-STAY', 'name_en' => 'Still Here', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($stays, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        $run = app(PayrollService::class)->build('2026-08-01');
        $this->assertSame(1, $run->payslips()->count(), 'দৃশ্যটাই বানানো যায়নি — খসড়ায় একজনের শিট নেই');

        /*
         * ⓘ খসড়ায় আরও ১,০০১টা পুরনো শিট — জানুয়ারিতে চলে যাওয়া কর্মীদের, যাঁরা আগস্টের বেতনে আর পড়েন না। আবার-বানানোর পর
         * এগুলোর একটাও থাকার কথা নয়।
         */
        $now = now()->toDateTimeString();
        $rows = [];

        foreach (range(1, self::EXTRA) as $i) {
            $rows[] = ['company_id' => $company->id, 'code' => sprintf('GONE-%04d', $i), 'name_en' => 'Gone '.$i,
                'joining_date' => '2026-01-01', 'leaving_date' => '2026-01-31', 'payment_method' => 'cash', 'is_active' => false,
                'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('hr_employees')->insert($chunk);
        }

        $gone = DB::table('hr_employees')->where('company_id', $company->id)->where('code', 'like', 'GONE-%')->pluck('id');
        $slips = $gone->map(fn ($id) => ['company_id' => $company->id, 'payroll_run_id' => $run->id, 'employee_id' => $id,
            'gross' => '100', 'net' => '100', 'payment_method' => 'cash', 'created_at' => $now, 'updated_at' => $now])->all();

        foreach (array_chunk($slips, 500) as $chunk) {
            DB::table('hr_payslips')->insert($chunk);
        }

        $this->assertSame(self::EXTRA + 1, $run->payslips()->count(), 'দৃশ্যটাই বানানো যায়নি — হাজারের বেশি শিট বসেনি');

        $run = app(PayrollService::class)->rebuild($run->fresh());

        $this->assertSame(1, $run->payslips()->count(), '⛔ আবার-বানানোর পরেও পুরনো শিট টিকে রইল');
        $this->assertSame(1, (int) $run->employee_count, '⛔ রানের জন-সংখ্যায় পুরনো শিটও গোনা');
        $this->assertSame(0, bccomp((string) $run->net_total, (string) $run->payslips()->sum('net'), 4));
        $this->assertSame((int) $stays->id, (int) $run->payslips()->value('employee_id'));
    }
}
