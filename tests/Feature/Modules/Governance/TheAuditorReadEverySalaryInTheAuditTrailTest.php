<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ নিরীক্ষক অডিটের খাতায় প্রত্যেকের বেতন পড়তেন — Governance অডিট, ১৮ অক্টোবর ২০২৬ (fe-র দফা ১, HOTFIX)।
 *
 * ⓘ নিরীক্ষকের (Auditor) হাতে `governance.audit.view`, `hr.salary.view` নেই। তবু অডিটের তালিকা, একটা সারি,
 * সময়যন্ত্র ("ওইদিন কেমন ছিল") আর তালিকার CSV বেতনের কাঠামো, বেতনশিট, শিটের সারি আর বেতনের রানের পুরনো ও নতুন
 * অঙ্ক খোলা ছাপত — কারণ HR মডিউল এই ঘরগুলো [[FieldSecurity]]-কে বলেনি (কেবল নিজের পর্দায় `@can` ছিল)।
 * এখন `section_guarded_fields`-এ ঘোষিত, তাই চাবি ছাড়া "••••"; মালিক আগের মতোই অঙ্ক দেখেন।
 *
 * ⓘ মালিকের সিদ্ধান্তের নম্বর নেই, IAS অনুচ্ছেদ নেই (কর্মীর তথ্যের গোপনীয়তা)। ec, ১৮ অক্টোবর ২০২৬।
 */
final class TheAuditorReadEverySalaryInTheAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    /** কাঠামোর পুরনো আর নতুন অঙ্ক — অন্য কোনো সংখ্যার সাথে মেলে না */
    private const STRUCTURE_OLD = '27182.81';

    private const STRUCTURE_NEW = '31415.27';

    private const RUN_GROSS = '86420.13';

    private const RUN_NET = '85062.22';

    private const SLIP_GROSS = '64813.57';

    private const SLIP_NET = '62345.55';

    private const LINE_AMOUNT = '75319.46';

    private Company $company;

    private User $owner;

    /** @var array<string, int> মডেল => তার অঙ্ক-বদলের অডিট-সারি */
    private array $trails = [];

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

        $basic = SalaryHead::query()->where('code', 'BASIC')->firstOrFail();
        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-AUD', 'name_en' => 'Audit Probe', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($employee, $basic, '2026-01-15', self::STRUCTURE_OLD);

        $run = app(PayrollService::class)->build('2026-08-01');
        $run->update(['gross_total' => self::RUN_GROSS, 'deduction_total' => '1357.91', 'net_total' => self::RUN_NET]);

        $slip = $run->payslips()->where('employee_id', $employee->id)->firstOrFail();
        $slip->update(['gross' => self::SLIP_GROSS, 'deductions' => '2468.02', 'net' => self::SLIP_NET]);

        $line = $slip->lines()->orderBy('id')->firstOrFail();
        $line->update(['amount' => self::LINE_AMOUNT]);

        // ⓘ কাঠামোর বদল শেষে — আসল পথে (একই দিনের সারি নতুন অঙ্ক পায়), আর তালিকার প্রথম পাতায় থাকে
        $structure = app(SalaryStructureService::class)->set($employee, $basic, '2026-01-15', self::STRUCTURE_NEW);

        $this->trails = [
            'structure' => $this->trailOf(SalaryStructure::class, (int) $structure->id, 'amount'),
            'run' => $this->trailOf(PayrollRun::class, (int) $run->id, 'net_total'),
            'slip' => $this->trailOf(Payslip::class, (int) $slip->id, 'net'),
            'line' => $this->trailOf(PayslipLine::class, (int) $line->id, 'amount'),
        ];
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_auditor_gets_no_salary_amount_on_any_audit_door(): void
    {
        $auditor = $this->auditor();

        foreach ($this->doors() as $door => [$url, $canary]) {
            $body = $this->reach($auditor, $url);

            $this->assertStringContainsString($canary, $body, "⛔ [{$door}] পাতাটা এই সারির নয় — \"নেই\" দাবিটা অন্ধ হত।");

            foreach ($this->amounts() as $amount) {
                $this->assertStringNotContainsString($amount, $body,
                    "⛔ [{$door}] বেতনের অঙ্ক {$amount} নিরীক্ষকের চোখে — `hr.salary.view` ছাড়াই।");
            }
        }

        // ⓘ ঘরটা আছে, কিন্তু তাঁর জন্য নয় — চিহ্নটা ফাঁকা ঘর নয়
        $this->assertStringContainsString(FieldSecurity::mask(),
            $this->reach($auditor, route('governance.audit.show', $this->trails['structure'])));
    }

    public function test_the_owner_still_reads_them(): void
    {
        $this->assertTrue($this->owner->can('hr.salary.view'), 'প্রস্তুতিটাই ভুল — মালিকের হাতে বেতনের চাবি নেই।');

        $all = '';

        foreach ($this->doors() as $door => [$url]) {
            $all .= $this->reach($this->owner, $url);
        }

        foreach ($this->amounts() as $amount) {
            $this->assertStringContainsString($amount, $all, "⛔ মালিক অডিটে {$amount} দেখছেন না — ঢাকনা সবার জন্য বসে গেছে।");
        }

        $this->assertStringContainsString(self::STRUCTURE_NEW,
            $this->reach($this->owner, route('governance.audit.at', ['trail' => $this->trails['structure'], 'on' => now()->toDateString()])),
            '⛔ মালিকের সময়যন্ত্রে আজকের কাঠামোর অঙ্ক নেই।');
    }

    public function test_the_auditor_with_the_salary_key_reads_them_too(): void
    {
        $auditor = $this->auditor();
        $auditor->givePermissionTo('hr.salary.view');

        $body = $this->reach($auditor->fresh(), route('governance.audit.show', $this->trails['structure']));

        $this->assertStringContainsString(self::STRUCTURE_OLD, $body, '⛔ চাবি থাকা নিরীক্ষকও পুরনো অঙ্ক দেখছেন না।');
        $this->assertStringContainsString(self::STRUCTURE_NEW, $body, '⛔ চাবি থাকা নিরীক্ষকও নতুন অঙ্ক দেখছেন না।');
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /** @return list<string> */
    private function amounts(): array
    {
        return [self::STRUCTURE_OLD, self::STRUCTURE_NEW, self::RUN_GROSS, self::RUN_NET, self::SLIP_GROSS, self::SLIP_NET, self::LINE_AMOUNT];
    }

    /**
     * প্রতিটা দরজা: ঠিকানা আর পাতাটা যে এই সারির তার চিহ্ন।
     *
     * @return array<string, array{string, string}>
     */
    private function doors(): array
    {
        $doors = [
            'audit list' => [route('governance.audit.index', ['module' => 'hr']), 'gross_total'],
            'audit list, CSV' => [route('governance.audit.index', ['module' => 'hr', 'export' => 'csv']), 'gross_total'],
        ];

        foreach ($this->trails as $name => $id) {
            $field = match ($name) {
                'structure', 'line' => 'amount',
                'run' => 'net_total',
                'slip' => 'net',
            };

            $doors["audit row of the {$name}"] = [route('governance.audit.show', $id), $field];
            $doors["audit history of the {$name}"] = [route('governance.audit.record', $id), $field];
            $doors["time machine of the {$name}"] = [route('governance.audit.at', ['trail' => $id, 'on' => now()->toDateString()]), $field];
        }

        return $doors;
    }

    /** নিরীক্ষকের ছাঁচ — `governance.audit.view` আর `governance.export.view`, HR-এর কিছুই নয়। */
    private function auditor(): User
    {
        foreach (['governance.audit.view', 'governance.export.view', 'hr.salary.view'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $user = User::factory()->create(['name' => 'Auditor Probe']);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo(['governance.audit.view', 'governance.export.view']);

        $user = $user->fresh();
        $this->assertFalse($user->can('hr.salary.view'), 'প্রস্তুতিটাই ভুল — চাবি না দিয়েও হাতে আছে।');

        return $user;
    }

    private function reach(User $user, string $url): string
    {
        $response = $this->actingAs($user)->get($url);

        $this->assertSame(200, $response->getStatusCode(), "দরজাটা খোলেনি ({$url}) — {$response->getStatusCode()}।");

        return $this->bodyOf($response);
    }

    private function bodyOf(TestResponse $response): string
    {
        if ($response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return (string) $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    /** এই রেকর্ডের যে অডিট-সারিতে ঘরটা বদলেছে। */
    private function trailOf(string $type, int $id, string $field): int
    {
        $trail = AuditTrail::query()
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->whereHas('changes', fn ($q) => $q->where('field', $field))
            ->latest('id')
            ->first();

        $this->assertNotNull($trail, "প্রস্তুতিটাই ভুল — {$type}#{$id}-এর `{$field}` বদলের কোনো অডিট-সারি নেই।");

        return (int) $trail->id;
    }
}
