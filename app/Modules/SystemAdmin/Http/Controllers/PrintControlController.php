<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintFormat;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Engines\Print\PrintSample;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Modules\SystemAdmin\Support\ControlPanelTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ছাপার নিয়ন্ত্রণ — প্রতিটা কাগজে কী আসবে, কোন কলাম, কোন ক্রমে।
 *
 * ── ⭐ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"ইনভয়েজের জন্য কন্ট্রোল প্যানেলে আলাদা ট্যাব করো … পারলে পস আর নরমাল
 * প্রিন্টার আলাদা করে রাখ সব কিছু যাতে নিয়ন্ত্রণ করা যায়, কি কি প্রিন্টে
 * আসবে কি কি কলাম দিবে কোনটার পর কোনটা সব কিছুই নিয়ন্ত্রণ হবে সুইচে।"*
 *
 * ── ⭐ আর ২৩ সেপ্টেম্বরে দুইটা অভিযোগ ─────────────────────────────────
 * *"eikhane kotaw printing tab nai"* — কন্ট্রোল প্যানেলের ট্যাবের সারিতে
 * ছাপার কিছু ছিল না, আর এই পর্দাটা মেনুর ভেতর থেকে খুঁজে বের করতে হত।
 * ⭐ এখন ট্যাবটা ঐ সারিরই একটা ([[ControlPanelTabs]]), কিন্তু পর্দাটা
 * আলাদাই — তাঁর কথায় *"print seting alada seting hobe"*।
 *
 * *"print e invoice template vew kore deke select korar bebosta koro"* —
 * ⭐ তাই প্রতিটা রূপের পাশে তার নিজের নমুনা কাগজ ([[preview()]])।
 *
 * ── ⚠️ কেন সাধারণ সেটিংস পর্দায় নয় ───────────────────────────────────
 * ঐ পর্দাটা তিন রকম ঘর আঁকতে জানে: সুইচ, বাছাইয়ের ঘর, আর লেখার ঘর।
 * ⛔ **ক্রম** ওই তিনটার কোনোটাই নয় — "কোনটার পর কোনটা" বলতে হলে প্রতিটা
 * কলামের পাশে তার জায়গার সংখ্যাটা থাকতে হয়। ⓘ তাই ছাপার সারিগুলোর
 * গ্রুপ `print_paper`, আর [[SettingsController]] ওটাকে ছেঁকে বাদ দেয়:
 * দুইটা পর্দা যেন কখনো একই সারিতে না লেখে।
 *
 * ── ⓘ ক্রম বসে সংখ্যার ঘরে, টেনে সরানোয় নয় ───────────────────────────
 * ⚠️ টেনে সরানোর তালিকা লিখতে JavaScript লাগে, আর এখানকার CSP-তে
 * ইনলাইন এক্সপ্রেশন চলে না। ⛔ তার চেয়েও বড় কথা: এই পর্দা ফোনেও খোলা
 * হয়, আর ফোনে টেনে সরানো সবচেয়ে বেশি ভুল হয় এমন ইশারা।
 *
 * ⭐ একটা `<select>`-এ ১, ২, ৩… আর "বন্ধ" — কোনো JS নেই, আর যিনি ছাপার
 * কাগজ সাজাচ্ছেন তিনি সংখ্যা দিয়েই ভাবেন।
 */
class PrintControlController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly ControlPanelTabs $tabs,
        private readonly PrintEngine $print,
    ) {}

    /**
     * ⓘ একই চাবি (`settings.manage`) — ছাপার কাগজে কী থাকবে সেটা
     * প্রতিষ্ঠানের সিদ্ধান্ত, আর সেটিংসের পর্দার প্রশ্নও তাই।
     */
    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function edit(Request $request): View
    {
        /*
         * ⭐ কোন কাগজ — ঠিকানা থেকে, ২৩ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ আগে ছয়টা কাগজই এক পাতায় ছিল, Alpine লুকিয়ে রাখত ────────
         * ⛔ তাতে তিনটা জিনিস ভাঙত: ট্যাবটার নিজের কোনো ঠিকানা ছিল না
         * (বুকমার্ক করা যেত না), সংরক্ষণের পর সে প্রথম কাগজে ফিরে যেত,
         * আর ছয়টা কাগজের ছয় গুচ্ছ নমুনা একসাথে আনতে হত।
         *
         * ⭐ এখন `?paper=challan` — প্রতিটা কাগজের নিজের ঠিকানা, আর
         * ফর্মটাও কেবল সেই একটা কাগজের।
         */
        $current = $this->paperFrom($request->query('paper'));

        return view('system_admin::print-control.edit', [
            'menu' => $this->menu->forUser($request->user()),

            /* কন্ট্রোল প্যানেলের সারিতে এই পর্দাটার ট্যাব জ্বলে থাকে */
            'tabs' => $this->tabs->all(),
            'tab' => 'print',

            /* উপরের সারির জন্য — কেবল কোড আর নাম */
            'papers' => $this->paperTabs(),

            'paper' => $this->paperData($current),
            'formats' => PrintFormat::all(),
        ]);
    }

    /**
     * ⭐ একটা রূপের নমুনা কাগজ — HTML, PDF নয়।
     *
     * ── ⭐ মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * *"zate age sample dekha zay tarpor select kora zay"*।
     *
     * ── ⚠️ কেন সংরক্ষিত সেটিংস পড়া হয় না ───────────────────────────────
     * ⛔ [[PrintProfile::for()]] কোম্পানির বসানো সুইচগুলো পড়ে, আর নমুনার
     * পুরো কথাই হলো **যেটা এখনো বাছা হয়নি** সেটা দেখা। ⓘ তাই
     * [[PrintProfile::previewing()]] — রূপটা হাতে দেওয়া হয়, সেটিংস
     * ছোঁয়াই হয় না।
     *
     * ── ⛔ আর কাগজটা বানানো, কারও আসল বিল নয় ──────────────────────────
     * ⚠️ এখানে ঢোকার চাবি `settings.manage`, আর বিক্রয়ের কাগজ দেখার
     * চাবি আলাদা। ⓘ শেষ বিলটা তুলে দেখালে এই পর্দা একজন গ্রাহকের নাম
     * ও তাঁর দর ফাঁস করার **দ্বিতীয় দরজা** হত — নীরবে।
     * [[PrintSample]] সেজন্যই ডেটাবেস ছোঁয় না।
     */
    public function preview(Request $request): Response
    {
        $target = $this->paperFrom($request->query('paper'));

        $sample = PrintSample::for($target);

        $html = $this->print->preview(
            template: $sample['template'],
            data: $sample['data'],
            paper: $sample['paper'],
            profile: PrintProfile::previewing(
                $target,
                (string) $request->query('format', 'standard'),
            ),
        );

        /*
         * ⓘ `X-Frame-Options` নয়, CSP-র `frame-ancestors 'self'` —
         * নমুনাটা নিজের পর্দার iframe-এ বসে, আর সেটা একই ডোমেইন।
         */
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * সংরক্ষণ।
     *
     * ── ⛔ কেবল যে কাগজটা পাঠানো হয়েছে, ২৩ সেপ্টেম্বর ২০২৬ ─────────────
     * ⚠️ আগে লুপটা ছয়টা কাগজেই ঘুরত, আর সেটা তখন ঠিক ছিল — ছয়টাই এক
     * ফর্মে থাকত। ⛔ কাগজগুলো আলাদা ঠিকানায় যাওয়ার পর একটা পাঠানোয়
     * কেবল **একটা** কাগজের ঘর থাকে, আর নিয়মটা না বদলালে চালানের সুইচ
     * বদলে সংরক্ষণ করতেই বাকি পাঁচটা কাগজ চুপচাপ "সাধারণ" রূপে ফিরে
     * যেত — কারণ অনুপস্থিত ঘরকে [[PrintFormat::chosen()]] `standard`
     * ধরে।
     *
     * ⓘ ঠিক এই ভুলটা কন্ট্রোল প্যানেলে একবার সত্যি হয়েছিল (৩০ আগস্ট,
     * ৩৪টা সেটিং নীরবে বন্ধ), তাই এখানেও একই ওষুধ: ফর্ম নিজে বলে দেয়
     * সে কোন কাগজটা বহন করছে (`scope[]`), আর তার বাইরে কিছু ছোঁয়া হয় না।
     *
     * ── ⚠️ রূপ বদলালে সুইচগুলো মুছে যায়, আর সেটাও ইচ্ছাকৃত ─────────────
     * ⓘ মালিক নতুন একটা রূপ বাছলে তিনি ঐ রূপটাই চান — আগের বদলগুলো
     * নয়। ⛔ কোম্পানির পুরনো ওভাররাইডগুলো রেখে দিলে নতুন রূপ বেছেও
     * কাগজে কিছুই বদলাত না, আর মালিক ভাবতেন বাছাইটা কাজ করে না।
     */
    public function update(Request $request): RedirectResponse
    {
        /*
         * ⛔ খালি হলে কিছুই নয় — পুরনো কোনো পাতা `scope[]` ছাড়া এলে
         * "কিছু সেভ হলো না" ব্যবহারকারী সাথে সাথে দেখেন; "ছয়টা কাগজ
         * নীরবে রিসেট" কেউ মাসের পর মাস দেখেন না।
         */
        $scope = array_values(array_intersect(
            PrintProfile::TARGETS,
            array_map('strval', (array) $request->input('scope', [])),
        ));

        foreach ($scope as $target) {
            $input = (array) $request->input("papers.{$target}", []);

            $format = PrintFormat::chosen(
                is_string($input['format'] ?? null) ? $input['format'] : null,
                null,
            );

            $formatChanged = $format !== (string) $this->settings->get("print.{$target}.format");

            $this->settings->set("print.{$target}.format", $format);

            if ($formatChanged) {
                $this->settings->reset("print.{$target}.parts");
                $this->settings->reset("print.{$target}.columns");

                continue;
            }

            $this->settings->set("print.{$target}.parts", $this->partsFrom($input));
            $this->settings->set("print.{$target}.columns", $this->columnsFrom($input));
        }

        /*
         * ⓘ `back()` — অর্থাৎ যে কাগজের ট্যাবে ছিলেন সেখানেই, কারণ
         * ঠিকানাটায় `?paper=` লেখা আছে। ⚠️ রুটের নামে ফেরত পাঠালে
         * প্রতিবার প্রথম কাগজে গিয়ে পড়তেন।
         */
        return back()->with('saved', __('system_admin::settings.print_saved'));
    }

    /**
     * চাওয়া কাগজটা — চেনা না হলে প্রথমটা।
     *
     * ⚠️ অচেনা নামে ৪০৪ নয়: পুরনো বুকমার্ক বা একটা টাইপোর জন্য গোটা
     * পর্দাটা বন্ধ করার কোনো কারণ নেই।
     */
    private function paperFrom(mixed $requested): string
    {
        return is_string($requested) && in_array($requested, PrintProfile::TARGETS, true)
            ? $requested
            : PrintProfile::TARGETS[0];
    }

    /**
     * উপরের সারির কাগজগুলো — কেবল কোড আর নাম।
     *
     * @return list<array{code: string, label: string}>
     */
    private function paperTabs(): array
    {
        return array_map(fn (string $target) => [
            'code' => $target,
            'label' => __("system_admin::settings.print_target.{$target}"),
        ], PrintProfile::TARGETS);
    }

    /**
     * একটা কাগজের সবটুকু — বসানো রূপ, অংশ আর কলামের ক্রম।
     *
     * @return array<string, mixed>
     */
    private function paperData(string $target): array
    {
        $profile = PrintProfile::for($target, $this->settings);

        return [
            'code' => $target,
            'label' => __("system_admin::settings.print_target.{$target}"),
            'format' => $profile->format->name,
            'parts' => $profile->parts(),

            /*
             * ⛔ এই কাগজে যেগুলো সত্যি আঁকা যায় — `PARTS` নয়।
             *
             * ⓘ ভাউচার `document-body` বাড়ায় না, তাই ব্যান্ড আর
             * আদায়ের ছক ওখানে নেই। ⚠️ সুইচ দুইটা দেখালে মালিক
             * ওগুলো বদলে দেখতেন কিছুই হয় না।
             */
            'allParts' => PrintProfile::partsFor($target),

            /*
             * ⓘ চালু কলামগুলো মালিকের ক্রমে, তারপর বন্ধগুলো।
             *
             * ⚠️ বন্ধগুলোও দেখাতে হয় — নাহলে একটা কলাম বন্ধ করার পর
             * সে পর্দা থেকেই উধাও হত, আর ফিরিয়ে আনার কোনো পথ থাকত না।
             */
            'columns' => [
                ...$profile->chosenColumns(),
                ...array_values(array_diff(PrintProfile::columnNames(), $profile->chosenColumns())),
            ],
            'on' => $profile->chosenColumns(),
        ];
    }

    /**
     * চালু অংশগুলো।
     *
     * ⓘ চেকবক্স বন্ধ থাকলে ব্রাউজার ঘরটাই পাঠায় না — তাই তালিকাটা
     * **যা এসেছে** তা থেকে বানানো হয়, যা আসেনি তা বাদ দিয়ে নয়।
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function partsFrom(array $input): array
    {
        $sent = (array) ($input['parts'] ?? []);

        return array_values(array_filter(
            PrintProfile::PARTS,
            fn (string $part) => ! empty($sent[$part]),
        ));
    }

    /**
     * কলামগুলো, মালিকের বসানো ক্রমে।
     *
     * ── ⚠️ একই সংখ্যা দুইবার বসলে ─────────────────────────────────────
     * ⓘ পর্দায় কিছুই আটকায় না, আর আটকানোও উচিত নয়: কেউ ৩-কে ১ করলে
     * ক্ষণিকের জন্য দুইটা ১ থাকে। ⛔ তখন ভুল বার্তা দিয়ে সেভ আটকে দিলে
     * মানুষ প্রতিটা সংখ্যা হাতে সাজাতে বাধ্য হতেন।
     *
     * ⭐ তাই সমান হলে তালিকার নিজের ক্রম ভাঙে টাই — `array_multisort`
     * নয়, কারণ ওটা সমান মানগুলোর ক্রমের কোনো নিশ্চয়তা দেয় না, আর
     * তখন সেভ করার পর কলামের ক্রম **এলোমেলো** হত।
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function columnsFrom(array $input): array
    {
        $sent = (array) ($input['columns'] ?? []);
        $ranked = [];

        foreach (PrintProfile::columnNames() as $i => $name) {
            $place = $sent[$name] ?? '';

            /* "" মানে বন্ধ — ঘরটা এলেও কলামটা কাগজে যাবে না */
            if ($place === '' || $place === null) {
                continue;
            }

            $ranked[$name] = [(int) $place, $i];
        }

        uasort($ranked, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_keys($ranked);
    }
}
