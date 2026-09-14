<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * যে নামে আইকন চাওয়া হয়েছে, সে নামে সত্যিই কিছু আঁকা হয় কি না।
 *
 * ── কেন এই পাহারা, ১৪ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * `x-ui.icon` অচেনা নাম পেলে **চুপ করে কিছুই আঁকে না** — আর সেটা
 * ইচ্ছাকৃত: একটা মেনু সারিতে ভুল বানানের জন্য গোটা পাতা নামিয়ে দেওয়ার
 * কারণ নেই। ⓘ কম্পোনেন্টের শেষে কথাটা লেখাও আছে।
 *
 * ⚠️ কিন্তু দামটা হলো **নীরবতা**। পাতা ২০০ দেবে, ব্লেড কম্পাইল হবে,
 * কোনো ব্যতিক্রম উঠবে না — কেবল ঘরটা ফাঁকা থাকবে। লক্ষণ কেবল চোখে,
 * আর চোখ কেবল মালিকের। ঠিক আজকের চেনা আকৃতি, যেমন nonce ছাড়া ইনলাইন
 * ব্লক ([[EveryInlineScriptCarriesItsNonceTest]]): ব্যর্থতা নিঃশব্দ।
 *
 * ── কেন `drawn`-টা আলাদা করে মাপা হয় ─────────────────────────────────
 * ⛔ ফাঁদটা এখানেই। মডিউলের আইকন ডিফল্টে **ইমোজি**, তাই `name` যাচাই
 * করলে সব সবুজ দেখায়। কিন্তু নিচের বারে (`bottom-nav`) আইকনগুলো সাদা
 * করতে `drawn` লাগে, আর তখন ইমোজির তালিকা নয়, **আঁকার তালিকা** খোঁজা
 * হয় — দুইটা এক নয়। ⓘ একটা নাম ইমোজিতে থেকেও আঁকায় না থাকতে পারে,
 * আর সেদিন বারটায় একটা ফাঁকা ঘর বসবে।
 *
 * ── কেন উৎস পড়ে নয়, এঁকে দেখে ────────────────────────────────────────
 * ⚠️ প্রথম খসড়ায় আমি `$paths`-এর চাবিগুলো regex দিয়ে তুলছিলাম, আর
 * চরিত্র-শ্রেণিতে underscore বাদ পড়ে গিয়েছিল — তাতে `master_data` আর
 * `system_admin` "নেই" দেখাচ্ছিল, অথচ দুইটাই ছিল। ⓘ অর্থাৎ যন্ত্রটাই
 * ভুল বলেছিল। তাই এখানে তালিকার নকল রাখা হয়নি: প্রতিটা নাম সত্যিকারের
 * কম্পোনেন্ট দিয়ে **রেন্ডার করে** দেখা হয় ফলাফল খালি কি না।
 */
final class EveryIconNameActuallyDrawsSomethingTest extends TestCase
{
    /**
     * ⛔ ব্লেডে বা মডিউল-ঘোষণায় ব্যবহৃত কোনো নাম ফাঁকা আঁকে না।
     */
    public function test_no_icon_name_renders_nothing(): void
    {
        $names = $this->iconNamesInUse($occurrences);

        /*
         * ⚠️ সংগ্রহটা আগে দাবি করা হয় — শূন্য তালিকার উপর লুপ চালিয়ে
         * সবুজ থাকা ঠেকাতে। ⓘ আজ একাধিকবার একটা মাপার যন্ত্র কিছুই না
         * মেপে সফল হয়েছে; খোঁজাটা ভেঙে গেলে নিচের দাবিগুলো নীরবে পাস
         * করত, আর সেটাই এই পাহারার সবচেয়ে বাজে পরিণতি হত।
         */
        $this->assertGreaterThan(150, $occurrences, implode("\n", [
            'আইকনের নাম লেখা আছে এমন জায়গা পাওয়া গেল '.$occurrences.'টা, অথচ',
            '১৪ সেপ্টেম্বর ২০২৬-এ শুধু মডিউল-ঘোষণাতেই ১৭৭টা মেনু আইটেমে নাম',
            'বসানো হয়েছিল। এত কম মানে খোঁজাটাই ভেঙেছে।',
        ]));

        /* ⓘ আলাদা নামের সংখ্যাটা অনেক ছোট (সেদিন ৪৯), কারণ একই আইকন বহু
           জায়গায় বসে — যেমন প্রতিটা মডিউলের প্রথম সারিতে `dashboard`।
           ⚠️ দুইটা সংখ্যাই দাবি করা হয়: উপরেরটা বলে ফাইলগুলো সত্যিই পড়া
           হয়েছে, নিচেরটা বলে নামগুলো আলাদা করে তোলা গেছে। */
        $this->assertGreaterThan(40, count($names),
            'আলাদা নাম পাওয়া গেল '.count($names).'টা — সেদিন ছিল ৪৯টা।');

        $blank = [];

        foreach ($names as $name => $where) {
            if (trim(Blade::render('<x-ui.icon :name="$n" />', ['n' => $name])) === '') {
                $blank[] = $name.'  ← '.$where;
            }
        }

        $this->assertSame([], $blank, implode("\n", [
            'এই নামগুলোয় `x-ui.icon` কিছুই আঁকে না — পর্দায় ফাঁকা ঘর বসবে,',
            'আর কোনো ভুলের বার্তা আসবে না:',
            '',
            ...$blank,
            '',
            'সারানোর উপায়: resources/views/components/ui/icon.blade.php-এ নামটা',
            'যোগ করো, নয়তো ব্যবহারের জায়গায় চেনা নাম বসাও।',
        ]));
    }

    /**
     * ⛔ প্রতিটা মডিউলের কোডের একটা **আঁকা** রূপ আছে।
     *
     * ⓘ MenuBuilder মডিউলের আইকন হিসেবে তার `code`-টাই পাঠায়
     * (`'icon' => $module->code`), আর নিচের বার সেটাকে `drawn` চায়।
     */
    public function test_every_module_code_has_a_line_art_icon(): void
    {
        $codes = [];

        foreach (File::glob(base_path('app/Modules/*/module.php')) as $path) {
            if (preg_match("/'code'\s*=>\s*'([a-z0-9_]+)'/", File::get($path), $m) === 1) {
                $codes[] = $m[1];
            }
        }

        /* ⓘ ড্যাশবোর্ড কোনো মডিউল নয়, তবু প্রতিটা মডিউলের প্রথম সারিতে
           বসে — তাই ওটাও এই দাবির ভেতরে। */
        $codes[] = 'dashboard';

        $this->assertGreaterThanOrEqual(14, count($codes),
            'মডিউল পাওয়া গেল '.count($codes).'টা — ১৪ সেপ্টেম্বর ২০২৬-এ ছিল ১৪টা + dashboard।');

        $blank = [];

        foreach ($codes as $code) {
            if (trim(Blade::render('<x-ui.icon :name="$n" drawn />', ['n' => $code])) === '') {
                $blank[] = $code;
            }
        }

        $this->assertSame([], $blank, implode("\n", [
            'এই মডিউলগুলোর রেখা-আঁকা আইকন নেই, তাই মোবাইলের নিচের বারে',
            'ওদের ঘর ফাঁকা বসবে (ইমোজি থাকলেও চলবে না — নীল পটিতে আইকন',
            'সাদা করতে `drawn` লাগে):',
            '',
            ...$blank,
        ]));
    }

    /**
     * ব্লেড ও মডিউল-ঘোষণায় লেখা আইকনের নাম — নাম => কোথায় পাওয়া গেল।
     *
     * ⓘ কেবল **আক্ষরিক** নাম ধরা হয়। `:name="$item['icon']"`-এর মতো
     * চলক-দেওয়া জায়গা এখান থেকে দেখা যায় না, আর সেটাই সৎ সীমা:
     * মডিউল-ঘোষণাগুলো আলাদা করে পড়া হয় বলে ঐ চলকগুলোর উৎসই ঢাকা পড়ে।
     *
     * @param  int|null  $occurrences  কতবার একটা নাম লেখা আছে — খোঁজাটা
     *                                  সত্যিই ফাইল পড়ছে কি না তার প্রমাণ।
     * @return array<string, string>
     */
    private function iconNamesInUse(?int &$occurrences = null): array
    {
        $names = [];
        $occurrences = 0;

        $sources = array_merge(
            File::glob(base_path('app/Modules/*/module.php')),
            $this->bladeFiles(),
        );

        foreach ($sources as $path) {
            $body = File::get($path);
            $short = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

            /* মডিউল-ঘোষণার মেনু আইটেম: 'icon' => 'wallet' */
            preg_match_all("/'icon'\s*=>\s*'([a-z0-9_-]+)'/", $body, $m);

            /* ব্লেডে সরাসরি: <x-ui.icon name="wallet" */
            preg_match_all('/<x-ui\.icon[^>]*\sname="([a-z0-9_-]+)"/', $body, $n);

            foreach (array_merge($m[1], $n[1]) as $name) {
                $occurrences++;
                $names[$name] ??= $short;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), base_path('app/Modules')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
