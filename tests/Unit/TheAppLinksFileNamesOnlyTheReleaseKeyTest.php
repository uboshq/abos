<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ⭐ Android App Links — নোটিশ বা SMS-এর erp.adi.com.bd লিংকে চাপলে ফোনে ABOS অ্যাপের ঠিক সেই পাতা খোলে, ব্রাউজার নয়
 * (মোবাইলের পরিকল্পনা ৪, অংশ ৬; fe, ২০ অক্টোবর ২০২৬)।
 *
 * ⓘ Android লিংক মানে কেবল যখন `/.well-known/assetlinks.json` ঠিক এই অ্যাপের নাম আর এর সই-চাবির SHA-256 ছাপ দেয়। ছাপ
 * পড়া হয়েছে প্রকাশিত APK থেকে (`apksigner verify --print-certs`, 0.4.38 আর 0.4.17 দুটোতে একই) — চাবি বা পাসওয়ার্ড ছুঁয়ে নয়।
 *
 * ⛔ এখানে অন্য কোনো চাবি ঢুকলে সেই চাবির যেকোনো অ্যাপ আমাদের লিংক ধরতে পারত — তাই ছাপ ঠিক একটা, আর সেটাই।
 */
final class TheAppLinksFileNamesOnlyTheReleaseKeyTest extends TestCase
{
    private const RELEASE_KEY = '25:2D:D4:F7:71:31:2F:C3:F2:E9:E7:37:48:D2:B7:2E:8C:71:CE:8E:ED:AB:6B:2A:3C:CA:C0:34:FC:16:13:1A';

    public function test_the_file_hands_every_link_to_the_abos_app_signed_with_the_release_key_and_nothing_else(): void
    {
        $path = dirname(__DIR__, 2).'/public/.well-known/assetlinks.json';
        $this->assertFileExists($path);

        $statements = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $statements);
        $this->assertSame(['delegate_permission/common.handle_all_urls'], $statements[0]['relation']);
        $this->assertSame('android_app', $statements[0]['target']['namespace']);
        $this->assertSame('com.abos.abos_mobile', $statements[0]['target']['package_name']);
        $this->assertSame([self::RELEASE_KEY], $statements[0]['target']['sha256_cert_fingerprints']);
    }

    public function test_the_file_is_served_as_it_is_not_through_laravel(): void
    {
        // ⓘ public/.htaccess পাঠায় কেবল যা ফাইল নয় — এই ফাইল আছে, তাই Apache নিজেই দেয়, Laravel-এর পথে নয়
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $this->htaccess());
    }

    public function test_the_file_goes_out_as_application_json(): void
    {
        // ⛔ Android যাচাই কেবল application/json মানে — সার্ভারের ডিফল্টে ভরসা নয়, .htaccess নিজে বলে দেয়
        $this->assertMatchesRegularExpression('#^\s*AddType\s+application/json\s+\.json\s*$#m', $this->htaccess());
    }

    public function test_no_redirect_can_reach_the_file(): void
    {
        // ⛔ Android যাচাই redirect মানে না (একটা 301-ও লিংক ব্রাউজারে ফেলে দেয়)। .htaccess-এর একমাত্র redirect
        // শেষের '/' মোছে — যার পথ '/' দিয়ে শেষ আর যা ফোল্ডার নয়; '/.well-known/assetlinks.json' তাতে পড়ে না।
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $this->htaccess())),
            fn (string $l) => $l !== '' && $l[0] !== '#',
        ));

        foreach ($lines as $line) {
            $this->assertDoesNotMatchRegularExpression('/^Redirect(Match|Permanent)?\b/i', $line, 'no mod_alias redirect');
        }

        // ⓘ RewriteRule-এর পতাকায় R বা R=30x — redirect
        $redirects = array_keys(array_filter(
            $lines,
            fn (string $l) => (bool) preg_match('/^RewriteRule\b.*\[[^\]]*\bR(=\d+)?\b[^\]]*\]/', $l),
        ));
        $this->assertCount(1, $redirects, 'one redirect only: the trailing-slash one');
        $this->assertSame('RewriteCond %{REQUEST_URI} (.+)/$', $lines[$redirects[0] - 1]);
        $this->assertSame(0, preg_match('#(.+)/$#', '/.well-known/assetlinks.json'), 'the file path does not end in a slash');
    }

    private function htaccess(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/public/.htaccess');
    }
}
