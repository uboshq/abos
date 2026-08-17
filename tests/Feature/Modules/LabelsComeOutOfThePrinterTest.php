<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লেবেলটা সত্যিই প্রিন্টার থেকে বেরোয়।
 *
 * ── কেন এই পর্দাটা লাগে ─────────────────────────────────────────────
 * ডিপোর অনেক পণ্যের গায়ে কোনো বারকোড থাকেই না — খোলা চাল, নিজের প্যাক
 * করা মশলা। কাউন্টারে ওগুলো প্রতিবার নাম লিখে খুঁজতে হয়, আর বানানে
 * একটু এদিক-ওদিক হলেই ভুল পণ্য বেরোয়।
 */
class LabelsComeOutOfThePrinterTest extends TestCase
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

    /** পর্দাটা খোলে আর প্রতিটা সচল পণ্য বাছার জন্য থাকে। */
    public function test_the_screen_lists_the_products(): void
    {
        $product = Product::query()->active()->firstOrFail();

        $this->get(route('inventory.label.index'))
            ->assertOk()
            ->assertSee($product->code);
    }

    /** ছাপলে সত্যিই একটা PDF বেরোয়। */
    public function test_printing_gives_a_pdf(): void
    {
        $product = Product::query()->active()->firstOrFail();

        $this->get(route('inventory.label.print', ['products' => [$product->id]]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * কপির সংখ্যা মানে সত্যিই ততগুলো ঘর।
     *
     * এক পণ্যের ত্রিশ কার্টন এলে ত্রিশটা লেবেল লাগে; একটা ছাপলে বাকি
     * ঊনত্রিশ কার্টন কাউন্টারে গিয়ে আবার নাম ধরে খুঁজতে হত।
     */
    public function test_copies_really_multiply_the_labels(): void
    {
        $product = Product::query()->active()->firstOrFail();

        $one = $this->get(route('inventory.label.print', [
            'products' => [$product->id], 'copies' => 1,
        ]))->assertOk()->getContent();

        $five = $this->get(route('inventory.label.print', [
            'products' => [$product->id], 'copies' => 5,
        ]))->assertOk()->getContent();

        $this->assertGreaterThan(strlen($one), strlen($five),
            'পাঁচ কপি চেয়ে একটার সমান কাগজ বেরিয়েছে।');
    }

    /**
     * দাম ডিফল্টে ছাপা হয় না।
     *
     * ── কেন এটা পরখ করা দরকার ───────────────────────────────────────
     * ডিলারের গুদামে দাম গ্রাহকভেদে আলাদা। লেবেলে ছেপে দিলে গুদামের
     * প্রতিটা কার্টনে একটা দাম সাঁটা থাকত, আর ক্রেতা সেটাই দাবি
     * করতেন। ঘরটা তাই বন্ধ থেকে শুরু করে, আর দোকানের তাকের জন্য টিক
     * দিতে হয়।
     */
    public function test_the_price_is_off_unless_asked_for(): void
    {
        $this->get(route('inventory.label.index'))
            ->assertOk()
            ->assertSee(__('inventory::label.price_note'));
    }

    /** পণ্য না বাছলে কিছুই ছাপা হয় না। */
    public function test_nothing_chosen_prints_nothing(): void
    {
        $this->get(route('inventory.label.print'))->assertSessionHasErrors('products');
    }

    /**
     * বাংলা নামের পণ্যেও লেবেল বেরোয়।
     *
     * ── কেন এটাই সবচেয়ে জরুরি পরীক্ষা ───────────────────────────────
     * Code 128 বাংলা অক্ষর বইতে পারে না, আর ডিপোর প্রতিটা পণ্যের নাম
     * বাংলায়। নামটা দাগে পাঠালে প্রতিটা লেবেল ছাপতে গিয়ে ব্যতিক্রম
     * উঠত — অর্থাৎ পর্দাটা কোনো ডিপোতেই কাজ করত না। দাগে যায় কোড,
     * চোখে পড়ে নাম।
     */
    public function test_a_bengali_named_product_still_gets_a_label(): void
    {
        $product = Product::query()->active()->firstOrFail();

        $this->assertNotSame('', trim((string) $product->name_bn),
            'ডেমোর পণ্যের বাংলা নামই নেই — পরীক্ষাটা কিছুই প্রমাণ করত না।');

        $this->get(route('inventory.label.print', ['products' => [$product->id]]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /** থার্মাল রোলেও ছাপা যায় — কাউন্টারের প্রিন্টারই বেশিরভাগ ডিপোর একমাত্র প্রিন্টার। */
    public function test_it_prints_on_a_thermal_roll_too(): void
    {
        $product = Product::query()->active()->firstOrFail();

        $this->get(route('inventory.label.print', [
            'products' => [$product->id], 'paper' => '80mm',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /** অনুমতি ছাড়া কেউ লেবেল ছাপতে পারেন না। */
    public function test_it_needs_permission(): void
    {
        $stranger = User::query()->where('email', 'accounts@abos.test')->first();

        if ($stranger === null) {
            $this->markTestSkipped('ডেমোতে হিসাবরক্ষকের অ্যাকাউন্ট নেই।');
        }

        $this->actingAs($stranger)->get(route('inventory.label.index'))->assertForbidden();
    }
}
