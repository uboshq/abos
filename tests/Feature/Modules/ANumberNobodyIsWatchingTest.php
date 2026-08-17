<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\Widget;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
 * সীমা ছাড়িয়ে গেছে, অথচ কেউ জানে না।
 *
 * ── কী ছিল না ───────────────────────────────────────────────────────
 * ধারের সীমা বসানো যেত, আর সীমা ছাড়ানো বিল আটকানোও যেত। কিন্তু যাঁরা
 * **ইতিমধ্যেই** ছাড়িয়ে গেছেন — পুরনো বিল, ফেরত না আসা টাকা — তাঁদের
 * কথা কোথাও লেখা হত না। কেউ খুঁজতে গেলে তবেই জানত, আর কেউ খুঁজত না।
 *
 * ── কেন উইজেট, আলাদা সতর্কবার্তা নয় ─────────────────────────────────
 * রোজ আসা বার্তা দুই সপ্তাহে মানুষ পড়া বন্ধ করে দেয়। করণীয় সারিটা
 * উল্টো: কিছু বাকি না থাকলে চুপ থাকে, আর থাকলে সংখ্যাটা ক্লিক করলে
 * ঠিক ওই লোকগুলোর তালিকা খোলে।
 */
class ANumberNobodyIsWatchingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** সীমা বসিয়ে তার চেয়ে বেশি টাকার বিল করা — অর্থাৎ ছাড়িয়ে যাওয়া। */
    private function pushOverTheLimit(Customer $customer, string $limit, string $sale): void
    {
        $customer->update(['credit_limit' => $limit]);

        $service = app(SalesInvoiceService::class);

        $invoice = $service->create(
            [
                'customer_id' => $customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $sale]],
        );

        $service->confirm($invoice);
    }

    /** @return list<Widget> */
    private function todo(): array
    {
        return app(DashboardRegistry::class)->forUser($this->owner)['todo'] ?? [];
    }

    private function overLimitWidget(): ?Widget
    {
        foreach ($this->todo() as $widget) {
            if ($widget->label === __('customer::dashboard.over_limit')) {
                return $widget;
            }
        }

        return null;
    }

    // ── সীমা ছাড়ানো গ্রাহক ──────────────────────────────────────────

    /** কেউ না ছাড়ালে সারিটা শূন্য বলে — মিথ্যা লাল দাগ নয়। */
    public function test_nobody_over_the_limit_reads_zero(): void
    {
        $widget = $this->overLimitWidget();

        $this->assertNotNull($widget, 'সীমা ছাড়ানোর সারিটাই হোম পর্দায় নেই।');
        $this->assertSame('0', $widget->value);
        $this->assertSame('neutral', $widget->tone, 'কেউ ছাড়ায়নি, তবু সারিটা সতর্ক রঙে।');
    }

    /** ছাড়িয়ে গেলে সংখ্যাটা তাঁকে গোনে, আর সারিটা সতর্ক রঙ নেয়। */
    public function test_a_customer_over_the_limit_is_counted(): void
    {
        $this->pushOverTheLimit(Customer::query()->firstOrFail(), '1000', '2500');

        $widget = $this->overLimitWidget();

        $this->assertSame('1', $widget->value);
        $this->assertSame('warn', $widget->tone);
    }

    /**
     * সীমা শূন্য মানে সীমাহীন, "কিছুই বাকি রাখা যাবে না" নয়।
     *
     * উল্টোটা ধরলে সীমা না-বসানো প্রতিটা গ্রাহক এই তালিকায় এসে
     * পড়তেন, আর তালিকাটা তখন কেউ খুলেও দেখতেন না।
     */
    public function test_a_customer_with_no_limit_is_never_over_it(): void
    {
        $customer = Customer::query()->firstOrFail();
        $customer->update(['credit_limit' => 0]);

        $this->pushOverTheLimit($customer, '0', '9000');

        $this->assertSame('0', $this->overLimitWidget()->value);
    }

    /**
     * সংখ্যাটা আর তালিকাটা একই কথা বলে।
     *
     * ── কেন এটাই এখানকার আসল পরীক্ষা ────────────────────────────────
     * "৩ জন ছাড়িয়েছেন" দেখে ক্লিক করে চারজন পেলে ব্যবহারকারী দুইটার
     * কোনোটাই আর বিশ্বাস করেন না — আর তিনি ঠিকই করেন, কারণ একটা তো
     * ভুল।
     */
    public function test_the_number_and_the_list_agree(): void
    {
        $customers = Customer::query()->take(2)->get();

        $over = $customers[0];
        $this->pushOverTheLimit($over, '500', '1500');

        $under = $customers[1] ?? null;
        $under?->update(['credit_limit' => 100000]);

        $this->assertSame('1', $this->overLimitWidget()->value);

        /*
         * নাম ধরে দেখা, রুটের নাম ধরে নয়।
         *
         * প্রথম সংস্করণে HTML-এ `customer.show` লেখাটা গোনা হত — কিন্তু
         * পাতায় বসে ঠিকানা (`/customers/5`), রুটের নাম নয়। তাই গোনাটা
         * সবসময় শূন্য হত, আর টেস্টটা ভুল কারণে লাল হত।
         */
        $html = $this->get(route('customer.index', ['over_limit' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString($over->name(), $html,
            'যিনি সীমা ছাড়িয়েছেন তিনিই ছাঁকা তালিকায় নেই।');

        if ($under !== null && $under->name() !== $over->name()) {
            $this->assertStringNotContainsString($under->name(), $html,
                'সীমার নিচে থাকা গ্রাহকও ছাঁকা তালিকায় এসে পড়েছেন।');
        }
    }

    /** ক্লিক করার ঠিকানাটা সত্যিই ওই ছাঁকনিতে নিয়ে যায় (নিয়ম ১)। */
    public function test_the_row_leads_to_the_filtered_list(): void
    {
        $this->assertSame(
            route('customer.index', ['over_limit' => 1]),
            $this->overLimitWidget()->href,
        );
    }

    // ── সুইচ ────────────────────────────────────────────────────────

    /** সুইচ বন্ধ থাকলে সারিটাই আসে না — যে ডিপো ধার দেয় না, তার এটা লাগে না। */
    public function test_the_row_can_be_switched_off(): void
    {
        app(SettingsService::class)->set('customer.alert_over_limit', false);

        $this->assertNull($this->overLimitWidget(), 'সুইচ বন্ধ, তবু সারিটা দেখাচ্ছে।');
    }

    // ── মোট বকেয়ার সীমা ─────────────────────────────────────────────

    /**
     * ০ মানে বন্ধ, শূন্য টাকার সীমা নয়।
     *
     * উল্টোটা ধরলে সুইচটা চালু করা মাত্রই রোজ সতর্কতা আসত, কারণ বকেয়া
     * সবসময়ই শূন্যের বেশি।
     */
    public function test_a_zero_ceiling_means_the_row_is_off(): void
    {
        app(SettingsService::class)->set('customer.alert_receivable_over', 0);

        $this->assertNull($this->receivableWidget(), '০ সীমাতেও সারিটা এসেছে।');
    }

    /** সীমা বসালে সারিটা আসে, আর টাকার অঙ্কটাই দেখায়। */
    public function test_a_ceiling_brings_the_row_with_the_amount(): void
    {
        app(SettingsService::class)->set('customer.alert_receivable_over', 1000);

        $this->pushOverTheLimit(Customer::query()->firstOrFail(), '100000', '2500');

        $widget = $this->receivableWidget();

        $this->assertNotNull($widget, 'সীমা বসানো সত্ত্বেও সারিটা নেই।');
        $this->assertStringContainsString('2,500', $widget->value);
        $this->assertSame('warn', $widget->tone);
    }

    /** সীমার নিচে থাকলে সারিটা থাকে, কিন্তু সতর্ক রঙে নয়। */
    public function test_below_the_ceiling_it_is_not_a_warning(): void
    {
        app(SettingsService::class)->set('customer.alert_receivable_over', 1000000);

        $this->pushOverTheLimit(Customer::query()->firstOrFail(), '100000', '2500');

        $this->assertSame('neutral', $this->receivableWidget()?->tone);
    }

    /** ড্রাফট বিল বকেয়া নয় — খতিয়ানে কিছু বসেনি, তাই গোনাতেও নেই। */
    public function test_a_draft_invoice_is_not_receivable(): void
    {
        app(SettingsService::class)->set('customer.alert_receivable_over', 1);

        $customer = Customer::query()->firstOrFail();

        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '5000']],
        );

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status);

        $this->assertStringNotContainsString('5,000', $this->receivableWidget()?->value ?? '',
            'খসড়া বিলের টাকা বকেয়া হিসেবে গোনা হয়েছে।');
    }

    private function receivableWidget(): ?Widget
    {
        foreach ($this->todo() as $widget) {
            if (str_starts_with($widget->label, mb_substr(__('customer::dashboard.receivable_over', ['limit' => '']), 0, 8))) {
                return $widget;
            }
        }

        return null;
    }
}
