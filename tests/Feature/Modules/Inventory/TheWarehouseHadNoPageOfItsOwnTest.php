<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গুদামের নিজের পাতা — কী আছে, কোথায় বসে, কাগজ কোথায়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ভাড়ার চুক্তি গুদামের সাথে জোড়া লাগানোর পর ধরা পড়ল গুদামের কোনো পাতাই
 * নেই — কেবল তালিকা আর সম্পাদনার ফর্ম। ⓘ ফলে "এই গুদামের ভাড়া কত, কী
 * আছে, দায়িত্বে কে" জানতে তিন পর্দা ঘুরতে হত। মালিক: *"ok"*।
 *
 * ⛔ ভাড়ার সংখ্যা এখানে গোনা হয় না — মজুদ মডিউল অর্থ মডিউলের নাম জানে না।
 * ঘরটা একটা লিংক, আর সেটা ইচ্ছাকৃত।
 */
final class TheWarehouseHadNoPageOfItsOwnTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->orderBy('id')->firstOrFail();
    }

    public function test_the_page_opens_and_says_what_is_here(): void
    {
        $html = $this->get(route('inventory.warehouse.show', $this->warehouse))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->warehouse->name(), $html, 'গুদামের নামই পাতায় নেই।');
        $this->assertStringContainsString($this->warehouse->code, $html, 'কোডটা পাতায় নেই।');

        /* ⚠️ কম্পোনেন্ট কম্পাইল না হলে পাতা তবু ২০০ দেয় — তাই এই দাবিটা */
        $this->assertStringNotContainsString('<x-ui.', $html, 'একটা কম্পোনেন্ট লেখা হিসেবে ছাপা হয়েছে।');
    }

    /** ⭐ ভাড়ার চুক্তি — সংখ্যা নয়, ঐ গুদামে ছাঁকা তালিকার লিংক। */
    public function test_it_links_to_the_rent_for_this_warehouse(): void
    {
        $html = $this->get(route('inventory.warehouse.show', $this->warehouse))->assertOk()->getContent();

        $this->assertStringContainsString(
            e(route('finance.rental.index', ['subject' => 'warehouse:'.$this->warehouse->id])),
            $html,
            'ভাড়ার চুক্তির লিংকটা নেই — গুদাম খুলে ভাড়া দেখা যায় না।',
        );
    }

    /** তালিকা থেকে নামে চাপলেই পাতাটা খোলে — নাহলে দরজাটা কেউ খুঁজে পায় না। */
    public function test_the_list_links_to_it(): void
    {
        $html = $this->get(route('inventory.warehouse.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            e(route('inventory.warehouse.show', $this->warehouse)),
            $html,
            'তালিকায় গুদামের নাম লিংক নয়।',
        );
    }
}
