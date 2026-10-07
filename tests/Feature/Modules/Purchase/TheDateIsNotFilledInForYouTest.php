<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ক্রয়ের কাগজে তারিখটা আগে থেকে ভরা থাকে না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"Received on, Billing date egulo faka thakbe, hate fill korar por
 * create hobe"*।
 *
 * ── ⚠️ কেন কথাটা ন্যায্য ────────────────────────────────────────────
 * কাগজটা আজকের নাও হতে পারে: সরবরাহকারীর বিল তিন দিন আগের, মাল পরশু
 * এসেছে। ⓘ ঘরটা আগে থেকে ভরা থাকলে মানুষ সেটা **পড়েন না** — চোখ পরের
 * ঘরে চলে যায়, আর আজকের তারিখেই কাগজটা বসে যায়।
 *
 * ⛔ ফল নীরব: খাতায় ভুল দিনে ভুক্তি, মাসের হিসাব মেলে না, আর কেউ বলতে
 * পারে না কেন। ⚠️ খালি ঘর জোর করে প্রশ্নটা করায় — *"কোন তারিখ?"*
 *
 * ── ⓘ দুইটা দাবি, আর দ্বিতীয়টাই আসল ────────────────────────────────
 * খালি রাখা একা যথেষ্ট নয়: তারিখ ছাড়াই সংরক্ষণ হয়ে গেলে কাগজটা
 * তারিখহীন হয়ে বসত, যা ভরা ঘরের চেয়েও খারাপ। ⭐ তাই `required` থেকে
 * যায়, আর নিচের দ্বিতীয় দাবিটা সেটাই পাহারা দেয়।
 */
final class TheDateIsNotFilledInForYouTest extends TestCase
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
     * @return list<array{0: string, 1: string}>
     */
    public static function screens(): array
    {
        return [
            'বিল' => ['purchase.bill.create', 'বিলের তারিখ'],
            'মাল বুঝে নেওয়া' => ['purchase.receipt.create', 'মাল আসার তারিখ'],
        ];
    }

    #[DataProvider('screens')]
    public function test_the_date_box_opens_empty(string $route, string $what): void
    {
        $html = (string) $this->get(route($route))->assertOk()->getContent();

        $at = strpos($html, 'name="trx_date"');

        $this->assertNotFalse($at, "{$what}-এর ঘরটাই পাতায় নেই।");

        /*
         * ⓘ তারিখের ঘরটা একটা Alpine কম্পোনেন্ট; আসল মানটা তার বীজে
         * (`abosDate("…")`)। ⚠️ কেবল `value=""` খুঁজলে দাবিটা কিছুই
         * প্রমাণ করত না — লুকানো ইনপুটটায় `x-bind:value` থাকে, কোনো
         * `value` নয়।
         */
        $chunk = substr($html, max(0, $at - 1400), 1400);
        $seed = strrpos($chunk, 'abosDate(');

        $this->assertNotFalse($seed, 'তারিখের কম্পোনেন্টটাই পাওয়া গেল না।');

        $this->assertStringStartsWith('abosDate(&quot;&quot;', substr($chunk, $seed, 24), implode("\n", [
            "⛔ {$what} আগে থেকে ভরা আছে।",
            '',
            '⚠️ ভরা ঘর কেউ পড়ে না — চোখ পরের ঘরে চলে যায়, আর কাগজটা',
            'আজকের তারিখেই বসে যায়। ⓘ খালি ঘর প্রশ্নটা করায়।',
        ]));
    }

    /**
     * ⭐ আর তারিখ ছাড়া সংরক্ষণ হয় না — এটাই দাবির অন্য অর্ধেক।
     *
     * ⛔ কেবল খালি রাখলে কাগজটা তারিখহীন হয়ে বসত, যা ভরা ঘরের চেয়েও
     * খারাপ: খাতায় কোন দিনে যাবে তার কোনো উত্তরই থাকত না।
     */
    public function test_a_bill_without_a_date_is_refused(): void
    {
        $before = PurchaseBill::query()->count();

        $this->from(route('purchase.bill.create'))
            ->post(route('purchase.bill.store'), [
                'supplier_id' => Supplier::query()->firstOrFail()->id,
                'lines' => [['product_id' => 1, 'qty' => '1', 'rate' => '10']],
            ])
            ->assertSessionHasErrors('trx_date');

        $this->assertSame($before, PurchaseBill::query()->count(),
            '⛔ তারিখ ছাড়াই একটা বিল তৈরি হয়ে গেছে।');
    }
}
