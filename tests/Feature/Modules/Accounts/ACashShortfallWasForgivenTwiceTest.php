<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashCountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা নগদ ঘাটতি দুইবার মাফ হত — অডিটের বাকি তালিকা (abos-2c), ১ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[CashCountService::approve()]] "আগেই অনুমোদিত কি না" দেখত হাতের কপি থেকে, লেনদেনের বাইরে। একই
 * গণনা দুই ট্যাবে খোলা থাকলে, বা বোতামে দুইবার চাপ পড়লে, দ্বিতীয় অনুরোধের কপিতে তখনো "খসড়া" — আর
 * পার্থক্যের সমন্বয় ভাউচার **দুইবার** খাতায় বসত: ১,০০০ টাকার ঘাটতি ২,০০০ টাকা হয়ে মুছত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লেনদেনের ভিতরে গণনার সারিতে তালা দিয়ে অবস্থা আবার ([[ReadsTheRowUnderLock]])।
 */
final class ACashShortfallWasForgivenTwiceTest extends TestCase
{
    use RefreshDatabase;

    private CashTill $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ মালিক — সুপার অ্যাডমিন; প্রথম অনুমোদনে তাঁকে কিছু আটকায় না
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->till = app(CashTillService::class)->ensurePrimaryTill();

        // খাতায় ১০,০০০ — [[CashCountTest]]-এর একই উপায়
        Account::query()->whereKey($this->till->account_id)->update(['opening_balance' => '10000', 'opening_date' => '2026-07-01']);
        app(OpeningBalanceService::class)->forAccount(Account::query()->findOrFail($this->till->account_id));
    }

    public function test_a_shortfall_approved_from_a_stale_copy_is_not_written_off_twice(): void
    {
        // ৯×১,০০০ গোনা, খাতায় ১০,০০০ — ১,০০০ ঘাটতি
        $count = app(CashCountService::class)->record(['cash_till_id' => $this->till->id, 'trx_date' => '2026-08-10'], [1000 => 9]);
        $stale = CashCount::query()->findOrFail($count->id);

        $approved = app(CashCountService::class)->approve($count);
        $this->assertNotNull($approved->adjustment_voucher_id, 'প্রস্তুতিটাই ভুল — ঘাটতির সমন্বয় ভাউচার হয়নি।');

        $said = null;

        try {
            app(CashCountService::class)->approve($stale);
        } catch (ValidationException $e) {
            $said = array_key_first($e->errors());
        }

        $this->assertSame('status', $said, '⛔ পুরনো কপিতে দ্বিতীয় অনুমোদন পরিষ্কার কথায় ফেরেনি।');
        $this->assertSame(1, Voucher::query()->whereKey($approved->adjustment_voucher_id)->count()
            + Voucher::query()->where('narration', 'like', '%'.$count->document_no.'%')->whereKeyNot($approved->adjustment_voucher_id)->count(),
            '⛔ ঘাটতির সমন্বয় ভাউচার দুইবার বসেছে।');
        $this->assertSame(0, bccomp('9000', $this->till->fresh()->balance(), 4), '⛔ টিলের জের ৯,০০০ নয় — ঘাটতি দুইবার মুছেছে: '.$this->till->fresh()->balance());
    }

    /**
     * ⛔ একই দিনে দুটো গণনা, দুটোই অনুমোদিত — অডিট গ৭, ৪ অক্টোবর ২০২৬।
     * দুটো গণনাই একই ১,০০০ ঘাটতি দেখে (খাতা তখনো বদলায়নি)। আগে দুটোই নিজের পুরনো পার্থক্যে সমন্বয় বসাত
     * — ঘাটতি ২,০০০ হয়ে খরচে উঠত, আর বাক্সের জের ৮,০০০, অথচ হাতে ৯,০০০।
     */
    public function test_two_counts_of_one_shortfall_write_it_off_once(): void
    {
        $service = app(CashCountService::class);

        $first = $service->record(['cash_till_id' => $this->till->id, 'trx_date' => '2026-08-10'], [1000 => 9]);
        $second = $service->record(['cash_till_id' => $this->till->id, 'trx_date' => '2026-08-10'], [1000 => 9]);
        $this->assertSame(0, bccomp('-1000', (string) $second->difference, 4), 'প্রস্তুতি ভুল — দ্বিতীয় গণনা ঘাটতি দেখছে না।');

        $service->approve($first);
        $done = $service->approve($second->fresh());

        $this->assertNull($done->adjustment_voucher_id, '⛔ দ্বিতীয় গণনা একই ঘাটতি আবার খরচে বসাল।');
        $this->assertSame(0, bccomp('0', (string) $done->difference, 4), 'দ্বিতীয় গণনার পার্থক্য এখন শূন্য হওয়ার কথা: '.$done->difference);
        $this->assertSame(0, bccomp('9000', $this->till->fresh()->balance(), 4),
            '⛔ বাক্সের জের ৯,০০০ নয়: '.$this->till->fresh()->balance());
    }
}
