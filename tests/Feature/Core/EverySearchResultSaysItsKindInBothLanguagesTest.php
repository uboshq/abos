<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\SearchEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * ⚠️ খোঁজার প্রতিটা উৎসের নাম দুই ভাষায় — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬ ([[SearchEngine::hitFrom()]]).
 *
 * ⓘ নাম না থাকলে ফলাফলে কাঁচা স্লাগ বসত ("sales_quotation · SQ-0012"), আর Ctrl+K-এর "নতুন …" কাজটা চুপচাপ বাদ পড়ত
 * ([[StartingPoints::creatable()]])। ২৯টা ধরন এভাবে ছিল — উদ্ধৃতি, স্থায়ী সম্পদ, অর্থের কাগজ, লট…।
 */
final class EverySearchResultSaysItsKindInBothLanguagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_search_source_has_a_name_in_bangla_and_english(): void
    {
        $sources = app(SearchEngine::class)->sources();
        $this->assertNotEmpty($sources, 'খোঁজার একটাও উৎস নেই — দাবিটা ফাঁকা।');

        $missing = [];

        foreach (array_keys($sources) as $class) {
            $slug = $class::drillSourceType();

            foreach (['bn', 'en'] as $locale) {
                if (! Lang::has('core.source.'.$slug, $locale, false)) {
                    $missing[] = $locale.': '.$slug.' ('.$class.')';
                }
            }
        }

        $this->assertSame([], $missing, "⚠️ এই উৎসগুলোর নাম নেই — খোঁজায় কাঁচা স্লাগ দেখাবে:\n".implode("\n", $missing));
    }
}
