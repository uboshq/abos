<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Models\CreditDecision;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Support\CreditPaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ দেয়ালের সাক্ষ্য — প্রতিটা সিদ্ধান্তে দেয়াল কী দেখেছিল আর কী বলেছিল (বাকি নিয়ন্ত্রণ ধাপ ১.৫, ১১ অক্টোবর ২০২৬)।
 *
 *   · পাশ: সীমা ১০,০০০, বকেয়া ২,০০০, বিল ১,২০০ → একটাই "pass" সারি (তালাসহ দেয়ালের), অঙ্কসহ।
 *   · থামা: সীমা ১,০০০, বিল ১,২০০ → "stop · over_limit", আর বিল খসড়াই থাকে।
 *   · লেনদেন ফেরত গেলেও থামার সারি থাকে — নইলে "কেন আটকাল" ঠিক তখনই হারাত।
 *   · বাকি বন্ধ গ্রাহক → "stop · blocked"।
 *   · DO-র যাচাই — হ্যাঁ আর না দুইটাই।
 *   · কাউন্টারের ভিতরে → "counter"; পর্দার আগাম হিসাবে (কাগজ নেই) কোনো সারি নয়।
 *   · সারি বদলানো বা মোছা যায় না।
 */
final class TheWallWritesDownWhatItSawTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    public function test_a_bill_that_passes_leaves_one_pass_row_with_what_the_wall_saw(): void
    {
        $c = $this->customer('EV-PASS', '10000');
        $this->owes($c, '2000');
        $bill = $this->draftBill($c, $this->stockedProduct(), '10');

        app(SalesInvoiceService::class)->confirm($bill);

        $rows = CreditDecision::query()->where('customer_id', $c->id)->get();
        $this->assertCount(1, $rows, '⛔ একটা বিলে ঠিক একটা "হ্যাঁ" — তালাসহ দেয়ালের; পাওয়া গেল '.$rows->count());

        $row = $rows->first();
        $this->assertSame(['invoice', 'pass', 'within_limit'], [$row->stage, $row->result, $row->reason]);
        $this->assertSame((int) $bill->id, (int) $row->paper_id);
        $this->assertSame(0, bccomp((string) $row->credit_limit, '10000', 4));
        $this->assertSame(0, bccomp((string) $row->due, '2000', 4));
        $this->assertSame(0, bccomp((string) $row->adding, '1200', 4));
        $this->assertSame(0, bccomp((string) $row->exposure_after, '3200', 4), 'বকেয়া + আটকে + এই বিল − এখনকার জমা');
    }

    public function test_a_bill_over_the_limit_leaves_a_stop_row_and_stays_a_draft(): void
    {
        $c = $this->customer('EV-STOP', '1000');
        $bill = $this->draftBill($c, $this->stockedProduct(), '10');

        try {
            app(SalesInvoiceService::class)->confirm($bill);
            $this->fail('সীমা পেরোনো বিল নিশ্চিত হয়ে গেল।');
        } catch (ValidationException) {
        }

        $row = CreditDecision::query()->where('customer_id', $c->id)->sole();
        $this->assertSame(['stop', 'over_limit'], [$row->result, $row->reason]);
        $this->assertSame(0, bccomp((string) $row->adding, '1200', 4));
        $this->assertNotEmpty($row->message, 'যে কথাটা বিলের পর্দায় গিয়েছিল, সেটাই সাক্ষ্যে।');
        $this->assertSame('draft', $bill->fresh()->status);
    }

    public function test_the_stop_row_survives_the_rolled_back_transaction(): void
    {
        $c = $this->customer('EV-ROLL', '1000');
        $bill = $this->draftBill($c, $this->stockedProduct(), '10');

        try {
            DB::transaction(fn () => app(CreditExposure::class)->assertRoomLocked(
                customer: $c, adding: '1200', exceptInvoiceId: (int) $bill->id, paper: CreditPaper::of('invoice', $bill),
            ));
            $this->fail('দেয়াল থামায়নি।');
        } catch (ValidationException) {
        }

        $this->assertSame(1, CreditDecision::query()->where('customer_id', $c->id)->where('result', 'stop')->count(),
            '⛔ লেনদেন ফেরত যেতেই থামার সাক্ষ্যও মুছে গেছে।');
    }

    public function test_a_blocked_customer_is_stopped_with_the_reason_blocked(): void
    {
        $c = $this->customer('EV-BLK', '100000');
        app(CustomerService::class)->blockCredit($c, 'পরীক্ষা');
        $bill = $this->draftBill($c, $this->stockedProduct(), '1');

        try {
            app(SalesInvoiceService::class)->confirm($bill);
            $this->fail('বাকি বন্ধ গ্রাহকের বিল নিশ্চিত হয়ে গেল।');
        } catch (ValidationException) {
        }

        $this->assertSame('blocked', CreditDecision::query()->where('customer_id', $c->id)->sole()->reason);
    }

    public function test_the_delivery_order_check_writes_both_answers(): void
    {
        $c = $this->customer('EV-DO', '1000');
        $order = (new DeliveryOrder)->forceFill(['document_no' => 'DO-EV-1', 'branch_id' => $c->branch_id]);
        $credit = app(CreditExposure::class);

        $this->assertTrue($credit->check($c, '800', null, CreditPaper::of('delivery_order', $order))['fits']);
        $this->assertFalse($credit->check($c, '1800', null, CreditPaper::of('delivery_order', $order))['fits']);

        $rows = CreditDecision::query()->where('customer_id', $c->id)->orderBy('id')->get();
        $this->assertSame([['delivery_order', 'pass'], ['delivery_order', 'stop']],
            $rows->map(fn ($r) => [$r->stage, $r->result])->all());
        $this->assertSame('over_limit', $rows->last()->reason);
    }

    public function test_the_counter_is_named_and_a_preview_writes_nothing(): void
    {
        $c = $this->customer('EV-CTR', '10000');
        $bill = $this->draftBill($c, $this->stockedProduct(), '1');

        // ⓘ পর্দার আগাম হিসাব — কাগজ নেই, সিদ্ধান্ত নয়
        app(CreditExposure::class)->assertRoom($c, '120');
        $this->assertSame(0, CreditDecision::query()->where('customer_id', $c->id)->count());

        CreditExposure::atCounter(fn () => app(SalesInvoiceService::class)->confirm($bill));
        $this->assertSame('counter', CreditDecision::query()->where('customer_id', $c->id)->sole()->stage);
    }

    public function test_a_row_cannot_be_changed_or_removed(): void
    {
        $c = $this->customer('EV-RO', '1000');
        app(CreditExposure::class)->check($c, '1', null, CreditPaper::of('delivery_order', (new DeliveryOrder)->forceFill(['document_no' => 'X'])));
        $row = CreditDecision::query()->where('customer_id', $c->id)->sole();

        $this->expectException(\RuntimeException::class);
        $row->update(['result' => 'stop']);
    }
}
