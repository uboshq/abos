<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Governance\Reports\AuditReports;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ §৮ ⛔১ — অডিটের প্রতিটা দরজায় বেতনের অঙ্ক কেবল `hr.salary.view`-এর চোখে; a6, ২১ অক্টোবর ২০২৬।
 *
 * ⓘ ec-র HOTFIX (66fd032fe, [[TheAuditorReadEverySalaryInTheAuditTrailTest]]) তালিকা, এক সারি, ইতিহাস, সময়যন্ত্র আর CSV ঢেকেছে —
 * নিয়মটা একটাই: Hr মডিউল `section_guarded_fields`-এ অঙ্কের ঘর ঘোষণা করে, [[FieldSecurity::permissionFor()]] পড়ে, আর অডিটের
 * প্রতিটা পাঠক [[FieldSecurity::show()]] দিয়ে ছাপে। এই টেস্ট বাকি তিনটা দাবি ধরে:
 *  · ⭐ পুরনো সারি — ঢাকনা পড়ার সময়ে, লেখার সময়ে নয়; তাই আগে থেকে টেবিলে থাকা সারিও ঢাকা (কাঁচা INSERT দিয়ে প্রমাণ);
 *  · ⭐ JSON আর xlsx রপ্তানি — CSV-র মতোই ধরা-টেবিল থেকে, তাই ঢাকা;
 *  · ⛔ নতুন দরজা — কেউ `old_value`/`new_value` পড়ে নতুন পর্দা বা API বানালে এখানে লাল, যতক্ষণ না তালিকায় কারণসহ লেখা হয়।
 *
 * ⓘ দেখা যায়: কে, কবে, কোন ঘর (`amount`) — বদলটা নিরীক্ষার, অঙ্কটা বেতনের।
 */
final class EveryAuditDoorHidesTheSalaryTest extends TestCase
{
    use RefreshDatabase;

    /** পুরনো সারির অঙ্ক — অন্য কোনো সংখ্যার সাথে মেলে না */
    private const OLD_ROW_FROM = '13579.24';

    private const OLD_ROW_TO = '97531.86';

    /**
     * ⓘ `old_value`/`new_value` যারা পড়ে — আর কেন সেখানে বেতন আসে না, বা কীভাবে ঢাকা।
     *
     * @var array<string, string>
     */
    private const READERS = [
        'app/Core/Engines/Audit/TimeMachine.php' => 'masks through FieldSecurity::visible()',
        'app/Modules/Governance/Resources/views/audit/partials/summary.blade.php' => 'masks through FieldSecurity::show()',
        'app/Modules/Governance/Resources/views/audit/show.blade.php' => 'masks through FieldSecurity::show()',
        'app/Modules/CreditControl/Dashboard/CreditDashboard.php' => 'customer_credit_changes, not the audit tables',
        'app/Modules/CreditControl/Http/Controllers/CreditProfileController.php' => 'customer_credit_changes, not the audit tables',
        'app/Modules/Hr/Services/JobHistory.php' => 'Employee designation/department/branch/type/manager ids only',
        'app/Modules/Sales/Services/PriceBookService.php' => 'price book rows only',
        'app/Modules/Sales/Services/SalePriceBook.php' => 'Product sale price only',
        'app/Modules/SystemAdmin/Http/Controllers/ControlPanelController.php' => 'Setting rows only',
    ];

    /** যে পাঠকরা নিজেরাই ছাপে — এদের ঢাকনা FieldSecurity দিয়েই হতে হবে */
    private const MASKING_READERS = [
        'app/Core/Engines/Audit/TimeMachine.php',
        'app/Modules/Governance/Resources/views/audit/partials/summary.blade.php',
        'app/Modules/Governance/Resources/views/audit/show.blade.php',
    ];

    private Company $company;

    private User $owner;

    private int $oldRow;

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

        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-OLD', 'name_en' => 'Old Row Probe', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        $structure = app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '1000');

        $this->oldRow = $this->writtenBeforeTheFix($structure);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_an_old_row_already_in_the_table_is_masked_on_every_web_door_and_export(): void
    {
        $auditor = $this->auditor();

        foreach ($this->doors() as $door => $url) {
            $body = $this->reach($auditor, $url);

            $this->assertStringContainsString('amount', $body, "⛔ [{$door}] ঘরের নাম নেই — পাতাটা এই সারির নয়, \"নেই\" দাবিটা অন্ধ হত।");

            foreach ([self::OLD_ROW_FROM, self::OLD_ROW_TO] as $amount) {
                $this->assertStringNotContainsString($amount, $body, "⛔ [{$door}] পুরনো সারির অঙ্ক {$amount} নিরীক্ষকের চোখে।");
            }
        }

        // ⓘ কে আর কবে দেখা যায় — সারিটা আছে, কেবল অঙ্ক নয়
        $show = $this->reach($auditor, route('governance.audit.show', $this->oldRow));
        $this->assertStringContainsString($this->owner->name, $show, '⛔ কে বদলেছেন তা-ও ঢাকা পড়ল — নিরীক্ষা অন্ধ।');
        $this->assertStringContainsString(FieldSecurity::mask(), $show, '⛔ অঙ্কের জায়গায় চিহ্ন নেই।');
    }

    public function test_the_salary_key_opens_the_old_row_again(): void
    {
        $auditor = $this->auditor('hr.salary.view');

        $all = '';

        foreach ($this->doors() as $url) {
            $all .= $this->reach($auditor, $url);
        }

        $this->assertStringContainsString(self::OLD_ROW_FROM, $all, '⛔ চাবি থাকা নিরীক্ষকও পুরনো অঙ্ক দেখছেন না।');
        $this->assertStringContainsString(self::OLD_ROW_TO, $all, '⛔ চাবি থাকা নিরীক্ষকও নতুন অঙ্ক দেখছেন না।');
    }

    public function test_every_reader_of_audit_values_is_known_and_the_printing_ones_mask(): void
    {
        $found = [];

        foreach (['app', 'resources'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $text = (string) file_get_contents($file->getPathname());

                if (preg_match('/(->|\[\')(old|new)_value\b/', $text) === 1) {
                    $found[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));
                }
            }
        }

        sort($found);
        $known = array_keys(self::READERS);
        sort($known);

        $this->assertSame($known, $found,
            "⛔ অডিটের পুরনো/নতুন মান পড়ে এমন ফাইলের তালিকা বদলেছে। নতুন দরজা হলে মানগুলো [[FieldSecurity::show()]] দিয়ে ছাপুন\n"
            .'(বেতনের অঙ্ক `hr.salary.view` ছাড়া ঢাকা — §৮ ⛔১), তারপর এখানে কারণসহ লিখুন।');

        foreach (self::MASKING_READERS as $path) {
            $this->assertStringContainsString('FieldSecurity::', (string) file_get_contents(base_path($path)),
                "⛔ {$path} অডিটের মান ছাপে, অথচ FieldSecurity ছাড়া — বেতনের অঙ্ক খোলা যেত।");
        }
    }

    public function test_the_changes_report_and_the_phone_have_no_value_column(): void
    {
        $keys = array_column(AuditReports::changes()->columns, 'key');

        foreach (['old_value', 'new_value', 'changes', 'amount'] as $value) {
            $this->assertNotContains($value, $keys, "⛔ \"বদল ও বাতিল\" রিপোর্টে `{$value}` কলাম — অঙ্ক চাবি ছাড়া বেরোত।");
        }

        // ⓘ ফোন/API-তে অডিটের দরজা নেই; কেউ বানালে এখানে লাল, আর ওপরের তালিকাও
        $apiAudit = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), 'Audit'))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $apiAudit, '⛔ API-তে অডিটের দরজা — বেতনের অঙ্কের ঢাকনা সেখানে আছে কি না দেখুন।');
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /**
     * ⓘ ঢাকনার আগের দিনের সারি — সেবা দিয়ে নয়, কাঁচা INSERT, যাতে প্রমাণটা "লেখার সময়ে ঢাকা" ধরে না নেয়।
     */
    private function writtenBeforeTheFix(SalaryStructure $structure): int
    {
        $id = (int) DB::table('audit_trails')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'user_id' => $this->owner->id,
            'action' => AuditTrail::UPDATED,
            'auditable_type' => SalaryStructure::class,
            'auditable_id' => $structure->id,
            'document_no' => null,
            'label' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
            'reason' => null,
            'created_at' => now(),
        ]);

        DB::table('audit_field_changes')->insert([
            'public_id' => (string) Str::uuid7(),
            'company_id' => $this->company->id,
            'audit_trail_id' => $id,
            'field' => 'amount',
            'old_value' => self::OLD_ROW_FROM,
            'new_value' => self::OLD_ROW_TO,
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, string> */
    private function doors(): array
    {
        return [
            'audit list' => route('governance.audit.index', ['module' => 'hr']),
            'audit list, CSV' => route('governance.audit.index', ['module' => 'hr', 'export' => 'csv']),
            'audit list, JSON' => route('governance.audit.index', ['module' => 'hr', 'export' => 'json']),
            'audit list, xlsx' => route('governance.audit.index', ['module' => 'hr', 'export' => 'xlsx']),
            'audit row' => route('governance.audit.show', $this->oldRow),
            'audit history' => route('governance.audit.record', $this->oldRow),
        ];
    }

    /** নিরীক্ষকের ছাঁচ — `governance.audit.view` আর `governance.export.view`; চাইলে আরও চাবি। */
    private function auditor(string ...$more): User
    {
        $user = User::factory()->create(['name' => 'Auditor Probe', 'current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $more) {
            foreach (['governance.audit.view', 'governance.export.view', ...$more] as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $user->fresh();
        $this->assertSame(in_array('hr.salary.view', $more, true), $user->can('hr.salary.view'), 'প্রস্তুতিটাই ভুল — বেতনের চাবি যেমন চাওয়া তেমন নয়।');

        return $user;
    }

    private function reach(User $user, string $url): string
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();

        $response = $this->actingAs($user)->get($url);

        $this->assertSame(200, $response->getStatusCode(), "দরজাটা খোলেনি ({$url}) — {$response->getStatusCode()}।");

        return $this->bodyOf($response);
    }

    /** ⓘ xlsx একটা zip — শিটের XML খুলে পড়া, নাহলে অঙ্ক থাকলেও সংকোচিত বাইটে খোঁজা মিলত না */
    private function bodyOf(TestResponse $response): string
    {
        $body = $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? (string) $response->streamedContent()
            : (string) $response->getContent();

        if (! str_starts_with($body, "PK\x03\x04")) {
            return $body;
        }

        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $body);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true, 'xlsx খোলা গেল না।');

        $text = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, 'xl/worksheets/') || $name === 'xl/sharedStrings.xml') {
                $text .= (string) $zip->getFromIndex($i);
            }
        }

        $zip->close();
        @unlink($file);

        return $text;
    }
}
