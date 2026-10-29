<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ পথ ধরে বিক্রয় (`sales.by_channel`) — ক্রম নিট বিক্রিতে (ভ্যাট ও ফেরত বাদে), আর ফেরতের আগের ঘর নিজের নামে
 * (fe, নিট বিক্রি ২য় ভাগ ২, ২১ অক্টোবর ২০২৬)।
 *
 * দাবি, আসল দরজায় (`GET /sales/reports/by-channel`, হেডারে বিলের শাখা), দুইটা নতুন পথে যেখানে কেবল এই কাগজগুলো:
 *   · বিল ১,০০,০০০ + VAT, আংশিক ফেরত ১০,০০০ + VAT → `billed` ১,০০,০০০, `returns_value` ১০,০০০, `net_after_returns` ৯০,০০০ পয়সায়;
 *   · মোটের সারি = সারির যোগ; নাম "নিট বিক্রি (ভ্যাট ও ফেরত বাদে)", ফেরতের আগের ঘর "বিল (ভ্যাট বাদে, ফেরতের আগে)";
 *   · বেশি ফেরতের পথ (বিল ১,০০,০০০, নিট ৪০,০০০) কম বিলের পথের (৫০,০০০, নিট ৫০,০০০) নিচে।
 */
final class TheChannelReportRanksByNetSalesTest extends TestCase
{
    use NetSalesReportFixture;
    use RefreshDatabase;

    private SalesChannel $heavy;

    private SalesChannel $steady;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNetSales();

        $this->heavy = SalesChannel::query()->create(['code' => 'NS2-HEAVY', 'name_en' => 'NS2 Heavy', 'name_bn' => 'NS2 Heavy', 'is_active' => true]);
        $this->steady = SalesChannel::query()->create(['code' => 'NS2-STEADY', 'name_en' => 'NS2 Steady', 'name_bn' => 'NS2 Steady', 'is_active' => true]);
        Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->forceFill(['channel_id' => $this->heavy->id])->save();
        Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail()->forceFill(['channel_id' => $this->steady->id])->save();
    }

    public function test_a_channel_shows_bills_less_returns_without_vat_to_the_paisa(): void
    {
        [$bill, $return] = $this->billAndPartReturn('Rahim Traders');
        $this->assertSame($this->heavy->id, (int) $bill->channel_id, 'প্রস্তুতিটাই ভুল — বিলটা নতুন পথে নয়।');
        $this->assertSame($this->heavy->id, (int) $return->channel_id, 'প্রস্তুতিটাই ভুল — ফেরতটা বিলের পথে নয়।');

        $result = $this->page('sales.report.show', 'by-channel');
        $row = $this->channelRow($result, $this->heavy);

        $this->assertSame('100000.0000', $this->money($row['billed']), '⛔ ফেরতের আগের বিল ভ্যাট বাদে নয়।');
        $this->assertSame('10000.0000', $this->money($row['returns_value']), '⛔ ফেরত ভ্যাট বাদে নয়।');
        $this->assertSame('90000.0000', $this->money($row['net_after_returns']), '⛔ পথের নিট বিক্রি = বিল − ফেরত, ভ্যাট বাদে নয়।');
        $this->assertArrayNotHasKey('net_sales', $row, '⛔ ফেরতের আগের ঘর এখনো "net_sales" নামে — অথচ ওটা নিট নয়।');

        $this->assertTotalRowAddsUp($result, 'net_after_returns');
        $this->assertTotalRowAddsUp($result, 'billed');
        $this->assertSame('net_after_returns', $result->report->rankBy, '⛔ "সবচেয়ে বড় পথ" নিট বিক্রি ধরে নয়।');

        $this->web();
        $this->get(route('sales.report.show', ['slug' => 'by-channel']))->assertOk()->assertSee('বিল (ভ্যাট বাদে, ফেরতের আগে)');
    }

    public function test_the_channel_with_heavy_returns_ranks_below_the_one_that_kept_its_sales(): void
    {
        $this->billAndPartReturn('Rahim Traders', '10', '6');   // বিল ১,০০,০০০, নিট ৪০,০০০
        $this->bill('5', '10000', 'Niloy Store');               // বিল ৫০,০০০, নিট ৫০,০০০

        $result = $this->page('sales.report.show', 'by-channel');
        $heavy = $this->channelRow($result, $this->heavy);
        $steady = $this->channelRow($result, $this->steady);

        $this->assertSame(1, bccomp($this->money($heavy['billed']), $this->money($steady['billed']), 4), 'প্রস্তুতিটাই ভুল — বিলে ভারী পথ বড় নয়।');
        $this->assertSame(1, bccomp($this->money($steady['net_after_returns']), $this->money($heavy['net_after_returns']), 4), 'প্রস্তুতিটাই ভুল — নিটে স্থির পথ বড় নয়।');

        $ids = array_map(fn (array $r) => (int) ($r['channel_id'] ?? 0), $this->rowsOf($result));
        $this->assertLessThan(array_search($this->heavy->id, $ids, true), array_search($this->steady->id, $ids, true),
            '⛔ পথগুলো ফেরতের আগের বিল ধরে সাজানো — বেশি ফেরতের পথ উপরে।');
    }

    /** @return array<string, mixed> */
    private function channelRow(\App\Core\Engines\Report\ReportResult $result, SalesChannel $channel): array
    {
        $row = collect($this->rowsOf($result))->first(fn (array $r) => (int) ($r['channel_id'] ?? 0) === (int) $channel->id);
        $this->assertNotNull($row, "প্রস্তুতিটাই ভুল — রিপোর্টে {$channel->code} পথের সারি নেই।");

        return $row;
    }
}
