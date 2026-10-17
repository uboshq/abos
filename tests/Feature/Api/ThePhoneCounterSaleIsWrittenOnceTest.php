<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Middleware\RemembersAPhoneWrite;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * ⛔ ফোনের কাউন্টারের বিক্রি একবারই — ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ⓘ জমার বিজ্ঞপ্তি, আদেশ, DO, ফেরত — সব দরজা ফোনের চাবি মনে রাখে ([[ThePhoneWroteItTwiceTest]]), কেবল কাউন্টারের বিক্রি নয়। সময়
 * পেরোনোর পরে ক্যাশিয়ার "আবার করুন" চাপলে দ্বিতীয় বিল, দ্বিতীয়বার মাল বের আর দ্বিতীয়বার টাকা। ফোন এখন চাবি পাঠায়, আর দরজাটা
 * সেই চাবি মানে — একই চাবিতে প্রথম উত্তরই ফেরে (মিডলওয়্যারের নিজের দাবি [[ThePhoneWroteItTwiceTest]]-এ)।
 */
final class ThePhoneCounterSaleIsWrittenOnceTest extends TestCase
{
    public function test_the_phone_counter_sale_door_remembers_the_phones_key(): void
    {
        $door = collect(Router::getRoutes()->getRoutes())
            ->first(fn (Route $route) => $route->uri() === 'api/v1/sales/direct' && in_array('POST', $route->methods(), true));

        $this->assertNotNull($door, 'দৃশ্যটাই নেই — ফোনের কাউন্টারের দরজা খুঁজে পাওয়া গেল না');
        $this->assertContains(RemembersAPhoneWrite::class, $door->gatherMiddleware(),
            '⛔ কাউন্টারের বিক্রির দরজা ফোনের চাবি মনে রাখে না — সময় পেরোনোর পরে আবার চাপলে দ্বিতীয় বিল');
    }
}
