<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PrintedPriceCeiling;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ছাপা দামের উপরে বেচা যায় না।
 *
 * নিয়মটার কোনো সুইচ নেই আর থাকবেও না — কোম্পানি আইন বন্ধ করতে পারে না।
 * তাই এখানকার প্রতিটা পরীক্ষা একটা "না" পিন করে, আর প্রতিটা "না"-এর
 * পাশে একটা "হ্যাঁ", যাতে নিয়মটা বৈধ বিক্রয়ও আটকে না দেয়।
 */
class PrintedPriceCeilingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
    }

    private function lot(string $no, ?string $mrp): Batch
    {
        return Batch::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_no' => $no,
            'expiry_date' => '2027-12-31',
            'mrp' => $mrp,
        ]);
    }

    private function ceiling(): PrintedPriceCeiling
    {
        return app(PrintedPriceCeiling::class);
    }

    // ── সিলিং ────────────────────────────────────────────────────

    public function test_selling_at_the_printed_price_is_allowed(): void
    {
        $this->ceiling()->assertWithin($this->lot('A', '20.0000'), '20');

        $this->addToAssertionCount(1);
    }

    public function test_selling_below_the_printed_price_is_allowed(): void
    {
        $this->ceiling()->assertWithin($this->lot('A', '20.0000'), '18.5');

        $this->addToAssertionCount(1);
    }

    public function test_selling_above_the_printed_price_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->ceiling()->assertWithin($this->lot('A', '20.0000'), '20.0001');
    }

    /**
     * ছাপা দাম না থাকলে কোনো সিলিং নেই।
     *
     * চাল, সাবান, বিস্কুট — বেশিরভাগ মালের গায়ে দাম ছাপা থাকে না।
     * ওখানে দাম বিক্রেতার সিদ্ধান্ত, আর কাল্পনিক সিলিং বসালে সাধারণ
     * ব্যবসাই আটকে যেত।
     */
    public function test_a_lot_with_no_printed_price_has_no_ceiling(): void
    {
        $this->ceiling()->assertWithin($this->lot('A', null), '9999');

        $this->addToAssertionCount(1);
    }

    /**
     * সিলিং লট ধরে, পণ্য ধরে নয়।
     *
     * একই ড্রয়ারে ৳২০ আর ৳২২-এর পাতা। ৳২১ নতুন লটের জন্য বৈধ, পুরনোটার
     * জন্য নয় — আর পণ্য-স্তরের সিলিং হলে দুইটার একটা ভুল হত, ঠিক ওই
     * দিকে যেদিকে বেআইনি বিক্রয়টা সঠিক দেখায়।
     */
    public function test_two_lots_of_one_product_have_two_different_ceilings(): void
    {
        $old = $this->lot('OLD', '20.0000');
        $new = $this->lot('NEW', '22.0000');

        // নতুন লটে ৳২১ বৈধ
        $this->ceiling()->assertWithin($new, '21');

        // পুরনো লটে সেই ৳২১-ই নয়
        $this->expectException(ValidationException::class);
        $this->ceiling()->assertWithin($old, '21');
    }

    // ── ছাড়ের পরের দাম ───────────────────────────────────────────

    /**
     * ক্রেতা যা দেন সেটাই দাম — ছাড়ের আগেরটা নয়।
     *
     * ছাড়ের আগের অঙ্ক দেখলে ৳২৫ দর আর ঋণাত্মক ছাড় বসিয়ে ৳২৫-এ বেচা
     * যেত, আর নিয়মটা কাগজে টিকে থাকত।
     */
    public function test_a_discount_brings_the_price_under_the_ceiling(): void
    {
        $lot = $this->lot('A', '20.0000');

        // ১০টা × ৳২২ = ৳২২০, ছাড় ৳৩০ → প্রতি এককে ৳১৯
        $net = $this->ceiling()->netUnitPrice('10', '22', '30');

        $this->assertSame(0, bccomp($net, '19', 4));

        $this->ceiling()->assertWithin($lot, $net);

        $this->addToAssertionCount(1);
    }

    /** ঋণাত্মক ছাড় দিয়ে সিলিং পেরোনো যায় না। */
    public function test_a_negative_discount_cannot_push_the_price_over(): void
    {
        $lot = $this->lot('A', '20.0000');

        // ১০টা × ৳১৮ = ৳১৮০, ছাড় −৳৫০ → প্রতি এককে ৳২৩
        $net = $this->ceiling()->netUnitPrice('10', '18', '-50');

        $this->assertSame(0, bccomp($net, '23', 4));

        $this->expectException(ValidationException::class);
        $this->ceiling()->assertWithin($lot, $net);
    }

    public function test_no_quantity_means_no_price_to_check(): void
    {
        $this->assertNull($this->ceiling()->netUnitPrice('0', '20', '0'));
    }

    /**
     * ছাড় দামের চেয়ে বড় হলে নিট শূন্যে থামে, ঋণাত্মক নয়।
     *
     * ঋণাত্মক দাম সিলিংয়ের নিচে বলে "ঠিক আছে" হয়ে যেত — সেটা আলাদা
     * সমস্যা (কেউ টাকা ফেরত দিয়ে মাল দিচ্ছেন), আর তা ধরার জায়গা
     * বিক্রয়ের নিজের যাচাই, এই নিয়ম নয়।
     */
    public function test_an_over_large_discount_stops_at_zero(): void
    {
        $this->assertSame(0, bccomp($this->ceiling()->netUnitPrice('10', '20', '500'), '0', 4));
    }

    // ── ভাগ হয়ে যাওয়া বিক্রয় ─────────────────────────────────────

    /**
     * FEFO বিক্রয়টা কয়েক লটে ভাগ করে — প্রতিটার সিলিং আলাদা করে দেখা হয়।
     *
     * পুরনো লটের সিলিং ৳২০, নতুনটার ৳২২। ৳২১ দরে বেচলে নতুনটার জন্য
     * বৈধ, পুরনোটার জন্য নয় — আর বিক্রয়টা দুইটাই ছুঁলে সেটা আটকাতে হবে।
     */
    public function test_every_lot_in_a_split_sale_is_checked(): void
    {
        $allocation = [
            ['batch' => $this->lot('NEW', '22.0000'), 'qty' => '2'],
            ['batch' => $this->lot('OLD', '20.0000'), 'qty' => '3'],
        ];

        $this->expectException(ValidationException::class);

        $this->ceiling()->assertAllocationWithin($allocation, '21');
    }

    public function test_a_split_sale_under_every_ceiling_is_allowed(): void
    {
        $allocation = [
            ['batch' => $this->lot('NEW', '22.0000'), 'qty' => '2'],
            ['batch' => $this->lot('OLD', '20.0000'), 'qty' => '3'],
        ];

        $this->ceiling()->assertAllocationWithin($allocation, '19.5');

        $this->addToAssertionCount(1);
    }

    /** ছাপা দামহীন লট মিশে থাকলেও বাকিগুলোর সিলিং খাটে। */
    public function test_a_priceless_lot_does_not_lift_the_others_ceiling(): void
    {
        $allocation = [
            ['batch' => $this->lot('NOPRICE', null), 'qty' => '2'],
            ['batch' => $this->lot('OLD', '20.0000'), 'qty' => '3'],
        ];

        $this->expectException(ValidationException::class);

        $this->ceiling()->assertAllocationWithin($allocation, '25');
    }
}
