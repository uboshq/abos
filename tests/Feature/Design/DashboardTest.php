<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\Widget;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * হোম পর্দা — মালিকের সংখ্যাগুলো।
 *
 * ── আগে এখানে কী ছিল ────────────────────────────────────────────────
 * চারটা টাইল, চারটাতেই "—"। লগইনের পর প্রথম যে পর্দাটা সবাই দেখত, সেটা
 * কিছুই বলত না। টেস্ট সবুজ ছিল, কারণ পাতাটা ২০০ দিত।
 *
 * এখানকার পরীক্ষাগুলো তাই সংখ্যা নিয়ে নয় শুধু — প্রতিটা সংখ্যা তার
 * উৎসে নিয়ে যায় কি না, আর যার অনুমতি নেই সে দেখে ফেলে কি না।
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        /*
         * আজকের একটা বিল — নাহলে পরীক্ষাগুলো ফাঁপা।
         *
         * ডেমো ডাটায় কেবল মাস্টার আছে, কোনো লেনদেন নেই। ওই অবস্থায়
         * "ড্যাশবোর্ডের সংখ্যা = তালিকার সংখ্যা" মিলিয়ে দেখা মানে
         * শূন্যের সাথে শূন্য মেলানো — যা ভুল হিসাবেও পাস করত।
         */
        $this->invoiceToday('1250.00');
    }

    /** আজকের তারিখে একটা নিশ্চিত বিল। */
    private function invoiceToday(string $rate): SalesInvoice
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $warehouse = Warehouse::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->orderBy('id')->firstOrFail();

        return app(SalesInvoiceService::class)->confirm(
            app(SalesInvoiceService::class)->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'trx_date' => Carbon::today()->toDateString(),
                ],
                [['product_id' => $product->id, 'qty' => '1', 'rate' => $rate]],
            )
        );
    }

    /**
     * পর্দাটা খোলে, আর সংখ্যাগুলো নিয়ে আসে।
     */
    public function test_the_home_screen_shows_figures_not_dashes(): void
    {
        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $groups = $response->viewData('groups');

        $this->assertNotEmpty($groups['today'], 'আজকের দলে একটাও সংখ্যা নেই।');
        $this->assertNotEmpty($groups['todo'], '"যা করা বাকি" দলটা খালি।');

        $response->assertDontSee('>—<', false);
    }

    /**
     * আজকের বিক্রয়ের সংখ্যাটা আজকের বিলগুলোরই যোগফল।
     *
     * ── কেন এটা পরীক্ষা করা হয় ─────────────────────────────────────
     * ড্যাশবোর্ডের সংখ্যা আর তালিকার সংখ্যা আলাদা হওয়া সবচেয়ে সাধারণ
     * ভুল, আর সেটা কেউ ধরে না — দুইটা পর্দা কেউ পাশাপাশি রেখে মেলায় না।
     */
    public function test_todays_sales_matches_todays_invoices(): void
    {
        $expected = SalesInvoice::query()
            ->whereIn('status', [DocumentStatus::CONFIRMED, DocumentStatus::CLOSED])
            ->whereDate('trx_date', Carbon::today()->toDateString())
            ->sum('total');

        $widget = $this->widget('sales_today');

        $this->assertSame(number_format((float) $expected, 2), $widget->value);
    }

    /**
     * সংখ্যার লিংক আজকের সারিগুলোতেই নামে।
     *
     * তারিখের ছাঁকনি না থাকলে লিংকটা পুরো তালিকা খুলত, আর ব্যবহারকারী
     * ৫০টা সারির মধ্যে আজকেরগুলো নিজে খুঁজতেন — অর্থাৎ যাচাই করা যেত না।
     */
    public function test_the_figure_opens_the_rows_behind_it(): void
    {
        $today = Carbon::today()->toDateString();
        $widget = $this->widget('sales_today');

        $this->assertStringContainsString('from='.$today, $widget->href);
        $this->assertStringContainsString('to='.$today, $widget->href);

        $response = $this->actingAs($this->owner)->get($widget->href)->assertOk();

        foreach ($response->viewData('invoices') as $invoice) {
            $this->assertSame($today, $invoice->trx_date->toDateString());
        }
    }

    /**
     * প্রতিটা উইজেটের অনুমতিটা তার মডিউল সত্যিই ঘোষণা করেছে।
     *
     * অঘোষিত অনুমতি কাউকে কখনো দেওয়া হত না, তাই সংখ্যাটা চিরকাল অদৃশ্য
     * থাকত — কোনো ভুলের বার্তা ছাড়াই। টাইপো সবচেয়ে সম্ভাব্য কারণ।
     */
    public function test_every_widget_asks_for_a_permission_its_module_declares(): void
    {
        $offenders = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->widgets as $provider) {
                foreach ($provider::widgets() as $widget) {
                    if (! in_array($widget->permission, $module->permissions, true)) {
                        $offenders[] = "{$module->code}: {$widget->permission}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'এই উইজেটগুলো অঘোষিত অনুমতি চাইছে।');
    }

    /**
     * অনুমতি না থাকলে সংখ্যাটাও নেই।
     *
     * ডেলিভারির লোক হোম পর্দায় দিনের মোট বিক্রয় দেখলে সেটা একটা ফাঁস —
     * তালিকাটা তার জন্য বন্ধ, অথচ যোগফলটা খোলা।
     */
    public function test_a_user_without_the_permission_never_sees_the_figure(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id);

        $groups = app(DashboardRegistry::class)->forUser($clerk);

        $this->assertSame([], $groups['today']);
        $this->assertSame([], $groups['month']);
        $this->assertSame([], $groups['todo']);
    }

    /**
     * লিংক ছাড়া উইজেট তৈরিই করা যায় না।
     *
     * নিয়মটা মন্তব্যে থাকলে একদিন কেউ তাড়াহুড়োয় লিংক ছাড়া একটা টাইল
     * বসাত, আর সেটাই প্রথম "বিশ্বাস করুন, যাচাই করবেন না" সংখ্যা হত।
     */
    public function test_a_widget_without_a_link_cannot_exist(): void
    {
        $this->expectExceptionMessage('has no link');

        new Widget(
            group: 'today',
            label: 'Sales',
            value: '100',
            href: '',
            permission: 'sales.invoice.view',
        );
    }

    /** নাম ধরে একটা উইজেট — লেখাটা নয়, ঘরের চাবিটা ধরে। */
    private function widget(string $key): Widget
    {
        $label = __('sales::dashboard.'.$key);

        foreach (app(DashboardRegistry::class)->forUser($this->owner) as $widgets) {
            foreach ($widgets as $widget) {
                if ($widget->label === $label) {
                    return $widget;
                }
            }
        }

        $this->fail("'{$key}' নামে কোনো উইজেট নেই।");
    }
}
