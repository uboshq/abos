<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\LedgerEntry;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ পরিশোধ ভাউচারে নকশার "মোট টাকা" — চুক্তি: E:\ABOS\পরিশোধে-মোট-টাকা-চুক্তি-২৫-অক্টোবর.md (fe-র সিদ্ধান্ত ক, ২৫ অক্টোবর ২০২৬;
 * a4 ফোনের দিক এই চুক্তিতেই বানাচ্ছে)।
 *
 * দাবি:
 *   ১. বিকাশের উদাহরণ হুবহু: মোট ৯০০, চার্জ ৫ → দেনা Dr ৯০০, ৫২১১ Dr ৫, বিকাশ Cr ৯০৫ — চার্জ মোটের বাইরে, কোম্পানির
 *   ২. মোট = নিট + কাটা: মোট ১,০০০, AIT ৫০, VDS ৭৫, চার্জ ২৫ → পক্ষ Dr পুরো ১,০০০, ২১২১ Cr ৫০, ২১২২ Cr ৭৫, টাকার খাত Cr ৯০০ (নিট
 *      ৮৭৫ + চার্জ ২৫); ভাউচারের নিট ৮৭৫
 *   ৩. পুরনো ফোন (`gross_amount` নেই, `amount` = নিট) আগের হুবহু খাতা পায়
 *   ৪. চার নিয়ম, নিজের ঘরে নিজের কথায় (চুক্তি §৪): মোট নেই · কাটা ≥ মোট · মোট আর নিট দুটোই
 *   ৫. ফোনের preview: নিট আর খাতা = পরে একই payload-এ লেখা ভাউচারের নিট আর খাতা; কিছুই লেখে না (ভাউচার, নম্বর, মানুষ);
 *      ভুলে খসড়ার একই ঘর আর চাবিতে ৪২২
 *   ৬. setup বলে `voucher_contract: 2`; ভাউচারের পাতা `net_amount` আর `ledger` দেয়
 *   ৭. ওয়েবের পরিশোধ ফর্ম মোট পাঠায় (`gross_amount`, "মোট টাকা (৳)"); রসিদ আগের মতোই `amount`
 */
final class ThePaymentIsWrittenAsItsGrossTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Account $mfs;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->mfs = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1105-GRS', 'name_en' => 'bKash gross', 'name_bn' => 'বিকাশ মোট',
            'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::MFS, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        $this->putMoneyIn($this->mfs, '5000');
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    public function test_the_ground_is_there(): void
    {
        $this->assertNotNull(StandardChart::find(StandardChart::MFS_CHARGES), 'প্রস্তুতিটাই ভুল — ৫২১১ নেই');
        $this->assertNotNull(StandardChart::find(StandardChart::TAX_DEDUCTED_AT_SOURCE), 'প্রস্তুতিটাই ভুল — ২১২১ নেই');
    }

    /** ১ — বিকাশের উদাহরণ, হুবহু (চুক্তি §১, fe-র সম্মতি) */
    public function test_the_bkash_example_keeps_the_charge_outside_the_gross(): void
    {
        $this->accepted($this->pay(['gross_amount' => '900', 'charge_amount' => '5', 'instrument_no' => 'GRS-BK-1']), 'বিকাশের উদাহরণ');

        $voucher = $this->lastPayment();
        $book = $this->book($voucher);

        $this->moved($book, StandardChart::find(StandardChart::PAYABLE), debit: '900', why: '⛔ দেনা ঠিক ৯০০ কমেনি — চার্জ মোটে মিশে গেছে');
        $this->moved($book, StandardChart::find(StandardChart::MFS_CHARGES), debit: '5', why: '⛔ চার্জ ৫ টাকা ৫২১১-এ বসেনি');
        $this->moved($book, $this->mfs, credit: '905', why: '⛔ বিকাশ থেকে ৯০৫ বেরোয়নি — চার্জ মোটের বাইরে নয়');
        $this->assertCount(3, $book, '⛔ বিকাশের উদাহরণে ঠিক তিনটা খাত নড়ার কথা: '.json_encode($book));
        $this->assertSame(0, bccomp((string) $voucher->amount, '900', 4), '⛔ কাটা ছাড়া নিট মোটের সমান নয়');
    }

    /** ২ — মোট = নিট + কাটা; পক্ষ পুরো মোটে কমে */
    public function test_the_gross_is_the_net_plus_what_was_withheld_and_the_party_goes_down_by_all_of_it(): void
    {
        $this->accepted($this->pay(['gross_amount' => '1,000', 'ait_amount' => '50', 'vds_amount' => '75', 'charge_amount' => '25', 'instrument_no' => 'GRS-WH-1']), 'কাটাসহ মোট');

        $voucher = $this->lastPayment();
        $book = $this->book($voucher);

        $this->moved($book, StandardChart::find(StandardChart::PAYABLE), debit: '1000', why: '⛔ পক্ষ পুরো মোটে (১,০০০) কমেনি');
        $this->moved($book, StandardChart::find(StandardChart::TAX_DEDUCTED_AT_SOURCE), credit: '50', why: '⛔ AIT ২১২১-এ বসেনি');
        $this->moved($book, StandardChart::find(StandardChart::VDS_PAYABLE), credit: '75', why: '⛔ VDS ২১২২-এ বসেনি');
        $this->moved($book, $this->mfs, credit: '900', why: '⛔ টাকার খাত থেকে নিট + চার্জ (৮৭৫ + ২৫) বেরোয়নি');
        $this->moved($book, StandardChart::find(StandardChart::MFS_CHARGES), debit: '25', why: '⛔ চার্জ কোম্পানির খরচে বসেনি');
        $this->assertSame(0, bccomp((string) $voucher->amount, '875', 4), '⛔ নিট = মোট − AIT − VDS নয়: '.$voucher->amount);
    }

    /** ৩ — পুরনো ফোন: `gross_amount` নেই, `amount` = নিট → আগের হুবহু খাতা */
    public function test_an_old_phone_without_the_gross_gets_the_same_books_as_before(): void
    {
        $this->accepted($this->pay(['gross_amount' => '1000', 'ait_amount' => '50', 'vds_amount' => '75', 'charge_amount' => '25', 'instrument_no' => 'GRS-NEW-1']), 'নতুন ফোনের মোট');
        $new = $this->book($this->lastPayment());

        $this->accepted($this->pay(['amount' => '875', 'ait_amount' => '50', 'vds_amount' => '75', 'charge_amount' => '25', 'instrument_no' => 'GRS-OLD-1'], withGross: false), 'পুরনো ফোনের নিট');
        $old = $this->book($this->lastPayment());

        $this->assertEquals($new, $old, '⛔ মোট ছাড়া পুরনো পথ (নিট) আর মোটের পথ একই খাতা বলে না');
    }

    /** ৪ — চুক্তি §৪-এর নিয়ম, নিজের ঘরে নিজের কথায় */
    public function test_the_gross_rules_refuse_in_their_own_field_and_words(): void
    {
        foreach ([
            'মোট নেই' => [['gross_amount' => ''], 'gross_amount', 'accounts::validation.gross_required', []],
            'কাটা ≥ মোট' => [['gross_amount' => '100', 'ait_amount' => '60', 'vds_amount' => '40'], 'gross_amount', 'accounts::validation.withheld_not_below_gross',
                ['gross' => '100.00', 'withheld' => '100.00']],
            'মোট আর নিট দুটোই' => [['gross_amount' => '100', 'amount' => '100'], 'amount', 'accounts::validation.gross_or_net_not_both', []],
        ] as $case => [$fields, $field, $key, $with]) {
            $before = Voucher::query()->count();
            $this->pay([...$fields, 'instrument_no' => 'GRS-NO-'.md5($case)]);
            $said = (string) (session('errors')?->getBag('default')->first($field) ?? '');

            $this->assertContains($said, [trans($key, $this->formatted($with), 'bn'), trans($key, $this->formatted($with), 'en')],
                "⛔ {$case}: থামেনি, বা {$field}-এর ঘরে নিজের কথায় নয়: ".$said);
            $this->assertSame($before, Voucher::query()->count(), "⛔ {$case}: থামা কাগজ লেখা হলো");
        }
    }

    /** ৫ — ফোনের preview: নিট আর খাতা = পরের পোস্টের; কিছুই লেখে না; ভুলে খসড়ার একই ঘর আর চাবিতে ৪২২ */
    public function test_the_phone_preview_tells_the_net_and_books_the_post_will_write_and_writes_nothing(): void
    {
        $payload = $this->payload(['gross_amount' => '1000', 'ait_amount' => '50', 'vds_amount' => '75', 'charge_amount' => '25', 'instrument_no' => 'GRS-PRV-1']);
        $counts = fn () => [Voucher::query()->count(), DB::table('mdm_people')->count(), DB::table('number_series')->sum('next_number')];
        $before = $counts();

        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $preview = $this->postJson('/api/v1/accounts/vouchers/preview', [...$payload, 'party_new' => 'নতুন মানুষ'])->assertOk()->json();
        $this->assertSame($before, $counts(), '⛔ preview কিছু লিখল — ভাউচার, মানুষ বা নম্বর');
        $this->assertSame('875.0000', $preview['net_amount'] ?? null, '⛔ preview-এর নিট সার্ভারের মোট − AIT − VDS নয়');
        $this->assertSame($preview['total_dr'] ?? null, $preview['total_cr'] ?? null, '⛔ preview-এর খাতা মেলে না');

        // ⓘ তালিকায় নেই এমন নাম — খসড়া হলে মানুষ বানাত; preview-এ নয় (উত্তর যা-ই হোক)
        $this->postJson('/api/v1/accounts/vouchers/preview', [...$payload, 'party_id' => '', 'party_new' => 'প্রিভিউর নতুন মানুষ']);
        $this->assertSame($before, $counts(), '⛔ preview হাতে লেখা নাম থেকে মানুষ বানাল');

        $this->actingAs($this->owner);
        $this->accepted($this->pay($payload, raw: true), 'preview-এর পরের পোস্ট');
        $voucher = $this->lastPayment();

        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $page = $this->getJson('/api/v1/accounts/vouchers/'.$voucher->public_id)->assertOk()->json();
        $this->assertSame($preview['net_amount'], $page['net_amount'] ?? null, '⛔ preview-এর নিট পরের পোস্টের নিট নয়');
        $this->assertEqualsCanonicalizing($this->rows($preview['ledger'] ?? []), $this->rows($page['ledger'] ?? []),
            '⛔ preview-এর খাতা পরের পোস্টের খাতা নয়');

        $refused = $this->postJson('/api/v1/accounts/vouchers/preview', [...$payload, 'gross_amount' => ''])->assertStatus(422);
        $this->assertContains((string) $refused->json('errors.gross_amount.0'), [trans('accounts::validation.gross_required', [], 'bn'), trans('accounts::validation.gross_required', [], 'en')],
            '⛔ preview-এর ভুল খসড়ার একই ঘর আর চাবিতে নয়');
    }

    /** ৬ — setup বলে চুক্তির সংস্করণ */
    public function test_the_phone_setup_says_contract_two(): void
    {
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $this->assertSame(2, $this->getJson('/api/v1/accounts/vouchers/setup?type=payment')->assertOk()->json('voucher_contract'),
            '⛔ setup চুক্তির সংস্করণ ২ বলে না — নতুন ফোন বুঝবে না মোট পাঠানো যায়');
    }

    /** ৭ — ওয়েবের পরিশোধ ফর্ম মোট পাঠায়, রসিদ নয় */
    public function test_the_payment_form_sends_its_gross_and_the_receipt_its_amount(): void
    {
        $payment = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="gross_amount"/', $payment, '⛔ পরিশোধের ফর্ম মোট পাঠায় না');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="hidden"[^>]*name="amount"/', $payment, '⛔ পরিশোধের ফর্ম মোট আর নিট দুটোই পাঠায় — সার্ভার থামাবে');
        $said = array_filter(['bn', 'en'], fn (string $l) => str_contains($payment, e(trans('money.screen.gross', [], $l))));
        $this->assertNotEmpty($said, '⛔ পরিশোধের টাকার ঘরের নাম "মোট টাকা (৳)" নয়');

        $receipt = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="amount"/', $receipt, '⛔ রসিদের টাকার ঘর বদলে গেল');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @param  array<string, string>  $fields  @return array<string, mixed> */
    private function payload(array $fields): array
    {
        return [
            'type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'মোটের দাবি',
            'party_type' => 'supplier', 'party_id' => $this->supplier->id, 'from_account_id' => $this->mfs->id, 'instrument' => 'mfs',
            ...$fields,
        ];
    }

    /** @param  array<string, string>  $fields */
    private function pay(array $fields, bool $withGross = true, bool $raw = false): \Illuminate\Testing\TestResponse
    {
        $body = $raw ? $fields : $this->payload($fields);
        if (! $withGross) {
            unset($body['gross_amount']);
        }

        return $this->from(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::PAYMENT]), $body);
    }

    /** ⓘ ফেরত এল কি না — নিজের ⛔ কথায় (Laravel-এর সাধারণ বার্তায় নয়), কোন ঘরে কী বলল সহ */
    private function accepted(\Illuminate\Testing\TestResponse $response, string $case): void
    {
        $said = session('errors')?->getBag('default')->toArray() ?? [];
        $this->assertSame([], $said, "⛔ {$case}: মোট টাকার পরিশোধ ফিরে এল: ".json_encode($said, JSON_UNESCAPED_UNICODE));
    }

    private function lastPayment(): Voucher
    {
        $voucher = Voucher::query()->where('type', Voucher::PAYMENT)->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, 'দৃশ্যটাই ঠিক নয় — পরিশোধ খাতায় বসেনি: '.$voucher->status);

        return $voucher;
    }

    /** @return array<int, array{debit: string, credit: string}> */
    private function book(Voucher $voucher): array
    {
        $book = [];
        LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])->where('source_id', $voucher->id)->get()
            ->each(function (LedgerEntry $e) use (&$book): void {
                $id = (int) $e->account_id;
                $book[$id] ??= ['debit' => '0.0000', 'credit' => '0.0000'];
                $book[$id]['debit'] = bcadd($book[$id]['debit'], (string) $e->debit, 4);
                $book[$id]['credit'] = bcadd($book[$id]['credit'], (string) $e->credit, 4);
            });
        ksort($book);

        return $book;
    }

    /** @param  array<int, array{debit: string, credit: string}>  $book */
    private function moved(array $book, ?Account $account, string $debit = '0', string $credit = '0', string $why = ''): void
    {
        $this->assertNotNull($account, 'দৃশ্যটাই ঠিক নয় — খাত নেই');
        $row = $book[(int) $account->id] ?? null;
        $this->assertNotNull($row, $why.' — খাত '.$account->code.' নড়েইনি: '.json_encode($book));
        $this->assertSame(0, bccomp($row['debit'], $debit, 4), $why." — {$account->code} ডেবিট {$row['debit']}, চাওয়া {$debit}");
        $this->assertSame(0, bccomp($row['credit'], $credit, 4), $why." — {$account->code} ক্রেডিট {$row['credit']}, চাওয়া {$credit}");
    }

    /** @param  list<array<string, mixed>>  $ledger  @return list<string> */
    private function rows(array $ledger): array
    {
        return array_map(fn (array $r) => ($r['account']['id'] ?? '').'|'.($r['dr'] ?? '').'|'.($r['cr'] ?? ''), $ledger);
    }

    /** @param  array<string, string>  $with  @return array<string, string> */
    private function formatted(array $with): array
    {
        return array_map(fn (string $v) => \App\Core\Support\Money::format($v), $with);
    }
}
