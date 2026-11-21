<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\CostNarration;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\FarePayment;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ আগের ভাড়ার ভাউচারের বিবরণ নতুন ক্রমে — `sales:fare-narrations` ([[\App\Console\Commands\FareNarrations]]; মালিক, ২১ নভেম্বর ২০২৬)।
 *
 * দাবি:
 *  - `--apply` ছাড়া কিছুই লেখে না, কেবল দেখায়।
 *  - `--apply`-এ কেবল যন্ত্রের আগের লেখা হুবহু থাকা ভাউচার বদলায় — মাথা আর সেই লেখার সারি; হাতে বদলানোটা থাকে।
 *  - দ্বিতীয়বার চালালে কিছুই বদলায় না।
 *  - অঙ্ক, খাতার সারি (ledger_entries) কখনো ছোঁয় না; অনুমোদনের সারি থাকা ভাউচার বাদ।
 */
final class OldFareNarrationsAreRewrittenOnlyWhenUntouchedTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app()->setLocale('bn');

        $till = app(CashTillService::class)->create(['code' => 'OFN-T', 'name_en' => 'Old narration till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '50000');
    }

    public function test_dry_run_by_default_then_apply_only_the_untouched_auto_text_once_and_never_amounts_or_ledger(): void
    {
        $point = Location::query()->create(['company_id' => $this->company->id, 'code' => 'PT-OFN', 'level' => Location::POINT,
            'name_en' => 'Mirpur', 'name_bn' => 'মিরপুর', 'is_active' => true]);
        $shop = Customer::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'OFN-1', 'name_en' => 'Mirpur Store', 'name_bn' => 'মিরপুর স্টোর', 'location_id' => $point->id, 'is_active' => true]);
        // ⓘ সীমা পরম (মালিক) — নতুন দোকানের সীমা ০, তাই নিশ্চিত করতে সীমা বসানো, অন্য চালানের পরীক্ষার মতো
        $shop->forceFill(['credit_limit' => '1000000000'])->save();

        [$untouched, $untouchedChallan] = $this->oldFareVoucher($shop, '6500');
        [$edited] = $this->oldFareVoucher($shop, '700');
        DB::table('vouchers')->where('id', $edited->id)->update(['narration' => 'হাতে লেখা — রফিককে ভাড়া']);

        $old = (string) $untouched->fresh()->narration;
        $this->assertStringContainsString('বাহক বা চালক', $old, 'প্রস্তুতি: আগের যন্ত্র-লেখা বসেনি।');
        $new = "গাড়ি ভাড়া — চালান {$untouchedChallan->document_no}, পয়েন্ট: মিরপুর, গাড়ি ঢাকা মেট্রো-ট 11-2345 (ড্রাইভার: করিম)";

        $amounts = $this->amounts();
        $ledger = $this->ledger();

        // ── শুকনো চালানো ──
        $this->artisan('sales:fare-narrations')->assertSuccessful();
        $this->assertSame($old, $untouched->fresh()->narration, '⛔ --apply ছাড়াই বিবরণ বদলাল।');

        // ── সত্যি ──
        $this->artisan('sales:fare-narrations', ['--apply' => true])->assertSuccessful();
        $this->assertSame($new, $untouched->fresh()->narration, '⛔ না-ছোঁয়া আগের বিবরণ নতুন ক্রমে বদলায়নি।');
        foreach ($untouched->fresh('lines')->lines as $line) {
            $this->assertSame($new, $line->narration, '⛔ ভাউচারের নিজের সারির আগের বিবরণ বদলায়নি।');
        }
        $this->assertSame('হাতে লেখা — রফিককে ভাড়া', $edited->fresh()->narration, '⛔ হাতে বদলানো বিবরণে হাত দিল।');

        // ── আবার — কিছুই নয় ──
        $snapshot = Voucher::query()->orderBy('id')->pluck('narration', 'id')->all();
        $this->artisan('sales:fare-narrations', ['--apply' => true])->assertSuccessful();
        $this->assertSame($snapshot, Voucher::query()->orderBy('id')->pluck('narration', 'id')->all(), '⛔ দ্বিতীয়বার চালাতে আবার বদলাল — কমান্ড idempotent নয়।');

        $this->assertSame($amounts, $this->amounts(), '⛔ কোনো ভাউচারের অঙ্ক বদলাল।');
        $this->assertSame($ledger, $this->ledger(), '⛔ খাতার সারি (ledger_entries) ছোঁয়া হলো।');
    }

    public function test_a_voucher_with_approval_rows_is_left_alone(): void
    {
        $shop = Customer::query()->firstOrFail();
        [$voucher] = $this->oldFareVoucher($shop, '400');
        $old = (string) $voucher->fresh()->narration;

        Approval::query()->forceCreate([
            'company_id' => $this->company->id, 'approvable_type' => Voucher::class, 'approvable_id' => $voucher->id,
            'module' => 'accounts', 'action' => 'create', 'status' => Approval::APPROVED, 'state_hash' => str_repeat('a', 64),
            'requested_by' => auth()->id(), 'requested_at' => now(), 'decided_at' => now(),
        ]);

        $this->artisan('sales:fare-narrations', ['--apply' => true])->assertSuccessful();
        $this->assertSame($old, $voucher->fresh()->narration, '⛔ অনুমোদনের সারি থাকা ভাউচারের বিবরণ বদলাল — সই "বদলেছে" দেখাত।');
    }

    public function test_an_unknown_company_code_fails_loudly_and_writes_nothing(): void
    {
        $shop = Customer::query()->firstOrFail();
        [$voucher] = $this->oldFareVoucher($shop, '400');
        $old = (string) $voucher->fresh()->narration;

        $this->artisan('sales:fare-narrations', ['--apply' => true, '--company' => 'NO-SUCH-CODE'])->assertFailed();
        $this->assertSame($old, $voucher->fresh()->narration, '⛔ অচেনা কোম্পানির কোডে কিছু লেখা হলো।');
    }

    // ── যন্ত্রপাতি ──

    /**
     * আজকের পথে ভাড়ার ভাউচার, তারপর বিবরণ (মাথা আর সারি) আগের যন্ত্র-লেখায় ফেরানো — ২১ নভেম্বরের আগের ভাউচারের মতো।
     *
     * @return array{0: Voucher, 1: DeliveryChallan}
     */
    private function oldFareVoucher(Customer $shop, string $fare): array
    {
        $service = app(DeliveryChallanService::class);
        $challan = $service->create(
            ['customer_id' => $shop->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );
        $challan->forceFill(['transport_cost' => $fare, 'fare_paid_by' => 'us', 'vehicle_id' => null,
            'vehicle_no' => 'ঢাকা মেট্রো-ট 11-2345', 'driver_name' => 'করিম'])->save();

        app(FarePayment::class)->stamp($challan->fresh(), ['fare_when' => 'now', 'fare_account_id' => $this->till->id]);
        $service->confirm($challan->fresh(['lines']));
        $challan = $challan->fresh();
        $voucher = Voucher::query()->findOrFail($challan->fare_voucher_id);

        $legacy = app(CostNarration::class)->legacyNarration($challan, 'bn');
        DB::table('vouchers')->where('id', $voucher->id)->update(['narration' => $legacy]);
        DB::table('voucher_lines')->where('voucher_id', $voucher->id)->update(['narration' => $legacy]);

        $this->assertFalse(Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->exists(),
            'প্রস্তুতি: ডেমোতে ভাড়ার ভাউচারে অনুমোদন লাগার কথা নয়।');

        return [$voucher->fresh(), $challan];
    }

    /** @return array<int, string> */
    private function amounts(): array
    {
        return Voucher::query()->orderBy('id')->get()->mapWithKeys(fn (Voucher $v) => [$v->id => (string) $v->amount
            .'|'.$v->lines()->orderBy('id')->get()->map(fn ($l) => $l->account_id.':'.$l->debit.':'.$l->credit)->implode(',')])->all();
    }

    /** @return list<string> */
    private function ledger(): array
    {
        return LedgerEntry::query()->orderBy('id')->get()
            ->map(fn (LedgerEntry $e) => implode('|', [$e->id, $e->account_id, $e->debit, $e->credit, $e->narration, $e->row_hash]))
            ->all();
    }
}
