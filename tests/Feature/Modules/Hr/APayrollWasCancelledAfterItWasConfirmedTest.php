<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বেতন-রান বাতিল হলো, অথচ বেতন খাতায় রয়ে গেল — অডিটের বাকি তালিকা (abos-2c), ১ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[PayrollService::cancel()]] আর `confirm()` দুইটাই অবস্থা দেখত হাতের কপি থেকে, তালা ছাড়া। একজন
 * পুরনো পাতা থেকে "বাতিল" চাপলেন, আর তার আগেই আরেকজন "নিশ্চিত" করে বেতন খাতায় বসিয়েছেন — বাতিলের
 * কপিতে তখনো "খসড়া", তাই বিপরীত দাখিলা হত না: রান বাতিল, বেতনের খরচ আর প্রদেয় খাতায় থেকে গেল।
 * উল্টো দিকে, বাতিল হয়ে যাওয়া রান পুরনো কপি থেকে "নিশ্চিত" হয়ে খাতায় বসত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * দুই কাজেই লেনদেনের ভিতরে রানের সারিতে তালা দিয়ে তাজা অবস্থা ([[ReadsTheRowUnderLock]]) — বিপরীত
 * দাখিলা হবে কি না সেটা তাজা অবস্থা ঠিক করে।
 */
final class APayrollWasCancelledAfterItWasConfirmedTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '2026-08-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ মালিক — সুপার অ্যাডমিন; প্রথম কাজে তাঁকে কিছু আটকায় না
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-001',
            'name_en' => 'Rafiq Islam',
            'joining_date' => '2026-01-15',
            'payment_method' => 'cash',
        ]);

        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
    }

    public function test_a_cancel_from_a_stale_copy_reverses_the_salaries_already_posted(): void
    {
        $run = app(PayrollService::class)->build(self::MONTH);
        $stale = PayrollRun::query()->findOrFail($run->id);

        app(PayrollService::class)->confirm($run);
        $this->assertNotSame('0', $this->net($run), 'প্রস্তুতিটাই ভুল — নিশ্চিত রানের বেতন খাতায় বসেনি।');

        app(PayrollService::class)->cancel($stale, 'ভুল মাস');

        $this->assertSame(DocumentStatus::CANCELLED, $run->fresh()->status);

        $posted = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->count();
        $reversed = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE.':reversal')->where('source_id', $run->id)->count();

        $this->assertSame($posted, $reversed, "⛔ রান বাতিল, অথচ বেতনের দাখিলা উল্টায়নি — {$posted}টা সারি বসেছিল, উল্টেছে {$reversed}টা।");
    }

    public function test_a_confirm_from_a_stale_copy_does_not_post_a_cancelled_run(): void
    {
        $run = app(PayrollService::class)->build(self::MONTH);
        $stale = PayrollRun::query()->findOrFail($run->id);

        app(PayrollService::class)->cancel($run, 'ভুল মাস');

        $said = null;

        try {
            app(PayrollService::class)->confirm($stale);
        } catch (ValidationException $e) {
            $said = array_key_first($e->errors());
        }

        $this->assertSame('status', $said, '⛔ বাতিল রান পুরনো কপি থেকে নিশ্চিত করতে গেলে পরিষ্কার কথায় ফেরেনি।');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->count(),
            '⛔ বাতিল রানের বেতন খাতায় বসেছে।');
    }

    private function net(PayrollRun $run): string
    {
        return (string) LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->sum('debit');
    }
}
