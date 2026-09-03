<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গাড়ির ভাড়া কোনোদিন খাতায় পৌঁছাত না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `sal_challans.transport_cost` ঘরটা ভরত, আর **সেখানেই থেমে যেত** —
 * কোনো ভাউচার নয়, কোনো খাত নয়। অর্থাৎ পরিবহনের খরচ লাভ-ক্ষতিতে আসতই
 * না, আর **মুনাফা ঠিক ওই পরিমাণ বেশি দেখাত**।
 *
 * ⚠️ ধরা পড়েনি কারণ সংখ্যাটা **চালানে দেখা যেত** — ব্যবহারকারীর মনে
 * হত হিসাবটা হয়েছে। কেবল খতিয়ান খুলে দেখলে বোঝা যেত ওখানে কিছু নেই,
 * আর কেউ ওভাবে খোঁজে না।
 *
 * ── দাখিলাটা কেন এই আকারে ───────────────────────────────────────────
 * মালিকের কথা (৪ সেপ্টেম্বর ২০২৬): *"চালানে বসালেও সেটা expense-এ
 * যাবে, আর transporter-এর সাথে হিসাব হবে"*।
 *
 * `Cr নগদ` লিখলে ধরে নেওয়া হত টাকাটা ওই দিনই মিটেছে — যা ডিপোতে সত্যি
 * নয়। **পরিবহনকারীর সাথে হিসাব চলতি**, মাসে একবার মেটে।
 */
final class TheLorryFareNeverReachedTheBooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * বাহক বাছা থাকলে ভাড়াটা তাঁর খাতায় পাওনা হয়ে বসে।
     */
    public function test_the_fare_becomes_a_due_on_the_carriers_ledger(): void
    {
        $carrier = $this->carrier();
        $challan = $this->challanWithFare('350.0000', $carrier);

        app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));

        $lines = $this->ledgerOf($challan);

        $this->assertCount(2, $lines, 'গাড়ির ভাড়ার দাখিলাটা লেখাই হয়নি।');

        $hire = $lines->firstWhere('code', StandardChart::VEHICLE_HIRE);
        $this->assertNotNull($hire, 'খরচটা গাড়ির ভাড়ার খাতে (৫২১৭) বসেনি।');
        $this->assertSame('350.0000', $hire->debit);

        $payable = $lines->firstWhere('code', StandardChart::PAYABLE);
        $this->assertNotNull($payable, 'অন্য পাশটা প্রদেয়তে বসেনি — সম্ভবত নগদে গেছে।');
        $this->assertSame('350.0000', $payable->credit);

        /*
         * ⚠️ এই দুইটা assert-ই আসল কথা: পক্ষ ছাড়া প্রদেয় মানে
         * "কাউকে দিতে হবে, কিন্তু কাকে জানি না" — আর তখন
         * পরিবহনকারীর খতিয়ানটাই খালি থাকত।
         */
        $this->assertSame('supplier', $payable->party_type);
        $this->assertSame($carrier->id, (int) $payable->party_id);
    }

    /**
     * বাহক বাছা না থাকলে নগদে — "একবারের গাড়ি"।
     *
     * ⓘ যে গাড়ি একবার আসে তার সাথে চলতি হিসাব থাকে না, টাকা ওই দিনই
     * মেটে। খতিয়ান দরকার হয় যেখানে বকেয়া জমে।
     */
    public function test_without_a_carrier_the_fare_goes_to_cash(): void
    {
        $challan = $this->challanWithFare('120.0000', carrier: null);

        app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));

        $lines = $this->ledgerOf($challan);
        $credit = $lines->firstWhere('credit', '120.0000');

        $this->assertNotNull($credit);
        $this->assertSame(StandardChart::CASH_IN_HAND, $credit->code);
        $this->assertNull($credit->party_type, 'একবারের গাড়িতে কোনো পক্ষ থাকার কথা নয়।');
    }

    /**
     * ভাড়া না থাকলে কোনো দাখিলাও নয়।
     *
     * ⚠️ শূন্য টাকার ভাউচার খতিয়ান ভরিয়ে দিত আর কিছুই বোঝাত না।
     */
    public function test_a_challan_without_a_fare_writes_nothing(): void
    {
        $challan = $this->challanWithFare(null, carrier: null);

        app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));

        $this->assertCount(0, $this->ledgerOf($challan));
    }

    /**
     * বাতিল করলে পাওনাটাও ফেরে — কিন্তু মূল সারি মুছে নয়।
     *
     * ⚠️ না ফিরলে পরিবহনকারীর খাতায় একটা পাওনা বসে থাকত **যার কোনো
     * চালান নেই**, আর সেটা ধরা পড়ত মাস শেষে মেলানোর সময়, কারণ ছাড়াই।
     */
    public function test_cancelling_gives_the_money_back(): void
    {
        $carrier = $this->carrier();
        $challan = $this->challanWithFare('500.0000', $carrier);

        $service = app(DeliveryChallanService::class);
        $service->confirm($challan->fresh(['lines']));

        $this->assertSame('500.0000', $this->dueOf($carrier));

        $service->cancel($challan->fresh(['lines']), 'ভুল করে লেখা হয়েছিল');

        $this->assertSame('0.0000', $this->dueOf($carrier), 'বাতিলের পরেও পাওনা রয়ে গেছে।');

        // মূল সারি অক্ষত — উল্টো সারি বসে, মোছা হয় না (নিয়ম ৫)
        $this->assertCount(2, $this->ledgerOf($challan));
    }

    /**
     * ⚠️ ভাড়াবিহীন চালান বাতিল করাও ভাঙে না।
     *
     * `PostingEngine::reverse()` কিছু না পেলে ব্যতিক্রম ছোঁড়ে, আর
     * বেশিরভাগ চালানে ভাড়া থাকেই না — যাচাই ছাড়া ডাকলে **ওই চালানগুলোর
     * বাতিলই ভেঙে যেত**, আর ভুলটা দেখা দিত বাতিলের মুহূর্তে।
     */
    public function test_cancelling_a_challan_with_no_fare_still_works(): void
    {
        $challan = $this->challanWithFare(null, carrier: null);

        $service = app(DeliveryChallanService::class);
        $service->confirm($challan->fresh(['lines']));
        $service->cancel($challan->fresh(['lines']), 'ভাড়া ছাড়া বাতিল');

        $this->assertSame('cancelled', $challan->fresh()->status->value ?? (string) $challan->fresh()->status);
    }

    // ── সহায়ক ────────────────────────────────────────────────────────

    private function carrier(): Supplier
    {
        $type = PartyType::query()->where('code', 'TRANSPORT')->firstOrFail();

        return Supplier::query()->create([
            'code' => 'TR-TEST',
            'name_en' => 'Karim Transport',
            'name_bn' => 'করিম ট্রান্সপোর্ট',
            'party_type_id' => $type->id,
        ]);
    }

    private function challanWithFare(?string $fare, ?Supplier $carrier): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => Customer::query()->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => Product::query()->firstOrFail()->id,
                'delivered_qty' => '1',
                'rate' => '50',
            ]],
        );

        $challan->update([
            'transport_cost' => $fare,
            'carrier_id' => $carrier?->id,
            'carrier_name' => $carrier?->name_en,
        ]);

        return $challan;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function ledgerOf(DeliveryChallan $challan): \Illuminate\Support\Collection
    {
        return LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.source_type', DeliveryChallan::STOCK_SOURCE)
            ->where('ledger_entries.source_id', $challan->id)
            ->get(['accounts.code', 'ledger_entries.debit', 'ledger_entries.credit',
                'ledger_entries.party_type', 'ledger_entries.party_id']);
    }

    /** পরিবহনকারীর খাতায় আজকের পাওনা — সব উৎস মিলিয়ে। */
    private function dueOf(Supplier $carrier): string
    {
        $rows = LedgerEntry::query()
            ->where('party_type', 'supplier')
            ->where('party_id', $carrier->id)
            ->get(['debit', 'credit']);

        $due = '0';

        foreach ($rows as $row) {
            $due = bcadd($due, bcsub((string) $row->credit, (string) $row->debit, 4), 4);
        }

        return $due;
    }
}
