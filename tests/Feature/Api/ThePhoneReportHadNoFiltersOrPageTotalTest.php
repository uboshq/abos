<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের সাধারণ রিপোর্ট-পর্দা: মাথায় ছাঁকনি, নিচে পাতার মোট আর সর্বমোট — ১৭ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল না ─────────────────────────────────────────────────────
 * মালিক: *"direct webpage app e bujajayna"*। ফোনের রিপোর্ট কেবল তারিখ নিত; শাখা বা রিপোর্টের নিজের ছাঁকনি (গুদাম,
 * পণ্য …) জিজ্ঞেস করার উপায় ছিল না — ইঞ্জিন চায় ভিতরের আইডি, আর ভিতরের আইডি তারে যায় না (§৩ ক)। আর নিচে কেবল
 * সর্বমোট, পাতার মোট নয়।
 *
 * ⭐ দাবি:
 *   · `choices` বাছাই-তালিকা দেয় — শাখা public_id-তে, বাকি "কোড · নাম" লেখায়; কোথাও ক্রমিক আইডি নেই
 *   · `branch=<public_id>` ওয়েবের `branch_id`-এর একই ফল দেয়; অচেনা শাখা ৪২২, চুপ করে "সব শাখা" নয়
 *   · `pageTotals` এই পাতার সারির যোগ, সার্ভারের কষা; সব সারি এক পাতায় হলে সর্বমোটের সমান
 */
final class ThePhoneReportHadNoFiltersOrPageTotalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id);
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

    public function test_the_choices_name_branches_by_public_id_and_the_rest_by_their_label(): void
    {
        $answer = $this->phone('/api/v1/reports/inventory.stock_ledger/choices')->assertOk();
        $filters = collect($answer->json('filters'))->keyBy('key');

        $this->assertSame(['date_range', 'branch', 'product_id', 'warehouse_id'], $filters->keys()->all(),
            'ঘোষণার প্রতিটা ছাঁকনি তালিকায়, ঘোষণার ক্রমে');
        $this->assertSame('date_range', $filters['date_range']['kind']);

        $branchIds = collect($filters['branch']['options'])->pluck('value')->all();
        $this->assertNotEmpty($branchIds);
        $this->assertSame(
            Branch::query()->where('company_id', $this->company->id)->whereIn('public_id', $branchIds)->count(),
            count($branchIds), '⛔ শাখার মান public_id নয়');

        foreach (['product_id', 'warehouse_id'] as $key) {
            $this->assertSame('list', $filters[$key]['kind']);
            $this->assertNotEmpty($filters[$key]['options'], "{$key}-এর বাছাই খালি — ছক ভুল");
            foreach ($filters[$key]['options'] as $option) {
                $this->assertFalse(ctype_digit((string) $option['value']), "⛔ {$key}-এর মান ক্রমিক আইডি: {$option['value']}");
            }
        }

        // ⓘ বাছাইয়ের লেখা দিয়েই রিপোর্ট চলে — ইঞ্জিন লেখা চেনে
        $warehouse = $filters['warehouse_id']['options'][0]['value'];
        $this->phone('/api/v1/reports/inventory.stock_ledger?'.http_build_query(['warehouse_id' => $warehouse]))->assertOk();
    }

    public function test_a_branch_by_public_id_gives_the_web_branch_result_and_a_stranger_is_refused(): void
    {
        $branch = Branch::query()->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $byPublic = $this->phone('/api/v1/reports/inventory.stock_summary?branch='.$branch->public_id)->assertOk();
        $byId = $this->phone('/api/v1/reports/inventory.stock_summary?branch_id='.$branch->id)->assertOk();

        $this->assertSame($byId->json('totalRows'), $byPublic->json('totalRows'), '⛔ public_id-এর শাখা আর ওয়েবের শাখা আলাদা ফল');
        $this->assertEquals($byId->json('totals'), $byPublic->json('totals'));

        $this->phone('/api/v1/reports/inventory.stock_summary?branch=not-a-branch')
            ->assertStatus(422)->assertJsonValidationErrors('branch');

        $elsewhere = Branch::query()->where('company_id', '<>', $this->company->id)->firstOrFail();
        $this->phone('/api/v1/reports/inventory.stock_summary?branch='.$elsewhere->public_id)
            ->assertStatus(422);

        // ⛔ ছাপাও একই ছাঁকনিতে — নইলে পর্দায় এক শাখা আর PDF-এ গোটা কোম্পানি। ⓘ খালি একটা শাখা: পর্দায় শূন্য সারি,
        // রপ্তানিতেও শূন্য — ছাঁকনি ফেলে দিলে রপ্তানি সব শাখার মাল দেখাত।
        $empty = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'EMPTY', 'name_en' => 'Empty branch', 'is_active' => true]);
        $everything = json_decode((string) $this->phone('/api/v1/reports/inventory.stock_summary/export?format=json')->assertOk()->getContent(), true);
        $this->assertNotEmpty($everything['rows'], 'ছক ভুল: রপ্তানিতে কোনো সারি নেই');
        $this->assertSame(0, $this->phone('/api/v1/reports/inventory.stock_summary?branch='.$empty->public_id)->assertOk()->json('totalRows'));
        $exported = json_decode((string) $this->phone('/api/v1/reports/inventory.stock_summary/export?format=json&branch='.$empty->public_id)->assertOk()->getContent(), true);
        $this->assertSame([], $exported['rows'], '⛔ রপ্তানি ফোনের শাখা মানেনি — গোটা কোম্পানির সারি ছেপেছে');
    }

    public function test_the_page_total_is_the_servers_sum_of_the_rows_on_the_page(): void
    {
        $all = $this->phone('/api/v1/reports/inventory.stock_summary?perPage=100')->assertOk();
        $totals = $all->json('totals');

        $this->assertGreaterThan(1, $all->json('totalRows'), 'ছক ভুল: দুইটার বেশি সারি লাগবে');
        $this->assertNotEmpty($totals, 'ছক ভুল: এই রিপোর্টের যোগ-কলাম নেই');

        if ($all->json('totalRows') <= 100) {
            $this->assertEquals($totals, $all->json('pageTotals'), '⛔ সব সারি এক পাতায়, অথচ পাতার মোট আর সর্বমোট আলাদা');
        }

        $one = $this->phone('/api/v1/reports/inventory.stock_summary?perPage=1')->assertOk();
        foreach (array_keys($totals) as $column) {
            $this->assertSame(
                bcadd((string) ($one->json('rows.0.'.$column) ?? '0'), '0', 4),
                $one->json('pageTotals.'.$column),
                "⛔ {$column}: এক সারির পাতার মোট সেই সারির মান নয়");
        }
        $this->assertEquals($totals, $one->json('totals'), 'সর্বমোট পাতার উপর নির্ভর করে না');
    }
}
