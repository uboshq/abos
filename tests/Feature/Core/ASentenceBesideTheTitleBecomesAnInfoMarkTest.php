<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * ⭐ Owner "ok", 24 Nov 2026 (sample 9VVoe6uQ v2): the sentence beside a list title ("আদেশে কিছুই নড়ে না — …") goes into the
 * round ⓘ, and the head row stays one line. The toolbar marks it; the look's CSS hides the line and shows the ⓘ — in the three
 * ABOS looks only (app.css), so every other look still shows the line exactly as before.
 *
 * Claims:
 *   · a sentence in `count` (no digit) → ⓘ inside the heading with the full text, the line marked `data-title-note-text`;
 *   · a long sentence that holds a number (> 40 letters) → the same;
 *   · `subtitle` with no count → the same;
 *   · a real count ("১২টি রেকর্ড") → no ⓘ, no mark — it stays visible beside the title.
 */
final class ASentenceBesideTheTitleBecomesAnInfoMarkTest extends TestCase
{
    public function test_a_sentence_in_the_count_slot_becomes_the_info_mark(): void
    {
        $note = 'আদেশে কিছুই নড়ে না — স্টকেও নয়, খাতাতেও নয়। মাল এলে তখন।';
        $html = (string) $this->blade('<x-ui.toolbar title="ক্রয় আদেশ" :count="$c" :filter="false" />', ['c' => $note]);

        $this->assertMatchesRegularExpression('/data-view-selector[^>]*>(?:(?!<\/h[12]>).)*data-title-note[^-]/s', $html, '⛔ the ⓘ is not inside the title');
        $this->assertStringContainsString('title="'.e($note).'"', $html, '⛔ the ⓘ lost the sentence');
        $this->assertStringContainsString('data-title-note-text', $html, '⛔ the line is not marked — it would stay beside the title');
    }

    public function test_a_long_sentence_with_a_number_and_a_bare_subtitle_are_notes_too(): void
    {
        $long = 'কোন কাজে কে কত টাকা থেকে অনুমোদন দেবেন তার নিয়ম · ৫টা নিয়ম চালু';
        $this->assertStringContainsString('data-title-note-text', (string) $this->blade('<x-ui.toolbar title="নিয়ম" :count="$c" :filter="false" />', ['c' => $long]));
        $this->assertStringContainsString('data-title-note-text', (string) $this->blade('<x-ui.toolbar title="নিয়ম" subtitle="কেবল দেখা" :filter="false" />'));
    }

    public function test_a_real_count_stays_where_it_is(): void
    {
        $html = (string) $this->blade('<x-ui.toolbar title="গ্রাহক" count="১২টি রেকর্ড" :filter="false" />');

        $this->assertStringNotContainsString('data-title-note', $html, '⛔ a real count was folded into the ⓘ');
        $this->assertStringContainsString('১২টি রেকর্ড', $html);
    }
}
