<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Models\TenancyCharge;
use App\Modules\Finance\Models\TenancyMove;
use App\Modules\Finance\Reports\TenancyReports;
use App\Modules\Finance\Services\TenancyService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ আমরাও জায়গা ভাড়া দিই — মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬ ([[TenancyService]])।
 *
 * ⛔ ভাড়াটে সবসময় পক্ষ (ব্যক্তি বা গ্রাহক), ১১২৫ আর ২১৫৫-এর প্রতিটা সারিতে তাঁর নাম; মাসের শুরুতে Dr ১১২৫ / Cr ৪৩২০, এক মাস
 * একবার, সামনের বা বন্ধ মাস নয়, বন্ধ বা শুরু-না-হওয়া চুক্তি নয়; সব ভাড়াটের বকেয়ার যোগ = ১১২৫-এর জের, জামানতের যোগ = ২১৫৫-এর জের
 * (সমন্বয়কের দাবি); জামানতে যা নেই তা কাটা বা ফেরত নয়; সই না পড়লে কিছু খাতায় নয়, "না" হলে সারি সরে।
 */
final class WeLetSpaceOutTooTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        // ⓘ আজ = মাসের ১০ তারিখ
        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
        $this->putMoneyIn($this->cash(), '1000000', $this->month(-3)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_tenant_is_always_a_party_and_every_line_carries_the_name(): void
    {
        $this->assertRefused(fn () => app(TenancyService::class)->open($this->terms(['party_type' => '', 'party_id' => 0])), 'party', '⛔ পক্ষ ছাড়া ভাড়াটে খুলল');
        $this->assertRefused(fn () => app(TenancyService::class)->open($this->terms(['party_type' => 'supplier', 'party_id' => 1])), 'party', '⛔ সরবরাহকারী ভাড়াটে হল');

        $person = $this->person('Shop Tenant');
        $tenancy = $this->open($person, '20000', deposit: '60000');

        $this->assertSame(Tenancy::ACTIVE, $tenancy->status);
        app(TenancyService::class)->charge($this->month(0));

        foreach ([StandardChart::RENT_RECEIVABLE, StandardChart::TENANT_DEPOSITS] as $code) {
            $lines = LedgerEntry::query()->where('account_id', StandardChart::find($code)->id)->get();
            $this->assertNotEmpty($lines, "দৃশ্যটাই বানানো যায়নি — {$code}-এ সারি নেই");

            foreach ($lines as $line) {
                $this->assertSame(['person', (int) $person->id], [$line->party_type, (int) $line->party_id], "⛔ {$code}-এর সারি ভাড়াটের নামে বসেনি");
            }
        }

        // ⓘ গ্রাহকও ভাড়াটে হতে পারেন
        $customer = Customer::query()->firstOrFail();
        $this->assertSame('customer', $this->open($customer, '5000', type: 'customer')->party_type);
    }

    public function test_a_month_is_charged_on_its_first_day_once_and_only_while_it_should_be(): void
    {
        $running = $this->open($this->person('Running Tenant'), '20000', starts: $this->month(-2));
        $this->open($this->person('Later Tenant'), '9000', starts: $this->month(1));
        $gone = $this->open($this->person('Gone Tenant'), '7000', starts: $this->month(-2));
        app(TenancyService::class)->close($gone, []);

        $this->assertSame(['charged' => 1, 'held' => 0], app(TenancyService::class)->charge($this->month(0)));
        $this->assertSame(['charged' => 0, 'held' => 0], app(TenancyService::class)->charge($this->month(0)), '⛔ একই মাস দুইবার');

        $charge = TenancyCharge::query()->sole();
        $this->assertSame($running->id, $charge->tenancy_id, '⛔ বন্ধ বা সামনের চুক্তির দাবি বসল');
        $this->assertSame($this->month(0)->toDateString(), $charge->voucher->trx_date->toDateString(), 'মাসের প্রথম দিনে');
        $this->assertMoney('20000', $this->net(StandardChart::RENT_RECEIVABLE), 'পাওনা');
        $this->assertMoney('-20000', $this->net(StandardChart::RENT_INCOME), 'আয়');

        // ⓘ দর বদলালে বসে যাওয়া মাস নড়ে না; সামনের দাবি নতুন দরে
        app(TenancyService::class)->revise($running, ['monthly_rent' => '22000']);
        $this->assertSame('20000', bcadd((string) $charge->fresh()->amount, '0', 0));
        app(TenancyService::class)->charge($this->month(-1));
        $this->assertSame('22000', bcadd((string) TenancyCharge::query()->whereDate('for_month', $this->month(-1)->toDateString())->sole()->amount, '0', 0));

        $this->assertRefused(fn () => app(TenancyService::class)->charge($this->month(1)), 'month', '⛔ সামনের মাসের ভাড়া আয়ে বসল');
        $this->closeMonth($this->month(-2));
        $this->assertRefused(fn () => app(TenancyService::class)->charge($this->month(-2)), 'month', '⛔ বন্ধ মাসে দাবি বসল');
        $this->assertSame(2, TenancyCharge::query()->count());
    }

    public function test_every_tenant_adds_up_to_the_two_accounts(): void
    {
        $a = $this->open($this->person('Tenant A'), '10000', deposit: '30000', starts: $this->month(-1));
        $b = $this->open($this->person('Tenant B'), '8000', deposit: '16000', starts: $this->month(-1));
        app(TenancyService::class)->charge($this->month(-1));
        app(TenancyService::class)->charge($this->month(0));

        // ⓘ A আংশিক দেন আর এক মাস জামানত থেকে কাটা; B বেশি দেন (আগাম), পরে জামানতের অর্ধেক ফেরত
        $this->collect($a, '4000');
        app(TenancyService::class)->fromDeposit($a, ['amount' => '10000']);
        $this->collect($b, '20000');
        app(TenancyService::class)->refund($b, ['amount' => '8000', 'money_account_id' => $this->cash()->id]);

        $this->assertMoney('6000', $a->fresh()->outstanding(), 'A: ২০,০০০ − ৪,০০০ − ১০,০০০');
        $this->assertMoney('-4000', $b->fresh()->outstanding(), 'B: ১৬,০০০ − ২০,০০০ (আগাম)');
        $this->assertMoney('20000', $a->fresh()->depositHeld(), 'A: ৩০,০০০ − ১০,০০০');
        $this->assertMoney('8000', $b->fresh()->depositHeld(), 'B: ১৬,০০০ − ৮,০০০');

        // ⭐ সমন্বয়কের দাবি — খাতা আর ভাড়াটে এক কথা বলে
        $this->assertMoney($this->net(StandardChart::RENT_RECEIVABLE), bcadd($a->fresh()->outstanding(), $b->fresh()->outstanding(), 4), '⛔ বকেয়ার যোগ ১১২৫-এর জের নয়');
        $this->assertMoney(bcmul($this->net(StandardChart::TENANT_DEPOSITS), '-1', 4), bcadd($a->fresh()->depositHeld(), $b->fresh()->depositHeld(), 4), '⛔ জামানতের যোগ ২১৫৫-এর জের নয়');

        $rows = collect(app(ReportEngine::class)->run(TenancyReports::ARREARS, ['from' => $this->month(-1)->toDateString(), 'to' => now()->toDateString()])->rows)
            ->map(fn ($r) => (array) $r)->keyBy('tenant');
        $this->assertMoney('6000', $rows['Tenant A']['outstanding'], 'রিপোর্ট: A-এর বকেয়া');
        $this->assertMoney('0.6', $rows['Tenant A']['months_behind'], 'রিপোর্ট: কত মাসের সমান');
        $this->assertMoney('8000', $rows['Tenant B']['deposit_held'], 'রিপোর্ট: B-এর জামানত');

        $collected = app(ReportEngine::class)->run(TenancyReports::COLLECTIONS, ['from' => $this->month(-1)->toDateString(), 'to' => now()->toDateString()]);
        $this->assertMoney('24000', $collected->totals['rent_cash'], 'রিপোর্ট: টাকায় আদায়');
        $this->assertMoney('10000', $collected->totals['from_deposit'], 'রিপোর্ট: জামানত থেকে');
    }

    public function test_the_deposit_cannot_give_what_it_does_not_hold(): void
    {
        $tenancy = $this->open($this->person('Short Tenant'), '10000', deposit: '15000');
        app(TenancyService::class)->charge($this->month(0));

        $this->assertRefused(fn () => app(TenancyService::class)->fromDeposit($tenancy, ['amount' => '15000.01']), 'amount', '⛔ জামানতের বেশি কাটা গেল');
        app(TenancyService::class)->fromDeposit($tenancy, ['amount' => '10000']);
        $this->assertRefused(fn () => app(TenancyService::class)->refund($tenancy, ['amount' => '5000.01', 'money_account_id' => $this->cash()->id]), 'amount', '⛔ বাকির বেশি ফেরত গেল');

        // ⓘ শেষের পরে: কাটা নয়, কিন্তু ফেরত আর বকেয়া আদায় চলে
        app(TenancyService::class)->close($tenancy, []);
        $this->assertRefused(fn () => app(TenancyService::class)->fromDeposit($tenancy->fresh(), ['amount' => '1']), 'status', '⛔ শেষ চুক্তিতে জামানত কাটা গেল');
        app(TenancyService::class)->refund($tenancy->fresh(), ['amount' => '5000', 'money_account_id' => $this->cash()->id]);
        $this->assertMoney('0', $tenancy->fresh()->depositHeld(), 'ফেরতের পরে জামানত শূন্য');
        $this->assertMoney('0', $this->net(StandardChart::TENANT_DEPOSITS), '২১৫৫ শূন্য');
    }

    public function test_nothing_reaches_the_books_before_the_signature_and_a_refusal_removes_the_row(): void
    {
        $this->flow();

        $tenancy = $this->open($this->person('Signed Tenant'), '10000', deposit: '20000');
        $this->assertSame(Tenancy::AWAITING, $tenancy->status, '⛔ জামানতের সই ছাড়াই চুক্তি চালু');
        $this->assertSame(0, app(TenancyService::class)->charge($this->month(0))['charged'], '⛔ সই বাকি চুক্তির দাবি বসল');
        $this->assertMoney('0', $this->net(StandardChart::TENANT_DEPOSITS), '⛔ সই ছাড়াই জামানত খাতায়');

        $this->sign();
        $this->assertSame(Tenancy::ACTIVE, $tenancy->fresh()->status, '⛔ শেষ সই পড়ল, চুক্তি চালু হয়নি');
        $this->assertMoney('-20000', $this->net(StandardChart::TENANT_DEPOSITS), 'সইয়ের পরে জামানত');

        $this->assertSame(['charged' => 1, 'held' => 1], app(TenancyService::class)->charge($this->month(0)));
        $this->assertMoney('0', $this->net(StandardChart::RENT_RECEIVABLE), '⛔ সই ছাড়াই দাবি খাতায়');
        $this->assertMoney('0', $tenancy->fresh()->outstanding(), '⛔ সই বাকি দাবি বকেয়ায় গোনা হল');
        $row = collect(app(ReportEngine::class)->run(TenancyReports::ARREARS, ['from' => $this->month(0)->toDateString(), 'to' => now()->toDateString()])->rows)
            ->map(fn ($r) => (array) $r)->firstWhere('tenant', 'Signed Tenant');
        $this->assertMoney('0', $row['charged'], '⛔ রিপোর্ট সই বাকি দাবি গুনল');
        $draft = TenancyCharge::query()->sole()->voucher;

        $this->refuse();
        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status);
        $this->assertSame(0, TenancyCharge::query()->count(), '⛔ ফেরানো মাস আটকে রইল');
        $this->assertSame(Tenancy::ACTIVE, $tenancy->fresh()->status, '⛔ দাবির "না" চুক্তি সরিয়ে দিল');

        // ⓘ খোলার জামানতই "না" — চুক্তিটা ভুল করে বসানো চুক্তির মতো সরে যায়
        $other = $this->open($this->person('Refused Tenant'), '5000', deposit: '5000');
        $this->refuse();
        $this->assertNull(Tenancy::query()->find($other->id), '⛔ "না" পাওয়া চুক্তি রয়ে গেল');
        $this->assertSame(0, TenancyMove::query()->where('tenancy_id', $other->id)->count());
    }

    public function test_the_pages_the_door_and_the_command(): void
    {
        $person = $this->person('Page Tenant');

        $this->get(route('finance.tenancy.index'))->assertOk()->assertSee('data-tenancy-list', false)->assertSee('data-tenancy-charge', false);
        $this->get(route('finance.tenancy.create'))->assertOk()->assertSee('person:'.$person->id, false);
        $this->post(route('finance.tenancy.store'), [
            'party' => 'person:'.$person->id, 'monthly_rent' => '12000', 'deposit_amount' => '0', 'term_months' => 12,
            'starts_on' => $this->month(0)->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $tenancy = Tenancy::query()->sole();
        $this->get(route('finance.tenancy.show', $tenancy))->assertOk()->assertSee('data-tenancy-collect', false);
        $this->get(route('finance.tenancy.report.show', ['slug' => 'arrears']))->assertOk();
        $this->get(route('finance.tenancy.report.show', ['slug' => 'collections']))->assertOk();

        // ⓘ কনসোল — চলতি মাস, দুই দিকই
        $this->app['auth']->forgetGuards();
        $this->artisan('abos:rent-accrue', ['--company' => 'TDEPOT'])->assertSuccessful();
        $this->artisan('abos:rent-accrue', ['--company' => 'TDEPOT'])->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame(1, TenancyCharge::query()->count(), '⛔ কমান্ড ভাড়াটের মাস বসায়নি বা দুইবার বসাল');

        // ⭐ দরজা — একই মানুষ, চাবি দিয়ে খোলে, চাবি কেড়ে নিলে বন্ধ
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(['finance.rental.view', 'finance.rental.create']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $pay = ['amount' => '1000', 'money_account_id' => $this->cash()->id];
        $this->actingAs($clerk->fresh())->post(route('finance.tenancy.collect', $tenancy), $pay)->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($clerk->fresh())->post(route('finance.tenancy.refund', $tenancy), $pay)->assertForbidden();

        CompanyContext::forCompany($this->company->id, fn () => $clerk->revokePermissionTo('finance.rental.create'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        $this->actingAs($clerk->fresh())->post(route('finance.tenancy.collect', $tenancy), $pay)->assertForbidden();
        $this->assertSame(1, TenancyMove::query()->where('kind', TenancyMove::RENT)->count(), '⛔ চাবি ছাড়া আদায় বসল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function month(int $offset): Carbon
    {
        return now()->startOfMonth()->addMonths($offset);
    }

    private function person(string $name): Person
    {
        return Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'name_en' => $name],
            ['code' => 'P-'.mb_substr(md5($name), 0, 6), 'name_bn' => $name, 'is_active' => true],
        );
    }

    /** @param  array<string, mixed>  $over  @return array<string, mixed> */
    private function terms(array $over = []): array
    {
        return $over + ['monthly_rent' => '10000', 'deposit_amount' => '0', 'term_months' => 24, 'starts_on' => $this->month(-2)->toDateString()];
    }

    private function open(Person|Customer $party, string $rent, string $deposit = '0', ?Carbon $starts = null, string $type = 'person'): Tenancy
    {
        return app(TenancyService::class)->open([
            'party_type' => $type, 'party_id' => $party->id, 'tenant' => $party->name_en, 'monthly_rent' => $rent,
            'deposit_amount' => $deposit, 'term_months' => 24, 'starts_on' => ($starts ?? $this->month(-2))->toDateString(),
            'money_account_id' => bccomp($deposit, '0', 2) > 0 ? $this->cash()->id : null,
        ]);
    }

    private function collect(Tenancy $tenancy, string $amount): void
    {
        app(TenancyService::class)->collect($tenancy->fresh(), ['amount' => $amount, 'money_account_id' => $this->cash()->id]);
    }

    private function flow(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'rental', 'is_active' => true]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id,
        ]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function pending(): Approval
    {
        return Approval::query()->where('status', Approval::PENDING)->where('action', 'rental')->latest('id')->firstOrFail();
    }

    private function sign(): void
    {
        app(ApprovalEngine::class)->approve($this->pending(), $this->signer);
    }

    private function refuse(): void
    {
        app(ApprovalEngine::class)->reject($this->pending(), $this->signer, 'এখন নয়');
    }

    private function closeMonth(Carbon $month): void
    {
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) $month->year, 'month' => (int) $month->month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);
    }

    private function assertRefused(callable $what, string $field, string $why): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), $why.' (অন্য ঘরে আটকেছে: '.implode(', ', array_keys($e->errors())).')');

            return;
        }

        $this->fail($why);
    }

    private function net(string $code): string
    {
        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
