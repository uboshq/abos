<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Imports\CustomerImporter;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Customer\Services\CustomerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ বাকির দিন বাড়ানো সীমা বাড়ানোর সইয়ের পথেই — fe-র বিক্রয় গ্যাপ ২৬, ২০ অক্টোবর ২০২৬।
 *
 * ⛔ গ্যাপ: সীমা বাড়াতে সই লাগে, অথচ দিন ৩০ থেকে ৯০ করলে একই সীমায় টাকা তিনগুণ সময় বাইরে থাকে। মালিকের নিয়ম: সই সবখানে,
 * চালু/বন্ধসহ। ⓘ সুইচ = বিদ্যমান ছক (সীমার `customer.credit_limit` বা দিনের `customer.credit_days`); ⛔ কোনো ছক বীজ নয়।
 *
 * দাবি, আসল রুটে (গ্রাহকের ফর্ম, অনুমোদনের ইনবক্স, আমদানি):
 *  (ক) সই চালু → ফর্মে দিন বাড়ালে গ্রাহক বদলায় না, অনুরোধ বসে; মালিক সই দিলে দিন নিজেই বসে।
 *  (খ) কমানো সাথে সাথে বসে, সই চালু থাকলেও।
 *  (গ) সব ছক বন্ধ (UB-র মতো) বা ছকই নেই → বাড়ানো সাথে সাথে বসে, আজকের মতো।
 *  (ঘ) আমদানি (আর নতুন গ্রাহকের ফর্ম) বেশি দিনের নতুন গ্রাহক এনে সই এড়াতে পারে না — সুইচ বন্ধ হলে পারে, আজকের মতো।
 *  ⓘ সীমার পথ চালু অথচ দিনের ছক নেই → থামে, বার্তা বলে কোথায় ছক বসাতে হবে (নীরবে পার নয়)।
 */
final class RaisedCreditDaysWaitForTheLimitSignatureTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ খুচরা (RETAIL) — পরিবেশকের পয়েন্ট লাগে, তাই ধরন খালি রাখলে সারিটা অন্য কারণে থামত */
    private const ROW = 'CUS-GAP26,Imported Ninety,,,,,RETAIL,,,0,90,0,';

    private Company $company;

    private User $owner;

    private User $clerk;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->clerk = User::factory()->create(['is_active' => true]);
        $this->clerk->companies()->attach($this->company, ['is_active' => true]);
        $this->clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $this->clerk->givePermissionTo([Permission::findOrCreate('customer.view', 'web'), Permission::findOrCreate('customer.update', 'web')]);

        // ⓘ ছক বসার আগে তৈরি — তখন সুইচ বন্ধ, তাই ৩০ দিনে জন্মানো আজকের মতোই চলে
        $this->customer = app(CustomerService::class)->create([
            'name_en' => 'Days Gap Store', 'name_bn' => 'দিনের ফাঁক স্টোর', 'credit_limit' => '0', 'credit_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── (ক) ────────────────────────────────────────────────────────────

    public function test_with_the_signature_on_a_raise_waits_and_lands_on_the_owners_signature(): void
    {
        $this->flows(limit: true, days: true);
        $this->assertFixture(on: true);

        $this->actingAs($this->clerk)
            ->put(route('customer.update', $this->customer), $this->form(['credit_days' => '90']))
            ->assertSessionHasErrors('credit_days');

        $this->assertSame(30, $this->days(), '⛔ সই ছাড়া বাকির দিন ৩০ থেকে ৯০ হলো।');

        $pending = $this->daysRequests()->where('status', Approval::PENDING)->sole();
        $this->assertSame(90, (int) $pending->amount, 'অনুরোধের অঙ্কে প্রস্তাবিত দিন নেই — সইকারী কীসে সই দিচ্ছেন?');

        $this->actingAs($this->owner)
            ->post(route('approval.inbox.approve', $pending->id))
            ->assertRedirect();

        $this->assertSame(Approval::APPROVED, $pending->fresh()->status);
        $this->assertSame(90, $this->days(), '⛔ মালিকের সই পড়ল, অথচ দিন বসল না — আবার সংরক্ষণ চাপতে হচ্ছে।');

        $row = CustomerCreditChange::query()->where('customer_id', $this->customer->id)->where('field', 'credit_days')->sole();
        $this->assertSame((int) $pending->id, (int) $row->approval_id, 'ইতিহাসে কোন সইয়ে বদলাল — সূত্র নেই।');

        // ⛔ সই একবারই খাটে — কমিয়ে আবার ৯০ চাইলে নতুন সই
        $this->actingAs($this->clerk)->put(route('customer.update', $this->customer), $this->form(['credit_days' => '30']))->assertSessionHasNoErrors();
        $this->actingAs($this->clerk)->put(route('customer.update', $this->customer), $this->form(['credit_days' => '90']))->assertSessionHasErrors('credit_days');
        $this->assertSame(30, $this->days(), '⛔ একই সই দুবার খাটল।');
    }

    public function test_with_the_limit_path_on_and_no_days_flow_a_raise_stops_and_says_where(): void
    {
        $this->flows(limit: true, days: false);
        $this->assertFixture(on: true);

        $this->actingAs($this->clerk)
            ->put(route('customer.update', $this->customer), $this->form(['credit_days' => '90']))
            ->assertSessionHasErrors(['credit_days' => __('customer::validation.days_need_a_flow')]);

        $this->assertSame(30, $this->days(), '⛔ সীমার সই চালু, দিনের ছক নেই — আর দিন নীরবে বেড়ে গেল।');
        $this->assertSame(0, $this->daysRequests()->count());
    }

    // ── (খ) ────────────────────────────────────────────────────────────

    public function test_a_decrease_lands_at_once_with_the_signature_on(): void
    {
        $this->flows(limit: true, days: true);
        $this->assertFixture(on: true);

        $this->actingAs($this->clerk)
            ->put(route('customer.update', $this->customer), $this->form(['credit_days' => '10']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('customer.show', $this->customer));

        $this->assertSame(10, $this->days(), '⛔ দিন কমাতে সই চাওয়া হলো — কমানো ঝুঁকি কমায়।');
        $this->assertSame(0, $this->daysRequests()->count(), '⛔ কমানোয় অনুরোধ বসল।');
    }

    // ── (গ) ────────────────────────────────────────────────────────────

    public function test_with_every_flow_switched_off_a_raise_lands_at_once(): void
    {
        // ⓘ UB-র মতো — ছক আছে, সব বন্ধ (৩ অক্টোবর ২০২৬)
        $this->flows(limit: true, days: true, active: false);
        $this->assertSame(2, ApprovalFlow::query()->where('module', 'customer')->whereIn('action', ['credit_limit', 'credit_days'])->where('is_active', false)->count(),
            'ফিক্সচার: দুইটা ছকই বসানো আর বন্ধ থাকার কথা।');
        $this->assertFixture(on: false);

        $this->actingAs($this->clerk)
            ->put(route('customer.update', $this->customer), $this->form(['credit_days' => '90']))
            ->assertSessionHasNoErrors();

        $this->assertSame(90, $this->days(), '⛔ সই বন্ধ কোম্পানিতে দিন বাড়ানো আটকে গেল।');
        $this->assertSame(0, $this->daysRequests()->count());
    }

    public function test_with_no_flow_at_all_a_raise_lands_at_once(): void
    {
        $this->assertSame(0, ApprovalFlow::query()->where('module', 'customer')->count(), 'ফিক্সচার: গ্রাহকের কোনো ছক থাকার কথা নয়।');
        $this->assertFixture(on: false);

        $this->actingAs($this->clerk)
            ->put(route('customer.update', $this->customer), $this->form(['credit_days' => '90']))
            ->assertSessionHasNoErrors();

        $this->assertSame(90, $this->days());
    }

    // ── (ঘ) ────────────────────────────────────────────────────────────

    public function test_the_import_cannot_bring_a_customer_with_raised_days_past_the_signature(): void
    {
        $this->flows(limit: true, days: true);
        $this->assertFixture(on: true);

        $checked = $this->actingAs($this->owner)
            ->post(route('system_admin.import.check'), ['kind' => 'customer', 'file' => $this->csv(self::ROW)])
            ->assertSessionHas('import_checked')
            ->getSession()->get('import_checked');

        // ⓘ সারিটা কেবল দিনের কারণেই থামে — অন্য কারণে থামলে দাবিটা ফাঁকা হত
        $this->assertSame([__('customer::validation.days_on_create')], $checked['rows'][0]['errors'] ?? null);

        $this->actingAs($this->owner)
            ->post(route('system_admin.import.store'), ['kind' => 'customer', 'file' => $this->csv(self::ROW)]);

        $this->assertFalse(Customer::query()->where('code', 'CUS-GAP26')->exists(), '⛔ আমদানিতে ৯০ দিনের গ্রাহক বসল, সই ছাড়া।');

        // ⓘ নতুন গ্রাহকের ফর্মও একই দরজা
        $this->actingAs($this->owner)
            ->post(route('customer.store'), ['name_en' => 'Born Ninety', 'name_bn' => 'নব্বই', 'phone' => '01711002626',
                'credit_limit' => '0', 'credit_days' => '90', 'opening_balance' => '0'])
            ->assertSessionHasErrors('credit_days');
        $this->assertFalse(Customer::query()->where('name_en', 'Born Ninety')->exists(), '⛔ নতুন গ্রাহক ৯০ দিন নিয়ে জন্মাল, সই ছাড়া।');
    }

    public function test_with_the_switch_off_the_same_import_row_lands_as_today(): void
    {
        $this->assertFixture(on: false);

        $this->actingAs($this->owner)
            ->post(route('system_admin.import.store'), ['kind' => 'customer', 'file' => $this->csv(self::ROW)]);

        $this->assertSame(90, (int) Customer::query()->where('code', 'CUS-GAP26')->value('credit_days'),
            '⛔ সই বন্ধ কোম্পানিতে আমদানি আটকে গেল — আজকের মতো চলার কথা।');
    }

    public function test_the_new_message_is_bengali(): void
    {
        app()->setLocale('bn');

        $this->assertMatchesRegularExpression('/\p{Bengali}/u', __('customer::validation.days_on_create'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** ⓘ ছক কেবল এই পরীক্ষার কোম্পানিতে, পরীক্ষার ভিতরে — ⛔ কোনো বীজ নয়; সইকারী মালিক */
    private function flows(bool $limit, bool $days, bool $active = true): void
    {
        foreach (array_keys(array_filter(['credit_limit' => $limit, 'credit_days' => $days])) as $action) {
            $flow = ApprovalFlow::create(['module' => 'customer', 'action' => $action]);
            $flow->forceFill(['is_active' => $active])->save();
            ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->owner->id]);
        }

        // ⓘ ইঞ্জিন ছক মনে রাখে — ছক বদলের পরে নতুন করে
        app()->forgetInstance(ApprovalEngine::class);
        app()->forgetInstance(DocumentApproval::class);
        app()->forgetInstance(CustomerService::class);
    }

    /** ⓘ ফিক্সচার যা ভাবছে তাই কি — সুইচ, মালিক, শুরুর দিন */
    private function assertFixture(bool $on): void
    {
        $engine = app(ApprovalEngine::class);
        $isOn = $engine->requires('customer', 'credit_limit', null, 'Customer', ApprovalEngine::fieldsOf($this->customer->fresh()))
            || $engine->requires('customer', 'credit_days', null, 'Customer', ApprovalEngine::fieldsOf($this->customer->fresh()));

        $this->assertSame($on, $isOn, 'ফিক্সচার: সইয়ের সুইচ '.($on ? 'চালু' : 'বন্ধ').' থাকার কথা।');
        $this->assertTrue(app(Ownership::class)->activeOwnersIn((int) $this->company->id)->contains('id', $this->owner->id), 'ফিক্সচার: সইকারী এই কোম্পানির মালিক নন।');
        $this->assertFalse(CustomerService::isSuperAdmin($this->clerk), 'ফিক্সচার: চাওয়ার মানুষটা মালিক হলে দাবিটা অন্য কিছু মাপত।');
        $this->assertSame(30, $this->days(), 'ফিক্সচার: গ্রাহক ৩০ দিনে শুরু হওয়ার কথা।');
    }

    /** @param array<string, string> $over */
    private function form(array $over): array
    {
        return [
            'name_en' => 'Days Gap Store', 'name_bn' => 'দিনের ফাঁক স্টোর',
            'credit_limit' => '0', 'credit_days' => '30', ...$over,
        ];
    }

    private function days(): int
    {
        return (int) $this->customer->fresh()->credit_days;
    }

    private function daysRequests()
    {
        return Approval::query()->where('approvable_type', $this->customer->getMorphClass())
            ->where('approvable_id', $this->customer->id)->where('action', 'credit_days');
    }

    private function csv(string $line): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'abos-gap26').'.csv';
        file_put_contents($path, implode(',', array_keys(CustomerImporter::columns()))."\n".$line."\n");

        return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    }
}
