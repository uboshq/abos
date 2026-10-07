<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\GroupLedgerService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ChecksTheFiveMatches;
use Tests\TestCase;

/**
 * গ্রুপের পাতা কেবল ভাউচারগুলো গুনত, আর বিক্রয়-ক্রয়-বেতন শূন্য দেখাত।
 *
 * ── ⛔ কী ঘটত, নিরীক্ষা §২ ────────────────────────────────────────────
 * [[GroupLedgerService]] যোগফল নিত `voucher_lines` থেকে। ⓘ কিন্তু
 * বিক্রয়, ক্রয় আর বেতন **ভাউচার দিয়ে যায় না** — ওরা সরাসরি পোস্টিং
 * ইঞ্জিন দিয়ে খতিয়ানে বসে ([[PostingEngine::post()]])।
 *
 * ⚠️ ফল: এক মালিকের সব কোম্পানি এক পাতায় দেখার জায়গাটায় আয় আর ব্যয়
 * **শূন্য** দেখাত, অথচ প্রতিটা কোম্পানির নিজের রিপোর্টে সংখ্যাগুলো
 * ঠিকই বসে ছিল।
 *
 * ⛔ আর শূন্য দেখতে ভাঙা লাগে না। ⚠️ দেখতে লাগে *"এই মাসে কিছু
 * হয়নি"* — তাই কেউ রিপোর্টও করে না, আর যে মালিক তিনটা কোম্পানি এক
 * পাতায় মেলাতে চান তিনি ভুল ছবিটার উপর সিদ্ধান্ত নেন।
 *
 * ── ⭐ কেন উৎস বদলে কিছু হারায় না ────────────────────────────────────
 * ভাউচারও একই পোস্টিং ইঞ্জিন দিয়েই খতিয়ানে বসে
 * ([[VoucherService::post()]])। ⓘ তাই `ledger_entries` **বেশি** ধরে,
 * কম নয় — আর নিচের প্রথম দাবিটা ঠিক সেটাই মাপে: ভাউচারের অঙ্কটা
 * উৎস বদলের পরেও অটুট।
 *
 * ── ⓘ প্রমাণের আকার ──────────────────────────────────────────────────
 * ⭐ পাঁচ মিল ([[ChecksTheFiveMatches]]) — খাতা নিজে ভারসাম্যে আছে কি না।
 * ⭐ আর গ্রুপের সংখ্যা = ঐ কোম্পানির **নিজের খতিয়ানের** সংখ্যা, সরাসরি
 *   `ledger_entries` থেকে গোনা — অ্যাপের কোনো রিপোর্ট-সেবা দিয়ে নয়।
 *   ⛔ সেবা দিয়ে মেলালে দুইটা ভুল একে অন্যকে ঢেকে দিত।
 */
final class TheGroupReportCountedOnlyTheVouchersTest extends TestCase
{
    use ChecksTheFiveMatches;
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->alpha->id, $this->alpha->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবি ───────────────────────────────────────────────────

    public function test_the_group_page_shows_what_the_companys_own_ledger_shows(): void
    {
        /*
         * ⓘ সিডার যা বসিয়েছে সেটাই যথেষ্ট — বিক্রয়, ক্রয় আর বেতনের
         * দাখিলা ওখানেই আছে, আর ওগুলোই আগে গ্রুপের পাতায় উধাও হত।
         */
        $group = $this->groupFigures();

        $this->assertArrayHasKey($this->alpha->id, $group,
            'মালিকের নিজের কোম্পানিটাই গ্রুপের পাতায় নেই।');

        foreach ($this->typesInTheBooks() as $type) {
            $this->assertSame(
                $this->ledgerNetFor($this->alpha->id, $type),
                $group[$this->alpha->id][$type] ?? '0.0000',
                "`{$type}` ধরনে গ্রুপের সংখ্যা কোম্পানির নিজের খতিয়ানের সাথে মেলে না।",
            );
        }
    }

    public function test_a_posting_that_never_went_through_a_voucher_reaches_the_group_page(): void
    {
        /*
         * ⭐ এটাই ভুলটার নিজের রূপ। বিক্রয়, ক্রয় আর বেতন ভাউচার
         * দিয়ে যায় না — সরাসরি পোস্টিং ইঞ্জিন দিয়ে খতিয়ানে বসে।
         *
         * ⛔ উৎস `voucher_lines` হলে এই অংকটা গ্রুপের পাতায় **কখনো**
         * পৌঁছাত না।
         *
         * ⓘ অংকটা এখানে নিজে বসানো, সিডারের উপর ছাড়া নয় — ⚠️ প্রথম
         * লেখায় *"আয় শূন্য নয়"* দাবি করেছিলাম আর লাল পেয়েছিলাম, কারণ
         * ডেমোর খাতায় আয়ের কোনো দাখিলাই নেই। ⓘ দাবিটা তখন কোডের
         * নয়, **ফিকশ্চারের** উপর দাঁড়িয়ে ছিল।
         */
        $before = $this->groupFigures()[$this->alpha->id]['income'] ?? '0';

        $this->postDirectly('income', '2500.0000', 'test:sale');

        $after = $this->groupFigures()[$this->alpha->id]['income'] ?? '0';

        $this->assertSame('2500.0000', bcsub($after, $before, 4), implode(PHP_EOL, [
            'ভাউচার ছাড়া বসানো আয়টা গ্রুপের পাতায় পৌঁছায়নি।',
            '',
            '⚠️ এই পথেই বিক্রয়, ক্রয় আর বেতন খাতায় বসে। এটা না পৌঁছালে',
            'মালিক গ্রুপের পাতায় শূন্য দেখেন, আর শূন্য দেখতে ভাঙা লাগে না —',
            'দেখতে লাগে "এই মাসে কিছু হয়নি"।',
        ]));
    }

    public function test_a_voucher_still_counts_after_the_source_changed(): void
    {
        /*
         * ⭐ পাল্টা-দাবি, আর এটাই সারাইটাকে নিরাপদ বলে: উৎস বদলে ভাউচার
         * **হারায়নি**। ⓘ ভাউচারও একই ইঞ্জিন দিয়ে খতিয়ানে বসে, তাই
         * নতুন উৎস বেশি ধরে, কম নয়।
         *
         * ⚠️ এটা ছাড়া সারাইটা এক জায়গায় সংখ্যা এনে অন্য জায়গায় হারাতে
         * পারত, আর উপরের মিলের দাবিটা তবু সবুজ থাকত — কারণ সে গ্রুপ আর
         * খতিয়ান দুইটাকেই মেলায়, দুইটাই একসাথে ভুল হলে ধরা পড়ত না।
         */
        $before = $this->groupFigures()[$this->alpha->id]['expense'] ?? '0.0000';

        $this->postDirectly('expense', '500.0000', 'test:voucherish');

        $after = $this->groupFigures()[$this->alpha->id]['expense'] ?? '0.0000';

        $this->assertSame('500.0000', bcsub($after, $before, 4),
            'ভাউচারের খরচটা গ্রুপের পাতায় পৌঁছায়নি — উৎস বদলে ভাউচার হারিয়েছে।');
    }

    public function test_the_books_balance(): void
    {
        /* ⭐ পাঁচ মিলের প্রথমটা — ডেবিট = ক্রেডিট, গোটা কোম্পানির খাতায় */
        $this->assertTheFiveMatches([]);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ⭐ দুই পাশে **একই জানালা**, আর সেটা মেপে শেখা।
     *
     * ⛔ প্রথমে `build(..., null, null)` ডেকেছিলাম আর খতিয়ান গুনেছিলাম
     * তারিখ ছাড়াই। ⚠️ কিন্তু [[GroupLedgerService::build()]]-এর ডিফল্ট
     * পরিসর **চলতি মাস**, আর সিডারের দাখিলা তার বাইরে — তাই লালটা
     * কোডের নয়, **আমার মাপের** ছিল: দুই পাশে দুই জানালা।
     */
    private function window(): array
    {
        return ['2000-01-01', '2100-12-31'];
    }

    /** @return array<int, array<string, string>> */
    private function groupFigures(): array
    {
        [$from, $to] = $this->window();

        $group = app(GroupLedgerService::class)->build($this->owner->fresh(), $from, $to);

        /*
         * ⓘ সেবাটা যে আকারে ফেরায় সেটাই ধরা হয়, পর্দার HTML নয় —
         * ⚠️ একটা হিসাবের পাতা সংখ্যায় ভরা, তাই লেখা খুঁজে দাবি করলে
         * সেটা অন্ধ হত।
         */
        /*
         * ⓘ `companies` একটা **তালিকা**, কোম্পানি-আইডি কী নয় — পড়ে
         * দেখা, ধরে নেওয়া নয়। প্রতিটা সারিতে `id` আর প্রতিটা ধরনের অংক।
         */
        $out = [];

        foreach ($group['companies'] ?? [] as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * এক কোম্পানির এক ধরনের নিট — **সরাসরি `ledger_entries` থেকে**।
     *
     * ⛔ অ্যাপের কোনো রিপোর্ট-সেবা দিয়ে নয়। ⚠️ সেবা দিয়ে মেলালে দুইটা
     * ভুল একে অন্যকে ঢেকে দিত, আর দাবিটা কেবল বলত *"দুই জায়গায় একই
     * কোড চলে"* — যেটা সত্যি হলেও অর্থহীন।
     */
    private function ledgerNetFor(int $companyId, string $type): string
    {
        $rows = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $companyId)
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', $this->window())
            ->groupBy('accounts.nature')
            ->selectRaw('accounts.nature,
                         COALESCE(SUM(ledger_entries.debit), 0) as debit,
                         COALESCE(SUM(ledger_entries.credit), 0) as credit')
            ->get();

        $net = '0';

        foreach ($rows as $row) {
            $net = bcadd($net, (string) $row->nature === 'debit'
                ? bcsub((string) $row->debit, (string) $row->credit, 4)
                : bcsub((string) $row->credit, (string) $row->debit, 4), 4);
        }

        return $net;
    }

    /** @return list<string> */
    private function typesInTheBooks(): array
    {
        return DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $this->alpha->id)
            ->whereBetween('ledger_entries.trx_date', $this->window())
            ->distinct()
            ->pluck('accounts.type')
            ->map(fn ($t) => (string) $t)
            ->all();
    }

    /**
     * একটা দাখিলা, সরাসরি পোস্টিং ইঞ্জিন দিয়ে — ভাউচার ছাড়াই।
     *
     * ⛔ খাতটা **postable** হতে হয়। ⓘ প্রথমে কোড ধরে প্রথম খাতটা
     * নিয়েছিলাম আর ইঞ্জিন থেমে গেছে: *"5000 একটা গ্রুপ, গ্রুপে
     * দাখিলা বসে না"*। ⭐ থামাটা সঠিক — গ্রুপে টাকা বসলে সেটা
     * খাতায় থাকত আর কোনো রিপোর্টে আসত না।
     */
    private function postDirectly(string $type, string $amount, string $sourceType): void
    {
        $credit = $this->postableAccount($type);
        $debit = $this->postableAccount($type === 'income' ? 'asset' : 'asset');

        $this->assertNotSame((int) $credit->id, (int) $debit->id,
            'একই খাতে দুই পাশ — দাখিলাটা কিছুই বলত না।');

        $lines = $type === 'income'
            ? [['account_id' => $debit->id, 'debit' => $amount, 'credit' => '0'],
               ['account_id' => $credit->id, 'debit' => '0', 'credit' => $amount]]
            : [['account_id' => $credit->id, 'debit' => $amount, 'credit' => '0'],
               ['account_id' => $debit->id, 'debit' => '0', 'credit' => $amount]];

        app(\App\Core\Engines\Posting\PostingEngine::class)->post(
            sourceType: $sourceType,
            sourceId: random_int(100000, 999999),
            trxDate: now()->toDateString(),
            lines: $lines,
        );
    }

    private function postableAccount(string $type): object
    {
        $row = DB::table('accounts')
            ->where('company_id', $this->alpha->id)
            ->where('type', $type)
            ->where('is_group', false)
            ->orderBy('code')
            ->first();

        $this->assertNotNull($row, "চার্টে `{$type}` ধরনের কোনো postable খাত নেই।");

        return $row;
    }
}
