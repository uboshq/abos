<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ কর্মীর ব্যক্তিগত তথ্য পরিচয়ের চাবির পেছনে — ঘাটতির তালিকা #১৯ (GDPR ন্যূনতম তথ্য, ISO 27001 A.5.34; a6, ২০ অক্টোবর ২০২৬;
 * [[Employee::GUARDED]])।
 *
 * ⛔ আগে কেবল NID আর ব্যাংক ঢাকা ছিল — `hr.employee.view` থাকলেই যে কেউ জন্মতারিখ, বাবা-মায়ের নাম, রক্তের গ্রুপ, বাসার ঠিকানা
 * আর জরুরি যোগাযোগ দেখতেন, বদলাতেও পারতেন।
 *
 * দাবি — একই মানুষ, আগে চাবি ছাড়া, পরে চাবিসহ: প্রোফাইলে অঙ্ক/লেখা নেই, ঢাকনা আছে; ফর্মে ঘর নেই; হাতে বানানো অনুরোধে বদলায়
 * না। চাবি দিলে সবই দেখা যায়, বদলায়।
 */
final class TheProfileShowedEveryonesHomeAddressTest extends TestCase
{
    use RefreshDatabase;

    private const PERSONAL = [
        'father_name' => 'Abdul Karim', 'mother_name' => 'Rahima Begum', 'blood_group' => 'B+',
        'present_address' => 'House 12, Road 7, Netrokona', 'permanent_address' => 'Village Kendua, Netrokona',
        'emergency_name' => 'Salma Akter', 'emergency_relation' => 'Sister', 'emergency_mobile' => '01711223344',
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_personal_details_need_the_identity_key_on_the_page_the_form_and_the_write(): void
    {
        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-PII', 'name_en' => 'Private Person', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        $employee->forceFill([...self::PERSONAL, 'date_of_birth' => '1990-05-17'])->save();

        foreach (self::PERSONAL as $field => $value) {
            $this->assertContains($field, Employee::GUARDED, "⛔ {$field} পরিচয়ের চাবির পেছনে নেই");
        }
        $this->assertContains('date_of_birth', Employee::GUARDED);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->give($clerk, 'hr.employee.view');
        $this->give($clerk, 'hr.employee.manage');

        $page = $this->as($clerk)->get(route('hr.employee.show', $employee->id))->assertOk();
        foreach ([...array_values(self::PERSONAL), '17 May 1990', \App\Core\Support\DateFormat::format('1990-05-17')] as $value) {
            $page->assertDontSee($value, false);
        }
        $page->assertSee(FieldSecurity::mask(), false);

        $form = $this->as($clerk)->get(route('hr.employee.edit', $employee->id))->assertOk();
        foreach (['mother_name', 'present_address', 'emergency_mobile', 'date_of_birth'] as $field) {
            $form->assertDontSee('name="'.$field.'"', false);
        }

        // ⛔ হাতে বানানো অনুরোধ — চাবি ছাড়া ঠিকানা আর জরুরি নম্বর বদলায় না
        $this->as($clerk)->put(route('hr.employee.update', ['employee' => $employee->id, 'emergency_mobile' => '01999999999']), [
            'name_en' => 'Private Person', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
            'present_address' => 'Somewhere Else', 'photo' => $this->png(),
        ])->assertSessionHasNoErrors();
        $fresh = $employee->fresh();
        $this->assertSame(self::PERSONAL['present_address'], $fresh->present_address, '⛔ চাবি ছাড়া বাসার ঠিকানা বদলাল');
        $this->assertSame(self::PERSONAL['emergency_mobile'], $fresh->emergency_mobile, '⛔ চাবি ছাড়া ঠিকানার query দিয়ে জরুরি নম্বর বদলাল');

        // ⭐ চাবি দিলে
        $this->give($clerk, 'hr.identity.view');
        $this->as($clerk)->get(route('hr.employee.show', $employee->id))->assertOk()
            ->assertSee(self::PERSONAL['mother_name'], false)->assertSee(self::PERSONAL['present_address'], false);
        $this->as($clerk)->get(route('hr.employee.edit', $employee->id))->assertOk()->assertSee('name="mother_name"', false);
        $this->as($clerk)->put(route('hr.employee.update', $employee->id), [
            'name_en' => 'Private Person', 'joining_date' => '2026-01-15', 'payment_method' => 'cash', 'present_address' => 'New Home',
        ])->assertSessionHasNoErrors();
        $this->assertSame('New Home', $employee->fresh()->present_address, 'চাবি থাকলে বদলায়');
    }

    private function png(): \Illuminate\Http\UploadedFile
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'pic');
        imagepng($image, $path);
        imagedestroy($image);

        return new \Illuminate\Http\UploadedFile($path, 'face.png', 'image/png', null, true);
    }

    private function give(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FieldSecurity::forget();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }
}
