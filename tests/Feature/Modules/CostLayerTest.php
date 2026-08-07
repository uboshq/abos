<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মালের দাম — যে চালানে ঢুকেছিল, সেই দামেই বেরোয়।
 *
 * ── কেন এই ফাইলটা আছে ───────────────────────────────────────────────
 * এতদিন বিক্রয়ের খরচের দর আসত পণ্য-মাস্টারে লেখা ক্রয়মূল্য থেকে, আর
 * মাল খতিয়ানে ঢুকত চালানের আসল দরে। জীবন্ত পর্দায় চালিয়ে ধরা পড়েছে:
 * ১,০০০ টাকার ১০ বস্তার ৪টা বেচে মজুদ খাত থেকে ১৩,৬০০ বেরিয়ে গেছে, আর
 * খাতটা ঋণাত্মক হয়ে বসেছিল — যা একটা গুদাম ধরে রাখতে পারে না।
 *
 * ৭৯৭টা টেস্ট তখন সবুজ ছিল, প্রতিটা জার্নালও ভারসাম্যে ছিল। ভুলটা
 * কোনো একটা নথির ভেতরে ছিল না, ছিল দুইটা নথির মাঝখানে — তাই এখানকার
 * প্রতিটা পরীক্ষা **ঢোকা আর বেরোনোর মিল** নিয়ে, একটা নথির নিজের
 * শুদ্ধতা নিয়ে নয়।
 *
 * বিস্তারিত: docs/Finding — Inventory is valued two different ways.md
 */
class CostLayerTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /*
         * খোলা মজুদের স্তরটা সরিয়ে নেওয়া হয়, প্রতিটা পরীক্ষার আগে।
         *
         * ── কেন ─────────────────────────────────────────────────────
         * সিডার এখন খোলা মজুদের সাথে দামও বসায় (১২০ বস্তা @ ৩,৪০০) —
         * সেটা ঠিক, কারণ তাকে থাকা মালের একটা দাম থাকেই। কিন্তু এই
         * ফাইলের পরীক্ষাগুলো FIFO-র ক্রম নিয়ে, আর তাতে শুরুর অবস্থাটা
         * জানা থাকতে হয়। না সরালে "১৫টা বেরোল, খরচ ১৬০০" মেলে না —
         * পুরনো সস্তা স্তরটাই আগে বেরোয়।
         *
         * স্তরটা মোছা হয় না, খরচ করা হয় — মোছা মানে ইতিহাস মুছে ফেলা,
         * আর এই ইঞ্জিনের পুরো কথাই হলো ইতিহাস থাকবে।
         */
        $onHand = $this->costs()->qtyOnHand($this->product);

        if (bccomp($onHand, '0', 4) > 0) {
            $this->costs()->issue($this->product, $onHand, 'test_opening_cleared', 1);
        }
    }

    /**
     * যেটা আগে ঢুকেছে সেটাই আগে বেরোয়, আর দামটা তার নিজের চালানের।
     *
     * এটাই মালিকের বেছে নেওয়া পদ্ধতি (FIFO), আর এটাই পুরো ফাইলের ভিত্তি।
     */
    public function test_the_oldest_consignment_leaves_first_at_its_own_price(): void
    {
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->receive($this->product, '10', '120', 'test_in', 2, 'IN-2', '2026-08-05');

        $result = $this->costs()->issue($this->product, '15', 'test_out', 1, 'OUT-1', '2026-08-07');

        // ১০ বস্তা পুরনো চালান থেকে, ৫ বস্তা নতুনটা থেকে
        $this->assertSame('1600.0000', $result['cost']);
        $this->assertCount(2, $result['uses']);
        $this->assertSame('100.0000', (string) $result['uses'][0]->unit_cost);
        $this->assertSame('120.0000', (string) $result['uses'][1]->unit_cost);
    }

    /**
     * তাকে যত মাল, খাতায় ততই — এক পয়সা বেশিও নয়, কমও নয়।
     *
     * এটাই আসল ভুলটার সরাসরি পাহারা। আগে ৬০০ টাকার মাল তাকে রেখে খাতা
     * বলত মজুদ ১২,৬০০ কমেছে।
     */
    public function test_what_stays_on_the_shelf_is_worth_what_was_paid_for_it(): void
    {
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->issue($this->product, '4', 'test_out', 1, 'OUT-1', '2026-08-07');

        $this->assertSame('6.0000', $this->costs()->qtyOnHand($this->product));
        $this->assertSame('600.0000', $this->costs()->valueOnHand($this->product));
    }

    /**
     * ফেরত আসে শেষে যেটা বেরিয়েছিল, তার দামে।
     *
     * ── কেন উল্টো ক্রমে ─────────────────────────────────────────────
     * পুরনো নিঃশেষ স্তরে ফেরালে সেটা আবার জ্যান্ত হয়ে উঠত, আর FIFO
     * মেনে পরের বিক্রয় ওখান থেকেই টানত — তাকে থাকত ১২০ টাকার মাল,
     * খাতায় বসত ১০০। ইঞ্জিনটা প্রথমবার চালিয়ে ঠিক এটাই ধরা পড়েছিল:
     * ১২০ টাকার ৩ বস্তা ফেরত এসে ৩০০ টাকা হয়ে গিয়েছিল।
     */
    public function test_a_return_comes_back_to_the_layer_it_last_left(): void
    {
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->receive($this->product, '10', '120', 'test_in', 2, 'IN-2', '2026-08-05');
        $this->costs()->issue($this->product, '15', 'test_out', 1, 'OUT-1', '2026-08-07');

        $value = $this->costs()->returnToLayers(
            $this->product, '3', 'test_out', 1, 'test_return', 1, 'RET-1', '2026-08-08',
        );

        $this->assertSame('360.0000', $value);

        // আর তাকের ৮ বস্তাই এখন ১২০ টাকার — পরের বিক্রয়ে সেটাই বসবে
        $this->assertSame('8.0000', $this->costs()->qtyOnHand($this->product));
        $this->assertSame('960.0000', $this->costs()->valueOnHand($this->product));

        $next = $this->costs()->issue($this->product, '8', 'test_out', 2, 'OUT-2', '2026-08-09');
        $this->assertSame('960.0000', $next['cost']);
    }

    /**
     * একই মাল দুইবার ফেরত দেওয়া যায় না।
     *
     * আগের ফেরতগুলো বাদ না দিলে একই চালান বারবার ফেরত দিয়ে গুদামে না
     * থাকা মাল খাতায় জমা করা যেত — আর প্রতিবার মজুদের মূল্য বাড়ত।
     */
    public function test_the_same_goods_cannot_come_back_twice(): void
    {
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->issue($this->product, '5', 'test_out', 1, 'OUT-1', '2026-08-07');

        $this->costs()->returnToLayers($this->product, '5', 'test_out', 1, 'test_return', 1, 'RET-1', '2026-08-08');

        $this->expectException(ValidationException::class);

        $this->costs()->returnToLayers($this->product, '1', 'test_out', 1, 'test_return', 2, 'RET-2', '2026-08-09');
    }

    /**
     * দাম জানা নেই এমন মাল বেরোতে পারে না।
     *
     * ── কেন থামা, ধরে নেওয়া নয় ──────────────────────────────────────
     * তাকে মাল আছে অথচ স্তরে নেই — এমন হয় কেবল যদি কোনো পথ দর ছাড়া
     * স্টক বাড়িয়ে থাকে। তখন একটা দর ধরে নিয়ে এগোলে ঠিক সেই ভুলটাই
     * ফিরে আসত যেটা সারাতে এই কোডটা লেখা। বার্তাটা তাই কী করতে হবে
     * বলে দেয়।
     */
    public function test_stock_with_no_known_cost_cannot_be_issued(): void
    {
        $this->costs()->receive($this->product, '2', '100', 'test_in', 1, 'IN-1', '2026-08-01');

        $this->expectException(ValidationException::class);

        $this->costs()->issue($this->product, '3', 'test_out', 1, 'OUT-1', '2026-08-07');
    }

    /**
     * ব্যর্থ টানে স্তর ছোঁয়া হয় না।
     *
     * অর্ধেক টেনে থেমে গেলে খাতায় মাল কমত অথচ কোনো নথি তৈরি হত না, আর
     * পার্থক্যটা কোথা থেকে এল তার উত্তর কেউ দিতে পারত না।
     */
    public function test_a_failed_issue_leaves_every_layer_untouched(): void
    {
        $this->costs()->receive($this->product, '2', '100', 'test_in', 1, 'IN-1', '2026-08-01');

        try {
            $this->costs()->issue($this->product, '3', 'test_out', 1, 'OUT-1', '2026-08-07');
        } catch (ValidationException) {
            // এখানে থামাটাই প্রত্যাশিত — দেখার বিষয় তার পরের অবস্থা
        }

        $this->assertSame('2.0000', $this->costs()->qtyOnHand($this->product));

        /*
         * প্রশ্নটা "কোনো টান আছে কি না" নয়, "এই ব্যর্থ টানটা কিছু লিখেছে
         * কি না" — তাই উৎস ধরে গোনা হয়।
         *
         * আগে পণ্যের সব টান গোনা হত আর শূন্য আশা করা হত। setUp এখন খোলা
         * মজুদ খরচ করে শুরু করে (সিডার এখন দামসহ মাল বসায়), তাই ওখানেই
         * দুইটা সারি থাকে — টেস্টটা ঠিকই ধরেছিল, শুধু ভুল প্রশ্ন করছিল।
         */
        $this->assertSame(0, CostLayerUse::query()
            ->where('source_type', 'test_out')
            ->where('source_id', 1)
            ->count());
    }

    /**
     * প্রতিটা খরচের অঙ্ক থেকে তার চালানে পৌঁছানো যায় — নিয়ম ১।
     *
     * "এই বিক্রয়ের ১,৬০০ টাকা খরচ কোথা থেকে এল" প্রশ্নের উত্তর সারি
     * ধরে ধরে থাকতে হবে, নইলে সংখ্যাটা কেউ যাচাই করতে পারত না।
     */
    public function test_every_cost_can_be_traced_back_to_its_consignment(): void
    {
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->receive($this->product, '10', '120', 'test_in', 2, 'IN-2', '2026-08-05');
        $this->costs()->issue($this->product, '15', 'test_out', 7, 'OUT-7', '2026-08-07');

        $uses = CostLayerUse::query()
            ->where('source_type', 'test_out')
            ->where('source_id', 7)
            ->with('layer')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $uses);
        $this->assertSame('IN-1', $uses[0]->layer->document_no);
        $this->assertSame('IN-2', $uses[1]->layer->document_no);
        $this->assertSame(
            '1600.0000',
            $uses->reduce(fn (string $sum, CostLayerUse $u) => bcadd($sum, (string) $u->amount, 4), '0'),
        );
    }

    /** নিঃশেষ স্তরও থেকে যায় — ছয় মাস পরেও পথটা খুঁজে পাওয়া যায়। */
    public function test_an_emptied_layer_is_kept_not_deleted(): void
    {
        $this->costs()->receive($this->product, '5', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->issue($this->product, '5', 'test_out', 1, 'OUT-1', '2026-08-07');

        $layer = CostLayer::query()->where('document_no', 'IN-1')->firstOrFail();

        $this->assertSame('0.0000', (string) $layer->qty_remaining);
        $this->assertSame('5.0000', (string) $layer->qty_in);
    }

    private function costs(): CostLayerService
    {
        return app(CostLayerService::class);
    }
}
