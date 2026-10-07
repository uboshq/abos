<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\FarePayment;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * গাড়ির ভাড়া বাছা খাত থেকে, পূর্ণাঙ্গ খরচ ভাউচারে — মালিক, ৭ অক্টোবর ২০২৬ ([[FarePayment]])।
 *
 * ⛔ মালিকের কথা: *"এখন ভাড়া তুললে Main Counter থেকে paid দেখায়, কোনো খাত বাছার সুযোগ নেই। কোথা থেকে কে দিল,
 * সেই ব্যবস্থা লাগবে।"*
 *
 * দাবি:
 *  - এখনই দিলাম: চালান পাকা হলে EV — Dr ৫২১৭ / Cr বাছা টিল (প্রধান টিল নয়), চালানের সাথে বাঁধা, চালানের নিজের
 *    দাখিলায় ভাড়া নেই; কে দিলেন = লগইন করা মানুষ। চালান বাতিলে EV বাতিল, টাকা টিলে ফেরে।
 *  - খাত না বাছলে, অন্যের নগদ টিল বাছলে, ব্যাংকে TrxID না দিলে — থামে, কিছু বসে না।
 *  - পরে দেব: বাহক ছাড়া থামে; বাহক থাকলে Dr ৫২১৭ / Cr ২১১৬ বাহকের নামে, কোনো ভাউচার নয়।
 *  - পুরনো কাগজ (`fare_rule` null): আগের পথ হুবহু।
 */
final class TheFareComesOutOfTheAccountSomeoneChoseTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_a_fare_paid_now_is_an_expense_voucher_from_the_chosen_till_and_cancels_with_the_challan(): void
    {
        $till = app(CashTillService::class)->create(['code' => 'FARE-T', 'name_en' => 'Fare till']);
        $account = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($account, '1000');
        $before = $account->balanceOn();

        $challan = $this->challan('350.0000', null);
        app(FarePayment::class)->stamp($challan, ['fare_when' => 'now', 'fare_account_id' => $account->id]);
        app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
        $challan->refresh();

        $voucher = Voucher::query()->with('lines.account')->find($challan->fare_voucher_id);
        $this->assertNotNull($voucher, '⛔ এখনই দেওয়া ভাড়ার কোনো ভাউচার হলো না।');
        $this->assertSame(Voucher::EXPENSE, $voucher->type, '⛔ ভাড়া পূর্ণাঙ্গ খরচ ভাউচার (EV) নয়।');
        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, '⛔ সই লাগে না, তবু ভাউচার পাকা হয়নি।');
        $this->assertSame([DeliveryChallan::drillSourceType(), $challan->id], [$voucher->against_type, (int) $voucher->against_id], '⛔ ভাউচার চালানের সাথে বাঁধা নয়।');
        $this->assertStringContainsString($challan->document_no, (string) $voucher->narration, '⛔ বিবরণে চালানের নম্বর নেই।');

        $debit = $voucher->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 4) > 0);
        $credit = $voucher->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 4) > 0);
        $this->assertSame(StandardChart::VEHICLE_HIRE, $debit->account->code, '⛔ খরচ গাড়ির ভাড়ায় (৫২১৭) বসেনি।');
        $this->assertSame($account->id, (int) $credit->account_id, '⛔ টাকা বাছা টিল থেকে নয় (Main Counter-এ গেল?)।');
        $this->assertSame(0, bccomp(bcsub($before, $account->fresh()->balanceOn(), 4), '350', 4), '⛔ বাছা টিল থেকে ঠিক ৩৫০ কমেনি।');
        $this->assertSame($this->owner->id, (int) $challan->fare_payer_id, '⛔ কে দিলেন লেখা নেই।');

        $this->assertFalse(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)
            ->whereIn('account_id', [$debit->account_id, $credit->account_id])->exists(), '⛔ ভাড়া ভাউচারের সাথে চালানেও বসল — দুইবার খরচ।');

        app(DeliveryChallanService::class)->cancel($challan->fresh(['lines']), 'ভুল চালান');
        $this->assertSame(DocumentStatus::CANCELLED, $voucher->fresh()->status, '⛔ চালান বাতিলে ভাড়ার ভাউচার বাতিল হয়নি।');
        $this->assertSame(0, bccomp($before, $account->fresh()->balanceOn(), 4), '⛔ বাতিলের পরে টাকা টিলে ফেরেনি।');
    }

    /**
     * খসড়া এক শাখার টিল ধরে, পাকা করতে চান অন্য হেডার-শাখার কেউ — ভাউচারের নিজের নিয়মে থামে, পরিষ্কার বার্তায়
     * (অন্য শাখার টিল থেকে টাকা নয়), আর কিছুই বসে না। ⛔ ৪০৪ বা ৫০০ নয় — খাত খোঁজায় শাখার দেয়াল বসলে তাই হত।
     */
    public function test_confirming_from_another_header_branch_stops_with_a_clear_message_and_writes_nothing(): void
    {
        $branches = \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', CompanyContext::id())->orderBy('id')->get();
        $till = app(CashTillService::class)->create(['code' => 'FARE-X', 'name_en' => 'Branch B till']);
        \App\Modules\Accounts\Models\CashTill::query()->withoutGlobalScopes()->whereKey($till->id)->update(['branch_id' => $branches[1]->id]);
        $account = Account::query()->withoutGlobalScopes()->findOrFail($till->account_id);
        $this->putMoneyIn($account, '500');

        $challan = $this->challan('90.0000', null);
        app(FarePayment::class)->stamp($challan, ['fare_when' => 'now', 'fare_account_id' => $account->id]);

        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $branches[0]->id])->save();
        CompanyContext::set(CompanyContext::id(), $branches[0]->id);
        app(\App\Core\Services\DataScope::class)->forget();
        $this->actingAs($this->owner->fresh());

        try {
            app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
            $this->fail('⛔ অন্য হেডার-শাখার মানুষ অন্য শাখার টিল থেকে ভাড়া দিলেন।');
        } catch (ValidationException) {
            // ⓘ পরিষ্কার বার্তা — ভাউচারের নিজের যাচাই
        }

        $this->assertNull($challan->fresh()->fare_voucher_id, '⛔ থেমে যাওয়া চালানে ভাউচার বসল।');
        $this->assertNotSame(DocumentStatus::CONFIRMED, $challan->fresh()->status, '⛔ ভাড়া থামল, তবু চালান পাকা হলো।');
        $this->assertSame(0, bccomp('500', $account->fresh()->balanceOn(), 4), '⛔ থেমে যাওয়া ভাড়ার টাকা টিল থেকে কাটল।');
    }

    public function test_no_account_another_persons_till_or_a_bank_without_trxid_stop_the_fare(): void
    {
        $challan = $this->challan('200.0000', null);

        $this->refused(fn () => app(FarePayment::class)->stamp($challan, ['fare_when' => 'now']), 'fare_account_id', __('sales::fare.needs_account'));

        // ⛔ হেডারে শাখা A, আর টিলটা শাখা B-র
        $branches = \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', CompanyContext::id())->orderBy('id')->get();
        $tillB = app(CashTillService::class)->create(['code' => 'FARE-B', 'name_en' => 'Branch B till']);
        \App\Modules\Accounts\Models\CashTill::query()->withoutGlobalScopes()->whereKey($tillB->id)->update(['branch_id' => $branches[1]->id]);
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $branches[0]->id])->save();
        CompanyContext::set(CompanyContext::id(), $branches[0]->id);
        app(\App\Core\Services\DataScope::class)->forget();
        $this->actingAs($this->owner->fresh());
        $this->refused(fn () => app(FarePayment::class)->stamp($challan, ['fare_when' => 'now', 'fare_account_id' => $tillB->account_id]),
            'fare_account_id', __('accounts::validation.unknown_account'));
        $this->owner->forceFill(['view_all_branches' => true])->save();
        app(\App\Core\Services\DataScope::class)->forget();
        $this->actingAs($this->owner->fresh());

        $other = User::factory()->create();
        $till = app(CashTillService::class)->create(['code' => 'FARE-O', 'name_en' => 'Not mine']);
        Account::query()->whereKey($till->account_id)->update(['held_by' => $other->id]);
        $this->refused(fn () => app(FarePayment::class)->stamp($challan, ['fare_when' => 'now', 'fare_account_id' => $till->account_id]),
            'fare_account_id', __('sales::fare.not_your_till'));

        // ⓘ ডেমোতে ব্যাংক খাত নেই — বানিয়ে নেওয়া, নইলে ব্যাংকের দাবি চুপচাপ বাদ পড়ত
        $bank = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1102-FARE', 'name_en' => 'Fare Bank', 'name_bn' => 'ভাড়ার ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);

        $this->refused(fn () => app(FarePayment::class)->stamp($challan, ['fare_when' => 'now', 'fare_account_id' => $bank->id, 'fare_reference' => '']),
            'fare_reference', __('sales::fare.needs_reference'));

        app(FarePayment::class)->stamp($challan, ['fare_when' => 'now', 'fare_account_id' => $bank->id, 'fare_reference' => 'TRX-77812']);
        $this->assertSame([FarePayment::NOW, $bank->id, 'TRX-77812'],
            [$challan->fresh()->fare_status, (int) $challan->fresh()->fare_account_id, $challan->fresh()->fare_reference], 'TrxID-সহ ব্যাংক মেনে নিল না — দাবি অন্ধ।');

        $this->assertNull($challan->fresh()->fare_voucher_id);
    }

    public function test_a_fare_paid_later_needs_a_carrier_and_becomes_the_carriers_due(): void
    {
        $challan = $this->challan('500.0000', null);
        $this->refused(fn () => app(FarePayment::class)->stamp($challan, ['fare_when' => 'later']), 'carrier_id', __('sales::fare.later_needs_carrier'));

        $carrier = Supplier::query()->create(['code' => 'TR-FARE', 'name_en' => 'Rahim Transport',
            'party_type_id' => PartyType::query()->where('code', 'TRANSPORT')->firstOrFail()->id]);
        $challan->forceFill(['carrier_id' => $carrier->id])->save();
        app(FarePayment::class)->stamp($challan->fresh(), ['fare_when' => 'later']);

        // ⛔ পরিবহন পর্দা বাহক মুছে দিলে পাকা করার সময় থামে — নইলে টাকা প্রধান টিল থেকে কাটত
        $challan->forceFill(['carrier_id' => null])->save();
        $this->refused(fn () => app(DeliveryChallanService::class)->confirm($challan->fresh(['lines'])), 'carrier_id', __('sales::fare.later_needs_carrier'));
        $this->assertFalse(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)->exists(),
            '⛔ থেমে যাওয়া চালানের কিছু খাতায় বসল।');
        $challan->forceFill(['carrier_id' => $carrier->id])->save();

        app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));

        $rows = LedgerEntry::query()->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.source_type', DeliveryChallan::STOCK_SOURCE)->where('ledger_entries.source_id', $challan->id)
            ->get(['accounts.code', 'ledger_entries.debit', 'ledger_entries.credit', 'ledger_entries.party_id']);
        $due = $rows->firstWhere('code', StandardChart::TRANSPORT_PAYABLE);
        $this->assertNotNull($due, '⛔ পরে-দেওয়া ভাড়া প্রদেয় পরিবহনে (২১১৬) বসেনি।');
        $this->assertSame([0, $carrier->id], [bccomp((string) $due->credit, '500', 4), (int) $due->party_id], '⛔ দেনা বাহকের নামে ৫০০ নয়।');
        $this->assertNull($challan->fresh()->fare_voucher_id, '⛔ পরে-দেওয়া ভাড়ায় আজই ভাউচার হলো।');
        $this->assertSame(FarePayment::DUE, $challan->fresh()->fare_status);
    }

    public function test_an_old_paper_without_the_rule_still_goes_the_old_way(): void
    {
        $challan = $this->challan('120.0000', null);
        app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));

        $this->assertNull($challan->fresh()->fare_voucher_id);
        $this->assertTrue(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)
            ->where('credit', '120.0000')->exists(), 'পুরনো কাগজের ভাড়া আগের পথে বসেনি — আজকের কাগজ ভাঙল।');
    }

    // ── যন্ত্রপাতি ──

    private function challan(string $fare, ?Supplier $carrier): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            ['customer_id' => Customer::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );
        $challan->update(['transport_cost' => $fare, 'carrier_id' => $carrier?->id, 'fare_paid_by' => 'us']);

        return $challan->fresh();
    }

    private function refused(\Closure $work, string $field, string $message): void
    {
        try {
            $work();
            $this->fail("⛔ থামার কথা ছিল: {$message}");
        } catch (ValidationException $e) {
            $this->assertSame([$message], $e->errors()[$field] ?? null, 'অন্য কারণে থামল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }
}
