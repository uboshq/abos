<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\ListFilter\Facet;
use App\Core\ListFilter\Facets;

/**
 * ⭐ রিপোর্টের নিজের বাছাইয়ের ঘর — ডান পাশের প্যানেলে, রিপোর্টের ঘোষণা থেকেই (মালিক, ২৩ নভেম্বর ২০২৬: *"রিপোর্টে ছাঁকা যায় না"*)।
 *
 * ⓘ যে ঘরের নির্দিষ্ট কয়টা মান আছে (টাকার মাধ্যম: নগদ / ব্যাংক / মোবাইল ব্যাংকিং; বাদ পড়ার কারণ), রিপোর্ট তা `choices`-এ ঘোষণা করে
 *   ([[ReportDefinition::$choices]]) — ইঞ্জিন সেখান থেকেই যাচাই করে। পাতার প্যানেল সেই একই তালিকা থেকে আঁকে, তাই প্রতিটা রিপোর্টের জন্য
 *   কন্ট্রোলারে আলাদা করে ঘর লিখতে হয় না, আর রিপোর্টে নতুন বাছাই এলে প্যানেলে নিজেই আসে।
 * ⓘ [[ReportPanel]] নিজে যা আঁকে (তারিখ, শাখা, হিসাব, সাধারণ ছাঁকনি …) তা `choices`-এ থাকে না — দুইবার আঁকা হয় না।
 */
final class ReportChoices
{
    /** @return list<Facet> */
    public static function facets(ReportDefinition $report): array
    {
        $facets = [];

        foreach ($report->choices as $key => $options) {
            if (! $report->hasFilter((string) $key) || $options === []) {
                continue;
            }

            $facets[] = Facets::pick(
                (string) $key,
                (string) __('core.report.choice.'.$key),
                array_map(fn (string $label): string => (string) __($label), $options),
                (string) __('core.report.filters.any'),
            )->look('teal', '◈');
        }

        return $facets;
    }
}
