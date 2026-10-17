<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ "কেবল ওয়েবে" ছাঁকনিগুলো ফোনেও — খাত, খরচের কেন্দ্র, পক্ষের ধরন, অবস্থা, কী ধরে (১৭ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল না ─────────────────────────────────────────────────────
 * ফোনের রিপোর্ট-পর্দা এগুলোকে "কেবল ওয়েবের পাতায়" বলত: খাত, কেন্দ্র আর ধরনের তালিকা ওয়েবের পাতা ভিতরের আইডিতে আঁকত (তারে
 * যায় না, §৩ ক), আর অবস্থা/কী-ধরে-র সংকেত কোথাও লেখা ছিল না — ইঞ্জিন ভুল সংকেত চুপচাপ উপেক্ষা করত।
 *
 * ⭐ দাবি:
 *   · বাছাই-তালিকায় এগুলো আসে — তালিকার ছাঁকনি ইঞ্জিনের নামে (`account_id`), "কোড · নাম" লেখায়; সংকেতের ছাঁকনি সংকেতে
 *   · লেখা পাঠালে ইঞ্জিন সেই নম্বরের ফলই দেয় (ওয়েবের পাতার সমান)
 *   · তালিকার বাইরের লেখা বা অচেনা সংকেত ৪২২ — চুপ করে গোটা রিপোর্ট নয়
 */
final class TheReportFiltersOnlyTheWebCouldAskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function phone(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);

        return $this->get($url, ['Accept' => 'application/json']);
    }

    /** @return array<string, array<string, mixed>> */
    private function choices(string $key): array
    {
        return collect($this->phone('/api/v1/reports/'.$key.'/choices')->assertOk()->json('filters'))->keyBy('key')->all();
    }

    public function test_an_account_goes_by_its_label_and_gives_the_same_ledger_as_its_number(): void
    {
        $filters = $this->choices('accounts.ledger');

        $this->assertArrayHasKey('account_id', $filters, '⛔ খাতের ছাঁকনি ফোনে নেই');
        $this->assertSame('list', $filters['account_id']['kind']);
        $this->assertArrayNotHasKey('account', $filters, 'ঘোষিত নামে নয়, ইঞ্জিনের নামে আসার কথা');
        foreach ($filters['account_id']['options'] as $option) {
            $this->assertFalse(ctype_digit((string) $option['value']), '⛔ খাতের মান ক্রমিক আইডি');
        }

        $cash = StandardChart::find(StandardChart::RECEIVABLE);
        $option = collect($filters['account_id']['options'])->first(fn (array $o) => str_starts_with($o['label'], $cash->code.' · '));
        $this->assertNotNull($option, 'ছক ভুল: প্রাপ্যের খাত তালিকায় নেই');
        // ⓘ মান public_id (দুই একই লেখার সারি কখনো এক নয়); ওয়েবের বাছাই-ঘরের লেখাও চলে
        $this->assertSame((string) $cash->public_id, $option['value']);
        $label = $option['label'];
        $this->phone('/api/v1/reports/accounts.ledger?account_id='.$option['value'])->assertOk();

        $byLabel = $this->phone('/api/v1/reports/accounts.ledger?'.http_build_query(['account_id' => $label]))->assertOk();
        $byId = $this->phone('/api/v1/reports/accounts.ledger?account_id='.$cash->id)->assertOk();
        $this->assertSame($byId->json('totalRows'), $byLabel->json('totalRows'));
        $this->assertEquals($byId->json('totals'), $byLabel->json('totals'), '⛔ লেখায় পাঠানো খাত অন্য ফল দিল');

        $this->phone('/api/v1/reports/accounts.ledger?'.http_build_query(['account_id' => '9999 · কোনো খাত নয়']))
            ->assertStatus(422)->assertJsonValidationErrors('account_id');
    }

    public function test_cost_centre_and_party_type_are_offered_by_their_engine_names(): void
    {
        $this->assertSame('list', $this->choices('accounts.project_ledger')['cost_center_id']['kind'] ?? null, '⛔ খরচের কেন্দ্র ফোনে নেই');

        $due = $this->choices('customer.due_list');
        $this->assertSame('list', $due['party_type_id']['kind'] ?? null, '⛔ পক্ষের ধরন ফোনে নেই');
        $this->assertNotEmpty($due['party_type_id']['options'], 'ছক ভুল: কোনো পক্ষের ধরন নেই');

        $type = $due['party_type_id']['options'][0]['value'];
        $this->phone('/api/v1/reports/customer.due_list?'.http_build_query(['party_type_id' => $type]))->assertOk();
    }

    public function test_a_status_or_grouping_is_offered_by_its_codes_and_a_stranger_code_is_refused(): void
    {
        $cheques = $this->choices('accounts.cheque_register');
        $this->assertSame(['received', 'issued'], collect($cheques['direction']['options'])->pluck('value')->all());
        $this->assertContains('bounced', collect($cheques['status']['options'])->pluck('value')->all());
        $this->assertSame('ফেরত', collect($cheques['status']['options'])->firstWhere('value', 'bounced')['label'],
            'নাম ওয়েবের চেকের পাতার একই শব্দ নয়');

        $this->assertSame(['slow', 'dead'], collect($this->choices('inventory.slow_dead')['status']['options'])->pluck('value')->all());
        $this->assertContains('product', collect($this->choices('inventory.stock_position')['group_by']['options'])->pluck('value')->all());
        $purchase = $this->choices('purchase.analysis');
        $this->assertContains('supplier', collect($purchase['group_by']['options'])->pluck('value')->all());
        $this->assertSame(['day', 'week', 'month', 'year'], collect($purchase['period']['options'])->pluck('value')->all());

        $this->phone('/api/v1/reports/inventory.slow_dead?status=dead')->assertOk();
        $this->phone('/api/v1/reports/inventory.slow_dead?status=rotten')
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_people_loans_contracts_lots_states_and_the_month_are_asked_too(): void
    {
        foreach ([
            'accounts.person_ledger' => 'person_id', 'finance.hand_loan_ledger' => 'person_id', 'finance.capital_ledger' => 'person_id',
            'finance.bank_loan_ledger' => 'facility_id', 'finance.rental_deposit_book' => 'rental_contract_id',
            'inventory.lot_trace' => 'batch_id',
        ] as $report => $filter) {
            $this->assertSame('list', $this->choices($report)[$filter]['kind'] ?? null, "⛔ {$report}: {$filter} ফোনে নেই");
        }

        $this->assertSame(['overdue', 'today', 'upcoming', 'undated'],
            collect($this->choices('finance.hand_loan_schedule')['state']['options'])->pluck('value')->all());
        $this->assertContains('settled', collect($this->choices('finance.insurance_claims')['state']['options'])->pluck('value')->all());
        $this->assertSame('month', $this->choices('supplier.principal_commission')['month']['kind'] ?? null);

        $this->phone('/api/v1/reports/finance.hand_loan_schedule?state=undated')->assertOk();
        $this->phone('/api/v1/reports/finance.hand_loan_schedule?state=sometime')
            ->assertStatus(422)->assertJsonValidationErrors('state');
        $this->phone('/api/v1/reports/accounts.person_ledger?'.http_build_query(['person_id' => 'কেউ নন']))
            ->assertStatus(422)->assertJsonValidationErrors('person_id');
    }

    /**
     * ⭐ পাহারা — মালিক অ্যাপে ১০০% চেয়েছেন: তালিকার কোনো রিপোর্টের কোনো ছাঁকনি আর "কেবল ওয়েবে" নয়। ⓘ নতুন রিপোর্ট নতুন
     * ধরনের ছাঁকনি নিয়ে এলে এখানে লাল — উৎস, সংকেত বা নতুন ধরন বসাতে হবে।
     */
    public function test_no_report_on_the_list_leaves_a_filter_to_the_web(): void
    {
        $left = [];

        foreach ($this->phone('/api/v1/reports')->assertOk()->json() as $report) {
            foreach ($this->choices($report['key']) as $filter) {
                if ($filter['kind'] === 'web') {
                    $left[] = $report['key'].' · '.$filter['key'];
                }
            }
        }

        $this->assertSame([], $left, '⛔ এখনো কেবল ওয়েবে: '.implode(', ', $left));
    }
}
