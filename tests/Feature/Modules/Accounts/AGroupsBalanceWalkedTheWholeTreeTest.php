<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\LedgerBalances;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * একটা দলের জের পুরো গাছটা এক খাত এক কোয়েরিতে হাঁটত।
 *
 * ── ⛔ দুই জায়গায় দুই রকম ফল, আর একটাতেও কোনো লক্ষণ নেই ───────────────
 * [[Account::balanceOn()]] দলের বেলায় `$this->children` ধরে নিচে নামত।
 * ⓘ যে পর্দা আগে সব সন্তান তুলে রাখে ([[ChartOfAccountsController]]) তার
 * কিছু হত না — কিন্তু যারা সোজা `StandardChart::find('1100')?->balanceOn()`
 * বলে ([[CfoFigures]], [[AccountsFacts]], [[MoneyCustodyController]],
 * [[ProfitDistribution]]), তাদের কাছে সম্পর্কটা তোলা থাকত না।
 *
 * ⚠️ ফলে:
 *   • স্থানীয়ভাবে `preventLazyLoading` চালু, তাই `/finance/cfo` ৫০০।
 *   • লাইভে ওটা বন্ধ (`AppServiceProvider`: কেবল `local`), তাই পাতা ২০০ —
 *     শুধু ২০ খাতের সাবট্রিতে ~৪০টা কোয়েরি।
 * ⛔ অর্থাৎ লাইভে বাগটা কোথাও লাল হত না, আর হাঁটার রিপোর্টেও পাতাটা "ঠিক"
 * লেখা আছে। ⓘ সংখ্যাটাও ঠিকই ছিল — কেবল দামটা লুকানো।
 *
 * ── ⭐ সারাইটা টাকার নিয়ম ছোঁয় না, আর এই ফাইলের প্রথম দাবিটাই সেটা ──
 * ⓘ চিহ্ন বসে আগের জায়গাতেই, প্রতিটা পাতা-খাতের নিজের প্রকৃতি ধরে; কেবল
 * কাঁচা যোগফল একবারে তোলা হয়। ⚠️ তাই প্রথম দাবিটা নতুন উত্তরকে **পুরনো
 * অ্যালগরিদমের** সাথে মেলায়, প্রতিটা দল খাতে, তারিখসহ ও তারিখ ছাড়া —
 * "ঠিক মনে হচ্ছে" যথেষ্ট নয়।
 */
final class AGroupsBalanceWalkedTheWholeTreeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_every_group_reads_exactly_what_the_old_walk_read(): void
    {
        /*
         * ⓘ এই দাবিটা "প্রতিটা পাতা একবার"-ও পাহারা দেয়: পাতা
         * দুইবার গুনলে এটা আর `/finance/cfo`-এর দাবি — দুইটাই লাল হয়।
         *
         * ⚠️ তবে একটা ক্ষেত্র এই পথে কখনো দেখা যাবে না, আর সেটা
         * লুকানোর চেয়ে লিখে রাখা ভালো: **জের-শূন্য** একটা পাতা দুইবার
         * গুনলে যোগফল বদলায় না, তাই কোনো সংখ্যা-দাবি ওটা ধরতে পারে না।
         * ⓘ একটা মিউট্যান্ট ঠিক ওই কারণেই টিকে গিয়েছিল, আর ওটা দুর্বল
         * দাবির চিহ্ন নয় — ওটা অদৃশ্য আচরণ। ⭐ খরচেও নয়: নকল
         * পাতায় বাড়তি কোনো কোয়ারি হয় না।
         */
        $groups = Account::query()->where('is_group', true)->get();

        $this->assertGreaterThan(3, $groups->count(),
            'ডেমোতে দল খাতই নেই — তুলনাটা তখন কিছুই মাপে না।');

        $dates = [null, now()->toDateString(), now()->subYear()->toDateString()];

        $checked = 0;

        foreach ($groups as $group) {
            foreach ($dates as $upto) {
                /*
                 * ⚠️ পুরনো পথটা আগে, আর `preventLazyLoading` সরিয়ে —
                 * ⓘ পুরনো অ্যালগরিদমটা ঠিক ওই অলস লোডের উপরেই দাঁড়ানো
                 * ছিল, তাই ওটা বন্ধ রেখে তুলনা করা অসম্ভব।
                 */
                $was = $this->withLazyLoadingAllowed(
                    fn () => $this->theOldWalk($group->fresh(), $upto),
                );

                app(LedgerBalances::class)->forget();

                $now = Account::query()->findOrFail($group->id)->balanceOn($upto);

                $this->assertSame(0, bccomp($was, $now, 4),
                    $group->code.' খাতে '.($upto ?? 'সব সময়').' পর্যন্ত জের বদলে গেছে: '
                    .'আগে '.$was.', এখন '.$now.'।');

                $checked++;
            }
        }

        $this->assertSame($groups->count() * count($dates), $checked);
    }

    public function test_the_cost_no_longer_grows_with_the_tree(): void
    {
        /*
         * ⛔ এখানে শর্তটা "কম কোয়েরি" নয়, **অপরিবর্তিত** কোয়েরি।
         *
         * ⚠️ আর দুইটা দল যদি সমান বড় হয়, তুলনাটা কিছুই বলে না — তাই
         * আগে প্রমাণ করা হয় ওদের সাবট্রি সত্যিই আলাদা মাপের।
         */
        $groups = Account::query()->where('is_group', true)->get();

        $sizes = [];

        foreach ($groups as $group) {
            /*
             * ⛔ যে দলের নিচে একটাও পাতা-খাত নেই, তার যোগফলের কোয়েরিটাই
             * লাগে না — খরচ ১, বাকিদের ২। ⚠️ ওদের মিলিয়ে দেখলে দাবিটা
             * একটা **সঠিক** আচরণে লাল হত, আর পরের জন গিয়ে শর্তটা ঢিলে
             * করে দিত। ⓘ তাই তুলনাটা কেবল পাতাওয়ালা দলগুলোর মধ্যে।
             */
            $leaves = $this->withLazyLoadingAllowed(
                fn () => $this->leafIdsUnder($group->fresh()),
            );

            if ($leaves === []) {
                continue;
            }

            $sizes[$group->id] = count($leaves);
        }

        $this->assertGreaterThan(1, count($sizes),
            'পাতাওয়ালা দল একটার বেশি নেই, তাই তুলনা করার কিছু নেই।');

        arsort($sizes);

        $biggest = Account::query()->findOrFail((int) array_key_first($sizes));
        $smallest = Account::query()->findOrFail((int) array_key_last($sizes));

        $this->assertGreaterThan($sizes[$smallest->id], $sizes[$biggest->id],
            'সবচেয়ে বড় আর সবচেয়ে ছোট দল সমান মাপের, তাই এই দাবিটা '
            .'দুইটা এক জিনিস মেলাচ্ছে।');

        $small = $this->queriesFor($smallest);
        $large = $this->queriesFor($biggest);

        $this->assertSame($small, $large,
            $smallest->code.' ('.$sizes[$smallest->id].'টা পাতা) নিল '.$small
            .'টা কোয়েরি, আর '.$biggest->code.' ('.$sizes[$biggest->id]
            .'টা পাতা) নিল '.$large.'টা — খরচ এখনও গাছের মাপের সাথে বাড়ে।');

        $this->assertSame(2, $large,
            'দলটার জের '.$large.'টা কোয়েরি নেয়; ছকটা একবার আর যোগফলটা '
            .'একবার — দুইটাই যথেষ্ট, আর দুইটার বেশি মানে আবার গাছ হাঁটা।');
    }

    public function test_a_group_never_counts_another_companys_account(): void
    {
        /*
         * ⛔ পথটা এক কোয়েরিতে **কোম্পানির সব খাত** তোলে, তাই স্কোপটা
         * ধরল কি না সেটা এখন টাকার প্রশ্ন।
         *
         * ⓘ `company_id` হাতে লেখা হয়নি ([[BelongsToCompany]] বসায়), আর
         * ⚠️ "গ্লোবাল স্কোপ আছে" কথাটা কোনো পাহারা নয় — তাই অন্য
         * কোম্পানিতে সত্যিকারের টাকা বসিয়ে মাপা হয়।
         */
        $group = Account::query()->where('is_group', true)
            ->where('code', '1100')->firstOrFail();

        app(LedgerBalances::class)->forget();
        $before = $group->balanceOn();

        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->assertNotSame($this->company->id, $beta->id);

        CompanyContext::set($beta->id, $beta->defaultBranch()?->id);

        $theirs = Account::query()->where('is_group', false)->first();

        $this->assertNotNull($theirs,
            'অন্য কোম্পানিতে কোনো পাতা-খাত নেই, তাই এই দাবিটা ফাঁকা।');

        $year = FinancialYear::query()->where('company_id', $beta->id)->first();

        $this->assertNotNull($year,
            'অন্য কোম্পানির কোনো অর্থবছর নেই, আর খতিয়ানের সারিতে সেটা বাধ্যতামূলক।');

        LedgerEntry::query()->create([
            'company_id' => $beta->id,
            'branch_id' => $beta->defaultBranch()?->id,
            'financial_year_id' => $year->id,
            'account_id' => $theirs->id,
            'trx_date' => now()->toDateString(),
            'debit' => '7777.0000',
            'credit' => '0',
            'source_type' => 'probe',
            'source_id' => 0,
        ]);

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(LedgerBalances::class)->forget();

        $after = Account::query()->findOrFail($group->id)->balanceOn();

        $this->assertSame(0, bccomp($before, $after, 4),
            'অন্য কোম্পানির ৭৭৭৭ টাকা এই কোম্পানির দলে ঢুকে গেছে: '
            .'আগে '.$before.', পরে '.$after.'।');
    }

    public function test_the_cfo_page_opens_with_lazy_loading_refused(): void
    {
        /*
         * ⛔ পাতাটাই ছিল লক্ষণ: স্থানীয়ভাবে ৫০০।
         *
         * ── ⚠️ আর পরীক্ষা নিজে সেটা কখনো দেখত না ─────────────
         * [[AppServiceProvider]] `preventLazyLoading` কেবল `local`-এ চালু
         * করে, আর `phpunit.xml` বসায় `APP_ENV=testing` — ওই ফাইলেই লেখা
         * আছে পুরো স্যুট সবুজ হওয়ার দিন `'testing'` যোগ হবে, তার আগে নয়।
         *
         * ⛔ ফল: ব্রাউজারে পাতা ৫০০, আর দাবিটা দিব্যি ২০০ দেখে সবুজ।
         * ⓘ প্রথমে ঠিক সেটাই লিখেছিলাম, আর সারাইয়ের আগে রান করে ধরা
         * পড়েছে — যে দাবি বাগটা পুনরুত্পাদনই করতে পারে না সেটা দাবি নয়।
         *
         * ⭐ তাই পাহারাটা এখানে নিজে চালু করা হয় — হুবহু যে অবস্থায়
         * মালিকের ডেভ ব্রাউজার পাতাটা খোলে।
         */
        $this->actingAs($this->owner);

        $page = $this->withLazyLoadingRefused(
            fn () => $this->get('/finance/cfo')->assertOk()->getContent(),
        );

        app(LedgerBalances::class)->forget();

        $assets = Account::query()->where('code', '1100')->firstOrFail()->balanceOn();

        $this->assertSame(0, bccomp($assets, $this->fromTheLedgerUnder('1100'), 4),
            'চলতি সম্পদের সংখ্যাটা খতিয়ানের সাথে মিলছে না।');

        /* ⓘ পাতার নিজের রীতিতেই — লাখ-কোটির কমা, নিজের অনুমানে নয় */
        $printed = Money::format($assets);

        $this->assertStringContainsString($printed, $page,
            '/finance/cfo পাতায় চলতি সম্পদের সংখ্যাটা ('.$printed.') নেই — '
            .'দাবিটা তখন একটা খালি পাতাও মেনে নিত।');
    }


    public function test_the_subtree_query_carries_the_company(): void
    {
        /*
         * ⛔ এই দাবিটা একটা টিকে যাওয়া মিউট্যান্ট লিখিয়েছে।
         *
         * ⓘ আমি ধরে নিয়েছিলাম কোম্পানি-স্কোপ তুলে দিলে অন্য
         * কোম্পানির টাকা যোগফলে ঢুকে যাবে। ⚠️ মিউট্যান্টটা
         * (`withoutGlobalScope('company')`) বসিয়ে দেখা গেল পাঁচটা দাবিই
         * সবুজ — অর্থাৎ আমার ধারণাটাই ভুল ছিল।
         *
         * ── ⓘ কারণটা পড়ে দেখলে পরিষ্কার ────────────────────
         * [[Account::gather()]] এই দলের **আইডি** থেকে `parent_id` ধরে নিচে
         * নামে, আর অন্য কোম্পানির সারিগুলো তাদের নিজের আইডিতে
         * দেখায়। ⭐ তাই যোগফলের পৃথকতা আসে আইডির শিকল থেকে,
         * স্কোপ থেকে নয় — আর সেটাই উপরের দাবিটা মাপে।
         *
         * ── ⭐ তবে স্কোপটা অন্য দুইটা জিনিস রক্ষা করে ────────────
         * ⓘ এক, খরচ: স্কোপ ছাড়া কোয়ারিটা **প্রতিটা কোম্পানির** সব
         * খাত তোলে — দশটা কোম্পানির সার্ভারে দশগুণ। ⚠️ দুই,
         * টেন্যান্ট পৃথকতা অলংঘনীয় শর্ত ৪, আর ওটা "এই ক্ষেত্রে তো
         * কোনো ক্ষতি হয় না" যুক্তি মানে না।
         *
         * ⭐ তাই এখানে কোয়ারিটাই মাপা হয় — কারণ কোয়ারিটাই এখানে বিষয়।
         */
        $group = Account::query()->where('is_group', true)
            ->where('code', '1100')->firstOrFail();

        app(LedgerBalances::class)->forget();

        $fresh = Account::query()->findOrFail($group->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $fresh->balanceOn();

        $log = DB::getQueryLog();

        DB::disableQueryLog();

        $pool = null;

        foreach ($log as $entry) {
            if (str_contains((string) $entry['query'], 'from `accounts`')) {
                $pool = $entry;
            }
        }

        $this->assertNotNull($pool,
            'ছকের কোয়ারিটাই হয়নি — দাবিটা তখন কিছুই মাপত না।');

        $this->assertStringContainsString('`company_id`', (string) $pool['query'],
            'সাবট্রির কোয়ারিতে `company_id` নেই: '.$pool['query']);

        $this->assertContains($this->company->id, $pool['bindings'],
            'কোয়ারিতে `company_id` আছে, কিন্তু এই কোম্পানির আইডি দিয়ে নয়।');
    }


    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ⭐ পুরনো অ্যালগরিদম, হুবহু — সন্তান ধরে নিচে, প্রতিটা পাতায় নিজের যোগফল।
     *
     * ⚠️ এখানে [[LedgerBalances]] ছোঁয়া হয় না: ওটা নতুন পথের স্মৃতি, আর
     * সেটা দিয়ে মাপলে দুই পক্ষ একই জায়গা থেকে উত্তর পড়ত।
     */
    private function theOldWalk(Account $account, ?string $upto): string
    {
        if ($account->is_group) {
            return $account->children->reduce(
                fn (string $carry, Account $child) => bcadd(
                    $carry, $this->theOldWalk($child, $upto), 4,
                ),
                '0',
            );
        }

        $row = LedgerEntry::query()
            ->where('account_id', $account->id)
            ->when($upto, fn (Builder $q, string $date) => $q->where('trx_date', '<=', $date))
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $net = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);

        return $account->nature === Account::CREDIT ? bcmul($net, '-1', 4) : $net;
    }

    private function queriesFor(Account $group): int
    {
        app(LedgerBalances::class)->forget();

        $fresh = Account::query()->findOrFail($group->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $fresh->balanceOn();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    private function fromTheLedgerUnder(string $code): string
    {
        $group = Account::query()->where('code', $code)->firstOrFail();

        $ids = $this->withLazyLoadingAllowed(
            fn () => $this->leafIdsUnder($group->fresh()),
        );

        $total = '0';

        foreach ($ids as $id) {
            $account = Account::query()->findOrFail($id);

            $row = LedgerEntry::query()
                ->where('account_id', $id)
                ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
                ->first();

            $net = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);

            $total = bcadd(
                $total,
                $account->nature === Account::CREDIT ? bcmul($net, '-1', 4) : $net,
                4,
            );
        }

        return $total;
    }

    /** @return list<int> */
    private function leafIdsUnder(Account $account): array
    {
        if (! $account->is_group) {
            return [(int) $account->id];
        }

        $ids = [];

        foreach ($account->children as $child) {
            $ids = array_merge($ids, $this->leafIdsUnder($child));
        }

        return $ids;
    }

    /**
     * ⓘ সমন্বয়কারীর শর্ত: তুলনাটা অলস লোড খোলা রেখে, কারণ পুরনো পথটা
     * ঠিক ওটার উপরেই দাঁড়ানো ছিল। ⚠️ শেষে আবার আগের অবস্থায় ফেরানো হয়,
     * নাহলে এর পরের দাবিগুলো একটা ঢিলে ঘরে চলত।
     *
     * @template T
     *
     * @param  \Closure(): T  $what
     * @return T
     */
    private function withLazyLoadingRefused(\Closure $what)
    {
        $was = Model::preventsLazyLoading();

        Model::preventLazyLoading(true);

        try {
            return $what();
        } finally {
            Model::preventLazyLoading($was);
        }
    }

    /**
     * ⓘ অলস লোড খুলে — পুরনো অ্যালগরিদম ওটার উপরেই দাঁড়ানো রয়েছে।
     *
     * @template T
     *
     * @param  \Closure(): T  $what
     * @return T
     */
    private function withLazyLoadingAllowed(\Closure $what)
    {
        $was = Model::preventsLazyLoading();

        Model::preventLazyLoading(false);

        try {
            return $what();
        } finally {
            Model::preventLazyLoading($was);
        }
    }
}
