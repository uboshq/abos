<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeePhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কর্মীর ছবি — মালিক, ২ অক্টোবর ২০২৬: "employee prf pic upload ki kore dibe"।
 *
 * ⭐ ফর্মে তোলা ছবি প্রোফাইলের কভারে বসে, আর ছবিটা খোলে `attachment.download` দিয়ে।
 * ⛔ একই মানুষ, `hr.employee.view` কেড়ে নিলে — ছবিও আর খোলে না (চাবি বন্ধ, তারপর খোলা নয়: আগে খোলা, তারপর বন্ধ)।
 * ⛔ অন্য কোম্পানির কর্মীর ছবি এই কোম্পানির দরজা দিয়ে খোলে না।
 * ⛔ নামে .png কিন্তু ভেতরে লেখা — ফেরত, আর আগের ছবি যেমন ছিল।
 */
final class TheProfileHadALetterWhereTheFaceGoesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->company = Company::create(['code' => 'PIC', 'name_en' => 'Picture Co']);
        $this->branch = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        CompanyContext::set($this->company->id, $this->branch->id);

        foreach (['hr.employee.view', 'hr.employee.manage'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id);
        $this->clerk->givePermissionTo(['hr.employee.view', 'hr.employee.manage']);
    }

    public function test_the_photo_reaches_the_cover_and_only_the_key_opens_it(): void
    {
        $karim = $this->employee('E-1', 'Karim Uddin');

        $before = (string) $this->actingAs($this->clerk)->get(route('hr.employee.show', $karim))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-profile-photo', $before, 'ছবি তোলার আগেই কভারে ছবি।');

        $this->actingAs($this->clerk)->put(route('hr.employee.update', $karim), $this->form($karim) + ['photo' => $this->png('karim.png')])
            ->assertSessionHasNoErrors()->assertRedirect();

        $photo = $karim->fresh()->photo;
        $this->assertNotNull($photo, '⛔ ছবি তোলা হলো, অথচ কর্মীর সারিতে বসেনি।');
        $this->assertSame('employee', $photo->source_entity);
        $this->assertSame((int) $karim->id, (int) $photo->source_entity_id);

        $html = (string) $this->actingAs($this->clerk)->get(route('hr.employee.show', $karim))->assertOk()->getContent();
        $this->assertStringContainsString('data-profile-photo', $html, 'কভারে ছবি নেই।');
        $this->assertStringContainsString(e(route('attachment.download', $photo)), $html, '⛔ কভারের ছবি দরজা-যাচাইয়ের রুট দিয়ে আসে না।');

        $this->actingAs($this->clerk)->get(route('attachment.download', $photo))->assertOk();

        // ⛔ একই মানুষ, চাবি কেড়ে নেওয়া — ছবিও বন্ধ
        $this->clerk->revokePermissionTo('hr.employee.view');
        $this->clerk->revokePermissionTo('hr.employee.manage');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->clerk->fresh())->get(route('attachment.download', $photo))->assertForbidden();
    }

    public function test_another_companys_photo_does_not_open_and_a_fake_photo_is_refused(): void
    {
        $karim = $this->employee('E-1', 'Karim Uddin');

        $other = Company::create(['code' => 'OTH', 'name_en' => 'Other Co']);
        $theirs = CompanyContext::forCompany($other->id, function () use ($other) {
            $stranger = Employee::query()->create([
                'company_id' => $other->id, 'code' => 'X-1', 'name_en' => 'Stranger', 'joining_date' => '2024-01-01',
            ]);

            return app(EmployeePhotoService::class)->replace($stranger, $this->png('stranger.png'));
        });
        CompanyContext::set($this->company->id, $this->branch->id);

        $status = $this->actingAs($this->clerk)->get(route('attachment.download', $theirs))->getStatusCode();
        $this->assertContains($status, [403, 404], "⛔ অন্য কোম্পানির কর্মীর ছবি খুলে গেছে (HTTP {$status})।");

        $path = tempnam(sys_get_temp_dir(), 'pic');
        file_put_contents($path, '<?php echo "not a photo";');
        $fake = new UploadedFile($path, 'face.png', 'image/png', null, true);

        $this->actingAs($this->clerk)->put(route('hr.employee.update', $karim), $this->form($karim) + ['photo' => $fake])
            ->assertSessionHasErrors('photo');
        $this->assertNull($karim->fresh()->photo_attachment_id, '⛔ ভেতরে ছবি নয়, তবু কর্মীর ছবি হিসেবে বসেছে।');
    }

    private function employee(string $code, string $name): Employee
    {
        return Employee::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'code' => $code,
            'name_en' => $name, 'joining_date' => '2024-03-01', 'payment_method' => 'cash', 'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function form(Employee $employee): array
    {
        return ['code' => $employee->code, 'name_en' => $employee->name_en, 'joining_date' => '2024-03-01', 'payment_method' => 'cash'];
    }

    private function png(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));

        $path = tempnam(sys_get_temp_dir(), 'pic');
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
