<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * প্রতিটা ফর্মের পর্দা নিজের হয়ে জবাব দেয়।
 *
 * ── ⛔ দুইটা আলাদা রোগ, একটাই পথে ধরা ────────────────────────────────
 * ⓵ **এক নামে দুইটা ঘর** — পাশের একটা পর্দায় `carried_by` দুইবার বসে
 * গিয়েছিল। ⚠️ HTML-এ সেটা কোনো ভুল নয়, কোনো সতর্কবার্তা নেই: ব্যবহারকারী
 * উপরের ঘরে বাছেন, আর সার্ভারে পৌঁছায় **নিচেরটার** মান। ⛔ বাছাইটা
 * নীরবে হারায়, আর অভিযোগ আসে মাস খানেক পরে "হিসাব মিলছে না" হয়ে।
 *
 * ⓶ **লেবেলহীন ঘর** — নিরীক্ষার ধাপ ৪.২: *"যে ইনপুটগুলোতে `<label for>`
 * নেই সেগুলো মেলান… একটি পরীক্ষা লিখুন যা নিশ্চিত করে প্রতিটি দৃশ্যমান
 * ইনপুটের সাথে লেবেল যুক্ত।"* ⓘ লেবেল ছাড়া ঘরে স্ক্রিন-রিডার কেবল
 * "edit text" বলে, আর লেবেলে ক্লিক করেও ঘরে যাওয়া যায় না।
 *
 * ── ⭐ কেন দুইটা একসাথে ──────────────────────────────────────────────
 * দুইটার উত্তরই একই জায়গায়: **পাতাটা সত্যিই এঁকে ফেলা HTML**। ⚠️ আর
 * আঁকাটাই এখানে দামি কাজ (সিডার, লগইন, তেরোটা পর্দা) — দুইবার করার
 * কোনো মানে নেই।
 *
 * ── ⚠️ একটা অন্ধ জায়গা, লুকাচ্ছি না ─────────────────────────────────
 * `<template x-if>`-এর ভিতরের ঘরগুলো বাদ। ⓘ Alpine ওগুলো শর্ত সত্যি না
 * হলে DOM-এ বসায়ই না, তাই দুইটা পরস্পর-বর্জনকারী ব্লকে একই নাম থাকা
 * বৈধ — খাতের ফর্মে `bank_name` ঠিক সেভাবেই দুইবার আছে, ইচ্ছাকৃতভাবে।
 * ⛔ দাম: দুইটা `x-if`-এর শর্ত একসাথে সত্যি হতে পারলে এই পাহারা সেটা
 * ধরবে না। ওটা ধরতে সত্যিকারের ব্রাউজার লাগত।
 */
final class EveryFormScreenAnswersForItselfTest extends TestCase
{
    use RefreshDatabase;

    /**
     * যে পর্দাগুলো আঁকা হয়।
     *
     * ⓘ সবগুলো নয়, কারণ অনেক ফর্মের জন্য আগে ডেটা বসাতে হয় (একটা চালান
     * ছাড়া তার ফেরত লেখা যায় না)। ⭐ এখানে সেগুলোই, যেগুলো খালি
     * ডেমো-ডেটাতেই খোলে — আর ওগুলোই রোজ সবচেয়ে বেশি ব্যবহার হয়।
     *
     * @var list<array{0: string, 1: array<string, string>}>
     */
    private const SCREENS = [
        ['customer.create', []],
        ['supplier.create', []],
        ['inventory.product.create', []],
        ['inventory.warehouse.create', []],
        ['hr.employee.create', []],
        ['accounts.coa.create', []],
        ['accounts.voucher.create', ['type' => 'receipt']],
        ['accounts.voucher.create', ['type' => 'payment']],
        ['accounts.voucher.create', ['type' => 'expense']],
        ['accounts.voucher.create', ['type' => 'journal']],
        ['accounts.voucher.create', ['type' => 'contra']],
        ['master_data.person.create', []],
        ['master_data.location.create', []],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⛔ সেটআপের দাবি — পর্দাগুলো সত্যিই আছে তো?
     *
     * ⚠️ একটা রুটের নাম বদলে গেলে নিচের দাবিগুলো ঐ পর্দাটা **চুপচাপ বাদ**
     * দিত, আর পাহারাটা ছোট হয়ে যেত — কেউ টের না পেয়ে।
     */
    public function test_every_screen_on_the_list_still_exists(): void
    {
        $missing = [];

        foreach (self::SCREENS as [$name, $params]) {
            if (! Route::has($name)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing,
            "তালিকার এই রুটগুলো আর নেই:\n  ".implode("\n  ", $missing));
    }

    /**
     * ⭐ এক ফর্মে দুইটা সক্রিয় ঘরের এক নাম নেই।
     */
    public function test_no_two_live_boxes_share_a_name(): void
    {
        $clashes = [];

        foreach (self::SCREENS as [$name, $params]) {
            $document = $this->render($name, $params);
            $names = [];

            foreach ($this->submittableFields($document) as $field) {
                $names[] = $field->getAttribute('name');
            }

            $this->assertGreaterThan(3, count($names),
                "{$name}: ফর্মে ঘরই পাওয়া গেল না — তাহলে দাবিটা কিছুই মাপে না।");

            $twice = array_keys(array_filter(
                array_count_values($names),
                static fn (int $n): bool => $n > 1,
            ));

            foreach ($twice as $clash) {
                $clashes[] = "{$name} → {$clash}";
            }
        }

        $this->assertSame([], $clashes, sprintf(
            "একই নামের একাধিক সক্রিয় ঘর:\n  %s\n\n".
            '⚠️ ব্রাউজার শেষেরটার মান পাঠাবে, আর আগেরটার বাছাই নীরবে হারাবে।',
            implode("\n  ", $clashes),
        ));
    }

    /**
     * ⭐ প্রতিটা দৃশ্যমান ঘরের একটা নাম আছে — চোখে দেখা যায় এমন।
     *
     * ── ⓘ চারটার যেকোনো একটাই যথেষ্ট ─────────────────────────────────
     *   · `<label for="…">` যেটা ঘরটার `id` ধরে
     *   · ঘরটা নিজেই একটা `<label>`-এর ভিতরে
     *   · `aria-label`
     *   · `aria-labelledby`
     *
     * ⚠️ `placeholder` গোনা হয় না, আর ইচ্ছাকৃতভাবে: টাইপ করা শুরু করলেই
     * ওটা উধাও হয়ে যায়, তাই ঘরটা কীসের সেটা ঠিক তখনই ভুলে যাওয়া যায়
     * যখন মনে রাখা দরকার।
     */
    public function test_every_visible_box_says_what_it_is(): void
    {
        $nameless = [];

        foreach (self::SCREENS as [$name, $params]) {
            $document = $this->render($name, $params);

            $labelled = [];
            foreach ($document->getElementsByTagName('label') as $label) {
                $for = $label->getAttribute('for');
                if ($for !== '') {
                    $labelled[$for] = true;
                }
            }

            foreach ($this->submittableFields($document) as $field) {
                if ($field->hasAttribute('aria-label') || $field->hasAttribute('aria-labelledby')) {
                    continue;
                }

                $id = $field->getAttribute('id');

                if ($id !== '' && isset($labelled[$id])) {
                    continue;
                }

                if ($this->insideA($field, 'label')) {
                    continue;
                }

                $nameless[] = sprintf('%s → <%s name="%s">',
                    $name, $field->nodeName, $field->getAttribute('name'));
            }
        }

        $this->assertSame([], $nameless, sprintf(
            "এই ঘরগুলো বলে না তারা কীসের:\n  %s\n\n".
            "⭐ চারটার যেকোনো একটা করুন —\n".
            "  ১. `<label for=\"…\">` (x-ui.field ও x-ui.select নিজেই করে)\n".
            "  ২. ঘরটা `<label>`-এর ভিতরে রাখুন\n".
            "  ৩. `aria-label`\n".
            "  ৪. `aria-labelledby`\n\n".
            '⚠️ `placeholder` যথেষ্ট নয় — টাইপ শুরু করলেই ওটা উধাও।',
            implode("\n  ", $nameless),
        ));
    }

    /**
     * পাতাটা এঁকে DOM ফেরত দেওয়া।
     */
    private function render(string $name, array $params): DOMDocument
    {
        $html = (string) $this->get(route($name, $params))->assertOk()->getContent();

        $document = new DOMDocument;

        // ⓘ HTML5 মার্কআপে libxml গজগজ করে; আমরা গড়ন মাপছি না
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return $document;
    }

    /**
     * যে ঘরগুলো ব্রাউজার সত্যিই পাঠাবে ও মানুষ দেখতে পাবে।
     *
     * ⓘ বাদ যায়: `disabled` (পাঠানোই হয় না) · `hidden` ও `_token`-জাতীয়
     * লুকানো ঘর (মানুষ দেখে না) · `submit`/`button`/`reset` (কেবল যেটায়
     * চাপ পড়ে সেটা যায়) · `radio` (একই নামে একাধিক থাকাই সংজ্ঞা) ·
     * `name="x[]"` (একাধিক হওয়াই উদ্দেশ্য) · `<template>`-এর ভিতরের সব।
     *
     * @return list<DOMElement>
     */
    private function submittableFields(DOMDocument $document): array
    {
        $fields = [];

        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach ($document->getElementsByTagName($tag) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $name = $node->getAttribute('name');

                if ($name === '' || str_ends_with($name, '[]')) {
                    continue;
                }

                if ($node->hasAttribute('disabled') || $node->hasAttribute('hidden')) {
                    continue;
                }

                $kind = strtolower($node->getAttribute('type'));

                if (in_array($kind, ['hidden', 'submit', 'button', 'reset', 'radio'], true)) {
                    continue;
                }

                if ($this->insideA($node, 'template')) {
                    continue;
                }

                $fields[] = $node;
            }
        }

        return $fields;
    }

    /** ঘরটা কোনো নির্দিষ্ট ট্যাগের ভিতরে কি না — উপরের দিকে হেঁটে দেখা। */
    private function insideA(DOMNode $node, string $tag): bool
    {
        for ($up = $node->parentNode; $up !== null; $up = $up->parentNode) {
            if (strtolower($up->nodeName) === $tag) {
                return true;
            }
        }

        return false;
    }
}
