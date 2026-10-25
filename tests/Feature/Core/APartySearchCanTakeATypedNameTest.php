<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * ⭐ [[x-ui.party-search]]-এ "লেখা নাম যোগ" (`addable`) — তালিকায় নেই এমন নাম হাতে লেখা, রসিদ/পরিশোধের পুরনো পিকারের হুবহু
 * (মালিক, ৩ অক্টোবর ২০২৬; fe আর cb, ২৪ অক্টোবর ২০২৬ — cb রসিদ, পরিশোধ আর সরবরাহকারী-পরিশোধে বসাবে)। আচরণ vitest-এ
 * ([[party-search.test.js]]); এখানে আঁকা: `addable` দিলে "+", প্যানেলের "+ নতুন নাম", আর আগের নামেই নতুন নাম ও মোবাইলের
 * ঘর (সার্ভার সেখান থেকেই ব্যক্তি বানায়, [[VoucherRequest]]); না দিলে এর কিছুই নেই — অন্য সব পিকার আগের মতো।
 */
class APartySearchCanTakeATypedNameTest extends TestCase
{
    private const OPTIONS = [['id' => 11, 'label' => 'Afia Enterprise', 'hint' => 'C-0103 · Uttara', 'find' => 'afia']];

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ অনুরোধের বাইরে আঁকা — middleware-এর মতো খালি ভুলের থলে দেওয়া, নইলে `$errors` থাকে না
        $this->app['view']->share('errors', new ViewErrorBag);
        // ⓘ `old()` পড়ে অনুরোধের সেশন থেকে — অনুরোধ ছাড়া আঁকায় সেশন বসানো, নইলে "Session store not set on request"
        $this->app['request']->setLaravelSession($this->app['session.store']);
    }

    private function draw(string $extra = ''): string
    {
        return Blade::render('<x-ui.party-search name="party_id" label="পক্ষ" :options="$options" '.$extra.' />', ['options' => self::OPTIONS]);
    }

    public function test_an_addable_picker_offers_the_typed_name_under_the_old_field_names(): void
    {
        $html = $this->draw('addable');

        $this->assertStringContainsString('data-party-add-toggle', $html, '⛔ নামের পাশে "+" নেই — তালিকায় না থাকা নাম লেখার পথ বন্ধ');
        $this->assertMatchesRegularExpression('/data-party-add-typed[^>]*x-on:click="addTyped\(\)"/s', $html,
            '⛔ খোঁজার প্যানেলে "+ নতুন নাম হিসেবে রাখুন" নেই');
        $this->assertMatchesRegularExpression('/data-party-new.*name="party_new".*name="party_mobile"/s', $html,
            '⛔ নতুন নাম আর মোবাইল আগের ঘরের নামে যায় না — সার্ভার (VoucherRequest) তাঁকে ব্যক্তি বানাতে পারত না');
        $this->assertStringContainsString('addable: true', $html, '⛔ খোঁজার যুক্তি জানে না ঘরটা নাম নিতে পারে — Enter কিছুই করত না');
        $this->assertStringContainsString('name="party_id"', $html, 'প্রস্তুতিটাই ভুল — পিকার আঁকা হয়নি');

        $named = $this->draw('addable add-name="payee_new" :add-mobile="false"');
        $this->assertStringContainsString('name="payee_new"', $named, '⛔ নতুন নামের ঘর পাতার দেওয়া নামে নয়');
        $this->assertStringNotContainsString('name="party_mobile"', $named, '⛔ মোবাইল না চাইলেও মোবাইলের ঘর আঁকা হলো');
    }

    public function test_every_other_picker_stays_as_it_was(): void
    {
        $html = $this->draw();

        foreach (['data-party-add-toggle', 'data-party-add-typed', 'data-party-new', 'name="party_new"', 'name="party_mobile"'] as $mark) {
            $this->assertStringNotContainsString($mark, $html, "⛔ addable ছাড়া পিকারেও {$mark} — অন্য পর্দার পক্ষের ঘর বদলে গেল");
        }
        $this->assertStringContainsString('addable: false', $html, '⛔ addable ছাড়া পিকারও লেখা নাম নিতে চায়');
    }

    public function test_a_refused_post_reopens_the_typed_name(): void
    {
        $this->app['session.store']->flashInput(['party_new' => 'Rahim Traders']);

        $html = $this->draw('addable');

        $this->assertStringContainsString('adding: true', $html, '⛔ ফেরানো জমার পরে লেখা নামের ঘর বন্ধ — লেখাটা চোখের আড়ালে');
        $this->assertStringContainsString('Rahim Traders', $html, '⛔ ফেরানো জমার পরে লেখা নামটা হারাল');
    }

    /**
     * ⭐ `stacked` — বাছা নাম উপরে, তার নিচে ছোট লাইনে পয়েন্ট · শাখা; সরু ঘরে এক লাইনে লেখা তীরের নিচে কাটত (আদায়ের ১৩৬৬-এর
     * ছবি, ২৪ অক্টোবর ২০২৬)। পর্দা-পাঠক লেবেলের সাথে বাছা নামটাও পড়ে। ⓘ না দিলে আগের এক লাইন।
     */
    public function test_a_stacked_picker_shows_the_name_above_its_point_and_branch(): void
    {
        $html = $this->draw('stacked');

        $this->assertMatchesRegularExpression('/data-party-picker[^>]*data-party-stacked/s', $html, '⛔ stacked দিলেও ঘরটা দুই লাইনের নয়');
        $this->assertMatchesRegularExpression('/data-party-stacked[^>]*min-h-\(--spacing-field\)/s', $html,
            '⛔ দুই লাইনের ঘরের উচ্চতা বাঁধা — নিচের লাইন কাটা পড়ে');
        $this->assertMatchesRegularExpression('/id="ps-party-id-[a-z0-9]+-picked"\s+class="[^"]*flex flex-col/', $html,
            '⛔ নাম আর পয়েন্ট-শাখা পাশাপাশি — নিচে নয়');
        $this->assertMatchesRegularExpression('/aria-labelledby="(ps-party-id-[a-z0-9]+)-label \1-picked"/', $html,
            '⛔ পর্দা-পাঠক কেবল লেবেল পড়ে, কাকে বাছা হলো তা নয়');

        $plain = $this->draw();
        $this->assertStringNotContainsString('data-party-stacked', $plain, '⛔ stacked ছাড়াও দুই লাইন — অন্য পিকার বদলে গেল');
        $this->assertMatchesRegularExpression('/data-party-picker[^>]*\bh-\(--spacing-field\)/s', $plain, '⛔ সাধারণ পিকারের এক লাইনের উচ্চতা হারাল');
    }

    /**
     * ⭐ বাছা নাম সার্ভারেই আঁকা — Alpine চালু হওয়ার আগেও ঘরে বাছা নাম আর তার পয়েন্ট-শাখা, "—" নয় (cb-ma-5-এর আদায়ের নকশার দাবি
     * লাল, ২৫ অক্টোবর ২০২৬)। ⓘ কিছু বাছা না থাকলে "—", আর ফাঁকা ছোট লাইন Alpine-এর আগে লুকানো (x-cloak)।
     */
    public function test_the_picked_name_is_drawn_by_the_server_before_alpine(): void
    {
        $picked = $this->draw('stacked :selected="11"');
        $this->assertMatchesRegularExpression('/<span[^>]*data-party-picked-label[^>]*>Afia Enterprise<\/span>/', $picked,
            '⛔ বাছা নাম সার্ভারের পাতায় নেই — Alpine চালু হওয়ার আগে ঘরে "—" দেখায়');
        $this->assertMatchesRegularExpression('/<span(?:(?!x-cloak)[^>])*data-party-picked-hint[^>]*>C-0103 · Uttara<\/span>/', $picked,
            '⛔ বাছা নামের পয়েন্ট-শাখা সার্ভারে আঁকা নয়, বা x-cloak-এ লুকানো');

        $none = $this->draw('stacked');
        $this->assertMatchesRegularExpression('/data-party-picked-label[^>]*>—<\/span>/', $none, '⛔ কিছু বাছা না থাকলেও "—" নয়');
        $this->assertMatchesRegularExpression('/<span[^>]*x-cloak[^>]*data-party-picked-hint[^>]*><\/span>/', $none,
            '⛔ ফাঁকা ছোট লাইন Alpine-এর আগে লুকানো নয়');
    }
}
