<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\NumberSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ডকুমেন্ট নম্বর সিরিজ।
 *
 * সিরিজগুলো মডিউলের ঘোষণা থেকে নিজে থেকে তৈরি হয়
 * (NumberSeriesProvisioner), তাই এখানে "নতুন" বোতাম নেই — শুধু উপসর্গ
 * ও শূন্যের সংখ্যা বদলানো যায়।
 *
 * পরের নম্বরটা এখানে বদলানো যায় না, ইচ্ছাকৃতভাবে: পিছিয়ে দিলে একই
 * নম্বর দুইবার ইস্যু হত, আর এগিয়ে দিলে অডিটে একটা ফাঁক থাকত যার কোনো
 * ব্যাখ্যা নেই। দুইটাই এমন ভুল যা মাস পরে ধরা পড়ে।
 */
class NumberSeriesController extends Controller implements HasMiddleware
{
    /**
     * ছকে যে চিহ্নগুলো বসানো যায়।
     *
     * তালিকাটা পর্দায় দেখানো হয়। না দেখালে ব্যবহারকারী জানতেন না
     * কী কী লেখা যায়, আর অনুমান করে ভুল চিহ্ন লিখলে সেটা হুবহু
     * নম্বরে বসে যেত — "INV-{MONTH}-0001"।
     *
     * @var array<string, string>
     */
    private const PLACEHOLDERS = [
        '{PREFIX}' => 'master_data::field.prefix',
        '{SUFFIX}' => 'master_data::field.suffix',
        '{FY}' => 'master_data::field.financial_year',
        '{YYYY}' => 'master_data::field.year_four',
        '{YY}' => 'master_data::field.year_two',
        '{MM}' => 'master_data::field.month',
        '{BRANCH}' => 'core.company.branch',
        '{SEQ}' => 'master_data::field.sequence',
    ];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ModuleRegistry $registry,
        private readonly NumberSeriesEngine $numbers,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:master_data.manage')];
    }

    public function index(Request $request): View
    {
        return view('master_data::series.index', [
            'menu' => $this->menu->forUser($request->user()),
            'series' => NumberSeries::query()
                ->orderBy('module')
                ->orderBy('doc_type')
                ->get(),
            'labels' => $this->docTypeLabels(),
            // নমুনাটা আসল নম্বরের কোড থেকেই আসে, তাই দুইটা আলাদা হতে
            // পারে না — আগে ভিউ নিজে হাতে জুড়ে দেখাত, আর ছক বদলালে
            // নমুনাটা মিথ্যা বলত
            'engine' => $this->numbers,
            'placeholders' => self::PLACEHOLDERS,
        ]);
    }

    public function update(Request $request, NumberSeries $series): RedirectResponse
    {
        $validated = $request->validate([
            'prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-]+$/'],
            'suffix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-]*$/'],
            'padding' => ['required', 'integer', 'min:1', 'max:12'],

            /*
             * ছকে {SEQ} থাকতেই হবে।
             *
             * না থাকলে প্রতিটা ডকুমেন্ট একই নম্বর পেত — "INV-2026" বারবার।
             * ব্যাপারটা সেভ করার সময় কোনো ভুল দেখাত না; ধরা পড়ত অনেক পরে,
             * যখন দুইটা ভিন্ন বিলের নম্বর এক হয়ে যেত। তাই এখানেই আটকানো।
             */
            'format' => [
                'required', 'string', 'max:64',
                'regex:/\{SEQ\}/',
            ],

            // বছর শেষে ক্রম আবার ১ থেকে শুরু হবে কি না — বাংলাদেশে
            // বেশিরভাগ প্রতিষ্ঠান অর্থবছর ধরে গোনে
            'reset_yearly' => ['nullable', 'boolean'],
        ], [
            /*
             * ডিফল্ট বার্তাটা ছিল "The format field format is invalid" —
             * ইংরেজি, আর কী ভুল হয়েছে তা বলে না। ব্যবহারকারী দেখতেন
             * শুধু "invalid" আর অনুমান করতেন।
             */
            'format.regex' => __('master_data::validation.format_needs_sequence'),
        ]);

        $validated['reset_yearly'] = $request->boolean('reset_yearly');

        /*
         * অনুসর্গ খালি রাখা যায়, কিন্তু কলামটা NOT NULL।
         *
         * null পাঠালে সেভ করার সময় ডাটাবেজ ব্যতিক্রম ছুঁড়ত, আর
         * ব্যবহারকারী পেতেন একটা ৫০০ পাতা — অথচ ভুলটা তার নয়, ঘরটা
         * ঐচ্ছিকই। খালি স্ট্রিং-ই এখানে "কিছু নেই"।
         */
        $validated['suffix'] = (string) ($validated['suffix'] ?? '');

        // next_number ইচ্ছাকৃতভাবে বাদ — উপরের মন্তব্য দেখুন
        $series->update($validated);

        return back()->with('saved', __('master_data::message.updated'));
    }

    /**
     * ডকুমেন্ট টাইপের পড়ার মতো নাম — মডিউলের ঘোষণা থেকে।
     *
     * "RV" দেখে কেউ বলতে পারে না কোন ডকুমেন্ট। module.php-তে প্রতিটার
     * অনুবাদের কী ঘোষিত আছে, আর সেটাই ব্যবহার হয় — এখানে আলাদা তালিকা
     * রাখলে নতুন ডকুমেন্ট টাইপে সেটা হালনাগাদ করতে কেউ ভুলত।
     *
     * @return array<string, string>
     */
    private function docTypeLabels(): array
    {
        $labels = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->docTypes as $code => $label) {
                $labels[$code] = $label;
            }
        }

        return $labels;
    }
}
