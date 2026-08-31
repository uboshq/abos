<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পথের টাকা — ড্রয়ার থেকে বেরিয়েছে, কেউ এখনো নেয়নি।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * হস্তান্তরের প্রথম ধাপে খতিয়ানে কিছুই বসত না। যুক্তি ছিল "গ্রহণ
 * নিশ্চিত না হওয়া পর্যন্ত টাকাটা দাতার" — দায়িত্বের দিক থেকে ঠিক,
 * ব্যালেন্সের দিক থেকে মিথ্যা।
 *
 * দুইটা আসল ক্ষতি:
 *
 *   ১. করিম দুপুরে ৳১২,০০০ প্রধান কাউন্টারে পাঠালেন। বিকেলে তাঁর টিলের
 *      নগদ গণনা হলো — ব্যবস্থা বলল ৳১২,০০০ কম আছে, আর **করিম দায়ী
 *      হলেন এমন টাকার জন্য যেটা তিনি হাতে হাতে দিয়ে দিয়েছেন**।
 *
 *   ২. একই ৳৫,০০০ একই মিনিটে সিন্দুকে ও ব্যাংকে পাঠানো যেত — দুইটাই
 *      সম্ভব দেখাত, কারণ ব্যালেন্স থেকে প্রথমটা কখনো বাদই যায়নি।
 */
class MoneyOnTheRoadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CashTill $from;

    private CashTill $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);

        app(StandardChart::class)->install();

        $tills = app(CashTillService::class);
        $this->from = $tills->ensurePrimaryTill();
        $this->to = $tills->create([
            'code' => 'SAFE',
            'name_en' => 'Office Safe',
            'name_bn' => 'অফিসের সিন্দুক',
        ]);

        $this->seedOpening($this->from, '20000');
    }

    /**
     * টিলে কিছু টাকা বসানো, যাতে পাঠানোর মতো কিছু থাকে।
     *
     * খাতের প্রারম্ভিক ব্যালেন্স দিয়ে, হাতে খতিয়ানের সারি বানিয়ে নয় —
     * ওভাবে লিখতে গিয়ে প্রথমবার `financial_year_id` বাদ পড়েছিল, আর
     * টেস্টটা কোডের দোষে নয়, নিজের দোষে ভেঙেছিল।
     */
    private function seedOpening(CashTill $till, string $amount): void
    {
        Account::query()->whereKey($till->account_id)->update([
            'opening_balance' => $amount,
            'opening_date' => '2026-07-01',
        ]);
        /*
         * কলামে বসানোই যথেষ্ট নয় — জেরটা খতিয়ানে বসাতে হয়।
         *
         * ── কেন এটা যোগ করতে হলো, ৩১ আগস্ট ২০২৬ ─────────────────────
         * খোলার জের এখন **খতিয়ানের প্রথম সারি**, আর
         * [[Account::balanceOn()]] কেবল খতিয়ান গোনে — `opening_balance`
         * কলামটা সে দেখেই না। কলামটা এখন কেবল **ঘোষণা**, ব্যালেন্স নয়।
         *
         * ওই বদলের পর এই সহায়কটা হালনাগাদ হয়নি, তাই টিলে টাকা বসত না
         * আর "হাতে আছে মাত্র ০.০০ টাকা" বলে সব হস্তান্তর আটকে যেত।
         * কোডের দোষ ছিল না; সহায়কটা বাসি ছিল।
         */
        app(OpeningBalanceService::class)->forAccount(
            Account::query()->findOrFail($till->account_id)
        );

    }

    private function service(): MoneyTransferService
    {
        return app(MoneyTransferService::class);
    }

    private function send(string $amount = '12000'): MoneyTransfer
    {
        return $this->service()->initiate([
            'from_till_id' => $this->from->id,
            'to_till_id' => $this->to->id,
            'amount' => $amount,
            'trx_date' => now()->toDateString(),
        ]);
    }

    private function transitBalance(): string
    {
        return StandardChart::find(StandardChart::CASH_IN_TRANSIT)->balanceOn();
    }

    // ── প্রথম পা ────────────────────────────────────────────────────

    /**
     * দেওয়ার সাথে সাথেই টাকাটা টিল থেকে বেরিয়ে যায়।
     *
     * এটাই পুরো কাজটার কারণ: হেফাজতকারীর ব্যালেন্স তাঁর ড্রয়ারের সাথে
     * মেলে, তাই নগদ গণনায় মিথ্যা ঘাটতি ওঠে না।
     */
    public function test_handing_money_over_takes_it_out_of_the_till_at_once(): void
    {
        $this->assertSame('20000.0000', $this->from->balance());

        $this->send('12000');

        $this->assertSame('8000.0000', $this->from->fresh()->balance(),
            'টাকাটা হাতে হাতে দেওয়ার পরেও টিলের ব্যালেন্সে রয়ে গেছে — গণনায় ঘাটতি দেখাবে।');
    }

    /** আর ওই টাকাটা "পথের টাকা" খাতে গিয়ে বসে — কারও হাতে নয়। */
    public function test_the_money_sits_in_transit_until_somebody_accepts_it(): void
    {
        $this->send('12000');

        $this->assertSame('12000.0000', $this->transitBalance());
        $this->assertSame('0.0000', $this->to->fresh()->balance(),
            'গ্রহণ করার আগেই টাকাটা গ্রহীতার হিসাবে চলে গেছে।');
    }

    // ── দ্বিতীয় পা ──────────────────────────────────────────────────

    public function test_accepting_moves_it_from_the_road_to_the_receiver(): void
    {
        $transfer = $this->send('12000');

        $this->service()->confirm($transfer);

        $this->assertSame('12000.0000', $this->to->fresh()->balance());
        $this->assertSame('0.0000', $this->transitBalance(),
            'গ্রহণের পরেও টাকাটা পথে রয়ে গেছে — কারও হাতে নেই, অথচ খাতায় আছে।');

        // দাতার টিল থেকে দুইবার বেরোয়নি
        $this->assertSame('8000.0000', $this->from->fresh()->balance(),
            'দাতার টিল থেকে টাকাটা দুইবার বেরিয়েছে।');
    }

    // ── যেটা এই কাজটা আটকায় ─────────────────────────────────────────

    /**
     * একই টাকা দুইবার পাঠানো যায় না।
     *
     * Ava-র নিজের নোটে এই ফাঁদটার কথা লেখা আছে: "কেউ একই ৫,০০০ একই
     * মিনিটে সিন্দুকে আর ব্যাংকে পাঠাতে পারত, আর দুইটাই সম্ভব দেখাত।"
     * আগে ABOS-এও পারত, কারণ প্রথম পাঠানোয় ব্যালেন্স থেকে কিছুই যেত না।
     */
    public function test_the_same_money_cannot_be_sent_twice(): void
    {
        $this->send('20000');

        $this->expectException(ValidationException::class);

        $this->send('20000');
    }

    // ── বাতিল ───────────────────────────────────────────────────────

    /** গ্রহণের আগে বাতিল করলে টাকাটা দাতার টিলেই ফেরে। */
    public function test_cancelling_before_acceptance_puts_it_back_in_the_till(): void
    {
        $transfer = $this->send('12000');

        $this->service()->cancel($transfer, 'ভুল কাউন্টারে পাঠানো হয়েছিল');

        $this->assertSame('20000.0000', $this->from->fresh()->balance());
        $this->assertSame('0.0000', $this->transitBalance(),
            'বাতিলের পরেও টাকাটা পথে আটকে আছে — কেউ কোনোদিন খুঁজে পাবে না।');
    }

    /** গ্রহণের পরে বাতিল করলে দুইটা পা-ই ফেরে। */
    public function test_cancelling_after_acceptance_unwinds_both_legs(): void
    {
        $transfer = $this->send('12000');
        $this->service()->confirm($transfer);

        $this->service()->cancel($transfer->fresh(), 'গণনায় ধরা পড়েছে, টাকাটা আসেনি');

        $this->assertSame('20000.0000', $this->from->fresh()->balance());
        $this->assertSame('0.0000', $this->to->fresh()->balance());
        $this->assertSame('0.0000', $this->transitBalance());
    }

    // ── পথে থাকার দিনগুলো ───────────────────────────────────────────

    /**
     * মাঝের দিনগুলোয় টাকাটা পথেই থাকে।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * গ্রহণের পা-টাও **হস্তান্তরের তারিখে** বসত। ফলে ১০ তারিখে দেওয়া
     * আর ১৪ তারিখে পাওয়া টাকাটা ১১, ১২, ১৩ তারিখে কারও হিসাবেই থাকত
     * না — না দাতার, না গ্রহীতার, না পথের। "পথের টাকা" খাতটা কোনোদিন
     * কোনো তারিখে শূন্যের বেশি হত না, অথচ ওই খাতটার গোটা কাজই হলো
     * "টাকাটা এখন পথে" বলা।
     *
     * ওই ভুলে এই পরীক্ষাটা পাস করত না: কালকের ব্যালেন্স শূন্য দেখাত।
     */
    public function test_the_money_stays_on_the_road_on_the_days_between(): void
    {
        $transfer = $this->service()->initiate([
            'from_till_id' => $this->from->id,
            'to_till_id' => $this->to->id,
            'amount' => '12000',
            'trx_date' => now()->subDays(4)->toDateString(),
        ]);

        $this->service()->confirm($transfer);

        $this->assertSame('12000.0000', StandardChart::find(StandardChart::CASH_IN_TRANSIT)
            ->balanceOn(now()->subDay()->toDateString()),
            'মাঝের দিনে টাকাটা পথে নেই — অথচ তখনো কেউ ওটা পায়নি।');

        $this->assertSame('0.0000', $this->transitBalance(),
            'গ্রহণের পরেও টাকাটা পথে রয়ে গেছে।');

        $this->assertSame('12000.0000', $this->to->fresh()->balance(),
            'গ্রহণের পরেও টাকাটা গ্রহীতার হিসাবে ওঠেনি।');

        $this->assertSame('0.0000', $this->to->fresh()
            ->balance(now()->subDay()->toDateString()),
            'পাওয়ার আগেই টাকাটা গ্রহীতার হিসাবে বসে গেছে।');
    }

    /**
     * বন্ধ মাস টাকাটাকে পথে আটকে রাখতে পারে না।
     *
     * ── কেন এটা নিছক তারিখের খুঁটিনাটি নয় ──────────────────────────
     * হিসাবরক্ষক ভ্যাটের জন্য জুন বন্ধ করলে জুনের হস্তান্তর গ্রহীতা আর
     * **কোনোদিন** "পেলাম" বলতে পারতেন না — পোস্টিং জুনের তারিখ চাইত,
     * আর তালাটা ওটা ফিরিয়ে দিত। টাকাটা পথের খাতে চিরকাল আটকে থাকত,
     * আর ওটা নামানোর কোনো পর্দাই নেই।
     *
     * ধরা পড়েছিল ঠিক এভাবেই: দাতা মালিক (পেছনের তারিখের ছাড় আছে),
     * গ্রহীতা হিসাবরক্ষক (নেই)।
     */
    public function test_a_closed_month_does_not_trap_the_money_on_the_road(): void
    {
        $handover = now()->subMonthNoOverflow();

        $transfer = $this->service()->initiate([
            'from_till_id' => $this->from->id,
            'to_till_id' => $this->to->id,
            'amount' => '12000',
            'trx_date' => $handover->toDateString(),
        ]);

        PeriodLock::query()->create([
            'company_id' => $this->company->id,
            'year' => (int) $handover->year,
            'month' => (int) $handover->month,
            'reason' => 'ভ্যাট দাখিল হয়ে গেছে',
            'locked_at' => now(),
        ]);

        $this->service()->confirm($transfer);

        $this->assertTrue($transfer->fresh()->isConfirmed(),
            'বন্ধ মাসে দেওয়া টাকা আজ আর গ্রহণ করা যাচ্ছে না — ওটা পথে আটকে গেল।');

        $this->assertSame('12000.0000', $this->to->fresh()->balance());
    }

    // ── খাতা মেলে ───────────────────────────────────────────────────

    /**
     * প্রতিটা ধাপের পরেই ডেবিট ও ক্রেডিট সমান।
     *
     * টাকার চলাচলে সবচেয়ে সহজ ভুল হলো এক পায়ে বসিয়ে আরেক পা ভুলে
     * যাওয়া। তখন রেওয়ামিল মেলে না, আর কারণটা খুঁজতে দিন যায়।
     */
    public function test_the_books_balance_at_every_step(): void
    {
        $transfer = $this->send('12000');
        $this->assertBalanced('পাঠানোর পর');

        $this->service()->confirm($transfer);
        $this->assertBalanced('গ্রহণের পর');

        $this->service()->cancel($transfer->fresh(), 'পরীক্ষা');
        $this->assertBalanced('বাতিলের পর');
    }

    private function assertBalanced(string $when): void
    {
        $row = LedgerEntry::query()
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $this->assertSame(0, bccomp((string) $row->d, (string) $row->c, 4),
            "{$when}: ডেবিট {$row->d}, ক্রেডিট {$row->c} — মিলছে না।");
    }
}
