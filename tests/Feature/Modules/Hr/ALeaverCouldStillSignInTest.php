<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ চাকরি ছাড়লে লগইনও বন্ধ — joiner-mover-leaver (ISO 27001 A.5.18, A.6.5; ঘাটতির তালিকা #২০; a6, ২০ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে ছাড়ার তারিখ বসালে কেবল কর্মীর সারি নিষ্ক্রিয় হত, তাঁর লগইন চালু থাকত — চলে যাওয়া মানুষ ফোনে আর ওয়েবে ঢুকতে পারতেন।
 *
 * দাবি:
 *  - গতকাল ছাড়লেন, আর কোথাও কাজ নেই: লগইন নিষ্ক্রিয়, এই কোম্পানির প্রবেশ বন্ধ, ফোনের টোকেন মোছা, নিরীক্ষায় লাইন।
 *  - বোন-কোম্পানিতে কাজ আছে: কেবল এই কোম্পানির প্রবেশ বন্ধ, লগইন চালু।
 *  - আজই শেষ দিন: আজ কাজ করছেন, কিছু বন্ধ নয়। ভবিষ্যতের দিন: আজ কিছু নয়, দিন পেরোনোর পরে রোজকার কাজে বন্ধ।
 *  - মালিক কখনো এভাবে বন্ধ নন।
 */
final class ALeaverCouldStillSignInTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_leaver_with_no_other_company_loses_the_login_and_the_phone_tokens(): void
    {
        [$worker, $employee] = $this->worker('EMP-LV1');
        $worker->createToken('phone', ['sync']);

        app(EmployeeService::class)->endEmployment($employee, now()->subDay()->toDateString());

        $this->assertFalse((bool) $worker->fresh()->is_active, '⛔ চাকরি ছাড়ার পরেও লগইন চালু');
        $this->assertFalse($this->member($worker), '⛔ এই কোম্পানির প্রবেশ খোলা');
        $this->assertSame(0, $worker->tokens()->count(), '⛔ ফোনের টোকেন রয়ে গেল — ফোন থেকে ঢোকা চলত');
        $this->assertTrue(AuditTrail::query()->withoutGlobalScopes()->where('auditable_type', $employee->getMorphClass())
            ->where('auditable_id', $employee->id)->where('action', 'leaver_login_closed')->exists(), '⛔ নিরীক্ষায় লেখা নেই');
    }

    public function test_work_in_a_sister_company_keeps_the_login_but_closes_this_company(): void
    {
        [$worker, $employee] = $this->worker('EMP-LV2');
        $mart = Company::query()->where('code', 'FMART')->firstOrFail();
        $worker->companies()->attach($mart->id, ['is_active' => true]);

        app(EmployeeService::class)->endEmployment($employee, now()->subDay()->toDateString());

        $this->assertTrue((bool) $worker->fresh()->is_active, '⛔ বোন-কোম্পানির কাজও বন্ধ হয়ে গেল');
        $this->assertFalse($this->member($worker), '⛔ এই কোম্পানির প্রবেশ খোলা');
        $this->assertTrue((bool) DB::table('company_user')->where('company_id', $mart->id)->where('user_id', $worker->id)->value('is_active'));
    }

    public function test_the_last_day_is_still_a_working_day_and_a_future_date_closes_on_its_morning(): void
    {
        [$today, $todayEmployee] = $this->worker('EMP-LV3');
        app(EmployeeService::class)->endEmployment($todayEmployee, now()->toDateString());
        $this->assertTrue((bool) $today->fresh()->is_active, '⛔ শেষ কাজের দিনেই লগইন বন্ধ');

        [$notice, $noticeEmployee] = $this->worker('EMP-LV4');
        app(EmployeeService::class)->endEmployment($noticeEmployee, now()->addDays(10)->toDateString());
        $this->assertTrue((bool) $notice->fresh()->is_active, '⛔ নোটিশের সময়েই লগইন বন্ধ');

        $this->travel(11)->days();
        $this->artisan('abos:hr-leavers')->assertSuccessful();
        $this->assertFalse((bool) $notice->fresh()->is_active, '⛔ ছাড়ার দিন পেরোনোর পরেও লগইন চালু — রোজকার কাজ চলেনি');
        $this->assertFalse((bool) $today->fresh()->is_active, 'আগের শেষ দিনের কর্মীও এখন বন্ধ');
    }

    public function test_the_owner_is_never_locked_out_this_way(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $employee = app(EmployeeService::class)->create(['code' => 'EMP-OWN', 'name_en' => 'The Owner', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $employee->forceFill(['user_id' => $owner->id])->save();

        app(EmployeeService::class)->endEmployment($employee->fresh(), now()->subDay()->toDateString());

        $this->assertTrue((bool) $owner->fresh()->is_active, '⛔ মালিক নিজের কোম্পানিতে তালাবদ্ধ');
        $this->assertTrue($this->member($owner));
    }

    /** @return array{0: User, 1: Employee} */
    private function worker(string $code): array
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $employee = app(EmployeeService::class)->create(['code' => $code, 'name_en' => 'Leaver '.$code, 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $employee->forceFill(['user_id' => $user->id])->save();

        return [$user, $employee->fresh()];
    }

    private function member(User $user): bool
    {
        return (bool) DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $user->id)->value('is_active');
    }
}
