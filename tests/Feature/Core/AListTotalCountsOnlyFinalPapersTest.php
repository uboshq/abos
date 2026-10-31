<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Concerns\GrandTotals;
use App\Core\Contracts\ClassifiesDrafts;
use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ListSummary;
use App\Core\Support\SummaryShape;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * ⭐ তালিকার মোট শুধু পাকা কাগজ গোনে, আর প্রতিটা সারি ঠিক একটা দলে — [[ListSummary]] (fe-র নিয়ম; তালিকার মোটের চুক্তি v6,
 * a4-list-summary-contract-24-oct.md — ২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────────
 * ফোনের ভাউচারের মোট `status <> cancelled` গুনত — সইয়ের অপেক্ষার ৳৫০,০০০ আর খসড়াও মোটে ঢুকত; শাখার ট্রান্সফারের ওয়েব মোট
 * বাতিলও গুনত (a4-এর নিরীক্ষা)। প্রতিটা তালিকা নিজের মতো যোগ করত, তাই ওয়েব আর ফোন আলাদা সংখ্যা বলত।
 *
 * ⓘ এখানে Core-এর যন্ত্রটা মাপা হয় — ভাউচারের টেবিলে, একটা সরল পরীক্ষার ভাগকারী দিয়ে (নম্বর "P-" হলে অপেক্ষা, "R-" হলে ফেরানো,
 * বাকি খসড়া)। ভাউচারের আসল সই-সেবার ভাগ পরের কমিটে, নিজের দাবিতে।
 */
final class AListTotalCountsOnlyFinalPapersTest extends TestCase
{
    use RefreshDatabase;

    private int $yearId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->yearId = (int) FinancialYear::query()->where('company_id', $company->id)->value('id');
    }

    public function test_every_group_adds_up_to_the_whole_list(): void
    {
        $this->sample();

        $summary = ListSummary::of($this->list(), $this->shape(), ['from' => '2026-10-01', 'to' => '2026-10-24']);

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-24'], $summary['period'], '⛔ যে তারিখ খাটানো হলো, summary তা বলল না');
        $this->assertSame(['period', 'counted', 'open', 'pending', 'draft', 'rejected', 'cancelled'], array_keys($summary),
            '⛔ summary-র চাবি চুক্তির ক্রমে নয়, বা কোনো দল বাদ পড়েছে');
        $this->assertSame(['count' => 3, 'total' => '350.1234'], $summary['counted'], '⛔ মোটে পাকা কাগজ (confirmed, closed) ছাড়া অন্য কিছু ঢুকেছে');
        $this->assertSame(['count' => 1, 'total' => '50000.0000'], $summary['pending'], '⛔ সইয়ের অপেক্ষার ৳৫০,০০০ নিজের লাইনে এল না');
        $this->assertSame(['count' => 2, 'total' => '30.0001'], $summary['draft'], '⛔ খসড়া নিজের লাইনে এল না');
        $this->assertSame(['count' => 1, 'total' => '20.0000'], $summary['rejected'], '⛔ ফেরানো কাগজ নিজের লাইনে এল না');
        $this->assertSame(['count' => 1, 'total' => '70.0000'], $summary['cancelled'], '⛔ বাতিল নিজের লাইনে এল না');

        $whole = $this->list()->get(['amount']);
        $count = 0;
        $total = '0';

        foreach (ListSummary::GROUPS as $group) {
            $count += $summary[$group]['count'];
            $total = bcadd($total, $summary[$group]['total'], 4);
        }

        $this->assertSame($whole->count(), $count, '⛔ দলের গোনা মিলে পুরো তালিকা হলো না — কোনো সারি সব দলের বাইরে পড়েছে, বা দুই দলে');
        $this->assertSame(
            $whole->reduce(fn (string $sum, Voucher $v) => bcadd($sum, (string) $v->getRawOriginal('amount'), 4), '0'),
            bcadd($total, '0', 4),
            '⛔ দলের টাকা মিলে পুরো তালিকার টাকা হলো না (bcmath-এ মাপা)',
        );
    }

    public function test_the_counted_total_is_the_webs_grand_total(): void
    {
        $this->sample();

        $web = new class
        {
            use GrandTotals;

            /** @return array<string, string> */
            public function of(Builder $query): array
            {
                return $this->grandTotals($query, ['amount' => Voucher::countedAmountSql('t')]);
            }
        };

        $this->assertSame(
            bcadd($web->of($this->list())['amount'], '0', 4),
            ListSummary::of($this->list(), $this->shape())['counted']['total'],
            '⛔ ফোনের summary-র মোট আর ওয়েবের পাতার সর্বমোট আলাদা সংখ্যা বলল',
        );
    }

    public function test_a_long_amount_is_not_rounded_by_a_float(): void
    {
        $this->voucher('C-1', DocumentStatus::CONFIRMED, '12345678901234.5678');
        $this->voucher('C-2', DocumentStatus::CONFIRMED, '0.0001');

        $this->assertSame('12345678901234.5679', ListSummary::of($this->list(), $this->shape())['counted']['total'],
            '⛔ চৌদ্দ অঙ্কের টাকা গোল হয়ে গেল — যোগফল কোথাও float হয়ে গেছে');
    }

    public function test_a_column_that_is_not_decimal_is_refused(): void
    {
        $shape = new SummaryShape(Voucher::class, 'id', [DocumentStatus::CONFIRMED => ListSummary::COUNTED]);

        try {
            ListSummary::assertShape($shape);
        } catch (LogicException $refused) {
            $this->assertStringContainsString('vouchers.id DECIMAL নয়', $refused->getMessage(), '⛔ ভুল কলামে থামল, কিন্তু অন্য কারণে');

            return;
        }

        $this->fail('⛔ DECIMAL নয় এমন কলামে তালিকার মোট থামল না — DOUBLE বা FLOAT হলে যোগ গোল হত');
    }

    public function test_a_status_in_no_group_stops_the_total(): void
    {
        $this->sample();
        $shape = new SummaryShape(Voucher::class, 'amount', [
            DocumentStatus::DRAFT => ListSummary::CLASSIFY,
            DocumentStatus::CONFIRMED => ListSummary::COUNTED,
            DocumentStatus::CLOSED => ListSummary::COUNTED,
        ], classifier: NumberedDrafts::class);

        try {
            ListSummary::of($this->list(), $shape);
        } catch (LogicException $refused) {
            $this->assertStringContainsString("'cancelled' কোনো দলে নেই", $refused->getMessage(), '⛔ অচেনা status-এ থামল, কিন্তু অন্য কারণে');

            return;
        }

        $this->fail('⛔ কোনো দলে নেই এমন status (বাতিল) নীরবে বাদ পড়ল — মোট থামল না');
    }

    public function test_open_sits_inside_counted_and_adds_nothing(): void
    {
        $this->sample();
        $summary = ListSummary::of($this->list(), $this->shape());

        $this->assertSame(['count' => 1, 'total' => '50.0000'], $summary['open'], '⛔ open-এ counted-এর ভিতরের "খোলা" কাগজগুলো এল না');
        $this->assertLessThanOrEqual($summary['counted']['count'], $summary['open']['count'], '⛔ open-এর গোনা counted-এর চেয়ে বেশি');
        $this->assertSame(-1, bccomp($summary['open']['total'], $summary['counted']['total'], 4), '⛔ open-এর টাকা counted-কে ছাড়াল');

        try {
            ListSummary::assertShape(new SummaryShape(Voucher::class, 'amount', [
                DocumentStatus::CONFIRMED => ListSummary::COUNTED, DocumentStatus::DRAFT => ListSummary::DRAFT,
            ], open: [DocumentStatus::DRAFT]));
        } catch (LogicException $refused) {
            $this->assertStringContainsString('counted-এর ভিতরে নয়', $refused->getMessage(), '⛔ counted-এর বাইরের open-এ থামল, কিন্তু অন্য কারণে');

            return;
        }

        $this->fail('⛔ counted-এর বাইরের status-কে open বলা গেল — open তখন আলাদা দল হয়ে যেত');
    }

    public function test_the_questions_do_not_grow_with_the_drafts(): void
    {
        $this->sample();
        $few = $this->questions();

        foreach (range(1, 27) as $n) {
            $this->voucher('D-MORE-'.$n, DocumentStatus::DRAFT, '1');
        }

        $this->assertSame($few, $this->questions(), '⛔ খসড়া বাড়তেই প্রশ্ন বাড়ল — সারি ধরে প্রশ্ন (N+1)');
    }

    public function test_many_drafts_are_all_counted_and_only_logged(): void
    {
        $first = $this->voucher('D-0', DocumentStatus::DRAFT, '1');
        $row = (array) DB::table('vouchers')->where('id', $first->id)->first();
        unset($row['id']);

        // ⓘ সরাসরি সারি — ৫০০টা মডেল বানানো ধীর; নম্বর আর public_id নিজের নিজের (একক সূচি)
        $rows = [];

        foreach (range(1, ListSummary::MANY_DRAFTS) as $n) {
            $copy = ['document_no' => 'D-'.$n] + $row;

            if (array_key_exists('public_id', $row)) {
                $copy['public_id'] = (string) Str::ulid();
            }

            $rows[] = $copy;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('vouchers')->insert($chunk);
        }

        // ⓘ লগের ঘটনা শুনে — Log::spy()-এর পরের shouldHaveReceived larastan চেনে না (baseline-এ নতুন সারি লাগত)
        $warned = [];
        Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$warned) {
            if ($logged->level === 'warning' && str_contains($logged->message, 'অনেক খসড়া')) {
                $warned[] = $logged->context['drafts'] ?? null;
            }
        });

        $summary = ListSummary::of($this->list(), $this->shape());

        $this->assertSame(ListSummary::MANY_DRAFTS + 1, $summary['draft']['count'], '⛔ অনেক খসড়ায় মোট কেটে দেওয়া হলো — সব গোনা হয়নি');
        $this->assertSame(bcadd((string) (ListSummary::MANY_DRAFTS + 1), '0', 4), $summary['draft']['total'], '⛔ অনেক খসড়ার টাকা পুরোটা এল না');
        $this->assertSame([ListSummary::MANY_DRAFTS + 1], $warned, '⛔ অনেক খসড়ায় লগ একবার লেখা হলো না (খসড়ার সংখ্যাসহ)');
    }

    public function test_fingerprints_taken_together_are_the_same_as_one_by_one(): void
    {
        app(StandardChart::class)->install();
        $account = (int) Account::query()->where('company_id', CompanyContext::id())->where('is_group', false)->value('id');
        $vouchers = new Collection;

        foreach (['F-1' => '100', 'F-2' => '250.5', 'F-3' => '7'] as $no => $amount) {
            $voucher = $this->voucher($no, DocumentStatus::DRAFT, $amount);
            VoucherLine::query()->create(['voucher_id' => $voucher->id, 'account_id' => $account, 'debit' => $amount, 'credit' => 0]);
            VoucherLine::query()->create(['voucher_id' => $voucher->id, 'account_id' => $account, 'debit' => 0, 'credit' => $amount]);
            $vouchers->push($voucher->fresh());
        }

        // ⓘ হাতের কপিতে অসংরক্ষিত বদল — দুই পথেই ছাপ ডাটাবেজের সারির, হাতের নয়
        $vouchers[0]->narration = 'হাতে বদলানো, সংরক্ষিত নয়';

        $fingerprint = app(DocumentFingerprint::class);
        $together = $fingerprint->ofMany($vouchers);

        foreach ($vouchers as $voucher) {
            $this->assertSame($fingerprint->of($voucher), $together[$voucher->id] ?? null,
                "⛔ {$voucher->document_no}: একসাথে নেওয়া ছাপ আর একটা করে নেওয়া ছাপ আলাদা — সই-সেবা আর তালিকা দুই কথা বলত");
        }

        VoucherLine::query()->where('voucher_id', $vouchers[1]->id)->where('debit', '>', 0)->update(['debit' => '999']);

        $this->assertNotSame($together[$vouchers[1]->id], $fingerprint->ofMany($vouchers)[$vouchers[1]->id],
            '⛔ সারির টাকা বদলালেও একসাথে নেওয়া ছাপ বদলাল না — সারিগুলো ছাপে যায়নি');
    }

    /** সাতটা অবস্থার নমুনা — পাকা দুই রকম, বাতিল, আর তিন রকম খসড়া */
    private function sample(): void
    {
        $this->voucher('C-1', DocumentStatus::CONFIRMED, '100.1234');
        $this->voucher('C-2', DocumentStatus::CONFIRMED, '200');
        $this->voucher('C-3', DocumentStatus::CLOSED, '50');
        $this->voucher('X-1', DocumentStatus::CANCELLED, '70');
        $this->voucher('P-1', DocumentStatus::DRAFT, '50000');
        $this->voucher('R-1', DocumentStatus::DRAFT, '20');
        $this->voucher('D-1', DocumentStatus::DRAFT, '30');
        $this->voucher('D-2', DocumentStatus::DRAFT, '0.0001');
    }

    private function shape(): SummaryShape
    {
        return new SummaryShape(Voucher::class, 'amount', [
            DocumentStatus::DRAFT => ListSummary::CLASSIFY,
            DocumentStatus::CONFIRMED => ListSummary::COUNTED,
            DocumentStatus::CLOSED => ListSummary::COUNTED,
            DocumentStatus::CANCELLED => ListSummary::CANCELLED,
        ], open: [DocumentStatus::CLOSED], classifier: NumberedDrafts::class);
    }

    /** তালিকার নিজের কোয়েরির মতো — ক্রম আর ছাঁকনিসহ */
    private function list(): Builder
    {
        return Voucher::query()->where('company_id', CompanyContext::id())->orderByDesc('trx_date')->orderByDesc('id');
    }

    /** summary-র প্রশ্নের সংখ্যা — কেবল এই ডাকের সময়টুকু গোনা */
    private function questions(): int
    {
        $counting = true;
        $count = 0;
        DB::listen(function () use (&$count, &$counting) {
            $count += $counting ? 1 : 0;
        });

        ListSummary::of($this->list(), $this->shape());
        $counting = false;

        return $count;
    }

    private function voucher(string $no, string $status, string $amount): Voucher
    {
        return Voucher::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => $this->yearId,
            'type' => Voucher::JOURNAL,
            'document_no' => $no,
            'trx_date' => '2026-10-10',
            'amount' => $amount,
            'status' => $status,
        ]);
    }
}

/** ⓘ পরীক্ষার ভাগকারী — নম্বর দেখে: "P-" অপেক্ষা, "R-" ফেরানো, বাকি খসড়া; কোনো প্রশ্ন করে না */
final class NumberedDrafts implements ClassifiesDrafts
{
    public function groups(): array
    {
        return [ListSummary::PENDING, ListSummary::DRAFT, ListSummary::REJECTED];
    }

    public function standing(Collection $drafts): array
    {
        return $drafts->mapWithKeys(fn (Voucher $v) => [$v->id => match (true) {
            str_starts_with((string) $v->document_no, 'P-') => ListSummary::PENDING,
            str_starts_with((string) $v->document_no, 'R-') => ListSummary::REJECTED,
            default => ListSummary::DRAFT,
        }])->all();
    }
}
