<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * মালিক ফোনে জিজ্ঞেস করলেন "আজ কেমন গেল" — চুক্তি §৮।
 *
 * ⭐ প্রতিটা ঘরের দাবি একই মানুষকে দুইবার: চাবি ছাড়া ঘরটাই নেই, চাবি
 * দিলে আছে ([[same-user-key-off-then-on]])। ⓘ ভূমিকাহীন মানুষ — ডেমোর
 * ভূমিকা বদলাচ্ছে, তাই ভূমিকার উপর দাঁড়ানো দাবি কাল ভুল কারণে লাল হত।
 */
final class TheOwnerAskedHowTodayWentFromThePhoneTest extends TestCase
{
    use RefreshDatabase;

    private const MONEY = '/^-?\d+\.\d{4}$/';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->user->companies()->attach($this->company->id, ['is_active' => true]);
    }

    /** ⛔ কোনো চাবি নেই — কেবল কাঠামো: দিন, কোম্পানি, সময়; একটা সংখ্যাও নয়। */
    public function test_without_a_key_only_the_frame_comes(): void
    {
        $body = $this->today()->assertOk()->json();

        foreach (['sales', 'collections', 'cashInHand', 'dues', 'approvals'] as $field) {
            $this->assertArrayNotHasKey($field, $body, "⛔ চাবি ছাড়াই {$field} এসেছে — শূন্য আর 'দেখতে পারেন না' এক হয়ে গেল।");
        }

        $this->assertSame(Carbon::today()->toDateString(), $body['date']);
        $this->assertSame($this->company->name(), $body['company'], '⛔ কোন কোম্পানির সংখ্যা, সেটা পর্দায় লেখা থাকতেই হবে।');
        $this->assertNull($body['branch'], 'ⓘ শাখা-সীমা নেই — null মানে সব শাখা।');
        $this->assertNotEmpty($body['asOf']);
    }

    public function test_the_sales_key_is_what_brings_the_sales_field(): void
    {
        $this->assertFieldFollowsItsKey('sales', 'sales.invoice.view', ['count', 'amount']);
    }

    public function test_the_collection_key_is_what_brings_the_collections_field(): void
    {
        $this->assertFieldFollowsItsKey('collections', 'sales.collection.view', ['count', 'amount']);
    }

    public function test_the_till_key_is_what_brings_the_cash_field(): void
    {
        $this->assertFieldFollowsItsKey('cashInHand', 'accounts.till.view', ['amount']);
    }

    public function test_the_due_report_key_is_what_brings_the_dues_field(): void
    {
        $this->assertFieldFollowsItsKey('dues', 'customer.report', ['amount', 'shops']);
    }

    public function test_the_decide_key_is_what_brings_the_approvals_field(): void
    {
        $this->assertFieldFollowsItsKey('approvals', 'approval.decide', ['pending']);
    }

    /**
     * ⭐ ফোন আর ওয়েব একই মানুষকে একই অঙ্ক বলে — আর আজকের একটা বিল
     * সত্যিই গোনা হয়।
     */
    public function test_the_phone_and_the_web_say_the_same_numbers(): void
    {
        $this->grant('sales.invoice.view');
        $this->grant('sales.collection.view');

        $before = $this->today()->json('sales');
        $invoice = $this->billToday();
        $after = $this->today()->json('sales');

        $this->assertSame($before['count'] + 1, $after['count'], '⛔ আজকের দাখিলা বিলটা গোনা হয়নি।');
        $this->assertSame(bcadd($before['amount'], (string) $invoice->fresh()->total, 4), $after['amount']);

        $this->actingAs($this->user);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertSame(bcadd(SalesMetrics::salesToday()->value(), '0', 4), $after['amount'],
            '⛔ একই মানুষকে ওয়েব এক অঙ্ক বলে, ফোন আরেক।');

        $today = Carbon::today()->toDateString();
        $this->assertSame(bcadd(SalesMetrics::collectionTotal($today, $today), '0', 4),
            $this->today()->json('collections.amount'));
    }

    /** ⛔ অন্য কোম্পানির আজকের বিল এই কোম্পানির সংখ্যায় ঢোকে না। */
    public function test_another_companys_bill_is_not_counted(): void
    {
        $this->grant('sales.invoice.view');
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        $before = $this->today()->json('sales');
        $this->billToday($other->id);

        $this->assertSame($before, $this->today()->json('sales'),
            '⛔ অন্য কোম্পানির বিক্রি এই কোম্পানির আজকের অঙ্কে ঢুকেছে।');
    }

    /** ⛔ "আজ" সার্ভারের দিন — ফোনের ঘড়ি নয় (চুক্তির নিয়ম গ)। */
    public function test_today_is_the_servers_day(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00'));

        $this->assertSame('2026-12-31', $this->today()->json('date'));
    }

    /** ⓘ এক শাখায় সীমিত হলে সেই শাখার নাম — null নয়, কারণ null মানে "সব শাখা"। */
    public function test_a_user_held_to_one_branch_sees_its_name(): void
    {
        $branch = $this->branches()->first();
        $this->holdTo([$branch->id]);

        $this->assertSame($branch->name(), $this->today()->json('branch'));
    }

    /** ⓘ কয়েকটা শাখায় সীমিত হলে নামগুলো ", " দিয়ে — null হলে মিথ্যা বলত "সব শাখা"। */
    public function test_a_user_held_to_several_branches_sees_every_name(): void
    {
        $two = $this->branches()->take(2)->values();
        $this->assertCount(2, $two, 'ডেমোতে দুইটা শাখা নেই — দাবিটা কিছু মাপছে না।');
        $this->holdTo($two->pluck('id')->all());

        $this->assertSame($two[0]->name().', '.$two[1]->name(), $this->today()->json('branch'));
    }

    /**
     * ⛔ বকেয়া কেবল ধনাত্মক জের — একজনের অগ্রিম আরেকজনের বকেয়া ঢাকে না।
     *
     * ⓘ হিসাবটা এখানে আলাদা পথে (PHP-তে, সারি ধরে) করা, যাতে একই SQL নিজের
     * সাথে মিলে সবুজ না হয়।
     */
    public function test_dues_count_only_what_is_owed(): void
    {
        $this->grant('customer.report');
        $this->billToday();
        $this->makeAnAdvance();

        $balances = DB::table('ledger_entries')
            ->where('company_id', $this->company->id)
            ->where('party_type', Customer::drillSourceType())
            ->where('trx_date', '<=', Carbon::today()->toDateString())
            ->whereIn('party_id', DB::table('customers')->select('id'))
            ->get(['party_id', 'debit', 'credit'])
            ->groupBy('party_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $c, $r) => bcadd($c, bcsub((string) $r->debit, (string) $r->credit, 4), 4), '0'));

        $owed = $balances->filter(fn (string $b) => bccomp($b, '0', 4) > 0);
        $this->assertTrue($balances->contains(fn (string $b) => bccomp($b, '0', 4) < 0), 'অগ্রিম নেই — দাবিটা কিছু মাপছে না।');
        $this->assertNotEmpty($owed, 'বকেয়া নেই — দাবিটা কিছু মাপছে না।');

        $this->today()
            ->assertJsonPath('dues.amount', $owed->reduce(fn (string $c, string $b) => bcadd($c, $b, 4), '0.0000'))
            ->assertJsonPath('dues.shops', $owed->count());
    }

    /**
     * ⛔ অপেক্ষার সংখ্যা ফোনের ইনবক্সের সারির সমান — বেতন গোনা হয় না।
     *
     * ⓘ ইঞ্জিন নিজে বেতনের অনুমোদনটা এই মানুষের তালিকায় রাখে (প্রথমে মাপা),
     * তাই শূন্যটা ছাঁকনির কাজ, ছক না-মেলার নয়।
     */
    public function test_the_waiting_count_matches_the_phone_inbox_and_skips_payroll(): void
    {
        $this->grant('approval.decide');

        /* ⓘ অনুরোধ অন্য কারও — নিজের অনুরোধে নিজে সই হয় না, তাই ইঞ্জিন দেখাতই না */
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $flow = ApprovalFlow::create(['module' => 'hr', 'action' => 'payroll']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->user->id,
        ]);
        $run = PayrollRun::create([
            'company_id' => $this->company->id, 'document_no' => 'PRL-TODAY-0001',
            'month' => now()->format('Y-m'), 'trx_date' => now()->toDateString(),
            'gross_total' => '900000', 'deduction_total' => '0', 'net_total' => '900000',
            'employee_count' => 12, 'created_by' => $clerk->id,
        ]);
        $payroll = Approval::create([
            'company_id' => $this->company->id, 'approvable_type' => PayrollRun::class,
            'approvable_id' => $run->id, 'module' => 'hr', 'action' => 'payroll',
            'amount' => '900000', 'status' => Approval::PENDING, 'current_level' => 1,
            'requested_by' => $clerk->id, 'requested_at' => now(),
        ]);

        $engineSees = CompanyContext::forCompany($this->company->id,
            fn () => app(ApprovalEngine::class)->pendingQueryFor($this->user->fresh())->pluck('id')->all());
        $this->assertContains($payroll->id, $engineSees, 'ইঞ্জিনই বেতন দেখছে না — দাবিটা কিছু মাপছে না।');

        $pending = $this->today()->json('approvals.pending');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user->fresh(), [AuthController::APP]);
        $rows = $this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows');

        $this->assertSame(count($rows), $pending, '⛔ পাতা বলে এতগুলো অপেক্ষায়, অথচ ইনবক্সে কম — বেতন গোনা হয়েছে।');
    }

    /** ⛔ refresh টোকেনে এই দরজা খোলে না — চুরি যাওয়া refresh টোকেনে আজকের হিসাব নয়। */
    public function test_a_refresh_token_does_not_open_it(): void
    {
        $token = $this->user->createToken('refresh', [AuthController::REFRESH])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/dashboard/today')->assertForbidden();
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @param list<string> $parts */
    private function assertFieldFollowsItsKey(string $field, string $key, array $parts): void
    {
        $this->today()->assertOk()->assertJsonMissingPath($field);

        $this->grant($key);

        $value = $this->today()->assertOk()->json($field);
        $this->assertIsArray($value, "⛔ {$key} দেওয়ার পরেও {$field} আসেনি।");

        foreach ($parts as $part) {
            $this->assertArrayHasKey($part, $value);

            if ($part === 'amount') {
                $this->assertIsString($value[$part], '⛔ টাকা স্ট্রিং হিসেবে যায় — দশমিক হারানো চলে না।');
                $this->assertMatchesRegularExpression(self::MONEY, $value[$part]);
            } else {
                $this->assertIsInt($value[$part], '⛔ গোনা পূর্ণসংখ্যা, স্ট্রিং নয়।');
            }
        }
    }

    private function today(): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/dashboard/today');
    }

    private function grant(string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $this->user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param list<int> $branchIds */
    private function holdTo(array $branchIds): void
    {
        foreach ($branchIds as $id) {
            UserDataScope::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'user_id' => $this->user->id,
                'scope_type' => UserDataScope::BRANCH,
                'scope_id' => $id,
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, Branch> */
    private function branches()
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
    }

    /**
     * আজকের একটা দাখিলা বিল — আসল পথে ([[SalesInvoiceService]]), মালিকের হাতে।
     *
     * ⓘ ডেমোতে দাখিলা বিল নেই (প্রথম রানে পূর্বশর্তটাই লাল হয়েছিল), তাই বানাতে হয়।
     * ⚠️ অন্য কোম্পানির বিল লাগলে এখানে বানিয়ে সারিটা সেখানে সরানো হয় — ওই
     * কোম্পানির গ্রাহক-পণ্য-গুদাম সাজানোর চেয়ে সোজা, আর প্রশ্নটা একই:
     * কোয়েরি কোম্পানি ধরে ছাঁকে কি না।
     */
    private function billToday(?int $moveToCompanyId = null): SalesInvoice
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $service = app(SalesInvoiceService::class);
        $invoice = $service->confirm($service->create(
            [
                'customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => Carbon::today()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']],
        ));

        if ($moveToCompanyId !== null) {
            DB::table('sal_invoices')->where('id', $invoice->id)->update(['company_id' => $moveToCompanyId]);
        }

        return $invoice;
    }

    /**
     * একজন গ্রাহকের অগ্রিম — ঋণাত্মক জের।
     *
     * ⓘ দাখিলা বিলের খতিয়ান-সারির নকল, দ্বিতীয় একজন গ্রাহকের নামে, কেবল
     * ক্রেডিটে। ⚠️ হিসাবটা পরীক্ষা খতিয়ান থেকেই পড়ে, তাই সারিটা কোথা থেকে
     * এল তাতে প্রশ্নটা বদলায় না: অগ্রিম কি বকেয়ায় ঢোকে?
     */
    private function makeAnAdvance(): void
    {
        $row = (array) DB::table('ledger_entries')
            ->where('company_id', $this->company->id)
            ->where('party_type', Customer::drillSourceType())
            ->orderBy('id')->first();
        $this->assertNotEmpty($row, 'দাখিলা বিলের পরেও গ্রাহকের খতিয়ান নেই — দাবিটা কিছু মাপছে না।');

        $other = Customer::query()->where('id', '!=', $row['party_id'])->orderBy('id')->value('id');
        $this->assertNotNull($other, 'দ্বিতীয় গ্রাহক নেই — দাবিটা কিছু মাপছে না।');

        unset($row['id']);
        foreach (['public_id', 'uuid'] as $unique) {
            if (array_key_exists($unique, $row)) {
                $row[$unique] = (string) Str::uuid7();
            }
        }
        $row['party_id'] = $other;
        $row['debit'] = '0';
        $row['credit'] = '5000';

        DB::table('ledger_entries')->insert($row);
    }
}
