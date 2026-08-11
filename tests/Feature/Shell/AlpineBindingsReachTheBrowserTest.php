<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Tests\TestCase;

/**
 * `::name` নয়, `:name` — নাহলে বাঁধনটা ব্রাউজারে পৌঁছায় না।
 *
 * ── কী হয়েছিল ────────────────────────────────────────────────────────
 * সরাসরি ক্রয়ের পর্দায় প্রতিটা লাইনের ঘর লেখা ছিল `::name="..."`।
 * পরীক্ষক পণ্য যোগ করতেন, পর্দায় সারিটা দেখা যেত, তারপর "Confirm
 * invoice" চাপলে আসত **"The lines field is required."**
 *
 * কারণটা এক অক্ষরের: Blade-এ `::` দিয়ে কোলন escape করা যায়, কিন্তু
 * **শুধু কম্পোনেন্ট ট্যাগে** (`<x-...>`)। সাধারণ `<input>`-এ Blade
 * অ্যাট্রিবিউট ছোঁয় না, তাই `::name` হুবহু `::name` হয়েই HTML-এ যায়।
 * Alpine `::name` চেনে না — সে চেনে `:name`। ফলে ঘরগুলোর কোনো `name`
 * বসত না, আর name ছাড়া ইনপুট ফর্মের সাথে যায়ই না।
 *
 * ── কেন এই ভুলটা ধরা কঠিন ────────────────────────────────────────────
 * পর্দায় সব ঠিক দেখায় — সারি আছে, সংখ্যা আছে, যোগফল আছে। ব্রাউজারের
 * কনসোলেও কিছু নেই, কারণ কোনো এরর হয়নি; একটা অ্যাট্রিবিউট নীরবে
 * উপেক্ষিত হয়েছে মাত্র। ভুলটা কেবল সাবমিট করার মুহূর্তে দেখা যায়, আর
 * তখন বার্তাটা বলে "লাইন দিতে হবে" — অথচ লাইন চোখের সামনেই আছে।
 *
 * তাই পাহারাটা সব ভিউয়ের উপর, শুধু ওই একটা ফাইলে নয়।
 */
class AlpineBindingsReachTheBrowserTest extends TestCase
{
    /**
     * Alpine-এর অ্যাট্রিবিউটগুলো, যেগুলোয় ভুলটা হতে পারে।
     *
     * তালিকাটা ছোট ও নির্দিষ্ট, কারণ `::` নিজে বৈধ — অনুবাদের চাবিতে
     * (`purchase::field.rate`) ওটাই স্বাভাবিক। খোঁজা হয় কেবল
     * `::অ্যাট্রিবিউট=` আকারে, যেখানে Alpine-এর বাঁধন হওয়ার কথা।
     */
    private const BOUND = [
        'name', 'value', 'class', 'disabled', 'checked', 'selected',
        'readonly', 'required', 'placeholder', 'href', 'src', 'title',
        'colspan', 'rowspan', 'style', 'id', 'for', 'min', 'max', 'step',
    ];

    public function test_no_view_binds_an_attribute_with_two_colons(): void
    {
        $attributes = implode('|', self::BOUND);
        $broken = [];

        foreach ($this->views() as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            foreach ($this->tagsIn($source) as $tag) {
                /*
                 * কম্পোনেন্ট ট্যাগে `::` সঠিক, সাধারণ HTML-এ ভুল।
                 *
                 * ── পার্থক্যটা কেন ──────────────────────────────────
                 * `<x-ui.button ::class="...">` — এখানে Blade
                 * অ্যাট্রিবিউটগুলো নিজে পড়ে, আর `:class` মানে হত "এটা
                 * PHP এক্সপ্রেশন"। তাই কোলনটা escape করতে `::` লাগে,
                 * আর Blade একটা কোলন খুলে `:class` বানিয়ে দেয় —
                 * যেটা Alpine চায়।
                 *
                 * `<input ::class="...">` — সাধারণ HTML, Blade
                 * অ্যাট্রিবিউট ছোঁয়ই না। `::class` হুবহু `::class`
                 * হয়ে যায়, আর Alpine সেটা চেনে না।
                 *
                 * অর্থাৎ একই বানান এক জায়গায় বাধ্যতামূলক, আরেক জায়গায়
                 * নীরব ভুল — আর সেটাই এই পরীক্ষাটার পুরো কারণ।
                 */
                if ($this->isComponent($tag)) {
                    continue;
                }

                if (preg_match_all('/(?<![\w:])::(?:'.$attributes.')\s*=/', $tag, $found) > 0) {
                    $broken[] = $this->relative($file).' — '.$this->shorten($tag);
                }
            }
        }

        sort($broken);

        $this->assertSame([], $broken, implode("\n", [
            'এই ভিউগুলোয় Alpine-এর বাঁধন `::` দিয়ে লেখা, `:` দিয়ে নয়।',
            'Blade কেবল কম্পোনেন্ট ট্যাগে `::` খুলে দেয়, সাধারণ HTML-এ নয় —',
            'তাই অ্যাট্রিবিউটটা হুবহু `::name` হয়ে ব্রাউজারে যায় আর Alpine',
            'সেটা নীরবে উপেক্ষা করে। পর্দায় কিছুই ভাঙা দেখায় না; ভুলটা',
            'কেবল সাবমিট করার সময় ধরা পড়ে, আর তখন বার্তাটা ভুল দিকে',
            'আঙুল তোলে।',
            ...$broken,
        ]));
    }

    /*
     * এখানে একটা দ্বিতীয় পরীক্ষা ছিল: `x-for`-এর ভেতরের প্রতিটা ইনপুটে
     * `name` আছে কি না। সেটা সরানো হয়েছে, আর কারণটা লিখে রাখা দরকার
     * যাতে কেউ আবার না লেখে।
     *
     * পরীক্ষাটা markup ও margin-এর ঘর দুইটাকে ভুল বলছিল — অথচ ওদের
     * `name` **ইচ্ছাকৃতভাবে নেই**: ওরা বিক্রয়মূল্যের উপর দুইটা জানালা,
     * সংরক্ষিত তথ্য নয় (line-editor.blade.php-তে কারণসহ লেখা)। একই
     * জিনিস দুই জায়গায় জমা রাখলে একদিন আলাদা হয়, আর তখন কোনটা সত্যি
     * বলার উপায় থাকে না।
     *
     * মার্কআপ দেখে "ভুলে গেছে" আর "ইচ্ছে করে দেয়নি" আলাদা করা যায় না।
     * আর যে পরীক্ষা সঠিক কোডকে ভুল বলে, সেটা পরের জন মুছে ফেলে — আর
     * তার সাথে উপরের কাজের পরীক্ষাটাও চলে যায়।
     */

    /**
     * ফাইলের প্রতিটা খোলা ট্যাগ, আলাদা করে।
     *
     * পুরো ফাইলে একবারে খোঁজা যেত না: একই ফাইলে `<x-ui.button ::class>`
     * (সঠিক) আর `<input ::name>` (ভুল) দুইটাই থাকতে পারে, আর ফাইল ধরে
     * দেখলে দুইটাই এক দেখাত।
     *
     * @return list<string>
     */
    private function tagsIn(string $source): array
    {
        preg_match_all('/<[a-zA-Z][^>]*>/s', $source, $found);

        return $found[0];
    }

    /** `<x-...>` — Blade নিজে যার অ্যাট্রিবিউট পড়ে। */
    private function isComponent(string $tag): bool
    {
        return preg_match('/^<x-/', $tag) === 1;
    }

    /** @return list<string> */
    private function views(): array
    {
        $found = [];

        foreach ([app_path('Modules'), resource_path('views')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }

    /**
     * `x-for` টেমপ্লেটের ভেতরের input/select/textarea ট্যাগগুলো।
     *
     * @return list<string>
     */
    private function inputsIn(string $source): array
    {
        $tags = [];

        // টেমপ্লেটের শুরু থেকে তার শেষ পর্যন্ত — নেস্টেড টেমপ্লেট নেই
        // বলে সরল খোঁজাই যথেষ্ট।
        if (preg_match_all('/<template[^>]*x-for[^>]*>(.*?)<\/template>/s', $source, $blocks) === 0) {
            return [];
        }

        foreach ($blocks[1] as $block) {
            preg_match_all('/<(?:input|select|textarea)\b[^>]*>/s', $block, $found);

            foreach ($found[0] as $tag) {
                // hidden হোক বা দৃশ্যমান, দুইটাতেই name লাগে। কেবল
                // যেগুলো ইচ্ছাকৃতভাবে পাঠানো হয় না (x-model ছাড়া
                // নিছক প্রদর্শন) সেগুলো বাদ — ওগুলোয় x-model থাকে না।
                if (str_contains($tag, 'x-model') || str_contains($tag, 'type="hidden"')) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    private function shorten(string $tag): string
    {
        $flat = preg_replace('/\s+/', ' ', $tag) ?? $tag;

        return mb_strlen($flat) > 120 ? mb_substr($flat, 0, 120).'…' : $flat;
    }
}
