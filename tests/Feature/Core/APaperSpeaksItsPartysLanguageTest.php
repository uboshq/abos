<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Core\Support\PaperLanguage;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ⭐ চেকলিস্ট ভা-১৬, ধাপ ১ আর ২ — কাগজের ভাষা যাঁর জন্য কাগজ তাঁর (a5-র পরিকল্পনা আর মালিকের পাঁচ উত্তর, ২৪ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *  - গ্রাহক আর সরবরাহকারীর "কাগজের ভাষা" ঘর আছে (ফর্মে, সারিতে), বাছাই বাংলা · English · দুই ভাষা।
 *  - পক্ষের ভাষাই কাগজের ভাষা; ছাপার মেনুর এবারের বাছাই (`?lang`) আগে, আর সেটা পক্ষের master বদলায় না; অচেনা মান উপেক্ষা।
 *  - ছাপার মেনুতে "পক্ষের ভাষা · বাংলা · English", শুরুতে "পক্ষের ভাষা" বাছা — পর্দার ভাষা নিজে থেকে যায় না।
 *  - ছাপার যন্ত্র আর বিক্রয়ের ছাপা এই নিয়মে চলে।
 */
final class APaperSpeaksItsPartysLanguageTest extends TestCase
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

    public function test_a_party_has_a_paper_language_on_its_form_and_its_row(): void
    {
        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['paper_language' => 'en'])->save();
        $this->assertSame('en', $customer->fresh()->paper_language, '⛔ গ্রাহকের সারিতে কাগজের ভাষা বসে না।');
        $this->assertContains('paper_language', $customer->getFillable(), '⛔ গ্রাহকের ফর্ম কাগজের ভাষা পাঠালেও বসে না।');
        $this->assertContains('paper_language', (new Supplier)->getFillable(), '⛔ সরবরাহকারীর কাগজের ভাষা বসে না।');

        foreach ([route('customer.edit', $customer), route('supplier.edit', Supplier::query()->firstOrFail())] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/data-paper-language.*?<select name="paper_language"/s', $html, "⛔ ফর্মে কাগজের ভাষার ঘর নেই: {$url}");
            foreach (['bn', 'en', 'both'] as $lang) {
                $this->assertStringContainsString('<option value="'.$lang.'"', $html, "⛔ কাগজের ভাষায় \"{$lang}\" বাছা যায় না: {$url}");
            }
        }
    }

    public function test_the_partys_language_leads_and_this_times_choice_wins_without_touching_the_party(): void
    {
        app()->setLocale('bn');
        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['paper_language' => 'en'])->save();

        $this->assertSame('en', PaperLanguage::for($customer), '⛔ পক্ষের কাগজের ভাষা উপেক্ষা — যিনি ছাপলেন তাঁর পর্দার ভাষায় ছাপা।');

        $this->app->instance('request', Request::create('/print', 'GET', ['lang' => 'bn']));
        $this->assertSame('bn', PaperLanguage::for($customer), '⛔ ছাপার মেনুর এবারের বাছাই খাটে না।');
        $this->assertSame('en', $customer->fresh()->paper_language, '⛔ এবারের বাছাই পক্ষের master বদলে দিল।');

        $this->app->instance('request', Request::create('/print', 'GET', ['lang' => 'fr']));
        $this->assertSame('en', PaperLanguage::for($customer), '⛔ অচেনা ভাষা নিয়ে ছাপা হলো।');

        $this->app->instance('request', Request::create('/print', 'GET'));
        $customer->forceFill(['paper_language' => null])->save();
        $this->assertSame('bn', PaperLanguage::for($customer->fresh()), '⛔ ভাষা ফাঁকা পক্ষের কাগজ আগের মতো পর্দার ভাষায় নয়।');
    }

    public function test_the_print_menu_offers_the_partys_language_first_and_two_languages(): void
    {
        $html = (string) $this->blade('<x-ui.print-menu :documents="$documents" />', [
            'documents' => [['label' => 'বিল', 'url' => '/sales/invoices/1/print']],
        ]);

        $this->assertMatchesRegularExpression('/data-print-lang.*?name="lang" value=""[^>]*checked/s', $html, '⛔ ছাপার মেনুতে শুরুতে "পক্ষের ভাষা" বাছা নেই — পর্দার ভাষা প্রতিবার পক্ষেরটাকে ছাপিয়ে যেত।');
        foreach (['bn', 'en'] as $lang) {
            $this->assertMatchesRegularExpression('/name="lang" value="'.$lang.'"/', $html, "⛔ ছাপার মেনুতে \"{$lang}\" নেই।");
        }
    }

    /**
     * ⛔ মালিক, ১৭ নভেম্বর ২০২৬ (জরুরি): *"ইনভয়েসে বাংলায় প্রিন্ট দিতে গেলে বাংলা প্রোডাক্টের নামগুলো আসতেছে না"* — ক্লাসিক বিলে
     *   পণ্য, গ্রাহকের নাম আর ঠিকানা `('en')`-এ বাঁধা ছিল। ⭐ ছাপার মেনুর ভাষা (বাংলা / English) এখন তিনটাতেই মানা হয়।
     */
    public function test_the_classic_bill_names_products_and_the_customer_in_the_chosen_language(): void
    {
        $invoice = app(\App\Modules\Sales\Services\SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => \App\Modules\Inventory\Models\Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => \App\Modules\Inventory\Models\Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']],
        )->load('lines.product', 'customer');
        $product = $invoice->lines->first()->product;
        $product->forceFill(['name_en' => 'Rod 12 mm', 'name_bn' => 'রড ১২ মিমি'])->save();
        $invoice->customer->forceFill(['name_bn' => 'রাইয়ান এন্টারপ্রাইজ', 'address_bn' => 'ফুলপুর বাজার'])->save();
        $facts = new \ReflectionMethod(\App\Modules\Sales\Http\Controllers\SalesPrintController::class, 'classicFacts');
        $controller = app(\App\Modules\Sales\Http\Controllers\SalesPrintController::class);

        request()->query->set('lang', 'bn');
        $bn = $facts->invoke($controller, $invoice->fresh());
        $this->assertContains('রড ১২ মিমি', array_column($bn['items']['rows'], 'name'), '⛔ বাংলা কাগজে পণ্যের বাংলা নাম আসে না');
        $this->assertSame('রাইয়ান এন্টারপ্রাইজ', $bn['bill_to']['name'], '⛔ বাংলা কাগজে গ্রাহকের বাংলা নাম আসে না');
        $this->assertSame('ফুলপুর বাজার', $bn['bill_to']['address'], '⛔ বাংলা কাগজে গ্রাহকের বাংলা ঠিকানা আসে না');

        request()->query->set('lang', 'en');
        $en = $facts->invoke($controller, $invoice->fresh());
        $this->assertContains('Rod 12 mm', array_column($en['items']['rows'], 'name'), '⛔ ইংরেজি কাগজে পণ্যের নাম ইংরেজিতে নয়');
        $this->assertNotSame('রাইয়ান এন্টারপ্রাইজ', $en['bill_to']['name']);
    }

    public function test_the_print_engine_and_the_sales_paper_follow_the_rule(): void
    {
        $engine = (string) file_get_contents(app_path('Core/Engines/Print/PrintEngine.php'));
        $this->assertStringContainsString('$locale = $locale ?? \\App\\Core\\Support\\PaperLanguage::for();', $engine, '⛔ ছাপার যন্ত্র কাগজের ভাষা না মেনে পর্দার ভাষায় আঁকে।');

        $sales = (string) file_get_contents(app_path('Modules/Sales/Http/Controllers/SalesPrintController.php'));
        $this->assertMatchesRegularExpression('/\$locale = \\\\App\\\\Core\\\\Support\\\\PaperLanguage::for\(/', $sales, '⛔ বিক্রয়ের কাগজ গ্রাহকের ভাষা ধরে না।');
        $this->assertStringContainsString('locale: $locale,', $sales, '⛔ বিক্রয়ের কাগজের ভাষা ছাপার যন্ত্রে পৌঁছায় না।');
    }
}
