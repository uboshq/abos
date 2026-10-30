<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;

/**
 * ⭐ "কোথায় আছি"-র শেষ অংশ — নতুন কাগজের পাতায় "› নতুন", সম্পাদনার পাতায় কাগজের নিজের নম্বর ("› RCV-0143"), নম্বর না থাকলে
 * "› সম্পাদনা" (রসিদ-পরিশোধ মিল-তালিকা #৬ আর #৫২, নকশা পাতা ২ আর ৪; ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ একবারই, খোলসে ([[shell/page-path]]) — কোনো পাতাকে কিছু লিখতে হয় না। পাতা চেনা হয় রুটের নাম থেকে, Laravel-এর resource
 * রীতিতে: `….create` নতুন, `….edit` সম্পাদনা (অ্যাপের ১০২টা `.create` আর ৬৯টা `.edit` রুট এই রীতিতেই)।
 * ⓘ নম্বর রুটের বাঁধা মডেল থেকে — খোঁজার ইঞ্জিনের নম্বরের একই ঘরগুলো ([[SearchEngine]]-এর `document_no`, `sale_no`, `code`);
 * রুট কেবল আইডি নিলে (মডেল বাঁধা নয়) নম্বর পড়ার উপায় নেই, তখন "সম্পাদনা"।
 * ⚠️ ঘরটা মডেলে আসলেই আছে কি না আগে দেখা — পরীক্ষায় অনুপস্থিত ঘর পড়লে Laravel ছোঁড়ে (strict mode)।
 */
final class PageTail
{
    /** @var list<string> কাগজের নিজের নম্বর যে ঘরে থাকতে পারে — আগেরটা আগে */
    public const NUMBER_COLUMNS = ['document_no', 'sale_no', 'code'];

    public static function of(?Route $route): ?string
    {
        $name = (string) $route?->getName();

        if (str_ends_with($name, '.create')) {
            return __('core.crumb.new');
        }

        if ($route === null || ! str_ends_with($name, '.edit')) {
            return null;
        }

        foreach ($route->parameters() as $value) {
            if (! $value instanceof Model) {
                continue;
            }

            $attributes = $value->getAttributes();

            foreach (self::NUMBER_COLUMNS as $column) {
                $number = array_key_exists($column, $attributes) ? trim((string) $attributes[$column]) : '';

                if ($number !== '') {
                    return $number;
                }
            }
        }

        return __('core.crumb.edit');
    }
}
