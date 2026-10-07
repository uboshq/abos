<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হিসাবের ছয়টা ভাউচার-পর্দা — উপরের সারিতে একটাই "ভাউচার" বোতাম।
 *
 * ── কেন, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: ভাউচার তালিকা, আদায়, পরিশোধ, খরচ, জাবেদা, কন্ট্রা — *"eigulo mile
 * ekta group korlei hoy"*, নামে "ভাউচার" শব্দ ছাড়া, আর *"All Voucher List
 * sobar niche diba"*। ⓘ মেনুতে সারিগুলো `'cluster' => 'vouchers'` পায়, আর
 * উপরের সারি ([[shell.modulebar]]) সেগুলো এক ড্রপডাউনে বসায়।
 *
 * ⚠️ মাপটা রেন্ডার হওয়া পাতার উপর: ড্রপডাউনের ভিতরে ছয়টা ঠিকানা, ক্রমে,
 * সব ভাউচার শেষে — আর ঐ ছয়টার একটাও বাইরে আলাদা ঘর হয়ে নেই।
 */
final class TheVoucherScreensShareOneButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_six_voucher_screens_sit_in_one_dropdown_with_the_list_last(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = $this->get(route('accounts.voucher.index', ['type' => 'receipt']))->assertOk()->getContent();

        // ⚠️ "modulebar" শব্দটা পাতার মাথার রঙেও আছে — আসল সারি শুরু হয় তার পটভূমি থেকে
        $bar = $this->between($html, 'background:var(--color-modulebar', '</nav>');

        $expected = [
            route('accounts.voucher.index', ['type' => 'receipt']),
            route('accounts.voucher.index', ['type' => 'payment']),
            route('accounts.voucher.index', ['type' => 'expense']),
            route('accounts.voucher.index', ['type' => 'journal']),
            route('accounts.voucher.index', ['type' => 'contra']),
            route('accounts.voucher.list'),
        ];

        /*
         * ⓘ ড্রপডাউনটা খোঁজা হয় প্রথম ঠিকানা ধরে, লেখা ধরে নয় — ভাষা
         * ব্যবহারকারীর পছন্দে বদলায়, ঠিকানা বদলায় না।
         */
        $at = strpos($bar, 'href="'.$expected[0].'"');
        $this->assertNotFalse($at, 'উপরের সারিতে আদায়ের পর্দাই নেই।');
        $open = strrpos(substr($bar, 0, $at), '<div x-data="{ open: false }"');
        $this->assertNotFalse($open, 'আদায়ের পর্দা কোনো ড্রপডাউনের ভিতরে নেই — আলাদা ঘর হয়ে আছে।');
        $menu = substr($bar, $open, strpos($bar, '</div>', $at) - $open);

        $this->assertTrue(str_contains($menu, 'Vouchers') || str_contains($menu, 'ভাউচার'),
            'ড্রপডাউনের নাম "ভাউচার" নয়।');

        preg_match_all('/href="([^"]+)"/', $menu, $m);

        $this->assertSame($expected, array_values(array_intersect($m[1], $expected)),
            'ড্রপডাউনে ছয়টা পর্দা নেই, বা ক্রম ভুল (সব ভাউচার শেষে হওয়ার কথা)।');

        $outside = str_replace($menu, '', $bar);
        foreach ($expected as $url) {
            $this->assertStringNotContainsString('href="'.$url.'"', $outside, "{$url} ড্রপডাউনের বাইরেও আলাদা ঘর হয়ে আছে।");
        }

        $this->assertStringNotContainsString('Receipt Voucher', $bar, 'মেনুর নামে এখনো "Voucher" আছে।');
        $this->assertStringNotContainsString('আদায় ভাউচার', $bar, 'মেনুর নামে এখনো "ভাউচার" আছে।');
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "পাতায় '{$from}' নেই।");
        $end = strpos($html, $to, $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
