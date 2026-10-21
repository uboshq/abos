<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Executive\Services\Figures;
use App\Modules\Executive\Services\SalesBasis;
use App\Modules\Executive\Services\Snapshots;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⭐ পুরনো রাতের হিসাবের বিক্রি নিট হয় — `abos:owner-snapshots-to-net` (fe, ২১ অক্টোবর ২০২৬)।
 *
 * দৃশ্য: গতকাল রাতের হিসাব পুরনো কোডের মতো বিলের মোটে লেখা (এখানে হাতে বসানো একটা ভুল অঙ্ক), আজ নতুন কোডের প্রথম রাত।
 * দাবি:
 *   · নতুন কোডের প্রথম রাত বদলের দিনটা রাখে, আর ইতিহাসের বিক্রির ধারায় চিহ্ন (⚑) দেখায়;
 *   · `--dry-run` কিছুই লেখে না, চিহ্নও থাকে;
 *   · `--company=` কেবল সেই কোম্পানির সারি বদলায় — অন্য কোম্পানির সারি ছোঁয় না, চিহ্নও থাকে;
 *   · পুরো চাল প্রতিটা পুরনো সারি তার নিজের কোম্পানি, জায়গা (শাখা বা গোটা) আর দিনের [[SalesMetrics::net()]]-এ বসায়,
 *     আজকের (নতুন) সারি ছোঁয় না, আর চিহ্ন সরায়;
 *   · দ্বিতীয় চাল কিছুই বদলায় না।
 */
final class TheOldNightsBecomeNetSalesTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = '999999.0000';

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->alpha->id, (int) $this->owner->current_branch_id);

        Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()
            ->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save();
    }

    public function test_old_gross_nights_become_net_once_and_the_marker_goes(): void
    {
        $yesterday = Carbon::yesterday()->toDateString();
        $today = Carbon::today()->toDateString();

        // ── গতকাল: বিল আর ফেরত, তারপর রাতের হিসাব — পুরনো কোডের মতো ভুল অঙ্কে ──
        $this->travelTo(Carbon::yesterday()->setTime(12, 0));
        $this->sellAndReturn();
        $this->artisan('abos:owner-snapshot')->assertSuccessful();
        Storage::disk('local')->delete(SalesBasis::PATH);
        DB::table(Snapshots::TABLE)->where('taken_on', $yesterday)->update([Figures::SALES => self::OLD]);
        $this->travelBack();

        // ── আজ: নতুন কোডের প্রথম রাত ──
        $this->artisan('abos:owner-snapshot')->assertSuccessful();
        $this->assertSame($today, app(SalesBasis::class)->pending(), '⛔ নতুন কোডের প্রথম রাত বদলের দিনটা রাখেনি।');
        $todayRows = $this->table($today);
        $this->assertNotSame([], $todayRows);
        $this->assertGreaterThan(2, count($this->table($yesterday)), 'প্রস্তুতিটাই ভুল — গতকালের সারি নেই।');
        $this->marker(true);

        // ── dry-run: কিছুই লেখা নয় ──
        $before = $this->table($yesterday);
        $this->artisan('abos:owner-snapshots-to-net', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, $this->table($yesterday), '⛔ dry-run সারি বদলেছে।');
        $this->assertSame($today, app(SalesBasis::class)->pending(), '⛔ dry-run চিহ্ন সরিয়েছে।');

        // ── এক কোম্পানির চাল: অন্য কোম্পানি অছোঁয়া ──
        $betaBefore = $this->table($yesterday, (int) $this->beta->id);
        $this->artisan('abos:owner-snapshots-to-net', ['--company' => (string) $this->alpha->id])->assertSuccessful();
        $this->assertSame($betaBefore, $this->table($yesterday, (int) $this->beta->id), '⛔ এক কোম্পানির চাল অন্য কোম্পানির সারি বদলেছে।');
        $this->assertNetOn($yesterday, (int) $this->alpha->id);
        $this->assertSame($today, app(SalesBasis::class)->pending(), '⛔ এক কোম্পানির চালেই চিহ্ন সরেছে, অথচ অন্যটা তখনো পুরনো।');

        // ── পুরো চাল ──
        $this->artisan('abos:owner-snapshots-to-net')->assertSuccessful();
        $this->assertNetOn($yesterday, (int) $this->alpha->id);
        $this->assertNetOn($yesterday, (int) $this->beta->id);
        $this->assertSame($todayRows, $this->table($today), '⛔ নতুন ভিত্তির সারি (আজকের) ছোঁয়া হয়েছে।');
        $this->assertNull(app(SalesBasis::class)->pending(), '⛔ সব সারি নিট, তবু চিহ্ন রয়ে গেছে।');
        $this->marker(false);

        // ── দ্বিতীয় চাল: কিছুই বদলায় না ──
        $this->travel(5)->minutes();
        $done = $this->tableWithStamps();
        $this->artisan('abos:owner-snapshots-to-net')->assertSuccessful();
        $this->assertSame($done, $this->tableWithStamps(), '⛔ দ্বিতীয় চাল সারি ছুঁয়েছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** প্রতিটা সারি তার নিজের কোম্পানি, জায়গা আর দিনের নিট বিক্রি */
    private function assertNetOn(string $day, int $companyId): void
    {
        $rows = DB::table(Snapshots::TABLE)->where('company_id', $companyId)->where('taken_on', $day)->get();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $branchId = (int) $row->place === Snapshots::WHOLE ? null : (int) $row->place;
            $net = app(CompanyLens::class)->within($this->owner->fresh(), $companyId, $branchId, fn () => SalesMetrics::net($day, $day));
            $this->assertNotSame(self::OLD, bcadd((string) $row->sales, '0', 4), "⛔ কোম্পানি {$companyId}, জায়গা {$row->place}: পুরনো অঙ্ক রয়ে গেছে।");
            $this->assertSame($net, bcadd((string) $row->sales, '0', 4), "⛔ কোম্পানি {$companyId}, জায়গা {$row->place}: সারিটা ঐ দিনের নিট বিক্রি নয়।");
        }
    }

    /** ইতিহাসের বিক্রির ধারায় চিহ্ন আছে কি না */
    private function marker(bool $expected): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        $page = $this->actingAs($this->owner->fresh())
            ->get(route('executive.history', ['date' => Carbon::today()->toDateString(), 'figure' => Figures::SALES]))->assertOk();

        $expected
            ? $page->assertSee('data-basis-marker="'.Carbon::today()->toDateString().'"', false)
            : $page->assertDontSee('data-basis-marker', false);
    }

    /** @return array<int, string> সারি → বিক্রি */
    private function table(string $day, ?int $companyId = null): array
    {
        return DB::table(Snapshots::TABLE)->where('taken_on', $day)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('id')->pluck(Figures::SALES, 'id')->map(fn ($v) => (string) $v)->all();
    }

    /** @return list<string> */
    private function tableWithStamps(): array
    {
        return DB::table(Snapshots::TABLE)->orderBy('id')->get(['id', Figures::SALES, 'updated_at'])
            ->map(fn ($r) => $r->id.'|'.$r->sales.'|'.$r->updated_at)->all();
    }

    private function sellAndReturn(): void
    {
        CompanyContext::forCompany((int) $this->alpha->id, function (): void {
            $warehouse = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();
            CompanyContext::set((int) $this->alpha->id, (int) $warehouse->branch_id);
            $invoices = app(SalesInvoiceService::class);
            $bill = $invoices->confirm($invoices->create([
                'customer_id' => Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'trx_date' => Carbon::today()->toDateString(),
            ], [['product_id' => Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->id, 'qty' => '4', 'rate' => '5000']]))->fresh('lines');

            $returns = app(SalesReturnService::class);
            $returns->confirm($returns->create([
                'customer_id' => $bill->customer_id,
                'warehouse_id' => $bill->warehouse_id,
                'sales_invoice_id' => $bill->id,
                'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
                'trx_date' => Carbon::today()->toDateString(),
            ], [['product_id' => $bill->lines->first()->product_id, 'sales_invoice_line_id' => $bill->lines->first()->id, 'qty' => '1']]));
        });
    }
}
