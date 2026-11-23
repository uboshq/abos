<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\AreaBinding;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesResponsibility;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ পুরনো বিলে কেউ দায়িত্বে ছিলেন না — ঘরটা নতুন, পুরনো সারি ফাঁকা (মালিক, ২৩ নভেম্বর ২০২৬; `sales:assign-responsible`)।
 *
 * ⓘ `--apply` ছাড়া কিছুই লেখে না; `--apply` ভরে; আবার চালালে কিছু বদলায় না; মোট আর খাতা অক্ষত।
 * ⓘ `--places-from` — চালু এলাকার বাঁধনের শুরু পেছনে টানে, তাই ইতিহাসও আজকের ম্যাপ ধরে (মালিক)।
 */
final class OldBillsHadNobodyResponsibleForThemTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        DB::table('dealer_bindings')->where('company_id', $this->company->id)->delete();
        DB::table('area_bindings')->where('company_id', $this->company->id)->delete();
        app(SalesResponsibility::class)->forget();

        $this->customer = (int) Customer::query()->where('name_en', 'Rahim Traders')->value('id');
    }

    public function test_a_dry_run_writes_nothing_apply_fills_and_twice_changes_nothing(): void
    {
        $a = $this->sell('2');
        $b = $this->sell('1');
        $this->assertNull($a->sales_person_id, 'প্রস্তুতিটাই ভুল — কেউ দায়িত্বে না থাকতেই বিলে মানুষ বসে গেল।');

        $sr = $this->person();
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'customer_id' => $this->customer, 'starts_on' => now()->subYear()->toDateString()]);

        $books = $this->books();
        $papers = $this->papers();
        $audits = DB::table('audit_trails')->count();

        $this->artisan('sales:assign-responsible', ['--company' => 'TDEPOT'])->assertSuccessful();
        $this->assertNull($a->fresh()->sales_person_id, '⛔ দেখার চালানো বিলে লিখে দিল।');
        $this->assertSame($audits, DB::table('audit_trails')->count(), '⛔ দেখার চালানো নিরীক্ষায় লিখল।');

        $this->artisan('sales:assign-responsible', ['--company' => 'TDEPOT', '--apply' => true])->assertSuccessful();
        $this->assertSame($sr->id, (int) $a->fresh()->sales_person_id, '⛔ --apply দায়িত্বের মানুষ বসায়নি।');
        $this->assertSame($sr->id, (int) $b->fresh()->sales_person_id);
        $this->assertTrue(
            DB::table('audit_trails')->where('action', 'sales_resp_assigned')->where('auditable_id', $this->company->id)->exists(),
            '⛔ --apply নিরীক্ষার খাতায় দাগ রাখেনি।',
        );

        $filled = $this->stamps();
        $this->artisan('sales:assign-responsible', ['--company' => 'TDEPOT', '--apply' => true])->assertSuccessful();
        $this->assertSame($filled, $this->stamps(), '⛔ দ্বিতীয়বার চালাতে কিছু বদলাল।');

        $this->assertSame($books, $this->books(), '⛔ আদেশ খাতার সারি ছুঁয়েছে।');
        $this->assertSame($papers, $this->papers(), '⛔ আদেশ মোট, নম্বর বা অবস্থা ছুঁয়েছে।');

        // ⓘ হাতে বসানো (বা আগে বসা) মানুষ --recompute ছাড়া ছোঁয়া হয় না
        $other = $this->person();
        DB::table('sal_invoices')->where('id', $a->id)->update(['sales_person_id' => $other->id]);
        $this->artisan('sales:assign-responsible', ['--company' => 'TDEPOT', '--apply' => true])->assertSuccessful();
        $this->assertSame($other->id, (int) $a->fresh()->sales_person_id, '⛔ --recompute ছাড়া বসানো ঘর বদলে দিল।');
        $this->artisan('sales:assign-responsible', ['--company' => 'TDEPOT', '--apply' => true, '--recompute' => true])->assertSuccessful();
        $this->assertSame($sr->id, (int) $a->fresh()->sales_person_id, '⛔ --recompute আবার মেলায়নি।');
    }

    public function test_places_from_moves_the_open_area_starts_back_so_history_follows_todays_map(): void
    {
        $point = Location::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'SR-BF', 'name_en' => 'SR-BF', 'level' => Location::POINT, 'is_active' => true,
        ]);
        DB::table('customers')->where('id', $this->customer)->update(['location_id' => $point->id]);

        $sale = $this->sell('1');
        DB::table('sal_invoices')->where('id', $sale->id)->update(['trx_date' => now()->subMonths(2)->toDateString(), 'sales_person_id' => null]);

        // ⓘ এলাকা বসানো হয়েছে আজ — দুই মাস আগের বিল কারো নয়
        $asm = $this->person();
        $open = AreaBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $asm->id, 'location_id' => $point->id, 'starts_on' => now()->toDateString()]);
        $gone = $this->person();
        $closed = AreaBinding::query()->create([
            'company_id' => $this->company->id, 'user_id' => $gone->id, 'location_id' => $point->id,
            'starts_on' => now()->subWeeks(3)->toDateString(), 'ends_on' => now()->subWeeks(2)->toDateString(),
        ]);
        $from = now()->subYear()->toDateString();

        $this->artisan('sales:assign-responsible', ['--company' => 'TDEPOT', '--apply' => true])->assertSuccessful();
        $this->assertNull($sale->fresh()->sales_person_id, 'প্রস্তুতিটাই ভুল — এলাকা সরানোর আগেই পুরনো বিল কেউ পেলেন।');

        $this->artisan('sales:assign-responsible', ['--company' => 'TDEPOT', '--places-from' => $from])->assertSuccessful();
        $this->assertSame(now()->toDateString(), $open->fresh()->starts_on?->toDateString(), '⛔ দেখার চালানো এলাকার শুরু সরিয়ে দিল।');
        $this->assertNull($sale->fresh()->sales_person_id, '⛔ দেখার চালানো বিলে লিখে দিল।');

        $this->artisan('sales:assign-responsible', ['--company' => 'TDEPOT', '--places-from' => $from, '--apply' => true])->assertSuccessful();
        $this->assertSame($from, $open->fresh()->starts_on?->toDateString(), '⛔ চালু এলাকার শুরু পেছনে সরেনি।');
        $this->assertSame(now()->subWeeks(3)->toDateString(), $closed->fresh()->starts_on?->toDateString(), '⛔ শেষ হয়ে যাওয়া (ইতিহাসের) বাঁধনও সরল।');
        $this->assertSame($asm->id, (int) $sale->fresh()->sales_person_id, '⛔ আজকের ম্যাপ ধরে পুরনো বিল এলাকার মানুষ পেলেন না।');
    }

    private function sell(string $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }

    private function person(): User
    {
        $u = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $u->companies()->attach($this->company->id, ['is_active' => true]);

        return $u->fresh();
    }

    private function books(): string
    {
        return (string) DB::table('ledger_entries')->orderBy('id')->get()->toJson();
    }

    private function papers(): string
    {
        return (string) DB::table('sal_invoices')->orderBy('id')
            ->get(['id', 'document_no', 'status', 'subtotal', 'discount', 'tax', 'total', 'cost_of_goods', 'updated_at'])->toJson();
    }

    /** @return array<int, ?int> */
    private function stamps(): array
    {
        return DB::table('sal_invoices')->orderBy('id')->pluck('sales_person_id', 'id')
            ->map(fn ($p) => $p === null ? null : (int) $p)->all();
    }
}
