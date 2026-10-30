<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use Tests\TestCase;

/**
 * ⭐ মালিকের শব্দ "পোস্ট" — "পাকা" নয় (fe, ২৫ অক্টোবর ২০২৬; অ্যাপে a4-এর একই বদল, a4/voucher-app 339d24786): "পোস্ট হওয়া বিল",
 * "পোস্ট করুন"। বিক্রয় আর ক্রয়ের ওয়েবের বাংলা লেখায় যা মানুষ পর্দায় পড়েন, তার কোথাও "পাকা" নেই। ⓘ মন্তব্যের কথা নয় — ফাইল
 * যেমন লোড হয় তেমন পড়া, কেবল মান। পরে a5 সব মডিউলে একই পাহারা বসাবে।
 */
class TheOwnersWordIsPostNotPakaTest extends TestCase
{
    private const MODULES = ['Sales', 'Purchase'];

    public function test_no_sales_or_purchase_sentence_says_paka(): void
    {
        $files = [];
        foreach (self::MODULES as $module) {
            $files = [...$files, ...glob(app_path('Modules/'.$module.'/Resources/lang/bn/*.php')) ?: []];
        }
        $this->assertGreaterThan(20, count($files), 'প্রস্তুতিটাই ভুল — বিক্রয়/ক্রয়ের বাংলা লেখার ফাইলই প্রায় পাওয়া যায়নি');

        $found = [];
        foreach ($files as $file) {
            $values = include $file;
            if (! is_array($values)) {
                continue;
            }
            array_walk_recursive($values, function (mixed $value, int|string $key) use ($file, &$found): void {
                if (is_string($value) && str_contains($value, 'পাকা')) {
                    $found[] = str_replace(app_path('Modules').DIRECTORY_SEPARATOR, '', $file).' › '.$key;
                }
            });
        }

        $this->assertSame([], $found, '⛔ বিক্রয়/ক্রয়ের লেখায় এখনো "পাকা" — মালিকের শব্দ "পোস্ট" ("পোস্ট হওয়া বিল", "পোস্ট করুন"): '.implode(', ', $found));
    }

    public function test_the_changed_sentences_say_post(): void
    {
        $this->app->setLocale('bn');

        $this->assertStringContainsString('পোস্ট হওয়া চালানের', __('sales::validation.challan_not_billable'), '⛔ "বিল কেবল পোস্ট হওয়া চালানের" নয়');
        $this->assertStringContainsString('আদেশ পোস্ট করুন', __('purchase::validation.order_rate_missing'), '⛔ "আদেশ পোস্ট করুন" নয়');
        $this->assertStringContainsString('পোস্ট হওয়া আদায়', __('sales::collection_rules.second_person_cancels'), '⛔ "পোস্ট হওয়া আদায়" নয়');
    }
}
