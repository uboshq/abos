<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Illuminate\Http\Request;

/**
 * ⭐ সব পাতায় একই ছাঁকনি — পাতার নিজের পুরোনো ঘরগুলো ডান পাশের প্যানেলে (মালিক, ২৩ নভেম্বর ২০২৬: *"স্লিম ছাঁকনি সব পাতায়
 *   পৌঁছায়নি, রিপোর্টে ছাঁকা যায় না"*; অনুমোদিত নকশা ১৪ ও ১৭ নভেম্বর ২০২৬)।
 *
 * ── কীভাবে ───────────────────────────────────────────────────────────────
 *   পাতা ঘোষণা করে কোন ঘর, কোন **পুরোনো** চাবিতে: `Facets::dates()`, `Facets::pick('status', …)`, `Facets::flag('cancelled', …)`।
 *   কোয়েরিতে এগুলো কিছু বসায় না — কন্ট্রোলার আগের মতোই সেই চাবিগুলো পড়ে ছাঁকে। তাই:
 *     ⛔ কোনো ছাঁকনি হারায় না, কোনো নতুন ছাঁকনি বানানো হয় না — ঠিকানা আর পুরোনো লিংক অটুট;
 *     ⓘ প্যানেল, চিপ, "📅 তারিখ ▾", "সব সরান" সব [[FilterSet]]-এর একই ছাঁচ, গোনা ছাড়া ([[Panel::plain()]])।
 *
 * ⓘ যে তালিকা পরে কেন্দ্রীয় ইঞ্জিনে পুরো যাবে (সংখ্যাসহ), সে নিজের `*ListFilters` লেখে — যেমন [[OrderListFilters]]।
 */
final class Facets
{
    /** ⓘ এর বেশি ঘর হলে গোল টিকের বদলে নামার তালিকা — দুই হাজার দোকানের গোল ঘর প্যানেলকে মাইল লম্বা করত */
    public const RADIO_UP_TO = 30;

    /**
     * পাতার প্যানেলের তথ্য — `x-ui.filter-layout :filters` আর টুলবারের `:facets`।
     *
     * @param  list<Facet|null>  $facets  ⓘ null বাদ — শর্তে থাকা ঘর (`$can ? Facets::pick(…) : null`)
     * @param  array<string, string|null>  $shown  ⓘ ঠিকানায় না থাকলে পাতা যে মান ধরে চলেছে — যেমন রিপোর্টের ডিফল্ট পরিসর
     *   (`['from' => …, 'to' => …]`); প্যানেল আর "📅" সেটাই দেখায়, যাতে পর্দা আর সংখ্যা একই কথা বলে
     * @return array<string, mixed>
     */
    public static function panel(array $facets, ?Request $request = null, array $shown = []): array
    {
        $request ??= request();

        if ($shown !== []) {
            $request = $request->duplicate();

            foreach ($shown as $key => $value) {
                if ($value !== null && $value !== '' && ! $request->filled($key)) {
                    $request->query->set($key, $value);
                }
            }
        }

        return Panel::plain(new FilterSet(array_values(array_filter($facets))), $request);
    }

    /**
     * তারিখের পরিসর — পাতার পুরোনো দুই চাবি (সাধারণত `from` / `to`); লেনদেনের তালিকায় বারে "📅 তারিখ ▾"।
     */
    public static function dates(string $label, string $from = 'from', string $to = 'to', bool $bar = true, string $key = 'date'): DateRangeFacet
    {
        $facet = (new DateRangeFacet($key, $label, self::none(), fromKey: $from, toKey: $to))->look('sky', '📅');

        return $bar ? $facet->onTheBar() : $facet;
    }

    /**
     * একটা বাছাই — পুরোনো `<select name="status">`। ⓘ "সব" (খালি মান) শুরুতে বাছা থাকে আর চিপ হয় না।
     *
     * @param  array<int|string, string>  $options  মান => নাম, পুরোনো তালিকার মতোই (খালি "সব" ঘরটা বাদে)
     */
    public static function pick(string $key, string $label, array $options, ?string $any = null): Facet
    {
        $any ??= __('core.list_filter.any');

        if (count($options) > self::RADIO_UP_TO) {
            return self::select($key, $label, $options, $any);
        }

        $choices = ['' => ['label' => $any, 'apply' => self::none()]];

        foreach ($options as $value => $name) {
            $choices[(string) $value] = ['label' => (string) $name, 'apply' => self::none()];
        }

        return (new ChoiceFacet($key, $label, $choices, multi: false, default: '', counted: false))->look('blue', '●');
    }

    /**
     * একটা বাছাই, "সব" ছাড়া — পুরোনো ঘরে খালি ঘর ছিল না (যেমন "অর্থবছর"); পাতা যেটা ধরে চলেছে সেটাই শুরুতে বাছা, চিপ নয়।
     *
     * @param  array<int|string, string>  $options
     */
    public static function one(string $key, string $label, array $options, string $current): ChoiceFacet
    {
        $choices = [];

        foreach ($options as $value => $name) {
            $choices[(string) $value] = ['label' => (string) $name, 'apply' => self::none()];
        }

        return (new ChoiceFacet($key, $label, $choices, multi: false, default: $current, counted: false))->look('violet', '◷');
    }

    /**
     * নামার তালিকা — অনেক ঘরের বাছাই (শাখা, দোকান, কর্মী)। ⓘ [[pick()]] নিজেই এখানে আসে ৩০টার বেশি হলে।
     *
     * @param  array<int|string, string>  $options
     */
    public static function select(string $key, string $label, array $options, ?string $any = null): FieldsFacet
    {
        $list = ['' => $any ?? __('core.list_filter.any')];

        foreach ($options as $value => $name) {
            $list[(string) $value] = (string) $name;
        }

        return (new FieldsFacet($key, $label, [['key' => $key, 'label' => $label, 'type' => 'select', 'options' => $list]],
            self::none(), fn (array $v) => $label.': '.($list[$v[$key]] ?? $v[$key])))->look('teal', '◈');
    }

    /**
     * একাধিক টিক — কেবল যে পাতা আগে থেকেই `name="kind[]"` পাঠাত।
     *
     * @param  array<int|string, string>  $options
     */
    public static function many(string $key, string $label, array $options): ChoiceFacet
    {
        $choices = [];

        foreach ($options as $value => $name) {
            $choices[(string) $value] = ['label' => (string) $name, 'apply' => self::none()];
        }

        return (new ChoiceFacet($key, $label, $choices, counted: false))->look('blue', '●');
    }

    /**
     * একটা টিক — পুরোনো `<input type="checkbox" name="cancelled" value="1">`; চাবি অ্যারে নয় ([[ChoiceFacet::flat()]])।
     */
    public static function flag(string $key, string $label, string $value = '1', ?string $group = null): ChoiceFacet
    {
        return (new ChoiceFacet($key, $group ?? $label, [
            $value => ['label' => $label, 'chip' => $label, 'apply' => self::none()],
        ], counted: false))->look('slate', '✓');
    }

    /** লেখার ঘর — পুরোনো `<input type="text" name="ref">` */
    public static function text(string $key, string $label, string $placeholder = ''): FieldsFacet
    {
        return (new FieldsFacet($key, $label, [['key' => $key, 'label' => $label, 'type' => 'text', 'placeholder' => $placeholder]],
            self::none(), fn (array $v) => $label.': '.$v[$key]))->look('violet', '✎');
    }

    /**
     * সংখ্যার ঘর — পুরোনো `<input type="number" name="year">`।
     * ⓘ `$shown` — ঠিকানায় না থাকলে পাতা যে মান ধরে চলেছে (যেমন চলতি বছর); ঘরে দেখায়, চিপ হয় না।
     */
    public static function number(string $key, string $label, ?int $min = null, ?int $max = null, string $placeholder = '', ?string $shown = null): FieldsFacet
    {
        $field = ['key' => $key, 'label' => $label, 'type' => 'number', 'placeholder' => $placeholder, 'default' => $shown];

        if ($min !== null) {
            $field['min'] = $min;
        }
        if ($max !== null) {
            $field['max'] = $max;
        }

        return (new FieldsFacet($key, $label, [$field], self::none(), fn (array $v) => $label.': '.$v[$key]))->look('amber', '#');
    }

    /**
     * একটা তারিখ — পুরোনো একক তারিখের ঘর (`as_of`, `date`)।
     * ⓘ `$shown` — ঠিকানায় না থাকলে পাতা যে দিন ধরে চলেছে (যেমন "আজ"), ঘরে সেটাই দেখায়; চিপ হয় না।
     */
    public static function day(string $key, string $label, ?string $shown = null): FieldsFacet
    {
        return (new FieldsFacet($key, $label, [['key' => $key, 'label' => $label, 'type' => 'date', 'default' => $shown]],
            self::none(), fn (array $v) => $label.': '.\App\Core\Support\DateFormat::format($v[$key])))->look('sky', '📅');
    }

    /**
     * একটা মাস — পুরোনো `x-ui.month` / `type="month"` ঘর (মান Y-m)।
     * ⓘ `$shown` — পাতা যে মাস ধরে চলেছে (সাধারণত চলতি মাস); ঘরে সেটাই দেখায়, চিপ হয় না।
     */
    public static function month(string $key, string $label, ?string $shown = null): FieldsFacet
    {
        return (new FieldsFacet($key, $label, [['key' => $key, 'label' => $label, 'type' => 'month', 'default' => $shown]],
            self::none(), fn (array $v) => $label.': '.$v[$key]))->look('sky', '🗓');
    }

    /**
     * লিখে-খোঁজা — নিচে নামের তালিকা (datalist); মান লেখা বা নম্বর, যেমন রিপোর্টের "কোড · নাম"।
     *
     * @param  array<int|string, string>  $options  নম্বর => নাম
     */
    public static function find(string $key, string $label, array $options, string $placeholder = ''): FieldsFacet
    {
        $names = [];

        foreach ($options as $value => $name) {
            $names[(string) $value] = (string) $name;
        }

        return (new FieldsFacet($key, $label, [['key' => $key, 'label' => $label, 'type' => 'find', 'options' => $names, 'placeholder' => $placeholder]],
            self::none(), fn (array $v) => $label.': '.($names[$v[$key]] ?? $v[$key])))->look('teal', '◈');
    }

    /** টাকার পরিসর — কেবল যে পাতা আগে থেকেই `<key>_min` / `<key>_max` পাঠাত */
    public static function money(string $key, string $label): RangeFacet
    {
        return (new RangeFacet($key, $label, self::none()))->look('amber', '৳');
    }

    /** ⓘ কোয়েরিতে কিছু নয় — পাতার কন্ট্রোলার নিজেই ছাঁকে */
    private static function none(): \Closure
    {
        return static fn (mixed ...$args): mixed => null;
    }
}
