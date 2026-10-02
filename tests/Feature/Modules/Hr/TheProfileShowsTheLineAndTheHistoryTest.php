<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\MasterData\Models\Designation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * প্রোফাইলের মানুষটা, রিপোর্টিং লাইন আর চাকরির ইতিহাস — মালিকের অনুমোদিত নকশা (১+৩+৪+১০), ২ অক্টোবর ২০২৬।
 *
 * ⭐ ফর্মে লেখা জন্মতারিখ, রক্তের গ্রুপ, জরুরি যোগাযোগ প্রোফাইলে ফেরে।
 * ⭐ "যাঁর অধীনে" উপরে, অধীনস্থরা নিচে; পদবি বদলালে ইতিহাসে আগের → পরের আর কে করেছেন।
 * ⛔ অন্য কোম্পানির কর্মীর অধীনে বসানো যায় না; নিজের বা নিজের অধীনস্থের অধীনেও নয় (চক্র)।
 */
final class TheProfileShowsTheLineAndTheHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->company = Company::create(['code' => 'LIN', 'name_en' => 'Line Co']);
        $this->branch = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        CompanyContext::set($this->company->id, $this->branch->id);

        foreach (['hr.employee.view', 'hr.employee.manage'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->clerk = User::factory()->create(['name' => 'HR Clerk', 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id);
        $this->clerk->givePermissionTo(['hr.employee.view', 'hr.employee.manage']);
    }

    public function test_the_person_the_line_and_the_history_reach_the_profile(): void
    {
        $officer = Designation::query()->create(['company_id' => $this->company->id, 'code' => 'OFF', 'name_en' => 'Officer', 'name_bn' => 'অফিসার', 'is_active' => true]);
        $senior = Designation::query()->create(['company_id' => $this->company->id, 'code' => 'SNR', 'name_en' => 'Senior Officer', 'name_bn' => 'সিনিয়র অফিসার', 'is_active' => true]);

        $boss = $this->employee('B-1', 'Boss Rahman');
        $karim = $this->employee('E-1', 'Karim Uddin', ['designation_id' => $officer->id]);
        $this->employee('S-1', 'Sub Hasan', ['reports_to_employee_id' => $karim->id]);

        $this->actingAs($this->clerk)->put(route('hr.employee.update', $karim), $this->form($karim, [
            'designation_id' => $senior->id,
            'reports_to_employee_id' => $boss->id,
            'date_of_birth' => '1990-05-04',
            'blood_group' => 'B+',
            'mother_name' => 'Amena Begum',
            'emergency_name' => 'Rahim Uddin',
            'emergency_relation' => 'Brother',
            'emergency_mobile' => '01811000000',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        app()->setLocale('en');
        $html = (string) $this->actingAs($this->clerk)->get(route('hr.employee.show', $karim))->assertOk()->getContent();

        foreach (['04 May 1990', 'B+', 'Amena Begum', 'Rahim Uddin (Brother)', '01811000000'] as $fact) {
            $this->assertStringContainsString(e($fact), $html, "ফর্মে লেখা \"{$fact}\" প্রোফাইলে ফেরেনি।");
        }

        $line = $this->between($html, 'data-reporting-line', '</section>');
        $this->assertStringContainsString('Boss Rahman', $line, 'যাঁর অধীনে তিনি রিপোর্টিং লাইনে নেই।');
        $this->assertStringContainsString('Sub Hasan', $line, 'অধীনস্থ রিপোর্টিং লাইনে নেই।');

        $history = $this->between($html, 'data-job-history', '</section>');
        $this->assertStringContainsString(e(__('hr::profile.event_designation_id')), $history, 'পদবি বদল ইতিহাসে নেই।');
        $this->assertStringContainsString('Officer → Senior Officer', $history, 'ইতিহাসে আগের → পরের পদবি নেই।');
        $this->assertStringContainsString('HR Clerk', $history, 'ইতিহাস বলে না কে বদলেছেন।');
        $this->assertStringContainsString(e(__('hr::profile.event_joined')), $history, 'যোগদান ইতিহাসে নেই।');
    }

    public function test_the_line_never_crosses_a_company_or_loops_back(): void
    {
        $karim = $this->employee('E-1', 'Karim Uddin');
        $sub = $this->employee('S-1', 'Sub Hasan', ['reports_to_employee_id' => $karim->id]);

        $other = Company::create(['code' => 'OTH', 'name_en' => 'Other Co']);
        $stranger = CompanyContext::forCompany($other->id, fn () => Employee::query()->create([
            'company_id' => $other->id, 'code' => 'X-1', 'name_en' => 'Stranger', 'joining_date' => '2024-01-01',
        ]));
        CompanyContext::set($this->company->id, $this->branch->id);

        foreach ([[$karim, $stranger->id, 'অন্য কোম্পানির কর্মী'], [$karim, $karim->id, 'নিজে'], [$karim, $sub->id, 'নিজের অধীনস্থ']] as [$who, $to, $case]) {
            $this->actingAs($this->clerk)->put(route('hr.employee.update', $who), $this->form($who, ['reports_to_employee_id' => $to]))
                ->assertSessionHasErrors('reports_to_employee_id');
            $this->assertNull($who->fresh()->reports_to_employee_id, "⛔ {$case}-এর অধীনে বসানো গেছে।");
        }

        $form = (string) $this->actingAs($this->clerk)->get(route('hr.employee.edit', $karim))->assertOk()->getContent();
        $this->assertStringNotContainsString('Stranger', $form, '⛔ অন্য কোম্পানির কর্মী "যাঁর অধীনে" তালিকায়।');
        $this->assertStringContainsString('Sub Hasan', $form);
    }

    /** @param array<string, mixed> $extra */
    private function employee(string $code, string $name, array $extra = []): Employee
    {
        return Employee::query()->create($extra + [
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'code' => $code,
            'name_en' => $name, 'joining_date' => '2024-03-01', 'payment_method' => 'cash', 'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(Employee $employee, array $changes): array
    {
        return $changes + [
            'code' => $employee->code, 'name_en' => $employee->name_en, 'joining_date' => '2024-03-01',
            'payment_method' => 'cash', 'designation_id' => $employee->designation_id, 'photo' => $this->png(),
        ];
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "\"{$from}\" পাতায় নেই।");
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    /** ⓘ ছবি বাধ্যতামূলক (মালিক, ৩ অক্টোবর ২০২৬) — ছবি ছাড়া ফর্ম ছবির ভুলেই থামত, আসল দাবি পর্যন্ত পৌঁছাত না */
    private function png(): \Illuminate\Http\UploadedFile
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'pic');
        imagepng($image, $path);
        imagedestroy($image);

        return new \Illuminate\Http\UploadedFile($path, 'face.png', 'image/png', null, true);
    }
}
