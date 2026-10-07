<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Security\LedgerChain;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ পুরনো হাতধারের খতিয়ান-সারিতে নাম বসানো — `abos:hand-loan-party` (সমন্বয়কের আদেশ, ৫ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবি (সমন্বয়কের তিন শর্ত):
 *   `--apply` ছাড়া কিছুই লেখে না — কেবল কয়টা, কত, কার নামে;
 *   চালালে পুরনো সারি থাকে (UPDATE নয়), উল্টো সারি আর নামসহ নতুন সারি বসে, একই তারিখ আর নম্বরে;
 *   আগে-পরে প্রতিটা মানুষের "হাতধারে বাকি" হুবহু এক, হাতধার খাতের মোট জের এক; "অন্য খাতে" আর ওঠে না;
 *   দ্বিতীয়বার চালালে কিছুই ধরে না।
 */
final class OldHandLoanRowsGetTheirPersonTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private int $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $this->putMoneyIn(Account::query()->findOrFail($this->till), '50000', now()->subDays(20)->toDateString());
    }

    public function test_a_dry_run_writes_nothing_and_the_apply_keeps_every_balance_and_ends_the_elsewhere_line(): void
    {
        $karim = $this->person('Karim Old');
        $rahim = $this->person('Rahim Old');
        $a = app(HandLoanService::class)->open(['person_id' => $karim->id]);
        $b = app(HandLoanService::class)->open(['person_id' => $rahim->id]);
        $old1 = $this->move($a, HandLoanMovement::OUT, '5000', 10);
        $old2 = $this->move($a, HandLoanMovement::IN, '1500', 6);
        $old3 = $this->move($b, HandLoanMovement::IN, '2000', 4);

        // ⓘ ৮c৬b২১৯৫-এর আগের সারি — হাতধার খাতে নাম নেই (পরীক্ষার প্রস্তুতি, কেবল এখানে)
        DB::table('ledger_entries')->whereIn('source_id', [$old1->voucher_id, $old2->voucher_id, $old3->voucher_id])
            ->whereIn('source_type', array_values(Voucher::SOURCE_TYPES))
            ->update(['party_type' => null, 'party_id' => null]);
        // ⓘ প্রস্তুতির সরাসরি বদলের পরে শিকল নতুন করে সিল — যাতে নিচে দেখা যায় কমান্ড নিজে শিকল ভাঙে না
        LedgerChain::reseal((int) CompanyContext::id());
        $this->assertTrue(LedgerChain::verify((int) CompanyContext::id())['ok'], 'প্রস্তুতিটাই ভুল — শিকল ভাঙা।');

        $before = $this->snapshot([$karim, $rahim]);
        $this->assertTrue($before['rows'][$karim->id]['differs'], 'প্রস্তুতিটাই ভুল — পুরনো হাতধারে "অন্য খাতে" ওঠার কথা।');
        $rows = LedgerEntry::query()->count();

        // ── শুকনো চালানো
        $this->artisan('abos:hand-loan-party')
            ->expectsOutputToContain('TDEPOT: 3টা ভাউচার, মোট 8500.00')
            ->expectsOutputToContain('Karim Old — 6500.00')
            ->expectsOutputToContain('Rahim Old — 2000.00')
            ->assertSuccessful();
        $this->assertSame($rows, LedgerEntry::query()->count(), '⛔ --apply ছাড়াই খতিয়ানে কিছু লেখা হলো।');

        // ── সত্যিই চালানো
        $this->artisan('abos:hand-loan-party --apply')->expectsOutputToContain('3টা ভাউচারে নাম বসেছে।')->assertSuccessful();
        $this->assertSame($rows + 2 * 6, LedgerEntry::query()->count(), 'প্রতিটা ভাউচারে দুই উল্টো আর দুই নতুন সারি — পুরনোগুলো মোছা নয়।');

        $this->assertTrue(LedgerChain::verify((int) CompanyContext::id())['ok'],
            '⛔ নাম বসাতে গিয়ে খতিয়ানের হ্যাশ-শিকল ভাঙল — UPDATE নয়, উল্টো আর নতুন সারি হওয়ার কথা।');

        $after = $this->snapshot([$karim, $rahim]);
        foreach ([$karim, $rahim] as $p) {
            $this->assertSame($before['rows'][$p->id]['balance'], $after['rows'][$p->id]['balance'], '⛔ নাম বসাতে গিয়ে হাতধারের বাকি বদলে গেল।');
            $this->assertFalse($after['rows'][$p->id]['differs'], '⛔ নাম বসানোর পরেও "অন্য খাতে" রইল — '
                .$p->name_en.': বাকি '.$after['rows'][$p->id]['balance'].', মোট '.$after['rows'][$p->id]['books']);
        }
        $this->assertSame($before['head'], $after['head'], '⛔ হাতধার খাতের মোট জের বদলে গেল।');

        $new = LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])->where('source_id', $old1->voucher_id)
            ->latest('id')->firstOrFail();
        $first = LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])->where('source_id', $old1->voucher_id)
            ->oldest('id')->firstOrFail();
        $this->assertSame($first->trx_date->toDateString(), $new->trx_date->toDateString(), 'নতুন সারি একই তারিখে।');
        $this->assertSame($first->document_no, $new->document_no, 'নতুন সারি একই নম্বরে।');

        // ⓘ দ্বিতীয়বার কিছুই ধরে না
        $this->artisan('abos:hand-loan-party --apply')->expectsOutputToContain('নাম বসানোর মতো কোনো পুরনো হাতধারের ভাউচার নেই।');
        $this->assertSame($rows + 2 * 6, LedgerEntry::query()->count(), '⛔ একই ভাউচার দুবার ধরা হলো।');
    }

    /**
     * @param  list<Person>  $people
     * @return array{rows: array<int, array<string, mixed>>, head: string}
     */
    private function snapshot(array $people): array
    {
        $ids = array_map(fn (Person $p) => (int) $p->id, $people);
        $rows = collect(app(HandLoanService::class)->people()['rows'])
            ->filter(fn (array $r) => in_array((int) $r['person']->id, $ids, true))
            ->mapWithKeys(fn (array $r) => [(int) $r['person']->id => $r])->all();
        $heads = Account::query()->where('code', StandardChart::HAND_LOAN)->firstOrFail()->selfAndDescendants()->modelKeys();
        $head = (string) LedgerEntry::query()->whereIn('account_id', $heads)->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');

        return ['rows' => $rows, 'head' => bcadd($head, '0', 4)];
    }

    private function move(HandLoanAccount $account, string $direction, string $amount, int $daysAgo): HandLoanMovement
    {
        return app(HandLoanService::class)->move($account, [
            'direction' => $direction, 'amount' => $amount, 'money_account_id' => $this->till, 'moved_on' => now()->subDays($daysAgo)->toDateString(),
        ]);
    }

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'P-'.substr(md5($name), 0, 6), 'name_en' => $name, 'is_active' => true,
        ]);
    }
}
