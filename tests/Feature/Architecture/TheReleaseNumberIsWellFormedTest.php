<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⭐ config/release.php-র সংস্করণ SemVer, তারিখ আসল দিন, আর দুই ভাষার নোটেই এই সংস্করণের অংশ আছে (মালিক, ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ fe প্রতিটা main-এ আনার সময় নম্বর বাড়ান। ⛔ "1.0", "v1.0.0" বা "24-10-2026" বসলে, বা নোট লেখা না হলে, এখানে লাল —
 * লাইভে নিচের বার আর "নতুন কী এল" ভুল কথা বলার আগেই।
 */
final class TheReleaseNumberIsWellFormedTest extends TestCase
{
    public function test_the_version_is_semver_and_the_date_is_a_real_day(): void
    {
        $file = require base_path('config/release.php');

        $this->assertMatchesRegularExpression('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', (string) ($file['version'] ?? ''),
            '⛔ config/release.php-র version SemVer নয় (বড়.মাঝারি.ছোট, যেমন 1.0.0)।');

        $date = (string) ($file['date'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $this->assertTrue($parsed !== false && $parsed->format('Y-m-d') === $date,
            '⛔ config/release.php-র date YYYY-MM-DD রূপের আসল দিন নয়: '.$date);
    }

    public function test_both_languages_have_notes_for_this_version(): void
    {
        $version = (string) ((require base_path('config/release.php'))['version'] ?? '');

        foreach (['bn', 'en'] as $lang) {
            $notes = (string) @file_get_contents(base_path('resources/release-notes/'.$lang.'.md'));
            $this->assertMatchesRegularExpression('/^##\s+v?'.preg_quote($version, '/').'\b/mu', $notes,
                '⛔ resources/release-notes/'.$lang.'.md-তে '.$version.'-এর অংশ নেই — "নতুন কী এল" এই সংস্করণের কথা বলবে না।');
        }
    }
}
