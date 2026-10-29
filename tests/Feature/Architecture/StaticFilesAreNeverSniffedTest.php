<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⭐ লোগো আর ছবির মতো স্থির ফাইলেও `X-Content-Type-Options: nosniff` (fe, ২২ অক্টোবর ২০২৬)।
 *
 * ⓘ Laravel-এর উত্তরে হেডারটা মিডলওয়্যার বসায় ([[ContentSecurityPolicy]]); কিন্তু `/storage/…`-এর ফাইল লাইভের Apache
 * নিজেই দেয়, তাই নিয়মটা `public/.htaccess`-এ থাকতে হয়। ⛔ লাইনটা কেউ মুছলে কোনো পাতা ভাঙে না — ব্রাউজার চুপচাপ আবার
 * ধরন আন্দাজ করতে শুরু করে, আর ছবির নামে রাখা HTML পাতা হয়ে চলতে পারে। তাই পাহারা।
 */
final class StaticFilesAreNeverSniffedTest extends TestCase
{
    public function test_apache_sets_nosniff_on_every_file_it_serves(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        $this->assertMatchesRegularExpression(
            '/<IfModule mod_headers\.c>\s*(?:[^<]*\n)*?\s*Header always set X-Content-Type-Options "nosniff"/',
            $htaccess,
            '⛔ public/.htaccess-এ `Header always set X-Content-Type-Options "nosniff"` নেই — লাইভের স্থির ফাইল (লোগো, ছবি) ধরন আন্দাজে চলবে'
        );
    }
}
