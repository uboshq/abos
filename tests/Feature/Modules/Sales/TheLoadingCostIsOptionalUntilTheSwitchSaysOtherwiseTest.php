<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\ChallanLoading;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⭐ চালানের লোডিং খরচ — ঐচ্ছিক, সুইচ চালু হলে বাধ্যতামূলক (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে"; ২০ নভেম্বর ২০২৬ দাবি; [[ChallanLoading]])।
 *
 * ⓘ মালিকের কারণ: মাল তোলার হাম্মালি বিক্রয়ের খরচ — লেখা না থাকলে ডেলিভারির আসল খরচ কম দেখায়। কিন্তু সব ডিপোতে আলাদা
 *   হাম্মালি নেই, তাই ডিফল্ট ঐচ্ছিক; নিয়ন্ত্রণ প্যানেলের `sales.loading.mandatory` চালু করলে ভাড়ার মতোই।
 *
 * দাবি:
 *  - সুইচ বন্ধ: লোডিং ছাড়া চালান পাকা, খাতায় লোডিংয়ের কিছু নেই।
 *  - সুইচ চালু: লোডিং ছাড়া থামে, কিছুই খাতায় নয়; "লাগেনি" (কারণসহ) দিলে পাকা।
 *  - নগদ: Dr লোডিং ৫২১৮ / Cr বাছা টিল, নিজের দাখিলা-নামে; বাতিলে উল্টো দাখিলা, টিলে টাকা ফেরে।
 *  - বাকি: Cr ২১১৭ ঠিকাদারের নামে।
 *  - পাকা চালানে পরে লেখা: একবার চলে, দ্বিতীয়বার থামে (খরচ দুইবার নয়)।
 */
final class TheLoadingCostIsOptionalUntilTheSwitchSaysOtherwiseTest extends TestCase
{
    use HiresTheLabour;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_by_default_a_challan_confirms_without_loading_and_books_none(): void
    {
        $this->assertFalse(app(ChallanLoading::class)->mandatory(), 'সুইচের ডিফল্ট বন্ধ থাকার কথা।');

        $challan = $this->confirmed($this->draft());

        $this->assertSame(DocumentStatus::CONFIRMED, $challan->status);
        $this->assertNull($challan->loading_mode);
        $this->assertFalse(LedgerEntry::query()->where('source_type', ChallanLoading::SOURCE)->where('source_id', $challan->id)->exists(),
            '⛔ লোডিং লেখা নেই, তবু খাতায় লোডিং বসল।');
    }

    public function test_with_the_switch_on_a_challan_waits_for_the_loading_choice(): void
    {
        app(SettingsService::class)->set(ChallanLoading::MANDATORY_KEY, true);
        $challan = $this->draft();

        $this->refusedOn(fn () => $this->confirmed($challan), 'loading_mode',
            __('supplier::labour.loading_required', ['no' => $challan->document_no]));

        $this->assertNotSame(DocumentStatus::CONFIRMED, $challan->fresh()->status, '⛔ সুইচ চালু, লোডিং ছাড়াই চালান পাকা হলো।');
        $this->assertFalse(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)->exists(),
            '⛔ থেমে যাওয়া চালানের কিছু খাতায় বসল।');

        app(ChallanLoading::class)->stamp($challan->fresh(), ['loading_mode' => 'none', 'loading_reason' => 'Customer loaded it']);
        $done = $this->confirmed($challan);

        $this->assertSame(DocumentStatus::CONFIRMED, $done->status, '"লাগেনি" দেওয়ার পরেও চালান পাকা হলো না।');
        $this->assertFalse(LedgerEntry::query()->where('source_type', ChallanLoading::SOURCE)->where('source_id', $challan->id)->exists(),
            '⛔ "লাগেনি" চালানে লোডিংয়ের খরচ বসল।');
    }

    public function test_cash_loading_debits_loading_credits_the_till_and_reverses_on_cancel(): void
    {
        $till = $this->labourTill('LOAD-T');
        $before = $till->balanceOn();

        $challan = $this->confirmed($this->draft(['loading_mode' => 'cash', 'loading_amount' => '75', 'loading_account_id' => $till->id]));

        $this->assertSame('75.0000', $this->booked(ChallanLoading::SOURCE, $challan->id, StandardChart::LOADING, 'debit'),
            '⛔ লোডিং খরচ (৫২১৮) ৭৫ বসেনি।');
        $this->assertSame('75.0000', $this->booked(ChallanLoading::SOURCE, $challan->id, (int) $till->id, 'credit'),
            '⛔ নগদের লোডিং বাছা টিল থেকে কাটেনি।');
        $this->assertSame(0, bccomp(bcsub($before, $till->fresh()->balanceOn(), 4), '75', 4));

        app(DeliveryChallanService::class)->cancel($challan->fresh(['lines']), 'Wrong challan');

        $this->assertSame('75.0000', $this->booked(ChallanLoading::SOURCE.':reversal', $challan->id, StandardChart::LOADING, 'credit'),
            '⛔ চালান বাতিলে লোডিংয়ের খরচ উল্টায়নি।');
        $this->assertSame(0, bccomp($before, $till->fresh()->balanceOn(), 4), '⛔ বাতিলের পরে লোডিংয়ের টাকা টিলে ফেরেনি।');
    }

    public function test_due_loading_is_owed_to_the_contractor(): void
    {
        $contractor = $this->labourContractor();

        $challan = $this->confirmed($this->draft(['loading_mode' => 'due', 'loading_amount' => '60', 'loading_contractor_id' => $contractor->id]));

        $due = LedgerEntry::query()->where('source_type', ChallanLoading::SOURCE)->where('source_id', $challan->id)
            ->where('account_id', StandardChart::find(StandardChart::LABOUR_PAYABLE)->id)->get();

        $this->assertCount(1, $due, '⛔ বাকি লোডিং প্রদেয় হাম্মালিতে (২১১৭) বসেনি।');
        $this->assertSame([0, 'supplier', $contractor->id], [bccomp((string) $due->first()->credit, '60', 4), $due->first()->party_type, (int) $due->first()->party_id],
            '⛔ দেনা ঠিকাদারের নামে ৬০ নয়।');
        $this->assertSame('60.0000', $this->booked(ChallanLoading::SOURCE, $challan->id, StandardChart::LOADING, 'debit'));
    }

    /** ⛔ লোডিংয়ের ঠিকাদারও কেবল "হাম্মালি ঠিকাদার" ধরনের — চালানে আর পাকা চালানের লেখায় (সমন্বয়ক, ২০ নভেম্বর ২০২৬) */
    public function test_a_supplier_who_is_not_a_labour_contractor_is_refused(): void
    {
        $mill = $this->plainSupplier();
        $count = DeliveryChallan::query()->count();

        $this->refusedOn(fn () => $this->draft(['loading_mode' => 'due', 'loading_amount' => '60', 'loading_contractor_id' => $mill->id]),
            'loading_contractor_id', __('supplier::labour.not_a_contractor'));
        $this->assertSame($count, DeliveryChallan::query()->count(), '⛔ ভুল ঠিকাদারের চালান থেকে গেল।');

        $challan = $this->confirmed($this->draft());

        $this->from(route('sales.challan.transport', $challan))
            ->post(route('sales.challan.loading.record', $challan), ['loading_mode' => 'due', 'loading_amount' => '40', 'loading_contractor_id' => $mill->id])
            ->assertSessionHasErrors(['loading_contractor_id' => __('supplier::labour.not_a_contractor')]);

        $this->assertNull($challan->fresh()->loading_mode, '⛔ ভুল ঠিকাদারে লোডিং লেখা হয়ে গেল।');
        $this->assertFalse(LedgerEntry::query()->where('source_type', ChallanLoading::SOURCE)->where('source_id', $challan->id)->exists());
    }

    public function test_loading_is_recorded_once_on_a_confirmed_challan(): void
    {
        $contractor = $this->labourContractor();
        $challan = $this->confirmed($this->draft());
        $from = route('sales.challan.transport', $challan);
        $choice = ['loading_mode' => 'due', 'loading_amount' => '40', 'loading_contractor_id' => $contractor->id];

        $this->from($from)->post(route('sales.challan.loading.record', $challan), $choice)
            ->assertRedirect($from)
            ->assertSessionHasNoErrors();

        $this->assertSame('due', $challan->fresh()->loading_mode, 'পাকা চালানে লোডিং লেখা হলো না।');
        $this->assertSame('40.0000', $this->booked(ChallanLoading::SOURCE, $challan->id, StandardChart::LABOUR_PAYABLE, 'credit'));

        $this->from($from)->post(route('sales.challan.loading.record', $challan), $choice)
            ->assertSessionHasErrors('loading_mode');

        $this->assertSame('40.0000', $this->booked(ChallanLoading::SOURCE, $challan->id, StandardChart::LABOUR_PAYABLE, 'credit'),
            '⛔ একই চালানে লোডিং দুইবার খাতায় বসল।');
    }

    // ── যন্ত্রপাতি ──

    /** @param  array<string, mixed>  $loading */
    private function draft(array $loading = []): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create(
            ['customer_id' => Customer::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'own_transport' => true] + $loading,
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );
    }

    private function confirmed(DeliveryChallan $challan): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
    }
}
