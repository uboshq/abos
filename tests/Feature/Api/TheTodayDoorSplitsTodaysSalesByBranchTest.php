<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Figures;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের আজকের দরজা আজকের বিক্রি শাখা ধরে ভাগ করে — মালিকের হোমের "আজকের বিক্রি" কার্ড, অ্যাপ ০.৪.৪১ (fe, ২০ অক্টোবর ২০২৬)।
 *
 * দাবি, আসল দরজায় (`GET /api/v1/dashboard/today`, Sanctum + অ্যাপের ক্ষমতা), আসল দুই শাখার আসল সরাসরি বিক্রিতে:
 *   · সারির যোগ = `salesByBranchTotal` = `sales.amount`, পয়সায়;
 *   · প্রতিটা শাখার অঙ্ক = ওয়েবের "আজ" ছকের ঐ শাখার বিক্রি ([[Board]]) = ঐ শাখার খাতায় বসা আজকের বিলের যোগ;
 *   · ক্রম: টাকা বড় থেকে ছোট; আজ বিক্রি না হওয়া শাখাও "0.0000"-এ আছে;
 *   · এক শাখায় বাঁধা কর্মী কেবল নিজের শাখা পান — অন্য শাখার নাম আর অঙ্ক JSON-এই নেই;
 *   · বাঁধা বিক্রয়কর্মী প্রতিটা শাখায় কেবল নিজের ডিলারের বিক্রি পান, মোটের মতোই;
 *   · `sales.invoice.view` ছাড়া চাবিটাই নেই।
 */
final class TheTodayDoorSplitsTodaysSalesByBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mms;

    private Branch $ntk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mms = Branch::acrossAllCompanies()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->ntk = Branch::acrossAllCompanies()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $this->web($this->owner);

        // ⓘ বাকির সীমা আর দিন এই দাবির বিষয় নয় — বিক্রি যেন আটকে না যায়
        Customer::acrossDealers()->whereIn('name_en', ['Rahim Traders', 'Niloy Store'])->get()
            ->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '10000000', 'credit_days' => 60])->save());
    }

    public function test_the_owner_gets_every_branch_and_the_rows_add_up_to_todays_sales(): void
    {
        // ⓘ পয়সাসহ দাম — গোল করার ভুল থাকলে যোগ মিলত না
        $inMms = $this->sell('WH-MMS', 'Cosmos Biscuit 40gm', '3', '1500.37');
        $inNtk = $this->sell('WH-NTK', 'Miniket Rice 50kg', '2', '3600.45');

        // প্রস্তুতি: দুইটা বিলই খাতায় বসা, দুই আলাদা শাখায়
        $this->assertSame((int) $this->mms->id, (int) $inMms->branch_id, 'প্রস্তুতিটাই ভুল — প্রথম বিলটা ময়মনসিংহে নয়।');
        $this->assertSame((int) $this->ntk->id, (int) $inNtk->branch_id, 'প্রস্তুতিটাই ভুল — দ্বিতীয় বিলটা নেত্রকোনায় নয়।');
        $this->assertTrue(SalesInvoice::query()->posted()->whereKey([$inMms->id, $inNtk->id])->count() === 2,
            'প্রস্তুতিটাই ভুল — বিল দুইটা খাতায় বসেনি।');

        $body = $this->phone($this->owner)->json();
        $rows = $body['salesByBranch'];

        // ⛔ যোগ = মোট = sales.amount
        $this->assertSame($body['sales']['amount'], $body['salesByBranchTotal'], '⛔ শাখার মোট আর আজকের বিক্রি দুই অঙ্ক।');
        $this->assertSame($body['salesByBranchTotal'], $this->sum($rows), '⛔ সারিগুলোর যোগ শাখার মোটের সমান নয়।');
        $this->assertSame(1, bccomp($body['sales']['amount'], '0', 4), 'প্রস্তুতিটাই ভুল — আজ বিক্রিই নেই।');

        // ⓘ আকার — চাবি, পূর্ণসংখ্যা আইডি, চার ঘরের টাকা-স্ট্রিং
        foreach ($rows as $row) {
            // ⓘ ২০ অক্টোবর ২০২৬ থেকে সারি নিট, পাশে বিল আর ফেরত ([[NetSalesIsBillsLessReturnsEverywhereTest]]); এখানে ফেরত নেই, তাই নিট = বিল
            $this->assertSame(['branch_id', 'branch', 'amount', 'billed', 'returned'], array_keys($row));
            $this->assertSame($row['billed'], $row['amount']);
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $row['amount']);
        }

        // ⭐ মালিক সব সচল শাখা পান — আজ বিক্রি না হলেও শূন্যে
        $all = Branch::acrossAllCompanies()->where('company_id', $this->company->id)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertGreaterThan(2, count($all), 'প্রস্তুতিটাই ভুল — ডেমোতে বিক্রিহীন শাখা নেই।');
        $ids = array_column($rows, 'branch_id');
        sort($ids);
        $this->assertSame($all, $ids, '⛔ মালিক সব শাখা দেখছেন না।');

        $byId = collect($rows)->keyBy('branch_id');

        // ⓘ ওয়েবের "আজ" ছকের শাখার সারি, আর ঐ শাখার ভিতরে বিক্রয়ের নিজের সংজ্ঞা — দুই আলাদা পথ
        $this->web($this->owner);
        app(Board::class)->refresh($this->owner);
        $board = collect(app(Board::class)->build($this->owner->fresh(), Figures::TODAY)['companies'])->firstWhere('id', (int) $this->company->id);
        $this->assertNotNull($board, 'প্রস্তুতিটাই ভুল — ওয়েবের ছকে কোম্পানিটাই নেই।');
        $boardRows = collect($board['rows'])->keyBy('id');
        $today = Carbon::today()->toDateString();

        foreach ($all as $id) {
            $web = $boardRows[$id]['values'][Figures::SALES] ?? null;
            $this->assertNotNull($web, "প্রস্তুতিটাই ভুল — ওয়েবের ছকে শাখা {$id} নেই।");
            $this->assertSame(bcadd((string) $web, '0', 4), $byId[$id]['amount'], "⛔ শাখা {$id}-এর বিক্রি ফোনে এক, ওয়েবের ছকে আরেক।");

            $inside = CompanyContext::forCompany((int) $this->company->id, function () use ($id, $today) {
                CompanyContext::set((int) $this->company->id, $id);

                return SalesInvoice::query()->withoutGlobalScope('user-branch')->posted()
                    ->where('branch_id', $id)->whereBetween('trx_date', [$today, $today])->sum('total');
            });
            $this->assertSame(bcadd((string) $inside, '0', 4), $byId[$id]['amount'], "⛔ শাখা {$id}-এর অঙ্ক ঐ শাখার বিলের যোগ নয়।");
        }

        // ⓘ নতুন দুই বিলের টাকা নিজের শাখাতেই
        $this->assertSame(1, bccomp($byId[(int) $this->ntk->id]['amount'], $byId[(int) $this->mms->id]['amount'], 4),
            'প্রস্তুতিটাই ভুল — নেত্রকোনার বিক্রি ময়মনসিংহের চেয়ে বেশি হওয়ার কথা।');

        // ⛔ ক্রম: টাকা বড় থেকে ছোট, সমান হলে নাম
        $this->assertSame((int) $this->ntk->id, $rows[0]['branch_id'], '⛔ সবচেয়ে বেশি বিক্রির শাখা প্রথমে নয়।');
        $this->assertSame((int) $this->mms->id, $rows[1]['branch_id']);
        $expected = $rows;
        usort($expected, fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], 4) ?: strcmp($a['branch'], $b['branch']));
        $this->assertSame($expected, $rows, '⛔ সারিগুলো টাকা-তারপর-নামের ক্রমে নয়।');
        $this->assertSame('0.0000', $rows[count($rows) - 1]['amount'], '⛔ বিক্রিহীন শাখা শূন্যে নেই।');
    }

    public function test_a_clerk_held_to_one_branch_sees_only_that_branch(): void
    {
        $this->sell('WH-MMS', 'Cosmos Biscuit 40gm', '3', '1500.37');
        $this->sell('WH-NTK', 'Miniket Rice 50kg', '2', '3600.45');
        $ntkSale = $this->phone($this->owner)->json('salesByBranch');
        $ntkAmount = collect($ntkSale)->firstWhere('branch_id', (int) $this->ntk->id)['amount'];
        $this->assertSame(1, bccomp($ntkAmount, '0', 4), 'প্রস্তুতিটাই ভুল — নেত্রকোনায় বিক্রি নেই।');

        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->mms->id,
        ]);
        app(DataScope::class)->forget();

        // ⛔ চাবি ছাড়া ঘরটাই নেই — শূন্যও নয়
        $without = $this->phone($clerk);
        $without->assertJsonMissingPath('salesByBranch')->assertJsonMissingPath('salesByBranchTotal')->assertJsonMissingPath('sales');

        $this->grant($clerk, 'sales.invoice.view');
        $response = $this->phone($clerk);
        $body = $response->json();

        $this->assertSame([(int) $this->mms->id], array_column($body['salesByBranch'], 'branch_id'), '⛔ এক শাখার কর্মী অন্য শাখার সারি পেলেন।');
        $this->assertSame($body['sales']['amount'], $body['salesByBranchTotal']);
        $this->assertSame($body['salesByBranchTotal'], $this->sum($body['salesByBranch']));

        $raw = $response->getContent();
        $this->assertStringNotContainsString(json_encode($this->ntk->name()), $raw, '⛔ অন্য শাখার নাম JSON-এ।');
        $this->assertStringNotContainsString($this->ntk->name(), $raw, '⛔ অন্য শাখার নাম JSON-এ।');
        $this->assertStringNotContainsString($this->ntk->name(), json_encode($body, JSON_UNESCAPED_UNICODE), '⛔ অন্য শাখার নাম JSON-এ।');
        $this->assertStringNotContainsString('"'.$ntkAmount.'"', $raw, '⛔ অন্য শাখার অঙ্ক JSON-এ।');
    }

    public function test_a_walled_salesman_gets_only_their_own_dealers_in_each_branch(): void
    {
        $sr = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertTrue(app(DealerScope::class)->walled($sr), 'প্রস্তুতিটাই ভুল — ডেমোর বিক্রয়কর্মী দেয়ালে নেই।');

        $own = $this->sell('WH-MMS', 'Cosmos Biscuit 40gm', '3', '1500.37', 'Rahim Traders');
        $this->sell('WH-MMS', 'Cosmos Biscuit 40gm', '2', '1700.11', 'Niloy Store');

        $this->grant($sr, 'sales.invoice.view');
        $body = $this->phone($sr)->json();

        $this->assertSame($body['sales']['amount'], $body['salesByBranchTotal']);
        $this->assertSame($body['salesByBranchTotal'], $this->sum($body['salesByBranch']));

        $mms = collect($body['salesByBranch'])->firstWhere('branch_id', (int) $this->mms->id);
        $this->assertNotNull($mms, 'প্রস্তুতিটাই ভুল — বিক্রয়কর্মীর নাগালে ময়মনসিংহ নেই।');
        $this->assertSame(bcadd((string) $own->total, '0', 4), $mms['amount'], '⛔ বিক্রয়কর্মীর শাখার সারিতে অন্য ডিলারের বিক্রি।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @param list<array{amount: string}> $rows */
    private function sum(array $rows): string
    {
        return array_reduce($rows, fn (string $s, array $r) => bcadd($s, $r['amount'], 4), '0.0000');
    }

    private function sell(string $warehouseCode, string $product, string $qty, string $rate, string $customer = 'Rahim Traders'): SalesInvoice
    {
        $this->web($this->owner);

        return CompanyContext::forCompany((int) $this->company->id, function () use ($warehouseCode, $product, $qty, $rate, $customer) {
            $warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $warehouseCode)->firstOrFail();
            CompanyContext::set((int) $this->company->id, (int) $warehouse->branch_id);

            $result = app(DirectSaleService::class)->complete(
                ['customer_id' => Customer::acrossDealers()->where('name_en', $customer)->firstOrFail()->id,
                    'warehouse_id' => $warehouse->id, 'own_transport' => '1', DirectSaleService::REPEAT_FIELD => '1'],
                [['product_id' => Product::query()->where('name_en', $product)->firstOrFail()->id,
                    'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
            );

            return $result['invoice']->fresh();
        });
    }

    private function web(User $user): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        $this->actingAs($user->fresh());
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);
    }

    private function phone(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/dashboard/today')->assertOk();
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany((int) $this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
