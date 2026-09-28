<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সরাসরি ক্রয়ের পর্দা বলত ভ্যাট "খরচেরই অংশ" — অথচ খাতা বলে উল্টো। মালিক, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ─────────────────────────────────────────────────────
 * ক্রয়ের ভ্যাট বসে ফেরতযোগ্য খাতে (২১২০, input VAT) — মালের খরচে নয়
 * ([[DirectPurchaseCostsLandRightTest]] তা মেপে রাখে)। পর্দার লেখা বলত
 * উল্টোটা, তাই যিনি পড়তেন তিনি ভাবতেন মজুদের দাম ভ্যাটসহ, আর ভ্যাট
 * ফেরতের হিসাব বাদ পড়ত।
 *
 * ⓘ মালিকের সিদ্ধান্ত (খ): কোম্পানি-প্রতি একটা সুইচ আসবে "ক্রয়ের ভ্যাট
 * ফেরতযোগ্য: হ্যাঁ/না", ডিফল্ট হ্যাঁ — আজকের আচরণ। সুইচ আসার আগেই
 * লেখাটা সারানো হলো, কারণ আজ প্রতিটা কোম্পানির জন্য এটাই সত্যি।
 * ⚠️ সুইচ এলে "না"-র দিকে লেখাটা বদলাবে — তখন এই দাবিও দুই ভাগ হবে।
 */
final class TheVatLabelCalledItPartOfTheCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_says_the_vat_is_recoverable_not_cost(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $html = $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->get(route('purchase.direct.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('খরচেরই অংশ', html_entity_decode($html),
            '⛔ পর্দা এখনো বলে ভ্যাট খরচের অংশ — খাতায় সে যায় ফেরতযোগ্য ২১২০-এ।');
        $this->assertTrue(str_contains($html, e(__('purchase::field.vat_recoverable'))),
            '⭐ ভ্যাটের সারিতে "ফেরতযোগ্য" লেখাটা নেই।');
    }
}
