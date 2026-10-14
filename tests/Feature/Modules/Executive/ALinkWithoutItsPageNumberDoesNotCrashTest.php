<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Models\Company;
use App\Models\User;
use App\Modules\Executive\Support\Go;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ দরকারি অংশ ছাড়া পাতার পথ (যেমন পণ্যের পাতা, পণ্য ছাড়া) চাপলে ৫০০ — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ দরজা আগে কোম্পানি বদলাত, তারপর ঠিকানা বানাতে গিয়ে ভাঙত: মানুষটা ভাঙা পাতা দেখতেন আর অন্য কোম্পানিতে বসে যেতেন।
 * এখন এমন পথ খালি মান পায়; দরজা যাচাইয়েই ফেরায়, কিছু বদলায় না।
 */
final class ALinkWithoutItsPageNumberDoesNotCrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_link_missing_a_required_page_part_is_refused_without_a_crash_or_a_switch(): void
    {
        $this->seed(DemoSeeder::class);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();
        $before = (int) $owner->current_company_id;
        $this->assertNotSame((int) $beta->id, $before, 'প্রস্তুতিটাই ভুল — মালিক আগেই অন্য কোম্পানিতে।');

        $go = Go::to((int) $beta->id, null, 'inventory.product.show', ['product' => null]);
        $this->assertSame('', $go, '⛔ পণ্য ছাড়া পণ্যের পাতার পথ বানানো হলো।');

        $this->actingAs($owner)
            ->from(route('executive.today'))
            ->post(route('executive.open'), ['go' => $go])
            ->assertRedirect(route('executive.today'));

        $this->assertSame($before, (int) $owner->fresh()->current_company_id, '⛔ ভাঙা পথে চাপতেই কোম্পানি বদলে গেল।');

        // ⓘ অংশ থাকলে পথ আগের মতোই
        $this->assertStringContainsString('route=inventory.product.show', Go::to((int) $beta->id, null, 'inventory.product.show', ['product' => 5]));
    }
}
