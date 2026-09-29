<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Print\PrintEngine;
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
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * ছাপার নিয়ন্ত্রণ — কাগজ ধরে ট্যাব, প্রতিটার ভেতরে মাপ (A4 · A5 · Thermal), প্রতিটায় সেই মাপের নকশা।
 *
 * ── ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Tab thakbe 'Set Your Invoice Information', Invoice, Challan, Order, Qutation, aday rosid,
 * ভাউচার … Ei sob koytar A4, A5, Tharmal printer virsion alada hobe protitir vitore tabe"* — আর
 * *"ager sokol sample bad daw ekta kore rakho sudhu kajer jonno"*, *"total 6 tab e mot 78 ti
 * sorabe"*: আগের তেরোটা তৈরি রূপ (ছয় কাগজে ৭৮টা কার্ড) পর্দা থেকে সরল; প্রতিটা কাগজে কাজ
 * চালানোর জন্য থাকল কেবল "সাধারণ", যতদিন না মালিকের নিশ্চিত করা নতুন নকশা আসে।
 *
 * ── ⚠️ নতুন নকশা কোথা থেকে আসে ─────────────────────────────────────────
 * ⓘ এই ক্লাস কোনো মডিউলের নকশার নাম জানে না ([[BoundariesTest]])। একটা মডিউল তার বাছাইয়ের
 * সেটিংয়ে `print_designs` => ['paper' => …, 'size' => …, 'sample_route' => …] ঘোষণা করে — যেমন
 * বিক্রয় বিলের A4 ([[InvoiceDesigns]]) — আর এই পাতা সেই ঘোষণা পড়ে কার্ড আঁকে। যে কাগজ-মাপে
 * এমন ঘোষণা নেই, সেখানে কেবল "সাধারণ"।
 *
 * ── ⓘ "সাধারণ"-এর নিচের সুইচগুলো থাকল ───────────────────────────────────
 * কোন অংশ ছাপা হবে আর কলামের ক্রম — এগুলো নমুনা নয়, "সাধারণ" কাগজের নিজের সুইচ; তাই থাকল।
 * ⛔ তেরোটা রূপের ইঞ্জিন ([[PrintFormat]]) কোডে আছে, কিন্তু বাছার পথ নেই, আর আগে বাছা রূপগুলো
 * ডিপ্লয়ের দিন "সাধারণ"-এ ফিরেছে (migration 2027_01_30_120000) — নইলে কার্ড সরলেও কাগজ পুরনো
 * রূপেই ছাপত, আর মালিক ভাবতেন সরানোটা কাজ করেনি।
 */
class PrintControlController extends Controller implements HasMiddleware
{
    /** উপরের কাগজের ট্যাব — মালিকের ক্রমে ("Set Your Invoice Information" আলাদা পাতা, সবার আগে) */
    public const PAPERS = ['invoice', 'challan', 'order', 'quotation', 'receipt', 'voucher'];

    /** প্রতিটা কাগজের ভেতরের মাপ */
    public const SIZES = ['a4', 'a5', 'thermal'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly ControlPanelTabs $tabs,
        private readonly PrintEngine $print,
    ) {}

    /** ⓘ একই চাবি (`settings.manage`) — কাগজে কী থাকবে সেটা প্রতিষ্ঠানের সিদ্ধান্ত */
    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function edit(Request $request): View
    {
        $paper = self::paperFrom($request->query('paper'));
        $size = self::sizeFrom($request->query('size'));
        $target = self::targetFor($paper, $size);
        $designs = $this->designsFor($paper, $size);

        return view('system_admin::print-control.edit', [
            'menu' => $this->menu->forUser($request->user()),
            'tabs' => $this->tabs->all(),
            'tab' => 'print',
            'papers' => self::paperTabs(),
            'sizes' => self::SIZES,
            'paper' => $paper,
            'size' => $size,
            'target' => $target,
            'cards' => $this->cards($target, $designs),
            'chosen' => $designs === null ? 'standard' : (string) $this->settings->get($designs['key']),
            'profile' => $target === null ? null : $this->profileData($target),
        ]);
    }

    /**
     * ⭐ "সাধারণ" কাগজের নমুনা — HTML, বানানো তথ্যে ([[PrintSample]]), কারও আসল কাগজ নয়।
     *
     * ⚠️ কেন সংরক্ষিত সেটিংস পড়া হয় না: নমুনার কথাই হলো রূপটা নিজে কেমন দেখায়
     * ([[PrintProfile::previewing()]])।
     */
    public function preview(Request $request): Response
    {
        $target = in_array($request->query('paper'), PrintProfile::TARGETS, true)
            ? (string) $request->query('paper')
            : PrintProfile::TARGETS[0];

        $sample = PrintSample::for($target);

        $html = $this->print->preview(
            template: $sample['template'],
            data: $sample['data'],
            paper: $sample['paper'],
            profile: PrintProfile::previewing(
                $target,
                (string) $request->query('format', self::defaultFormat($target)),
            ),
        );

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * সংরক্ষণ — কেবল যে কাগজ-মাপ পাঠানো হয়েছে।
     *
     * ⛔ ফর্ম নিজে বলে সে কোন কাগজ-মাপ বহন করছে (`paper`, `size`), আর তার বাইরে কিছু ছোঁয়া হয়
     * না — ৩০ আগস্টে কন্ট্রোল প্যানেলে ৩৪টা সেটিং নীরবে বন্ধ হওয়ার পর থেকে এই নিয়ম।
     * ⓘ রূপ (`print.*.format`) আর লেখা হয় না — বাছার পথটাই নেই।
     */
    public function update(Request $request): RedirectResponse
    {
        /*
         * ⛔ দেখানোর বেলায় অচেনা মানে প্রথম কাগজ; সংরক্ষণের বেলায় অচেনা মানে কিছুই না — পুরনো
         * কোনো পাতা কাগজ না বলে এলে "বিল"-এর সুইচ নীরবে বদলে যেত।
         */
        $paper = $request->input('paper');
        $size = $request->input('size');

        if (! in_array($paper, self::PAPERS, true) || ! in_array($size, self::SIZES, true)) {
            return back()->withErrors(['paper' => __('system_admin::settings.print_nothing_saved')]);
        }

        $target = self::targetFor($paper, $size);

        $designs = $this->designsFor($paper, $size);
        $design = $request->input('design');

        if ($designs !== null && is_string($design) && in_array($design, $designs['options'], true)) {
            $this->settings->set($designs['key'], $design);
        }

        $input = (array) $request->input("papers.{$target}", []);

        if ($target !== null && $input !== []) {
            $this->settings->set("print.{$target}.parts", $this->partsFrom($input));
            $this->settings->set("print.{$target}.columns", $this->columnsFrom($input));
        }

        return redirect()
            ->route('system_admin.print_control', ['paper' => $paper, 'size' => $size])
            ->with('saved', __('system_admin::settings.print_saved'));
    }

    /**
     * উপরের সারির কাগজগুলো — কোড আর নাম।
     *
     * ⓘ `public static`: "Set Your Invoice Information" ([[InvoiceInfoController]]) একই সারি আঁকে।
     *
     * @return list<array{code: string, label: string}>
     */
    public static function paperTabs(): array
    {
        return array_map(fn (string $paper) => [
            'code' => $paper,
            'label' => __("system_admin::settings.print_paper.{$paper}"),
        ], self::PAPERS);
    }

    /**
     * কাগজ-মাপের "সাধারণ" ছাপা কোন প্রোফাইল দিয়ে হয় — null মানে এই কাগজের এখনো ছাপাই নেই (কোটেশন)।
     *
     * ⓘ বিলের থার্মাল = কাউন্টারের রসিদ (`pos`), [[SalesPrintController::profileFor()]]-এর একই নিয়ম।
     * ⚠️ A5 আর A4 একই প্রোফাইল: আজ মাপভেদে আলাদা সুইচ নেই — মাপের ট্যাব আলাদা নকশার জন্য।
     */
    public static function targetFor(string $paper, string $size): ?string
    {
        return match (true) {
            $paper === 'quotation' => null,
            $paper === 'invoice' && $size === 'thermal' => 'pos',
            default => $paper,
        };
    }

    private static function paperFrom(mixed $asked): string
    {
        return is_string($asked) && in_array($asked, self::PAPERS, true) ? $asked : self::PAPERS[0];
    }

    private static function sizeFrom(mixed $asked): string
    {
        return is_string($asked) && in_array($asked, self::SIZES, true) ? $asked : self::SIZES[0];
    }

    private static function defaultFormat(string $target): string
    {
        return $target === 'pos' ? 'compact' : 'standard';
    }

    /**
     * এই কাগজ-মাপের নকশার বাছাই — কোনো মডিউল ঘোষণা করে থাকলে।
     *
     * @return array{key: string, options: list<string>, option_label: string, sample_route: ?string}|null
     */
    private function designsFor(string $paper, string $size): ?array
    {
        foreach ($this->settings->definitions() as $key => $definition) {
            $where = $definition['print_designs'] ?? null;

            if (is_array($where) && ($where['paper'] ?? null) === $paper && ($where['size'] ?? null) === $size) {
                return [
                    'key' => $key,
                    'options' => array_values((array) ($definition['options'] ?? [])),
                    'option_label' => (string) ($definition['option_label'] ?? ''),
                    'sample_route' => isset($where['sample_route']) && Route::has($where['sample_route'])
                        ? (string) $where['sample_route']
                        : null,
                ];
            }
        }

        return null;
    }

    /**
     * কার্ডগুলো — আগে "সাধারণ", তারপর মডিউলের নকশা।
     *
     * @return list<array{code: string, name: string, sample: ?string}>
     */
    private function cards(?string $target, ?array $designs): array
    {
        $cards = [];

        if ($target !== null) {
            $cards[] = [
                'code' => 'standard',
                'name' => __('system_admin::settings.print_standard'),
                'sample' => route('system_admin.print_control.preview', ['paper' => $target, 'format' => self::defaultFormat($target)]),
            ];
        }

        foreach ($designs['options'] ?? [] as $code) {
            if ($code === 'standard') {
                continue;
            }

            $cards[] = [
                'code' => $code,
                'name' => (string) __($designs['option_label'].$code),
                'sample' => $designs['sample_route'] === null ? null : route($designs['sample_route'], ['design' => $code]),
            ];
        }

        return $cards;
    }

    /**
     * "সাধারণ" কাগজের সুইচ — কোন অংশ, কোন কলাম কোন ক্রমে।
     *
     * @return array<string, mixed>
     */
    private function profileData(string $target): array
    {
        $profile = PrintProfile::for($target, $this->settings);

        return [
            'parts' => $profile->parts(),

            /* ⛔ এই কাগজে যেগুলো সত্যি আঁকা যায় — ভাউচারে ব্যান্ড আর আদায়ের ছক নেই */
            'allParts' => PrintProfile::partsFor($target),

            /* ⓘ চালুগুলো মালিকের ক্রমে, তারপর বন্ধগুলো — নাহলে বন্ধ কলাম ফেরানোর পথ থাকত না */
            'columns' => [
                ...$profile->chosenColumns(),
                ...array_values(array_diff(PrintProfile::columnNames(), $profile->chosenColumns())),
            ],
            'on' => $profile->chosenColumns(),
        ];
    }

    /**
     * চালু অংশগুলো — চেকবক্স বন্ধ থাকলে ব্রাউজার ঘরটাই পাঠায় না, তাই যা এসেছে তা থেকে।
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
     * কলামগুলো, মালিকের বসানো ক্রমে; সমান সংখ্যায় তালিকার নিজের ক্রম টাই ভাঙে ("" = বন্ধ)।
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

            if ($place === '' || $place === null) {
                continue;
            }

            $ranked[$name] = [(int) $place, $i];
        }

        uasort($ranked, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_keys($ranked);
    }
}
