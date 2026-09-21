<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * বিল শূন্য থেকে জন্মায় না — দুইটাই দরজা।
 *
 * ── ⛔ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"new invoice bolte kono from thakbe na, just duto poth thakbe — ek
 * order, dui direct sales. baki poth bondo koro, r kono vabei bill
 * generate hobe na"*।
 *
 * ── ⚠️ কেন কথাটা ন্যায্য ─────────────────────────────────────────────
 * ⓘ INV-0002 ড্যাশবোর্ডের "নতুন বিল" টালি থেকে হয়েছিল — একটা **খালি
 * ফর্ম**, পিছনে কোনো আদেশ নেই, কোনো চালান নেই, মজুদের সাথে মেলানোর
 * কিছু নেই। ⛔ ফল: ৫৬,৯৬,৫৯,০৭,৪১২ টাকার একটা বিল, আর ভুল ধরার কোনো
 * উপায় নেই কারণ মেলানোর কাগজই ছিল না।
 *
 * ⭐ বিল একটা **ফল**, একটা শুরু নয়: আদেশ → চালান → বিল, নয়তো সরাসরি
 * বিক্রয় (যেখানে কার্ট, মজুদ আর টাকা একসাথে মেলে)।
 *
 * ── ⓘ তিনটা স্তরেই মাপা হয়, আর কারণটা পুরনো ─────────────────────────
 * ⚠️ এই অ্যাপে একবার রপ্তানি "বন্ধ" করা হয়েছিল কেবল **বোতাম লুকিয়ে**,
 * আর ঠিকানা টাইপ করলেই ফাইল নামত। ⛔ সেজন্যই এখানে পর্দা, `GET`, আর
 * `POST` — তিনটাই আলাদা করে দেখা হয়।
 */
final class AnInvoiceHasOnlyTwoDoorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⛔ খালি ফর্মটা আর খোলে না — অনুমতি থাকলেও।
     *
     * ⓘ মালিকের সব অধিকার আছে, তাই ৪০৪-টা অনুমতির কথা বলছে না —
     * বলছে **পথটাই নেই**।
     */
    public function test_the_blank_invoice_form_is_gone(): void
    {
        $this->get(route('sales.invoice.create'))->assertNotFound();
    }

    /**
     * ⚠️ আর পর্দা না খুলেও কেউ সরাসরি জমা দিতে পারে না।
     *
     * ⛔ কেবল `create()` আটকানো ফাঁক রেখে দিত: ফর্মটা না খুলেই একটা
     * POST করলেই বিল হয়ে যেত। ⓘ মালিকের কথা ছিল *"কোনোভাবেই"*।
     *
     * ⚠️ তালাটা [[SalesInvoiceRequest::authorize()]]-এ, কন্ট্রোলারে নয়:
     * ফর্ম-রিকোয়েস্টের যাচাই কন্ট্রোলারের **আগে** চলে, তাই কন্ট্রোলারে
     * বসানো তালা একটা আধা-ভরা POST-এ কোনোদিন পৌঁছাতই না। ⓘ প্রথমে ওখানেই
     * বসিয়েছিলাম, আর এই দাবিটাই ভুলটা ধরিয়ে দিয়েছে। তাই উত্তর ৪০৩।
     */
    public function test_a_bare_post_cannot_make_an_invoice(): void
    {
        $before = SalesInvoice::query()->count();

        $this->post(route('sales.invoice.store'), [
            'customer_id' => Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => 1, 'qty' => '1', 'rate' => '10']],
        ])->assertForbidden();

        $this->assertSame($before, SalesInvoice::query()->count(),
            '⛔ সরাসরি POST করে একটা বিল তৈরি হয়ে গেছে।');
    }

    /**
     * ⭐ আর ড্যাশবোর্ডের টালিটা আর খালি ফর্মে পাঠায় না।
     *
     * ⓘ INV-0002 ঠিক ঐ টালি থেকেই হয়েছিল। ⚠️ দরজা বন্ধ করে টালিটা
     * রেখে দিলে ব্যবহারকারী একটা ৪০৪-এ গিয়ে পড়তেন, আর ভাবতেন
     * ব্যবস্থাটা ভেঙেছে।
     */
    public function test_the_dashboard_no_longer_points_at_it(): void
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => 'sales']))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('sales.invoice.create'), $html, implode("\n", [
            '⛔ ড্যাশবোর্ড এখনো খালি বিলের ফর্মে পাঠাচ্ছে।',
            '',
            'ⓘ বিলের দুইটাই দরজা: আদেশ আর সরাসরি বিক্রয়।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের "নেই" দাবিগুলো সবুজ থাকত যদি রুটটাই মুছে যেত — তখন
     * `route()` ছুঁড়ত আর বার্তাটা হত অন্য কিছু। ⚠️ তাই দেখা হয় রুট
     * তিনটা এখনো নিবন্ধিত, অর্থাৎ দরজাটা **বন্ধ**, **অনুপস্থিত নয়**।
     */
    public function test_the_doors_still_exist_they_are_only_shut(): void
    {
        foreach (['sales.invoice.create', 'sales.invoice.store', 'sales.order.create'] as $name) {
            $this->assertTrue(Route::has($name), "রুট {$name} নেই।");
        }

        /* ⭐ আর আদেশের দরজাটা সত্যিই খোলা — নাহলে বিল বানানোর পথই থাকত না। */
        $this->get(route('sales.order.create'))->assertOk();
    }
}
