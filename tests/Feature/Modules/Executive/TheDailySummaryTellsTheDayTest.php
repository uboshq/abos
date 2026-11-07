<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Notifications\DailySummaryMail;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ প্রতিদিনের সারাংশ — মালিক, ৭ নভেম্বর ২০২৬ (নমুনা আর A4 PDF "ok"; রাতের ইমেইল "ok"): আজ কত টাকার ডেলিভারি, কোন চালক কত
 * ভাড়া, কোন কাউন্টারে কত, কোন পণ্য অর্ডার দিতে হবে — এক পাতায়, PDF-এ, আর রাতে মালিকের ইমেইলে, সাথে PDF।
 */
final class TheDailySummaryTellsTheDayTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-09 15:00:00'));
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->forceFill(['credit_limit' => '1000000'])->save();

        $till = app(CashTillService::class)->create(['code' => 'CTR-D', 'name_en' => 'Daily till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '1000', '2026-11-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_page_tells_the_day_delivery_driver_fare_counter_and_reorder(): void
    {
        $this->sell();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $product->forceFill(['reorder_level' => '100000'])->save();

        $html = (string) $this->get(route('executive.daily_summary'))->assertOk()->getContent();

        foreach (['delivered', 'collected', 'fare', 'received', 'cash_left', 'reorder'] as $kpi) {
            $this->assertStringContainsString('data-kpi="'.$kpi.'"', $html, "⛔ মাথায় \"{$kpi}\"-এর বড় অঙ্ক নেই।");
        }
        $this->assertStringContainsString(\App\Core\Support\Money::format((string) $challan->total), $html, '⛔ আজকের ডেলিভারির টাকা পাতায় নেই।');
        $this->assertMatchesRegularExpression('/data-fare-row="paid".*?রহিম.*?01711000000/s', $html, '⛔ গাড়ি-ভাড়ার ঘরে চালকের নাম-মোবাইল আর "শোধ" নেই।');
        $this->assertStringContainsString('Daily till', $html, '⛔ কাউন্টার ধরে টাকার ছকে আজকের টিল নেই।');
        $this->assertMatchesRegularExpression('/data-reorder-row>\s*<td>[^<]*Cosmos Biscuit 40gm/u', $html, '⛔ সীমার নিচের পণ্য "অর্ডার দিতে হবে"-তে নেই।');
    }

    public function test_the_day_prints_as_an_a4_landscape_pdf(): void
    {
        $this->sell();
        $response = $this->get(route('executive.daily_summary', ['pdf' => 1]))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'), '⛔ PDF নয়।');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent(), '⛔ ফাঁকা বা ভাঙা PDF।');
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0\s+0\s+841\.\d+\s+595\.\d+/', (string) $response->getContent(), '⛔ PDF আড়াআড়ি A4 নয়।');
    }

    public function test_someone_without_the_owner_key_cannot_open_it(): void
    {
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach(CompanyContext::id(), ['is_active' => true]);

        $this->assertSame(403, $this->actingAs($clerk)->get(route('executive.daily_summary'))->status(), '⛔ মালিকের চাবি ছাড়া কেউ প্রতিদিনের সারাংশ খুলল।');
    }

    public function test_the_night_mail_goes_to_the_owner_with_the_pdf(): void
    {
        $this->sell();
        Notification::fake();
        config(['abos.executive.daily_mail_to' => 'boss@example.test', 'abos.executive.mail_as' => $this->owner->id]);

        $this->artisan('abos:daily-summary-mail')->assertSuccessful();

        Notification::assertSentOnDemand(DailySummaryMail::class, function (DailySummaryMail $mail, array $channels, AnonymousNotifiable $to) {
            $message = $mail->toMail($to);

            return ($to->routes['mail'] ?? null) === 'boss@example.test'
                && str_contains((string) $message->subject, 'প্রতিদিনের সারাংশ')
                && count($message->rawAttachments) === 1
                && str_starts_with((string) $message->rawAttachments[0]['data'], '%PDF');
        });
    }

    private function sell(): void
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'vehicle_owner' => 'hired',
            'vehicle_no' => 'ঢাকা মেট্রো ন ১২-৩৪৫৬',
            'driver_name' => 'রহিম',
            'driver_phone' => '01711000000',
            'transport_cost' => '100',
            'fare_paid_by' => 'us',
            'fare_when' => 'now',
            'fare_account_id' => $this->till->id,
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        ])->assertSessionHasNoErrors();
    }
}
