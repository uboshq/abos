<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\EmploymentType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ "শ্রমিক" ধরন বসানো — বারবার চালানো নিরাপদ, `--dry` কিছু বসায় না; ধরনের পাশে BLA ধারা 2(65)-এর কথা বাংলায় (a6, ২১ অক্টোবর ২০২৬;
 * [[HrWorkerType]])।
 */
final class TheWorkerTypeIsInstalledOnceTest extends TestCase
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
        EmploymentType::query()->withTrashed()->where('code', 'WORKER')->forceDelete();
    }

    public function test_dry_installs_nothing_and_a_real_run_installs_once_in_every_company(): void
    {
        $this->artisan('abos:hr-worker-type', ['--dry' => true])->assertSuccessful();
        $this->assertFalse($this->hasWorker(), '⛔ শুকনো চালানো ধরন বসিয়ে দিল।');

        $this->artisan('abos:hr-worker-type')->assertSuccessful();
        $this->artisan('abos:hr-worker-type')->assertSuccessful();

        $this->assertSame(1, EmploymentType::query()->withTrashed()->where('code', 'WORKER')->count(), '⛔ দুইবার চালাতে দুইটা "শ্রমিক"।');
        $this->assertSame('শ্রমিক', EmploymentType::query()->where('code', 'WORKER')->value('name_bn'));

        foreach (Company::query()->get() as $company) {
            $this->assertTrue(CompanyContext::forCompany($company->id, fn () => $this->hasWorker()), "⛔ {$company->code}-এ ধরন বসেনি।");
        }
    }

    public function test_the_bla_note_stands_beside_the_category(): void
    {
        $note = __('hr::message.worker_note');
        $this->assertStringContainsString('2(65)', $note);

        $this->get(route('hr.employee.create'))->assertOk()->assertSee('data-worker-note', false);
        $this->get(route('hr.overtime.index'))->assertOk()->assertSee('data-worker-note', false);
    }

    private function hasWorker(): bool
    {
        return EmploymentType::query()->where('code', 'WORKER')->exists();
    }
}
