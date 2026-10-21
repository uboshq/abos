<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ হোমের অনুমোদনের কার্ড — সারিতে ইনবক্সের ঘর (কাজের নাম, নম্বর · পক্ষ), আর `title`-এ সেই কথাই সাদা লেখায়।
 *
 * ⛔ দুই শাখা মেশানোয় (ওয়েবের মান ধাপ ২ + a5-এর "নম্বরের পাশে পক্ষ", ২১ অক্টোবর ২০২৬) তালিকার প্রথম ঘর লেখা থেকে HTML হলো
 * (`approval::inbox.partials.what`), অথচ হোম ঘরটা `title="…"`-এও বসাত — ট্যাগসুদ্ধ HTML গুণের ভেতরে পড়ে মার্কআপ ভাঙত।
 *
 * দাবি: অপেক্ষমাণ বিক্রয় বিল থাকলে হোমের কার্ডে সারি আছে, তার `title`-এ কোনো `<` নেই আর বিলের নম্বর আছে, আর লেখায় পক্ষের ঘর।
 */
final class TheHomeApprovalRowKeepsItsTagsOutOfTheTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_row_shows_the_inbox_cell_and_its_title_is_plain_text(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $product = Product::query()->whereNull('tax_id')->orderBy('id')->firstOrFail();
        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '2', 'rate' => '500']],
        );
        Approval::query()->create([
            'company_id' => $company->id, 'approvable_type' => $invoice::class, 'approvable_id' => $invoice->id,
            'module' => 'sales', 'action' => 'invoice', 'amount' => '1000', 'status' => Approval::PENDING, 'current_level' => 1,
            'requested_by' => $owner->id, 'requested_at' => now(),
        ]);
        $number = (string) $invoice->fresh()->document_no;
        $this->assertNotSame('', $number, 'প্রস্তুতিটাই ভুল — বিলের নম্বর নেই।');

        $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
        $start = strpos($html, 'data-home-listing');
        $this->assertNotFalse($start, 'প্রস্তুতিটাই ভুল — হোমে অনুমোদনের তালিকা নেই।');
        $end = strpos($html, '</section>', $start);
        $card = substr($html, $start, $end === false ? null : $end - $start);

        preg_match_all('/<span class="line-clamp-2 min-w-0[^"]*" title="([^"]*)">/', $card, $titles);
        $this->assertNotEmpty($titles[1], '⛔ হোমের কার্ডে কোনো সারি নেই, বা সারির গুণ ভেঙে গেছে।');
        $mine = array_values(array_filter($titles[1], fn (string $t) => str_contains(html_entity_decode($t), $number)));
        $this->assertNotEmpty($mine, "⛔ সারির title-এ বিলের নম্বর {$number} নেই।");
        foreach ($titles[1] as $title) {
            $this->assertStringNotContainsString('<', html_entity_decode($title), '⛔ title-এ ট্যাগ — HTML গুণের ভেতরে পড়েছে।');
        }
        $this->assertStringContainsString('data-party', $card, '⛔ সারির লেখায় ইনবক্সের পক্ষের ঘর নেই।');
    }
}
