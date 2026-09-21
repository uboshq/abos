<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * পর্দায় নতুন আগে, কাগজে ব্যাংকের খাতার মতো।
 *
 * ── ⭐ মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Transactions dekhar somoy ajker date sobar upore ... but print er
 * somoy ba printe dile bank er moto ledger dekhabe"*।
 *
 * ⓘ দুইটা আলাদা হওয়াই ঠিক: পর্দায় মানুষ দেখেন **শেষ কী হলো**, কাগজে
 * মেলান **শুরু থেকে**। ব্যাংকের স্টেটমেন্টও ঠিক তাই করে।
 *
 * ── ⚠️ আসল ঝুঁকিটা জেরের কলামে ─────────────────────────────────────
 * ⛔ কোয়েরিতে `orderByDesc` বসিয়ে দিলে চলমান জেরটা উল্টো দিক থেকে
 * গুনত, আর **প্রতিটা সারির জের মিথ্যা হত** — দেখতে ঠিক, যোগ করলে ভুল।
 * ⓘ তাই গোনা আগের মতোই পুরনো → নতুন, কেবল দেখানোর ক্রম উল্টানো।
 *
 * ⭐ নিচের সবচেয়ে দামি দাবিটা ক্রম মাপে না — মাপে **শেষ সারির জের
 * মোট প্রদেয়ের সমান কি না**। ওটাই ধরবে যদি কেউ কোয়েরিতে উল্টে দেয়।
 */
final class TheLedgerReadsNewestFirstButPrintsOldestFirstTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->supplier = Supplier::query()->firstOrFail();

        /*
         * ⛔ দাখিলা দুইটা পরীক্ষা নিজেই বানায়, আর এটাই এই ফাইলের সবচেয়ে
         * দামি লাইন।
         *
         * ⚠️ প্রথম চালে ডেমোর সরবরাহকারীর উপর ভরসা করা হয়েছিল, আর তিনটা
         * দাবি **এড়িয়ে গেল** — তাঁর কোনো লেনদেনই নেই। ⓘ "সত্যিই তাকায়"
         * দাবিটা লাল হয়ে সেটা ধরিয়ে দিয়েছে; নাহলে ফাইলটা সবুজ দেখাত আর
         * কিছুই মাপত না।
         *
         * ⓘ বিদ্যমান একটা দাখিলা নকল করা হয় — ঘর ধরে ধরে আন্দাজ করলে
         * পরের মাইগ্রেশনেই ভাঙত।
         */
        $sample = LedgerEntry::query()->firstOrFail();

        foreach ([['2026-03-01', '1000', '0'], ['2026-06-01', '0', '400']] as [$date, $debit, $credit]) {
            $row = $sample->replicate(['public_id']);

            $row->forceFill([
                'party_type' => Supplier::drillSourceType(),
                'party_id' => $this->supplier->id,
                'company_id' => $this->supplier->company_id,
                'trx_date' => $date,
                'debit' => $debit,
                'credit' => $credit,
                'document_no' => 'LED-'.$date,
            ])->save();
        }
    }

    public function test_the_screen_shows_the_newest_row_first(): void
    {
        $rows = $this->rows();

        if (count($rows) < 2) {
            $this->markTestSkipped('এই সরবরাহকারীর দুইটা লেনদেন নেই — ক্রম মাপা যায় না।');
        }

        $first = $rows->first()->trx_date;
        $last = $rows->last()->trx_date;

        $this->assertGreaterThanOrEqual($last, $first,
            '⛔ পর্দায় পুরনো সারিটা উপরে — মালিক চান আজকেরটা উপরে।');
    }

    public function test_the_print_order_is_the_bank_order(): void
    {
        $rows = $this->rows(['ledger' => 'asc']);

        if ($rows->count() < 2) {
            $this->markTestSkipped('এই সরবরাহকারীর দুইটা লেনদেন নেই।');
        }

        $this->assertLessThanOrEqual($rows->last()->trx_date, $rows->first()->trx_date,
            '⛔ কাগজের ক্রমে নতুনটা উপরে — ব্যাংকের খাতা পুরনো থেকে শুরু হয়।');
    }

    /**
     * ⭐ আর জেরের সংখ্যাগুলো সারির সাথেই থাকে — এটাই আসল দাবি।
     *
     * ⓘ কাগজের ক্রমে **শেষ সারির** চলমান জের গোটা প্রদেয়ের সমান হওয়ার
     * কথা। ⚠️ কেউ কোয়েরিতে `orderByDesc` বসিয়ে দিলে এই সংখ্যাটাই
     * প্রথমে ভাঙবে, আর ক্রমের দাবি দুইটা তখনো সবুজ থাকত।
     */
    public function test_the_running_balance_still_adds_up(): void
    {
        $rows = $this->rows(['ledger' => 'asc']);

        if ($rows->isEmpty()) {
            $this->markTestSkipped('এই সরবরাহকারীর কোনো লেনদেন নেই।');
        }

        $this->assertSame(
            0,
            bccomp((string) $rows->last()->running_balance, (string) $this->supplier->payable(), 2),
            implode("\n", [
                '⛔ শেষ সারির চলমান জের মোট প্রদেয়ের সাথে মিলছে না।',
                '',
                '⚠️ জেরটা পুরনো → নতুন দিকে গোনা হয়। কেউ কোয়েরিতে ক্রম',
                'উল্টে দিলে প্রতিটা সারির সংখ্যা মিথ্যা হয়ে যায় — দেখতে ঠিক,',
                'যোগ করলে ভুল।',
            ]),
        );
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দাবিগুলো `markTestSkipped`-এ পালিয়ে যেতে পারত। ⚠️ তাই
     * এখানে মাপা হয় সারি সত্যিই এসেছে — নাহলে গোটা ফাইলটাই নামমাত্র।
     */
    public function test_there_really_are_rows_to_order(): void
    {
        $this->assertGreaterThan(0, $this->rows()->count(),
            'ডেমোর এই সরবরাহকারীর কোনো লেনদেন নেই — দাবিগুলো কিছুই প্রমাণ করে না।');
    }

    /** @return Collection<int, object> */
    private function rows(array $query = []): Collection
    {
        $response = $this->get(route('supplier.show', $this->supplier).($query ? '?'.http_build_query($query) : ''));

        $response->assertOk();

        return collect($response->viewData('entries')->items());
    }
}
