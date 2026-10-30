<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Api\AuthController;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ গ্রাহকের পাতার "বাকি সীমা" = বাকি-নিয়ন্ত্রণের পাতার "অবশিষ্ট" = ফোনের বাকির পাতা — ফাঁক ৪৮ (fe-র বিক্রয়-ফাঁকের তালিকা, ২০ অক্টোবর ২০২৬)।
 *
 * ⛔ গ্রাহকের পাতা নিজে যোগ করত (সীমা − বকেয়া − আটকে থাকা − চেক), বাকির ইঞ্জিন আরেকবার। এখন পাতা ইঞ্জিনের অঙ্কই পড়ে
 * ([[CreditHolds::availableOf()]] → [[CreditExposure::positionOf()]])।
 *
 * ⓘ বিপজ্জনক উপাত্ত — চারটা ভাগই একসাথে: খাতার বকেয়া ১,০০,০০০ + বিল না হওয়া চালান ৩০,০০০ + খোলা আদেশ ২০,০০০ + ক্লিয়ার না
 * হওয়া চেক ৫,০০০; সীমা ৫,০০,০০০ → অবশিষ্ট ৩,৪৫,০০০। কেবল খাতা দেখলে ৪,০০,০০০, চেক ভুললে ৩,৫০,০০০।
 */
final class TheCustomerPageReadsTheCreditEnginesOwnRoomTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
        app(SettingsService::class)->set(PhoneModules::key('credit_control'), true);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_customer_page_the_credit_page_and_the_phone_show_one_room(): void
    {
        $c = $this->customer('G48-ROOM', '500000');
        $this->owes($c, '100000');
        $this->unclearedCheque($c, '5000');
        $this->owes($c, '5000'); // ⓘ চেকের জমা খাতায় ৫,০০০ কমিয়েছে — বকেয়া আবার ১,০০,০০০
        $this->unbilledChallan($c, '30000');
        $this->openOrder($c, '20000');

        // ⓘ উপাত্ত সত্যিই তাই — চারটা ভাগ আলাদা করে
        $p = app(CreditExposure::class)->positionOf($c->fresh());
        $this->assertSame(0, bccomp($p['due'], '100000', 4), 'বকেয়া: '.$p['due']);
        $this->assertSame(0, bccomp($p['held'], '50000', 4), 'চালান + আদেশ: '.$p['held']);
        $this->assertSame(0, bccomp($p['uncleared'], '5000', 4), 'চেক: '.$p['uncleared']);
        $this->assertSame(0, bccomp($p['available'], '345000', 4), 'অবশিষ্ট: '.$p['available']);

        $customerPage = $this->get(route('customer.show', $c))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-figure="available-limit">\s*([^<]*?)\s*</', $customerPage, $m), 'গ্রাহকের পাতায় বাকি সীমার ঘর নেই।');
        $onCustomerPage = $m[1];

        $creditPage = $this->get(route('credit_control.customer.show', $c->id))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-figure="available"[^>]*>\s*<div[^>]*>[^<]*<\/div>\s*<div[^>]*>\s*([^<]*?)\s*<\/div>/', $creditPage, $m),
            'বাকি-নিয়ন্ত্রণের পাতায় অবশিষ্টের ঘর নেই।');
        $onCreditPage = $m[1];

        $this->assertSame(Money::format('345000'), $onCreditPage, '⛔ বাকি-নিয়ন্ত্রণের পাতা দেয়ালের অঙ্ক নয়।');
        $this->assertSame($onCreditPage, $onCustomerPage, '⛔ গ্রাহকের পাতা '.$onCustomerPage.', বাকি-নিয়ন্ত্রণের পাতা '.$onCreditPage);

        // ⓘ ফোনের বাকির পাতাও একই অঙ্ক
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $phone = $this->getJson('/api/v1/credit-control/customers/'.$c->public_id)->assertOk()->json('position.available');
        $this->assertSame(0, bccomp((string) $phone, '345000', 4), '⛔ ফোন: '.$phone);
    }

    /** পাকা চালান, এক সারি, কোনো বিলে বাঁধা নয় */
    private function unbilledChallan(Customer $c, string $amount): void
    {
        $challan = DB::table('sal_challans')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'G48-DC',
            'customer_id' => $c->id, 'warehouse_id' => $this->warehouse(), 'trx_date' => now()->toDateString(),
            'total' => $amount, 'status' => DocumentStatus::CONFIRMED,
        ]);

        DB::table('sal_challan_lines')->insert([
            'delivery_challan_id' => $challan, 'product_id' => $this->product(), 'delivered_qty' => 10,
            'rate' => bcdiv($amount, '10', 4), 'amount' => $amount, 'line_no' => 1,
        ]);
    }

    /** নতুন ধারার (সীমা আটকানো) নিশ্চিত আদেশ, কিছুই যায়নি */
    private function openOrder(Customer $c, string $amount): void
    {
        $order = SalesOrder::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'G48-SO', 'customer_id' => $c->id, 'trx_date' => now()->toDateString(),
            'subtotal' => $amount, 'total' => $amount, 'status' => SalesOrderStatus::CONFIRMED,
            'hold_mode' => SalesOrderStatus::HOLD_HOLDS,
        ]);

        DB::table('sal_order_lines')->insert([
            'sales_order_id' => $order->id, 'product_id' => $this->product(), 'ordered_qty' => '10', 'rejected_qty' => '0',
            'rate' => bcdiv($amount, '10', 4), 'amount' => $amount, 'line_no' => 1, 'line_status' => SalesOrderStatus::LINE_OPEN,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(): int
    {
        return (int) Product::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->value('id');
    }

    private function warehouse(): int
    {
        return (int) Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->value('id');
    }
}
