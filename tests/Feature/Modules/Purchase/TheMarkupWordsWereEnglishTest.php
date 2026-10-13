<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use Tests\TestCase;

/**
 * ক্রয়ের লাইনে "markup %" আর "margin %" — বাংলা পাতায় ইংরেজি শব্দ (পাতা-ঝাড়ু ধাপ ২, ১২ অক্টোবর ২০২৬)।
 *
 * ⓘ মজুদ মডিউল আগে থেকেই "মার্কআপ" লেখে — ক্রয় এখন একই শব্দে। ⚠️ শব্দ দুটো আলাদাই থাকে: মার্কআপ খরচের উপর, মার্জিন দামের উপর।
 */
final class TheMarkupWordsWereEnglishTest extends TestCase
{
    public function test_the_markup_and_margin_words_are_bengali_on_a_bengali_page(): void
    {
        app()->setLocale('bn');

        foreach (['purchase::field.markup', 'purchase::field.margin', 'purchase::message.rate_first', 'purchase::message.need_sales_price'] as $key) {
            $this->assertDoesNotMatchRegularExpression('/\b(markup|margin)\b/i', (string) __($key), "⛔ «{$key}» বাংলা পাতায় ইংরেজি শব্দে।");
        }

        $this->assertNotSame(__('purchase::field.markup'), __('purchase::field.margin'), '⛔ মার্কআপ আর মার্জিন এক শব্দ হয়ে গেছে।');
    }
}
