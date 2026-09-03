<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerConduct;
use App\Modules\Customer\Services\ConductService;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Customer\Support\ConductType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পার্টির আচরণ — Status নয়, "কেমন কারবার"।
 *
 * চার সিদ্ধান্তই এখানে পরীক্ষিত: বাঁধা তালিকা · কে লিখল বাধ্যতামূলক ·
 * নামানো যায় মোছা নয় · OTHER-এ নোট বাধ্যতামূলক। আর A1-এর চুক্তি:
 * activeConduct() ঝুঁকি আগে ফেরায়, এক কোয়েরিতে।
 */
class ConductTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private ConductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->service = app(ConductService::class);
    }

    private function customer(string $name = 'Rahim Store'): Customer
    {
        return app(CustomerService::class)->create([
            'name_en' => $name,
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);
    }

    public function test_a_recorded_flag_carries_who_and_when(): void
    {
        $customer = $this->customer();

        $note = $this->service->record($customer, 'LATE_PAYMENT');

        $this->assertTrue($note->is_active);
        $this->assertSame($this->user->id, $note->recorded_by);
        $this->assertNotNull($note->recorded_at);
        $this->assertSame('risk', $note->severity());
    }

    public function test_other_needs_a_note(): void
    {
        $customer = $this->customer();

        $this->expectException(ValidationException::class);
        $this->service->record($customer, ConductType::OTHER, null);
    }

    public function test_other_with_a_note_is_fine(): void
    {
        $customer = $this->customer();

        $note = $this->service->record($customer, ConductType::OTHER, 'Keeps the gate locked past noon');

        $this->assertSame(ConductType::OTHER, $note->type);
        $this->assertSame('Keeps the gate locked past noon', $note->note);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $customer = $this->customer();

        $this->expectException(ValidationException::class);
        $this->service->record($customer, 'NOT_A_REAL_TYPE');
    }

    /** ★ নামানো যায়, মোছা নয় — সারিটা থাকে, শুধু চলমান নয়। */
    public function test_retiring_keeps_the_row_as_history(): void
    {
        $customer = $this->customer();
        $note = $this->service->record($customer, 'CHEQUE_DISHONOURED');

        $this->service->retire($note);

        $fresh = CustomerConduct::query()->find($note->id);
        $this->assertNotNull($fresh, 'পতাকা নামাতে গিয়ে সারিটাই মুছে গেছে।');
        $this->assertFalse($fresh->is_active);
        $this->assertSame($this->user->id, $fresh->retired_by);
        $this->assertNotNull($fresh->retired_at);
    }

    /** ★ চিপ অগ্রাধিকার: ঝুঁকি আগে, তারপর লক্ষণীয়, ভালো শেষে। */
    public function test_active_conduct_comes_back_risk_first(): void
    {
        $customer = $this->customer();
        $this->service->record($customer, 'QUICK_UNLOADING');   // good
        $this->service->record($customer, 'SLOW_UNLOADING');    // notice
        $this->service->record($customer, 'LATE_PAYMENT');      // risk

        $severities = $customer->fresh()->activeConduct()->pluck('severity')->all();

        $this->assertSame(['risk', 'notice', 'good'], $severities);
    }

    public function test_retired_flags_do_not_show_as_active(): void
    {
        $customer = $this->customer();
        $keep = $this->service->record($customer, 'PAYS_ON_TIME');
        $drop = $this->service->record($customer, 'DISPUTES_INVOICE');

        $this->service->retire($drop);

        $active = $customer->fresh()->activeConduct();
        $this->assertCount(1, $active);
        $this->assertSame('good', $active->first()['severity']);
    }

    public function test_the_screen_records_through_the_endpoint(): void
    {
        $customer = $this->customer();

        $this->post(route('customer.conduct.store', $customer), [
            'type' => 'REFUSES_AT_GATE',
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_conduct_notes', [
            'customer_id' => $customer->id,
            'type' => 'REFUSES_AT_GATE',
            'is_active' => true,
        ]);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $customer = $this->customer();
        $stranger = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($stranger)
            ->post(route('customer.conduct.store', $customer), ['type' => 'LATE_PAYMENT'])
            ->assertForbidden();
    }
}
