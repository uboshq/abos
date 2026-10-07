<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\CarrierAndLabourLedger;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লরির মালিক জানতে চাইলেন তাঁর কত পাওনা — মানচিত্র §৬ "ট্রান্সপোর্ট ও
 * শ্রমিকের খতিয়ান"।
 *
 * ⭐ ২১১৬-এ চালানের ভাড়া জমে (ক্রেডিট), ভাউচারে দেওয়া হয় (ডেবিট)। পাতা
 * পক্ষ ধরে দেখায়: শুরুতে বাকি + জমল − দেওয়া = এখন বাকি। পক্ষ ছাড়া সারি
 * আলাদা সারিতে — লুকালে মোট মিলত না।
 */
final class TheLorryOwnerAskedWhatHeWasOwedTest extends TestCase
{
    use RefreshDatabase;

    private Account $payable;

    private Account $hire;

    private Supplier $carrier;

    private int $source = 900000;

    private string $demoNoParty = '0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->payable = StandardChart::find(StandardChart::TRANSPORT_PAYABLE);
        $this->hire = StandardChart::find(StandardChart::VEHICLE_HIRE);
        // ⓘ ডেমোর চালানে যাঁর ভাড়া জমেনি — নাহলে সংখ্যা ডেমোর উপর নির্ভর করত
        $this->carrier = Supplier::query()
            ->whereNotIn('id', LedgerEntry::query()->where('account_id', $this->payable->id)
                ->whereNotNull('party_id')->pluck('party_id'))
            ->orderBy('id')
            ->firstOrFail();

        // পক্ষ ছাড়া সারির মোটও ডেমোর উপর নির্ভর না করুক
        $this->demoNoParty = (string) LedgerEntry::query()->where('account_id', $this->payable->id)
            ->whereNull('party_type')->whereDate('trx_date', '<=', now()->toDateString())
            ->selectRaw('COALESCE(SUM(credit - debit), 0) as b')->value('b');
    }

    public function test_each_carrier_s_owed_amount_carries_across_the_period(): void
    {
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString();

        $this->accrue('3000', $lastMonth, $this->carrier->id);      // শুরুর আগে
        $this->accrue('2000', now()->toDateString(), $this->carrier->id);
        $this->pay('4000', now()->toDateString(), $this->carrier->id);
        $this->accrue('700', now()->toDateString(), null);           // একবারের গাড়ি, পক্ষ নেই

        $ledger = app(CarrierAndLabourLedger::class);
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $rows = $ledger->parties($this->payable, $from, $to)->keyBy('key');
        $mine = $rows['supplier:'.$this->carrier->id];

        $this->assertSame(0, bccomp($mine['opening'], '3000', 2), 'আগের মাসের বাকি শুরুতে আসেনি।');
        $this->assertSame(0, bccomp($mine['charged'], '2000', 2));
        $this->assertSame(0, bccomp($mine['paid'], '4000', 2));
        $this->assertSame(0, bccomp($mine['closing'], '1000', 2), 'এখনকার বাকি ভুল।');
        $this->assertSame(0, bccomp($rows['']['closing'], bcadd($this->demoNoParty, '700', 4), 2), 'পক্ষ ছাড়া সারি হারিয়ে গেছে।');

        $statement = $ledger->statement($this->payable, 'supplier:'.$this->carrier->id, $from, $to);
        $this->assertSame(0, bccomp((string) $statement['rows']->last()->running_balance, '1000', 2),
            'চলমান বাকি শেষে মেলেনি।');

        // ⚠️ দ্বিতীয় পাতা শূন্য থেকে শুরু করলে সংখ্যাগুলো মিথ্যা হত
        $second = $ledger->statement($this->payable, 'supplier:'.$this->carrier->id, $from, $to, perPage: 1);
        $this->assertSame(0, bccomp($second['brought'], $second['opening'], 2), 'প্রথম পাতায় আগের সারি থাকার কথা নয়।');

        $this->get(route('finance.carrier_labour.index'))->assertOk()
            ->assertSee(route('finance.carrier_labour.index', [
                'tab' => 'transport', 'from' => $from, 'to' => $to, 'party' => 'supplier:'.$this->carrier->id,
            ]));

        $this->get(route('finance.carrier_labour.index', ['party' => 'supplier:'.$this->carrier->id]))->assertOk()
            ->assertSee(__('finance::carrier_labour.back'));

        $this->get(route('finance.carrier_labour.index', ['tab' => 'labour']))->assertOk()
            ->assertSee(__('finance::carrier_labour.none'));
    }

    private function accrue(string $amount, string $date, ?int $carrierId): void
    {
        app(PostingEngine::class)->post('test_challan', $this->source++, $date, [
            ['account_id' => $this->hire->id, 'debit' => $amount],
            ['account_id' => $this->payable->id, 'credit' => $amount,
                'party_type' => $carrierId ? 'supplier' : null, 'party_id' => $carrierId],
        ], documentNo: 'DC-T'.$this->source);
    }

    private function pay(string $amount, string $date, int $carrierId): void
    {
        $cash = Account::query()->money()->postable()->active()->firstOrFail();

        app(PostingEngine::class)->post('test_payment', $this->source++, $date, [
            ['account_id' => $this->payable->id, 'debit' => $amount, 'party_type' => 'supplier', 'party_id' => $carrierId],
            ['account_id' => $cash->id, 'credit' => $amount],
        ], documentNo: 'PV-T'.$this->source);
    }
}
