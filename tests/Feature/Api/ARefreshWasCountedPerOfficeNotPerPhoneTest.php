<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ টোকেন নবায়ন IP ধরে গোনা হত (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; routes/api.php-এর `throttle:refresh`)।
 *
 * ⚠️ মন্তব্য বলত "প্রতি ব্যবহারকারী", কিন্তু নবায়নের দরজায় `auth` নেই, তাই থলেটা IP-র — এক অফিস বা এক অপারেটরের NAT-এর
 * সব ফোন মিনিটে মোট ৩০; সকালের ভিড়ে একত্রিশতম ফোন ৪২৯ পেয়ে "সেশন শেষ" দেখত। ⭐ এখন প্রতি টোকেনে ৩০, IP-তে ৩০০।
 */
final class ARefreshWasCountedPerOfficeNotPerPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_thirty_one_phones_behind_one_address_each_get_their_refresh(): void
    {
        for ($phone = 1; $phone <= 31; $phone++) {
            $status = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => 'phone-'.$phone.'|not-a-real-token'])->status();

            $this->assertNotSame(429, $status, "⛔ একই ঠিকানার {$phone} নম্বর ফোনের নবায়ন থ্রটলে আটকাল");
        }
    }
}
