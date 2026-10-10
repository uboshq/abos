<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ⛔ অডিটের কাজের নাম ২৪ অক্ষরের মধ্যে — `audit_trails.action` ঘরটা string(24) (১১ অক্টোবর ২০২৬, documents রিভিউ)।
 *
 * ⓘ ঘরটা ছোট, আর লম্বা নাম কোনো সতর্কতা ছাড়াই গোটা কাজটা ভেঙে দেয় (৫০০): "document_published_without_approval" (৩৫) লেখায় অনুমোদন
 * বন্ধ কোম্পানিতে কাগজ জমাই দেওয়া যেত না, আর "document_version_restored" আগেই একবার একই কারণে ছোট করতে হয়েছিল (9d78ba11)।
 * ⭐ কোডে লেখা প্রতিটা `auditAction('…')` পড়ে দেখা।
 */
final class AnAuditWordFitsItsColumnTest extends TestCase
{
    private const COLUMN = 24;

    public function test_every_audit_action_written_in_the_code_fits_the_column(): void
    {
        $long = [];
        $seen = 0;

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            preg_match_all("/auditAction\(\s*'([a-z0-9_]+)'/", $file->getContents(), $m);

            foreach ($m[1] as $action) {
                $seen++;

                if (strlen($action) > self::COLUMN) {
                    $long[] = $action.' ('.strlen($action).') — '.$file->getRelativePathname();
                }
            }
        }

        $this->assertGreaterThan(20, $seen, 'ⓘ খোঁজাটাই কিছু পায়নি — দাবিটা কিছু মাপছে না।');
        $this->assertSame([], $long, "⛔ অডিটের ঘরে ধরে না — লেখায় ৫০০:\n".implode("\n", $long));
    }
}
