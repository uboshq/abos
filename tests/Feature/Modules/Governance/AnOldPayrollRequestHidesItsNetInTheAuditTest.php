<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ বেতনের রানের পুরনো সইয়ের অনুরোধের নিট অডিটের খাতায় খোলা — fe-র উত্তর খ, ২১ অক্টোবর ২০২৬ (a6)।
 *
 * ⓘ নিট আজ আর `approvals.amount`-এ খোলা বসে না ([[ApprovalRules::amountKey()]]), কিন্তু আগের অনুরোধগুলোর তৈরির অডিট-সারিতে
 * (`audit_field_changes`, ঘর `amount`) সেটা লেখা — আর অডিট বদলানো যায় না। ⭐ তাই ঢাকনা পড়ার মুহূর্তে, সারি ধরে
 * ([[GuardsFieldsPerRow]], [[Approval::fieldPermissionFor()]]): যে অনুরোধের কাজের অঙ্ক চাবির পেছনে, তার `amount` চাবি ছাড়া "••••" —
 * তালিকা, এক সারি, ইতিহাস, সময়যন্ত্র আর তালিকার ফাইলে। ⓘ অন্য মডিউলের অনুরোধ (বিক্রির ছাড়) আগের মতোই খোলা।
 */
final class AnOldPayrollRequestHidesItsNetInTheAuditTest extends TestCase
{
    use RefreshDatabase;

    private const NET = '12345.6700';

    private const DISCOUNT = '777.4400';

    private Company $company;

    private User $owner;

    private int $payrollTrail;

    private int $salesTrail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();
        FieldSecurity::forget();

        $run = app(PayrollService::class)->build('2026-08-01');

        // ⓘ ঢাকনার আগের দিনের মতো — অঙ্ক খোলা ঘরে, অডিটেও খোলা; কাঁচা INSERT, যাতে প্রমাণ "লেখার সময়ে ঢাকা" ধরে না নেয়
        $this->payrollTrail = $this->oldRequest(PayrollRun::class, (int) $run->id, 'hr', 'payroll', self::NET);
        $this->salesTrail = $this->oldRequest(PayrollRun::class, (int) $run->id, 'sales', 'discount', self::DISCOUNT);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_old_payroll_request_shows_no_net_without_the_key_on_any_audit_door(): void
    {
        $auditor = $this->auditor();

        foreach ($this->doors($this->payrollTrail) as $door => $url) {
            $body = $this->reach($auditor, $url);
            $this->assertStringContainsString('amount', $body, "⛔ [{$door}] পাতাটা এই সারির নয় — \"নেই\" দাবিটা অন্ধ হত।");
            $this->assertStringNotContainsString('12345', $body, "⛔ [{$door}] বেতনের রানের পুরনো অনুরোধের নিট নিরীক্ষকের চোখে।");
        }

        $row = $this->reach($auditor, route('governance.audit.show', $this->payrollTrail));
        $this->assertStringContainsString(FieldSecurity::mask(), $row);
        // ⓘ ঢাকা কেবল অঙ্ক — একই অনুরোধের অন্য ঘর খোলা
        $this->assertStringContainsString('ReasonProbehr', $row, '⛔ বেতনের রানের অনুরোধের অঙ্ক ছাড়া অন্য ঘরও ঢাকা পড়ল।');
    }

    public function test_the_salary_key_shows_the_net_again(): void
    {
        $auditor = $this->auditor('hr.salary.view');

        $this->assertStringContainsString(self::NET, $this->reach($auditor, route('governance.audit.show', $this->payrollTrail)),
            '⛔ চাবি থাকা নিরীক্ষকও নিট দেখছেন না।');
        $this->assertStringContainsString('12345', $this->reach($auditor, route('governance.audit.index', ['export' => 'csv'])));
    }

    public function test_another_modules_request_keeps_its_amount_for_an_auditor_without_hr_keys(): void
    {
        $auditor = $this->auditor();

        foreach ($this->doors($this->salesTrail) as $door => $url) {
            $this->assertStringContainsString('777.44', $this->reach($auditor, $url),
                "⛔ [{$door}] বিক্রির ছাড়ের অনুরোধের অঙ্কও ঢাকা পড়ল — নিয়মটা কেবল বেতনের রানের।");
        }
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    private function oldRequest(string $type, int $id, string $module, string $action, string $amount): int
    {
        $approval = (int) DB::table('approvals')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id,
            'approvable_type' => $type, 'approvable_id' => $id, 'module' => $module, 'action' => $action,
            'amount' => $amount, 'status' => Approval::PENDING, 'current_level' => 1, 'payload' => null,
            'requested_by' => $this->owner->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $trail = (int) DB::table('audit_trails')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'branch_id' => null,
            'user_id' => $this->owner->id, 'action' => AuditTrail::CREATED, 'auditable_type' => Approval::class,
            'auditable_id' => $approval, 'document_no' => null, 'label' => null, 'ip_address' => '127.0.0.1',
            'user_agent' => null, 'reason' => null, 'created_at' => now(),
        ]);

        DB::table('audit_field_changes')->insert([
            ['public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'audit_trail_id' => $trail,
                'field' => 'amount', 'old_value' => null, 'new_value' => $amount, 'created_at' => now()],
            // ⓘ অঙ্ক ছাড়া আরেকটা ঘর — সেটা খোলা থাকে
            ['public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'audit_trail_id' => $trail,
                'field' => 'requested_reason', 'old_value' => null, 'new_value' => 'ReasonProbe'.$module, 'created_at' => now()],
        ]);

        return $trail;
    }

    /** @return array<string, string> */
    private function doors(int $trail): array
    {
        return [
            'audit list' => route('governance.audit.index'),
            'audit list, CSV' => route('governance.audit.index', ['export' => 'csv']),
            'audit list, JSON' => route('governance.audit.index', ['export' => 'json']),
            'audit row' => route('governance.audit.show', $trail),
            'audit history' => route('governance.audit.record', $trail),
            'time machine' => route('governance.audit.at', ['trail' => $trail, 'on' => now()->toDateString()]),
        ];
    }

    private function auditor(string ...$more): User
    {
        $user = User::factory()->create(['name' => 'Auditor Probe', 'current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $more) {
            // ⓘ তালিকা নামানোর চাবি আলাদা (১৭ নভেম্বর ২০২৬, [[ExportListing]]) — এই দাবি ফাইলের ভিতরের অঙ্ক মাপে, দরজা নয়
            foreach (['governance.audit.view', 'governance.export.view', 'system_admin.list.export', ...$more] as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function reach(User $user, string $url): string
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();

        $response = $this->actingAs($user)->get($url);
        $this->assertSame(200, $response->getStatusCode(), "দরজাটা খোলেনি ({$url}) — {$response->getStatusCode()}।");

        return $this->body($response);
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? (string) $response->streamedContent()
            : (string) $response->getContent();
    }
}
