<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Print\PrintFormat;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $papers = [];

        foreach (PrintProfile::TARGETS as $target) {
            $profile = PrintProfile::for($target, $this->settings);

            $papers[] = [
                'code' => $target,
                'label' => __("system_admin::settings.print_target.{$target}"),
                'format' => $profile->format->name,
                'parts' => $profile->parts(),

                /*
                 * ⛔ এই কাগজে যেগুলো সত্যি আঁকা যায় — `PARTS` নয়।
                 *
                 * ⓘ ভাউচার `document-body` বাড়ায় না, তাই ব্যান্ড আর
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

        return view('system_admin::print-control.edit', [
            'menu' => $this->menu->forUser($request->user()),
            'papers' => $papers,
            'formats' => PrintFormat::all(),
        ]);
    }

    /**
     * সংরক্ষণ।
     *
     * ── ⚠️ রূপ বদলালে সুইচগুলো মুছে যায়, আর সেটা ইচ্ছাকৃত ──────────────
     * ⓘ মালিক নতুন একটা রূপ বাছলে তিনি ঐ রূপটাই চান — আগের বদলগুলো
     * নয়। ⛔ কোম্পানির পুরনো ওভাররাইডগুলো রেখে দিলে নতুন রূপ বেছেও
     * কাগজে কিছুই বদলাত না, আর মালিক ভাবতেন বাছাইটা কাজ করে না।
     */
    public function update(Request $request): RedirectResponse
    {
        foreach (PrintProfile::TARGETS as $target) {
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

        return back()->with('saved', __('system_admin::settings.print_saved'));
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
