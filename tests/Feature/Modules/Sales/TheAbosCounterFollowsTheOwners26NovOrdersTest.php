<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ABOS রূপের (Smart/Navy/Blue) সরাসরি বিক্রয় কাউন্টার — মালিকের ২৬ নভেম্বর ২০২৬-এর নির্দেশ (লাইভ 1.3.27–1.3.29):
 *
 *   · নয় বোতাম: … মাল কীভাবে নেবে, খসড়া রাখুন, মন্তব্য, ফেরত নিন, খসড়া খুলুন, আবার ছাপুন, বিল বাতিল — তারপর নিশ্চিত করুন
 *     (ফেরত আর খসড়া অদলবদল, মন্তব্য যোগ — "লোডিং খরচ" বোতাম নয়);
 *   · ক্রেতার নাম রঙিন পিলে, নিচে পয়েন্ট (শাখা নয়); ঠিকানার সারি নেই;
 *   · মজুদের বাক্সগুলো পরিমাণের ঘরের ঠিক উপরে নিজের সারিতে; উপহার কার্টের আগে; বাছা পণ্যের নাম পিলে;
 *   · কার্টের নিচে মোট (tfoot); ভাড়ার পাঁচ বোতাম;
 *   · "ক্রয়মূল্য" পপআপ আর তার ঠিকানা কেবল `sales.cost.view`-এ — ⛔ চাবি ছাড়া 403, পাতায় পপআপও নেই।
 *
 * ⓘ ক্লাসিক কাউন্টার (SAP/Dynamic/Odoo) আগের মতো — তার দাবি পুরোনো পরীক্ষাগুলোয়, `ui = dynamic` দিয়ে।
 */
final class TheAbosCounterFollowsTheOwners26NovOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['ui' => 'rose'])->save();
    }

    public function test_the_abos_counter_stands_as_the_owner_ordered(): void
    {
        $html = (string) $this->actingAs($this->owner->fresh())->get(route('sales.direct.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-abos-counter', $html, 'প্রস্তুতিটাই ভুল — ABOS রূপের কাউন্টার খোলেনি।');

        $at = function (string $needle, int $from = 0) use ($html): int {
            $pos = strpos($html, $needle, $from);
            $this->assertNotFalse($pos, "⛔ পাতায় নেই: {$needle}");

            return (int) $pos;
        };

        // নয় বোতাম, মালিকের ক্রমে
        $order = array_values(array_filter(
            ['deposit', 'transport', 'delivery', 'save_draft', 'note', 'return', 'drafts', 'reprint', 'cancel'],
            fn (string $b) => str_contains($html, 'data-counter-button="'.$b.'"'),
        ));
        foreach (['delivery', 'save_draft', 'note', 'return', 'drafts', 'reprint', 'cancel'] as $must) {
            $this->assertContains($must, $order, "⛔ বোতাম নেই: {$must}");
        }
        $positions = array_map(fn (string $b) => $at('data-counter-button="'.$b.'"'), $order);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '⛔ বোতামগুলো মালিকের ২৬ নভেম্বরের ক্রমে নেই: '.implode(', ', $order));
        $this->assertGreaterThan($at('data-counter-button="cancel"'), $at('x-ref="confirm"'), '⛔ "নিশ্চিত করুন" শেষে নেই।');

        // ক্রেতার ঘর
        $this->assertStringContainsString('ds-name-pill', $html, '⛔ ক্রেতার নাম পিলে নেই।');
        $this->assertStringContainsString('customer.point', $html, '⛔ নামের নিচে পয়েন্ট নেই।');
        $this->assertStringNotContainsString('x-text="customer.address"', $html, '⛔ ঠিকানার সারি ফিরে এসেছে — মালিক বাদ দিতে বলেছিলেন।');

        // মজুদের সারি পরিমাণের ঘরের উপরে, উপহার কার্টের আগে, বাছা পণ্যের নাম পিলে
        $stock = $at('ds-stockrow');
        $this->assertGreaterThan($stock, $at('ds-qtyrow', $stock), '⛔ মজুদের বাক্স পরিমাণের ঘরের উপরে নেই।');
        $gift = $at('data-gift-button');
        $this->assertGreaterThan($gift, $at('data-cart-button', $gift), '⛔ উপহার কার্টের আগে নেই।');
        $this->assertStringContainsString('ds-picked-pill', $html, '⛔ বাছা পণ্যের নাম পিলে নেই।');

        // কার্টের নিচে মোট, ভাড়ার পাঁচ বোতাম
        $this->assertStringContainsString('data-grand-total', $html, '⛔ কার্টের নিচে মোটের সারি নেই।');
        foreach (['us_now', 'us_later', 'add', 'customer', 'none'] as $f) {
            $this->assertStringContainsString("fareChoose('{$f}')", $html, "⛔ ভাড়ার বোতাম নেই: {$f}");
        }

        // মালিকের কাছে ক্রয়মূল্যের পপআপ আছে
        $this->assertStringContainsString('data-cost-popup', $html, '⛔ মালিক ক্রয়মূল্যের পপআপ পাচ্ছেন না।');
    }

    public function test_the_cost_popup_needs_the_cost_key(): void
    {
        $product = Product::query()->where('company_id', $this->company->id)->firstOrFail();

        $this->actingAs($this->owner->fresh())->getJson(route('sales.direct.cost', $product->id))->assertOk()->assertJsonStructure(['found', 'book']);

        // ⛔ বিক্রি করতে পারেন, কিন্তু খরচ দেখার চাবি নেই
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id,
            'is_active' => true, 'ui' => 'rose']);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(
            \Spatie\Permission\Models\Permission::findOrCreate('sales.direct.create', 'web'),
            \Spatie\Permission\Models\Permission::findOrCreate('sales.view', 'web'),
        ));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk->fresh())->getJson(route('sales.direct.cost', $product->id))->assertForbidden();
    }
}
