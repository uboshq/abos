<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রসিদে নেওয়া মূলধন — মূলধনের পাতাতেও ওঠে, নিজে থেকে, একবারই।
 *
 * ── ⛔ কেন, ১৯ সেপ্টেম্বর ২০২৬ ──────────────────────────────────────
 * মালিক রসিদ ভাউচারে নিজের দ্বিতীয় মূলধন (৫ লাখ) নিলেন। খাতায় ঠিক বসত,
 * কিন্তু "মূলধন ও বিনিয়োগ" পাতা জানত না — কে কত দিলেন, অংশ, লাভের ভাগ
 * সব পুরনো থাকত। বললেন: *"capital theke asle eta auto boslei to valo hoy"*।
 *
 * ⓘ চারটা মাপ: রসিদে উঠে আসা · "নতুন মূলধন" পথে দুইবার না ওঠা · রসিদ
 * বাতিল হলে না গোনা · আগের দাতার রসিদে খাতের ঘর খালি এলেও মূলধনে যাওয়া।
 */
final class CapitalTakenOnAReceiptReachesTheCapitalPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Person $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->owner = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'OWN1',
            'name_en' => 'The Owner',
            'is_active' => true,
        ]);
    }

    public function test_capital_on_a_receipt_shows_on_the_capital_page(): void
    {
        $this->firstContribution('2500000');

        $this->receipt('500000', from: $this->capital()->id)->assertSessionHasNoErrors();

        $me = $this->position();

        $this->assertSame(0, bccomp($me['net'], '3000000', 4), "মূলধনের পাতায় {$me['net']}, ৩০ লাখ হওয়ার কথা।");
        $this->assertSame(2, CapitalEntry::query()->where('person_id', $this->owner->id)->count());
    }

    public function test_the_capital_screen_path_is_not_listed_twice(): void
    {
        $this->firstContribution('2500000');

        $this->assertSame(1, CapitalEntry::query()->count(), '"নতুন মূলধন" পথে রেকর্ড দুইবার উঠেছে।');
    }

    public function test_a_cancelled_receipt_no_longer_counts(): void
    {
        $this->firstContribution('2500000');
        $this->receipt('500000', from: $this->capital()->id)->assertSessionHasNoErrors();

        $voucher = Voucher::query()->where('type', Voucher::RECEIPT)->latest('id')->firstOrFail();
        app(VoucherService::class)->cancel($voucher, 'ভুল');

        $this->assertSame(0, bccomp($this->position()['net'], '2500000', 4), 'বাতিল রসিদের মূলধন এখনো গোনা হচ্ছে।');
    }

    public function test_an_earlier_contributor_s_receipt_goes_to_capital_even_with_the_box_empty(): void
    {
        $this->firstContribution('2500000');

        $this->receipt('500000', from: null)->assertSessionHasNoErrors();

        $voucher = Voucher::query()->where('type', Voucher::RECEIPT)->latest('id')->firstOrFail();
        $credit = $voucher->lines()->where('credit', '>', 0)->firstOrFail();

        $this->assertSame($this->capital()->id, (int) $credit->account_id, 'আগের দাতার টাকা মূলধনে যায়নি।');
    }

    private function capital(): Account
    {
        return Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
    }

    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function firstContribution(string $amount): void
    {
        $entry = app(CapitalService::class)->record([
            'person_id' => $this->owner->id,
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
        ]);

        app(CapitalService::class)->post($entry, $this->bank(), reference: 'CAP-REF');
    }

    private function receipt(string $amount, ?int $from)
    {
        return $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), array_filter([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'to_account_id' => $this->bank()->id,
            'instrument_no' => 'R-'.fake()->unique()->numberBetween(1000, 9999),
            'party_type' => 'person',
            'party_id' => $this->owner->id,
            'from_account_id' => $from,
            'narration' => '2nd capital dewa',
        ], fn ($v) => $v !== null));
    }

    /** @return array<string, mixed> */
    private function position(): array
    {
        return collect(app(CapitalService::class)->positions())->firstWhere('person_id', $this->owner->id);
    }
}
