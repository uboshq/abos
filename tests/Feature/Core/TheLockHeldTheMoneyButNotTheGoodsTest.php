<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\OpenPeriod;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * তালাটা টাকা ধরে রেখেছিল, মাল নয়।
 *
 * ── কী পাওয়া গেল ────────────────────────────────────────────────────
 * [[OpenPeriod::assertOpen()]] ডাকা হয় ঠিক দুই জায়গায়, আর দুইটাই
 * [[PostingEngine]]-এর ভেতরে — অর্থাৎ **কেবল খতিয়ানে বসার পথে**।
 *
 * কিন্তু মালের একমাত্র দরজা [[StockService::move()]] তিনটা নিয়ম দেখে —
 * শূন্য চলাচল নয় · তাকে যা নেই তা যাবে না · ফ্রি ভাণ্ডারে যা নেই তা
 * যাবে না — **মাসের তালা দেখে না**, অথচ সে একটা `date:` প্যারামিটার
 * নেয় আর সেটাকে চলাচলের সারিতে বসিয়ে দেয়।
 *
 * বেশিরভাগ পথে এটা ধরা পড়ে না, কারণ বিল-ক্রয়-ফেরত সবই একই
 * `DB::transaction`-এ খতিয়ানেও যায়, আর তখন তালাটা সেখানেই ছোঁড়ে।
 * **যে পথগুলো খতিয়ানে যায় না, সেগুলোই খোলা:**
 *
 * ```
 * গুদাম বদল  (StockTransferService)  → খতিয়ানে যায় না  → তালা লাগে না
 * উৎপাদন     (ProductionService)     → খতিয়ানে যায় না  → তালা লাগে না
 * আটকানো/ছাড়া (StockService::hold)   → খতিয়ানে যায় না  → তালা লাগে না
 * ```
 *
 * ── কেন এটা গুরুতর ──────────────────────────────────────────────────
 * অগাস্ট বন্ধ করে রিপোর্ট পাঠানোর পরেও অগাস্টের তারিখে একটা গুদাম-বদল
 * ঢোকানো যায়। **খাতা স্থির থাকে, মাল নড়ে** — অগাস্টের স্টক রিপোর্ট আজ
 * আবার চালালে অন্য সংখ্যা আসে, আর কোথাও কিছু লাল হয় না।
 *
 * ── কেন আগের টেস্টগুলো ধরেনি ────────────────────────────────────────
 * [[TheLockOnThePastWasNeverRealTest]]-এ ১৩টা টেস্ট আছে, আর তেরোটাই
 * ভাউচার-পোস্টিং পথের। ওই ফাইলে `stock`, `transfer` বা `production`
 * শব্দ **একবারও নেই**। তালাটা যেখানে আছে সেখানেই পরীক্ষা হয়েছিল,
 * যেখানে নেই সেখানে নয়।
 *
 * ── এই ফাইলটার কাজ ──────────────────────────────────────────────────
 * এটা লেখা হয়েছে **লাল হওয়ার জন্য**। সবুজ হলে বুঝতে হবে তালাটা
 * মালের দরজাতেও পৌঁছেছে।
 */
class TheLockHeldTheMoneyButNotTheGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $from;

    private Warehouse $to;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->from = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->to = Warehouse::query()->where('id', '<>', $this->from->id)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /** একটা মাস বন্ধ — ঠিক যেভাবে মালিক করেন। */
    private function closeMonth(Carbon $month): PeriodLock
    {
        return PeriodLock::query()->create([
            'company_id' => $this->company->id,
            'year' => (int) $month->year,
            'month' => (int) $month->month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে',
            'locked_by' => auth()->id(),
            'locked_at' => now(),
        ]);
    }

    /**
     * বন্ধ মাসের তারিখে গুদাম-বদল রওনা দেওয়া যাবে না।
     *
     * `dispatch()`-ই আসল ঘটনা — `create()` কেবল খসড়া, কিছুই নড়ে না।
     */
    public function test_a_closed_month_refuses_a_warehouse_transfer(): void
    {
        $closed = Carbon::today()->subMonthNoOverflow();
        $this->closeMonth($closed);

        $this->expectException(ValidationException::class);

        $transfers = app(StockTransferService::class);

        $transfers->dispatch($transfers->create(
            [
                'from_warehouse_id' => $this->from->id,
                'to_warehouse_id' => $this->to->id,
                'trx_date' => $closed->copy()->startOfMonth()->addDays(10)->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '5']],
        ));
    }

    /**
     * দরজাটা নিজেই আটকায় — কে ডাকল তা নির্বিশেষে।
     *
     * এই একটা পরীক্ষা উৎপাদন, মাল আটকানো/ছাড়া, আর **ভবিষ্যতে লেখা
     * প্রতিটা নতুন ডাকনেওয়ালাকেও** ঢেকে দেয়, কারণ [[StockService::move()]]
     * মজুদে লেখার একমাত্র পথ। প্রতিটা সার্ভিসের জন্য আলাদা পরীক্ষা
     * লিখলে তালিকাটা সবসময় একটা পিছিয়ে থাকত — যে সার্ভিসটা কাল লেখা
     * হবে সেটার নাম আজ জানা নেই।
     */
    public function test_the_stock_door_itself_refuses_a_closed_month(): void
    {
        $closed = Carbon::today()->subMonthNoOverflow();
        $this->closeMonth($closed);

        $this->expectException(ValidationException::class);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->from,
            sourceType: StockService::ADJUSTMENT,
            sourceId: $this->product->id,
            floor: '1',
            date: $closed->copy()->startOfMonth()->addDays(10),
        );
    }

    /**
     * খোলা মাসে সবকিছু আগের মতোই নড়ে।
     *
     * এটা না থাকলে পাহারাটা "সবসময় ছোঁড়ে" হয়েও সবুজ থাকত, আর তখন
     * বাকি দুইটা পরীক্ষা কিছুই প্রমাণ করত না।
     */
    public function test_an_open_month_still_moves(): void
    {
        $this->closeMonth(Carbon::today()->subMonthsNoOverflow(3));

        $movement = app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->from,
            sourceType: StockService::ADJUSTMENT,
            sourceId: $this->product->id,
            floor: '1',
            date: Carbon::today(),
        );

        $this->assertSame(0, bccomp('1', (string) $movement->floor_change, 4));
    }

    /**
     * গত মাসের একটা বদল আজও বাতিল করা যায়।
     *
     * ── কেন এটাই সবচেয়ে জরুরি পরীক্ষা ───────────────────────────────
     * তালাটা `move()`-এ বসানোর সময় আসল ভয় ছিল এটাই: অগাস্ট বন্ধ করার
     * পর অগাস্টের একটা বদল আর **বাতিলই করা যাবে না**, অর্থাৎ ভুলটা
     * চিরকাল খাতায় থেকে যাবে।
     *
     * ভয়টা সত্যি হয়নি, আর কারণটা কাকতালীয় নয় — [[StockTransferService]]
     * উল্টো চলাচল লেখে `date: now()` দিয়ে, ঠিক যেমন [[PostingEngine]]
     * উল্টো এন্ট্রি আজকের তারিখে লেখে। এই পরীক্ষাটা ওই নিয়মটাকে
     * বেঁধে রাখে: কেউ কোনোদিন `date: $transfer->trx_date` করে দিলে
     * এখানে লাল হবে।
     *
     * ── প্রথমে যেভাবে লিখেছিলাম, আর কেন সেটা ভুল ছিল ─────────────────
     * প্রথম খসড়ায় বদলটা আজ বানিয়ে **আজকের মাসটাই** বন্ধ করেছিলাম, আর
     * বাতিল আটকে গিয়েছিল। কোডের দোষ নয়, গল্পের দোষ: চলতি মাস বন্ধ
     * মানে আজ কিছুই লেখা যাবে না — উল্টো এন্ট্রিও নয়। টাকার দিকেও
     * ঠিক তা-ই ঘটত। তাই গল্পটা বাস্তবের মতো করা হলো: **গত মাসে
     * পাঠানো, এই মাসে বন্ধ, আজ বাতিল।**
     */
    public function test_a_transfer_from_a_closed_month_can_still_be_cancelled_today(): void
    {
        $lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth()->addDays(10);

        // গত মাসে — তখন মাসটা খোলা ছিল
        $this->travelTo($lastMonth);

        $transfers = app(StockTransferService::class);

        $transfer = $transfers->dispatch($transfers->create(
            [
                'from_warehouse_id' => $this->from->id,
                'to_warehouse_id' => $this->to->id,
                'trx_date' => $lastMonth->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '5']],
        ));

        $this->travelBack();

        // মাস শেষ, রিপোর্ট পাঠানো হয়ে গেছে
        $this->closeMonth($lastMonth);

        // তবু ভুলটা শোধরানো যায় — উল্টো চলাচল আজকের তারিখে বসে
        $transfers->cancel($transfer, 'ভুল গুদামে পাঠানো হয়েছিল');

        $this->assertSame(
            Carbon::today()->toDateString(),
            Carbon::parse((string) StockMovement::query()
                ->where('source_type', StockTransfer::STOCK_SOURCE)
                ->orderByDesc('id')->value('trx_date'))->toDateString(),
            'বাতিলের উল্টো চলাচল বন্ধ মাসে নয়, আজকের তারিখে বসার কথা।',
        );
    }

    /**
     * তালাটা মাসপ্রতি একবার জিজ্ঞেস করে, সারিপ্রতি নয়।
     *
     * ── কেন এই পাহারাটা লাগে ────────────────────────────────────────
     * তালাটা আগে বসত [[PostingEngine]]-এ — **ডকুমেন্টপ্রতি একবার**।
     * আজ সেটা [[StockService::move()]]-এও বসেছে, যা ডাকা হয়
     * **সারিপ্রতি একবার**। ক্যাশ না থাকলে ৫০ সারির একটা বিলে ৫০+ বার
     * একই প্রশ্ন — অর্থাৎ একটা পাহারা বসাতে গিয়ে একটা N+1 রেখে যাওয়া।
     *
     * এই রিপো ৩১ আগস্ট ছয়টা N+1 সারিয়েছে। সাতটা নম্বর যোগ করা হলো না।
     */
    public function test_the_lock_asks_the_database_once_per_month_not_once_per_line(): void
    {
        $period = app(OpenPeriod::class);

        // প্রথম ডাকেই উত্তরটা জানা হয়ে যাবে
        $period->isOpen(Carbon::today());

        DB::enableQueryLog();

        for ($i = 0; $i < 20; $i++) {
            $period->isOpen(Carbon::today());
        }

        $asked = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'period_locks'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(0, $asked, "একই মাসের কথা ডাটাবেজকে আরও {$asked} বার জিজ্ঞেস করা হয়েছে।");
    }

    /**
     * তবু আলাদা মাস আলাদা উত্তর পায়।
     *
     * ক্যাশটা যদি তারিখ না দেখে একটাই উত্তর ধরে রাখত, তাহলে উপরের
     * পরীক্ষাটা সবুজ থাকত আর **তালাটা পুরোপুরি অকেজো হয়ে যেত** —
     * বন্ধ মাসের উত্তরও "খোলা" আসত।
     */
    public function test_each_month_still_gets_its_own_answer(): void
    {
        $closed = Carbon::today()->subMonthNoOverflow();
        $this->closeMonth($closed);

        $period = app(OpenPeriod::class);

        $this->assertFalse($period->isOpen($closed), 'বন্ধ মাসটা খোলা বলে ফেরত এল।');
        $this->assertTrue($period->isOpen(Carbon::today()), 'খোলা মাসটা বন্ধ বলে ফেরত এল।');
    }
}
