<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ডিপোজিটরের ধরন আগে থেকেই বসানো — আদায়ে গ্রাহক, পরিশোধে সরবরাহকারী।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: *"ডিপোজিটরের ধরন আদায় ভাউচার e grahok, payment e supplyer,
 * defolt koro"*। ⓘ টাকা আসে প্রায় সবসময় গ্রাহকের কাছ থেকে আর যায়
 * সরবরাহকারীর কাছে, তাই ফাঁকা ঘরটা প্রতিটা কাগজে একটা বাড়তি ক্লিক —
 * আর ধরন না বাছা পর্যন্ত নামের তালিকাটাও খালি থাকে।
 *
 * ⚠️ পুরনো কাগজ খুললে তার নিজের ধরনই থাকে; ডিফল্টটা কেবল নতুনে।
 */
final class TheDepositorTypeWasBlankOnEveryReceiptTest extends TestCase
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

    public function test_a_new_receipt_starts_on_customer_and_a_payment_on_supplier(): void
    {
        foreach ([Voucher::RECEIPT => 'customer', Voucher::PAYMENT => 'supplier'] as $type => $expected) {
            $html = $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();

            preg_match('/<select[^>]*name="party_type"(.*?)<\/select>/s', $html, $select);

            $this->assertNotEmpty($select, "{$type}: ধরনের ঘরটাই নেই।");

            preg_match('/<option[^>]*value="([a-z_]+)"[^>]*selected/', $select[1], $chosen);

            $this->assertSame($expected, $chosen[1] ?? null, "{$type}: আগে থেকে \"{$expected}\" বাছা নেই।");
        }
    }

    /** ⭐ পাওনার ঘরটা রঙিন — ধূসর ছোট লেখায় সংখ্যাটা চোখ এড়িয়ে যেত। */
    public function test_the_dues_box_carries_a_background(): void
    {
        $html = $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();

        $this->assertStringContainsString('color-badge-danger-bg', $html, 'পাওনার ঘরে রঙের পটভূমি নেই।');
        $this->assertStringContainsString('color-badge-success-bg', $html, 'অগ্রিমের জন্য আলাদা রং নেই।');
    }
}
