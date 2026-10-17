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
        $label = collect($filters['account_id']['options'])->pluck('value')->first(fn (string $v) => str_starts_with($v, $cash->code.' · '));
        $this->assertNotNull($label, 'ছক ভুল: প্রাপ্যের খাত তালিকায় নেই');

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

    public function test_no_declared_filter_of_these_reports_is_left_to_the_web(): void
    {
        foreach (['accounts.ledger', 'accounts.project_ledger', 'accounts.cheque_register', 'customer.due_list',
            'inventory.slow_dead', 'inventory.stock_alerts', 'inventory.stock_position', 'purchase.analysis'] as $key) {
            $web = collect($this->choices($key))->where('kind', 'web')->keys()->all();
            $this->assertSame([], $web, "⛔ {$key}: এখনো কেবল ওয়েবে — ".implode(', ', $web));
        }
    }
}
