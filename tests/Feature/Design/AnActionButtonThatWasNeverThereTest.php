<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use Tests\TestCase;

/**
 * যে Action বোতামটা কোনোদিন পর্দায় ছিলই না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `x-ui.row-actions` কেবল `:items` অ্যারে আঁকে। সে `$slot` কোথাও রেন্ডার
 * করে না, আর ভেতরে `@if ($items !== [])` থাকায় স্লট দিয়ে ডাকলে
 * **কোনো ত্রুটি ছাড়াই কিছুই আঁকত না**।
 *
 * `recipe-actions.blade.php` ঠিক ওভাবেই ডাকত — সম্পাদনা আর চালু/বন্ধের
 * লিংক দুইটা স্লটে লেখা। ফলে রেসিপির তালিকায় Action বোতামটা অদৃশ্য
 * ছিল, আর সেটা হুবহু সেই অভিযোগ যেটা মালিক ৩ সেপ্টেম্বর ২০২৬-এ
 * পণ্যের তালিকা নিয়ে করেছেন।
 *
 * ── কেন এটা কোথাও ধরা পড়েনি ─────────────────────────────────────────
 * ব্যর্থতাটা নীরব: পাতা ২০০ দিত, টেবিল আঁকত, ছবিও তোলা যেত। কেবল
 * ডান কলামের ঘরটা খালি থাকত — আর একটা খালি ঘর দেখে কেউ ভাবে না যে
 * ওখানে কিছু থাকার কথা ছিল।
 *
 * ⚠️ ডেমো ডাটায় একটাও রেসিপি নেই, তাই সারিটাই কখনো আঁকা হয়নি। যেদিন
 * প্রথম রেসিপি তৈরি হত, সেদিন বোতামটা না থাকত — আর কেউ বলতে পারত না
 * এটা নতুন বাগ না পুরনো।
 *
 * ── এই পরীক্ষাটা কেন কম্পোনেন্ট ধরে ──────────────────────────────────
 * পর্দা ধরে দেখতে গেলে আগে রেসিপি বসাতে হত, আর তখন পরীক্ষাটা ডেটার
 * উপর নির্ভর করত — অর্থাৎ ডেটা না থাকলে সবুজ, যেটা আসল সমস্যাটারই
 * পুনরাবৃত্তি। কম্পোনেন্টের চুক্তিটা সরাসরি পরখ করলে ডেটা লাগে না।
 */
class AnActionButtonThatWasNeverThereTest extends TestCase
{
    /**
     * স্লট দিয়ে ডাকলে নীরবে হারায় না — জোরে ভাঙে।
     *
     * ⓘ ব্যতিক্রমটা `InvalidArgumentException` হিসেবে ছোঁড়া হয়, কিন্তু
     * Blade সেটাকে `ViewException`-এ মুড়ে দেয়। এখানে সেটাই ধরা হচ্ছে —
     * `InvalidArgumentException` ধরতে চাইলে পরীক্ষাটা পাস করত না, আর
     * কারণটা বোঝা কঠিন হত।
     */
    public function test_calling_row_actions_with_a_slot_fails_loudly(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/never slot content/');

        Blade::render('<x-ui.row-actions><a href="/x">Edit</a></x-ui.row-actions>');
    }

    /**
     * আর সঠিক পথটা আগের মতোই আঁকে।
     *
     * পাহারা বসানোর সময় আসল কাজটা ভেঙে ফেলা সবচেয়ে সহজ ভুল, তাই
     * দুইটা দিকই এক ফাইলে।
     */
    public function test_items_still_render_a_button_and_its_menu(): void
    {
        $html = Blade::render(
            '<x-ui.row-actions :items="$i" />',
            ['i' => [['label' => 'Edit', 'url' => '/x']]]
        );

        $this->assertStringContainsString('aria-haspopup', $html);
        $this->assertStringContainsString('Edit', $html);
    }

    /**
     * খালি তালিকায় কিছুই আঁকে না — আর সেটা ঠিক।
     *
     * অনুমতি না থাকলে `$items` খালি হয়, আর তখন একটা কাজহীন বোতাম
     * দেখানোর মানে নেই। নীরব থাকাটা এখানে বাগ নয়, নকশা — তফাতটা
     * হলো স্লটের ক্ষেত্রে ডাকা ভুল ছিল, এখানে ডাকাটা ঠিক।
     */
    public function test_an_empty_item_list_draws_nothing(): void
    {
        $this->assertSame('', trim(Blade::render('<x-ui.row-actions :items="[]" />')));
    }

    /**
     * মেনুটা fixed-এ বসে, নাহলে শেষ সারিতে সে অদৃশ্য।
     *
     * ── কেন এটা পরীক্ষায় বাঁধা ───────────────────────────────────────
     * সারির বোতামটা `.table-responsive`-এর ভেতরে, আর তার
     * `overflow-x: auto` **দুই দিকেই** কাটে: CSS-এ এক অক্ষে `auto`
     * দিলে অন্যটা আর `visible` থাকতে পারে না, নীরবে `auto` হয়ে যায়।
     *
     * তাই `absolute` মেনুটা শেষ সারিতে পুরোপুরি অদৃশ্য হয়ে যেত — মাপা
     * হয়েছিল স্ক্রলারের তল ছাড়িয়ে ১৩৬px। ব্যবহারকারীর মনে হত বোতামটা
     * কাজ করে না।
     *
     * ⚠️ কেউ একদিন `absolute` ক্লাসটা ফিরিয়ে আনলে বাগটা হুবহু ফিরে
     * আসত, আর সেটা কেবল **শেষ সারিতে** দেখা যেত — অর্থাৎ স্ক্রিনশটে
     * নয়, কেবল হাতে খুলে দেখলে।
     *
     * ── কেন `fixed` ক্লাসে, JS-এ নয় ──────────────────────────────────
     * খোলার পর JS দিয়ে `position` বসালে এক মুহূর্তের জন্য মেনুটা
     * `static` থাকে, আর তখন সে ঘরের ভেতরে জায়গা নিয়ে **বোতামটাকেই বাঁ
     * দিকে ঠেলে দেয়** (`flex justify-end`)। ওই সরানো অবস্থার মাপ নিয়ে
     * মেনু বসত ২০৪px বাঁয়ে — প্রায় মেনুরই প্রস্থ। ক্লাসে থাকলে সে
     * কখনো ফ্লোতে ঢোকেই না।
     */
    public function test_the_menu_escapes_the_tables_clipping(): void
    {
        $html = Blade::render(
            '<x-ui.row-actions :items="$i" />',
            ['i' => [['label' => 'Edit', 'url' => '/x']]]
        );

        $this->assertStringContainsString('class="fixed z-50', $html);
        $this->assertStringNotContainsString('absolute end-0 top-full', $html);
    }
}
