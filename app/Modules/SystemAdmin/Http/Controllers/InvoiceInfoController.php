<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Modules\SystemAdmin\Support\ControlPanelTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * "Set Invoice Information" — ছাপার নিয়ন্ত্রণের ভেতরের একটা ভাগ: বিলের মাথা, কোন ঘর ছাপা হবে,
 * সইয়ের ঘর, নিচের লেখা।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Set Invoice Information ei name alada tab koro"*, তারপর *"Print control er vitotre korte
 * paro"* — তাই কাগজের সারিতে একটা আলাদা ট্যাব, নিজের ঠিকানায়।
 *
 * ── ⚠️ এই ক্লাস কোনো সেটিংয়ের নাম জানে না ─────────────────────────────
 * ⓘ সেটিংগুলো বিক্রয়ের (`module.php`, গ্রুপ `invoice_info`)। ⛔ SystemAdmin বিক্রয়ের নাম
 * জানলে সীমানা ভাঙত ([[BoundariesTest]]), আর বিক্রয় বন্ধ কোম্পানিতে পাতাটা অচেনা চাবিতে
 * ভেঙে পড়ত। ⭐ তাই পাতাটা ঘোষণা পড়ে আঁকে — `part` দিয়ে ভাগ, `type` দিয়ে ঘর — আর বিক্রয়
 * বন্ধ থাকলে ভাগটা এমনিতেই খালি।
 *
 * ── ⚠️ লোগো আর নম্বর এখানে বদলায় না ──────────────────────────────────
 * ⓘ লোগো কোম্পানির পাতায় ওঠে, নম্বর নম্বর সিরিজের পাতায় — দুইটা জায়গাই আগে থেকে আছে,
 * তাই এখানে কেবল দেখা আর সেখানে যাওয়ার লিংক। ⛔ দ্বিতীয় একটা আপলোডের পথ মানে দুইটা পথ
 * পাহারা দেওয়া।
 */
class InvoiceInfoController extends Controller implements HasMiddleware
{
    /** ⓘ যে গ্রুপটা এই ভাগের — [[SettingsController]] এটাকে ছেঁকে বাদ দেয় */
    public const GROUP = 'invoice_info';

    /** ভাগগুলোর ক্রম — ঘোষণায় অচেনা `part` এলে শেষে বসে, হারায় না */
    private const PARTS = ['header', 'show', 'signature', 'note'];

    /** লেখার ঘরের সীমা — ঠিকানা সবচেয়ে লম্বা, আর এক লাইনের কাগজে এর বেশি ধরেও না */
    private const MAX_TEXT = 300;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly ControlPanelTabs $tabs,
        private readonly NumberSeriesEngine $numbers,
    ) {}

    /** ⓘ ছাপার নিয়ন্ত্রণের চাবিটাই — একই প্রশ্ন, প্রতিষ্ঠান তার কাগজ কেমন চায় */
    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function edit(Request $request): View
    {
        $company = Company::query()->findOrFail(CompanyContext::id());

        return view('system_admin::print-control.invoice-info', [
            'menu' => $this->menu->forUser($request->user()),
            'tabs' => $this->tabs->all(),
            'tab' => 'print',
            'papers' => PrintControlController::paperTabs(),
            'parts' => $this->parts(),
            'company' => $company,
            'next' => $this->nextSaleNumber(),

            /* ⓘ নমুনার পাতা বিক্রয়ের — বিক্রয় বন্ধ থাকলে রুটটাই নেই, তখন লিংকও নেই */
            'sample' => Route::has('sales.invoice_sample') ? route('sales.invoice_sample') : null,
        ]);
    }

    /**
     * সংরক্ষণ — একটা লেনদেনে, যাতে অর্ধেক বসে বাকিটা না থেকে যায়।
     *
     * ⚠️ খালি লেখার ঘর মানে "প্রোফাইলের/ডিফল্টের মান" — তাই সারিটা মুছে দেওয়া (`reset`), ফাঁকা
     * লেখা বসানো নয়; নাহলে প্রোফাইল পরে বদলালেও বিলে ফাঁকা থাকত।
     */
    public function update(Request $request): RedirectResponse
    {
        /*
         * ⚠️ পুরো অ্যারেটা একবারে — চাবিতে ডট আছে, আর `input('settings.a.b')` ডটকে পথ ধরে
         * নিত; প্রতিটা মান null আসত, কিছুই সেভ হত না, নীরবে ([[SettingsController::update()]])।
         *
         * @var array<string, mixed> $sent
         */
        $sent = (array) $request->input('settings', []);
        $definitions = $this->definitions();

        $errors = [];

        foreach ($definitions as $key => $definition) {
            if ($definition['type'] === 'string' && mb_strlen(trim((string) ($sent[$key] ?? ''))) > self::MAX_TEXT) {
                $errors["settings.{$key}"] = __('validation.max.string', [
                    'attribute' => $this->label($definition),
                    'max' => self::MAX_TEXT,
                ]);
            }
        }

        if ($errors !== []) {
            return back()->withInput()->withErrors($errors);
        }

        DB::transaction(function () use ($definitions, $sent, $request) {
            foreach ($definitions as $key => $definition) {
                if (! $this->settings->mayChange($key, $request->user())) {
                    continue;
                }

                $raw = $sent[$key] ?? null;

                match ($definition['type']) {
                    /* ⓘ চেকবক্স বন্ধ থাকলে ব্রাউজার ঘরটাই পাঠায় না — তাই "আছে কি নেই" */
                    'boolean' => $this->settings->set($key, filter_var($raw, FILTER_VALIDATE_BOOLEAN)),

                    /* ⛔ তালিকার বাইরের কিছু এলে যা ছিল তাই থাকে */
                    'choice' => in_array($raw, (array) ($definition['options'] ?? []), true)
                        ? $this->settings->set($key, $raw)
                        : null,

                    default => trim((string) $raw) === ''
                        ? $this->settings->reset($key)
                        : $this->settings->set($key, trim((string) $raw)),
                };
            }
        });

        return redirect()
            ->route('system_admin.print_control.invoice_info')
            ->with('saved', __('system_admin::settings.invoice_info_saved'));
    }

    /**
     * এই ভাগের ঘোষিত সেটিংগুলো, ঘোষণার ক্রমে।
     *
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return array_filter(
            $this->settings->definitions(),
            fn (array $d) => ($d['group'] ?? null) === self::GROUP,
        );
    }

    /**
     * পাতার ভাগগুলো — প্রতিটায় ঘরগুলো, এখনকার মান আর ঘরের নাম।
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function parts(): array
    {
        $parts = array_fill_keys(self::PARTS, []);

        foreach ($this->definitions() as $key => $definition) {
            $parts[$definition['part'] ?? 'note'][] = [
                ...$definition,
                'key' => $key,
                'value' => $this->settings->get($key),
                'name' => $this->label($definition),
            ];
        }

        return array_filter($parts);
    }

    /** ঘরের নাম — সইয়ের চারটা ঘর একই নাম নেয়, কেবল ক্রমিক আলাদা */
    private function label(array $definition): string
    {
        return (string) __((string) $definition['label'], ['n' => $definition['label_n'] ?? '']);
    }

    /**
     * পরের বিক্রি নম্বর — কেবল দেখানোর জন্য।
     *
     * ⓘ ২৯ সেপ্টেম্বর থেকে বিলের নম্বরই বিক্রি নম্বর (S-0001), তাই সিরিজ `S`। শাখার নিজের
     * সিরিজ না থাকলে কোম্পানির সাধারণটা — ইঞ্জিন যে ক্রমে খোঁজে।
     */
    private function nextSaleNumber(): ?string
    {
        $series = NumberSeries::query()
            ->where('doc_type', 'S')
            ->where('is_active', true)
            ->orderByRaw('branch_id is null')
            ->orderByDesc('financial_year_id')
            ->first();

        return $series === null ? null : $this->numbers->preview($series);
    }
}
