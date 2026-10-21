<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Security\FieldSecurity;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PaymentChange;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PaymentChanges;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ একজনই কর্মীর বেতনের ঠিকানা বদলাতেন, আর পরের বেতন সেখানেই যেত — আন্তর্জাতিক মানের ফাঁক #৮ (গুরুতর), ২০ অক্টোবর ২০২৬
 * (fe; gaps-core-hr.md #৯; [[PaymentChanges]])।
 *
 * দাবি (কর্মীর সম্পাদনার দরজা আর পাতার সই-বোতাম দিয়ে):
 *  - চাবিওয়ালা কর্মী নম্বর বদলালে কর্মীর সারি বদলায় না — "অপেক্ষার বদল"; পাতায় ঢাকা নম্বরে দেখায়; সইকারী আর কর্মী খবর পান;
 *  - অপেক্ষার সময়ের বেতনের রানে স্লিপ পুরনো হিসাবের;
 *  - লেখক নিজে সই দিতে পারেন না (সইয়ের চাবি থাকলেও); চাবিহীন অন্যজনও না; চাবিওয়ালা অন্যজন দিলে বসে — আগেরটা `previous`-এ;
 *  - ডাটাবেজে আর নিরীক্ষার খাতায় নতুন নম্বর খোলা লেখা নেই;
 *  - "না" দিলে বা লেখক ফিরিয়ে নিলে কর্মীর সারি যেমন ছিল; মালিক নিজে বদলালে সাথে সাথে বসে, তবু লেখা থাকে; সুইচ বন্ধে সবারটা সরাসরি।
 */
final class TheSalaryWentWhereOneHandSaidTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\SignsTheSecondHand;

    private const OLD = '1234567890123';

    private const NEW = '5555555555555';

    private Company $company;

    private User $owner;

    private User $clerk;

    private User $signer;

    private User $worker;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->clerk = $this->person(['hr.employee.view', 'hr.employee.manage', 'hr.identity.view']);
        $this->signer = $this->person(['hr.employee.view', 'hr.payment_change.approve']);
        $this->worker = $this->person([]);

        app(SalaryHeadService::class)->installDefaults();
        $this->employee = app(EmployeeService::class)->create([
            'code' => 'EMP-PAY', 'name_en' => 'Paid Person', 'joining_date' => '2026-01-15', 'user_id' => $this->worker->id,
            'payment_method' => 'bank', 'bank_account_no' => self::OLD, 'bank_name' => 'Sonali', 'bank_routing_no' => '200270001',
        ]);
        app(SalaryStructureService::class)->set($this->employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
    }

    public function test_one_hand_asks_another_signs_and_the_salary_waits_at_the_old_account(): void
    {
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]))
            ->assertSessionHasNoErrors()->assertSessionHas('saved', __('hr::payment_change.waiting_saved'));

        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no, '⛔ একজনের হাতেই বেতনের ঠিকানা বদলাল');
        $change = PaymentChange::query()->where('employee_id', $this->employee->id)->where('status', PaymentChange::PENDING)->firstOrFail();
        $this->assertSame(self::NEW, $change->proposed['bank_account_no']);

        // ⓘ সইকারী আর কর্মী খবর পান — কর্মী জানেন কেউ তাঁর ঠিকানা ছুঁতে চাইছেন
        $this->assertSame(1, $this->notices($this->signer, PaymentChanges::WAITING), '⛔ সইকারী জানলেন না');
        $this->assertSame(1, $this->notices($this->worker, PaymentChanges::TOLD), '⛔ কর্মী জানলেন না তাঁর বেতনের ঠিকানা বদলাতে চাওয়া হয়েছে');
        $this->assertSame(0, $this->notices($this->clerk, PaymentChanges::WAITING), 'লেখক নিজের সইয়ের খবর পেলেন');

        // ⓘ পাতায় অপেক্ষার বদল — সইকারীর চাবিতে পরিচয় খোলে না, তাই নম্বর ঢাকা (শেষ চার অঙ্ক)
        $this->as($this->signer)->get(route('hr.employee.show', $this->employee))->assertOk()
            ->assertSee('data-payment-change', false)
            ->assertSee(route('hr.employee.payment_change.approve', [$this->employee, $change]), false)
            ->assertSee('•••••••••5555')
            ->assertDontSee(self::NEW);

        // ⓘ অপেক্ষার সময়ের বেতন পুরনো হিসাবে
        $this->as($this->owner);
        $run = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $this->assertSame(self::OLD, (string) $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail()->bank_account_no,
            '⛔ সইয়ের আগেই বেতন নতুন হিসাবে গেল');

        // ⛔ লেখক নিজে — সইয়ের চাবি দিলেও না
        $this->give($this->clerk, 'hr.payment_change.approve');
        $this->as($this->clerk)->post(route('hr.employee.payment_change.approve', [$this->employee, $change]))
            ->assertSessionHasErrors(['payment_change' => __('hr::payment_change.another_hand')]);

        // ⛔ চাবিহীন অন্যজন — না
        $hr = $this->person(['hr.employee.view']);
        $this->as($hr)->post(route('hr.employee.payment_change.approve', [$this->employee, $change]))
            ->assertSessionHasErrors(['payment_change' => __('hr::payment_change.no_right')]);
        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no);

        // ⓘ চাবিওয়ালা অন্যজন — বসে
        $this->as($this->signer)->post(route('hr.employee.payment_change.approve', [$this->employee, $change]))
            ->assertSessionHasNoErrors()->assertSessionHas('saved', __('hr::payment_change.applied'));

        $this->assertSame(self::NEW, (string) $this->employee->fresh()->bank_account_no, 'সইয়ের পরেও বসল না');
        $change = $change->fresh();
        $this->assertSame(PaymentChange::APPLIED, $change->status);
        $this->assertSame((int) $this->signer->id, (int) $change->decided_by);
        $this->assertSame(self::OLD, $change->previous['bank_account_no'], '⛔ আগের হিসাব কোথাও রইল না');
        $this->assertSame(1, $this->notices($this->worker, PaymentChanges::TOLD, 'applied'), '⛔ কর্মী জানলেন না যে ঠিকানা বদলেছে');

        // ⛔ খোলা লেখা কোথাও নেই — অপেক্ষার সারিতে নয়, নিরীক্ষার খাতায়ও নয়
        $this->assertSame(0, DB::table('hr_payment_changes')->where('proposed', 'like', '%'.self::NEW.'%')
            ->orWhere('previous', 'like', '%'.self::OLD.'%')->count(), '⛔ ব্যাংক নম্বর ডাটাবেজে খোলা');
        $this->assertSame(0, DB::table('audit_field_changes')->where(fn ($q) => $q->where('new_value', 'like', '%'.self::NEW.'%')
            ->orWhere('old_value', 'like', '%'.self::OLD.'%'))->count(), '⛔ নিরীক্ষার খাতায় ব্যাংক নম্বর খোলা');
    }

    public function test_a_refusal_or_a_withdrawal_leaves_the_account_and_the_owner_alone_applies_at_once(): void
    {
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]));
        $first = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();

        $this->as($this->signer)->post(route('hr.employee.payment_change.reject', [$this->employee, $first]))->assertSessionHasNoErrors();
        $this->assertSame(PaymentChange::REJECTED, $first->fresh()->status);
        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no, '⛔ "না"-র পরেও বদলাল');

        // ⓘ লেখক নিজে ফিরিয়ে নেন — বাতিল, মোছা নয়
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['payment_method' => 'mfs', 'mfs_number' => '01711111111']));
        $second = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();
        $this->assertSame('bank', $this->employee->fresh()->payment_method, '⛔ পথ বদলও একজনের হাতে বসল');
        $this->as($this->clerk)->post(route('hr.employee.payment_change.reject', [$this->employee, $second]))->assertSessionHasNoErrors();
        $this->assertSame(PaymentChange::CANCELLED, $second->fresh()->status);

        // ⓘ মালিক নিজে — সাথে সাথে, তবু লেখা থাকে
        $this->as($this->owner)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]))
            ->assertSessionHasNoErrors();
        $this->assertSame(self::NEW, (string) $this->employee->fresh()->bank_account_no);
        $this->assertSame(PaymentChange::APPLIED, PaymentChange::query()->latest('id')->firstOrFail()->status, 'মালিকের বদল লেখা রইল না');

        // ⓘ সুইচ বন্ধ — সবারটা সরাসরি, লেখা থাকে
        app(SettingsService::class)->set('hr.payment_change_needs_second_hand', false);
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::OLD]));
        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no, 'সুইচ বন্ধেও অপেক্ষায় রইল');
        $this->assertSame(PaymentChange::APPLIED, PaymentChange::query()->latest('id')->firstOrFail()->status);

        // ⓘ ঠিকানা না ছুঁলে কোনো বদল নয় — নাম বদলালে নতুন সারি হয় না
        $count = PaymentChange::query()->count();
        app(SettingsService::class)->set('hr.payment_change_needs_second_hand', true);
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['name_en' => 'Paid Person Renamed']))
            ->assertSessionHas('saved', __('hr::message.employee_updated'));
        $this->assertSame($count, PaymentChange::query()->count(), 'ঠিকানা না ছুঁলেও অপেক্ষার বদল হলো');
    }

    // ── যন্ত্রপাতি ──

    /** @param array<string, mixed> $over */
    private function form(array $over): array
    {
        return [
            'name_en' => 'Paid Person', 'joining_date' => '2026-01-15', 'payment_method' => 'bank', 'bank_name' => 'Sonali',
            'bank_account_no' => self::OLD, 'bank_routing_no' => '200270001', 'photo' => $this->png(),
            ...$over,
        ];
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            $this->give($user, $key);
        }

        return $user->fresh();
    }

    private function notices(User $user, string $type, ?string $what = null): int
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', $type)
            ->when($what, fn ($q, $w) => $q->where('title', __('hr::payment_change.told_'.$w.'_title')))->count();
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
