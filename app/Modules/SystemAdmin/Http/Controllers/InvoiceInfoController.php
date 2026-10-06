<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Image\ImageEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\BranchSettings;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Modules\SystemAdmin\Support\ControlPanelTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
    private const PARTS = ['header', 'show', 'challan_show', 'signature', 'note'];

    /** লেখার ঘরের সীমা — ঠিকানা সবচেয়ে লম্বা, আর এক লাইনের কাগজে এর বেশি ধরেও না */
    private const MAX_TEXT = 600;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly ControlPanelTabs $tabs,
        private readonly NumberSeriesEngine $numbers,
        private readonly BranchSettings $branches,
    ) {}

    /** ⓘ ছাপার নিয়ন্ত্রণের চাবিটাই — একই প্রশ্ন, প্রতিষ্ঠান তার কাগজ কেমন চায় */
    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function edit(Request $request): View
    {
        $company = Company::query()->findOrFail(CompanyContext::id());

        /*
         * ⭐ কোন শাখার — মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"inv info, iNVOICE lOGO protiti branch ER JONNO ALADA ALADA HOBE"*।
         * ⓘ null = কোম্পানি (সব শাখার মূল); একটা শাখা = তার নিজের বদল, খালি ঘর "কোম্পানির মতো"।
         */
        $branch = $this->branchFrom($request->query('branch'));
        $logo = $this->branches->invoiceLogoPath($branch);

        return view('system_admin::print-control.invoice-info', [
            'branches' => Branch::query()->where('company_id', $company->id)->orderBy('code')->get(['id', 'company_id', 'code', 'name_en', 'name_bn']),
            'branch' => $branch,
            'invoiceLogo' => $logo !== null && Storage::disk('public')->exists($logo) ? Storage::disk('public')->url($logo) : null,
            'ownLogo' => $branch === null
                ? $this->settings->get(BranchSettings::INVOICE_LOGO) !== null
                : $this->branches->own(BranchSettings::INVOICE_LOGO, $branch) !== null,
            'menu' => $this->menu->forUser($request->user()),
            'tabs' => $this->tabs->all(),
            'tab' => 'print',
            'papers' => PrintControlController::paperTabs(),
            'parts' => $this->parts($branch),
            'company' => $company,
            ...$this->nextSaleNumber($branch),

            /* ⓘ নমুনার পাতা বিক্রয়ের — বিক্রয় বন্ধ থাকলে রুটটাই নেই, তখন লিংকও নেই */
            'sample' => Route::has('sales.invoice_sample') ? route('sales.invoice_sample', array_filter(['branch' => $branch])) : null,
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
        $branch = $this->branchFrom($request->input('branch'));

        /* ⭐ বিলের লোগো — কোম্পানির পাতার সেই একই দরজা: ছবি, ১২ MB পর্যন্ত, ইঞ্জিন ছোট করে */
        $request->validate([
            'invoice_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:12288'],
            'remove_invoice_logo' => ['nullable', 'boolean'],
        ]);

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

        DB::transaction(function () use ($definitions, $sent, $request, $branch) {
            foreach ($definitions as $key => $definition) {
                if (! $this->settings->mayChange($key, $request->user()) || ($definition['part'] ?? null) === 'logo') {
                    continue;
                }

                $raw = $sent[$key] ?? null;

                /*
                 * ⓘ শাখার বেলায় প্রতিটা ঘরের তৃতীয় একটা মান আছে — "কোম্পানির মতো" (খালি)। ⛔ চেকবক্সে সেটা বলা যায়
                 * না, তাই শাখায় সুইচগুলো বাছাইয়ের ঘর: "" = কোম্পানির মতো, "1" = চালু, "0" = বন্ধ।
                 */
                if ($branch !== null) {
                    if (! $this->branches->isPerBranch($key)) {
                        continue;
                    }

                    $value = trim((string) $raw);

                    match (true) {
                        $value === '' => $this->branches->reset($key, $branch),
                        $definition['type'] === 'boolean' => $this->branches->set($key, $branch, $value === '1'),
                        $definition['type'] === 'choice' => in_array($value, (array) ($definition['options'] ?? []), true)
                            ? $this->branches->set($key, $branch, $value)
                            : null,
                        default => $this->branches->set($key, $branch, $value),
                    };

                    continue;
                }

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

            $this->keepInvoiceLogo($request, $branch);
        });

        return redirect()
            ->route('system_admin.print_control.invoice_info', array_filter(['branch' => $branch]))
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
    private function parts(?int $branch = null): array
    {
        $parts = array_fill_keys(self::PARTS, []);

        foreach ($this->definitions() as $key => $definition) {
            /* ⓘ লোগো নিজের তোলার ঘরে আঁকা হয়, সাধারণ লেখার ঘরে নয় */
            if (($definition['part'] ?? null) === 'logo') {
                continue;
            }

            $parts[$definition['part'] ?? 'note'][] = [
                ...$definition,
                'key' => $key,

                /* ⓘ শাখায়: কেবল শাখার নিজের বসানো মান (null = কোম্পানির মতো); পাশে কোম্পানিরটা, দেখানোর জন্য */
                'value' => $branch === null ? $this->settings->get($key) : $this->branches->own($key, $branch),
                'inherited' => $this->settings->get($key),
                'name' => $this->label($definition),

                /* ⓘ সাধারণ লেখা — ঘর খালি থাকলে এটাই ছাপা হয়; পাতায় ভরা দেখায়, যাতে বদলে নেওয়া যায় */
                'default_text' => isset($definition['default_text']) ? (string) __($definition['default_text'], [], 'bn') : null,
            ];
        }

        return array_filter($parts);
    }

    /**
     * চাওয়া শাখা — কেবল এই কোম্পানির; অচেনা বা অন্য কোম্পানির হলে null (কোম্পানি)।
     * ⛔ অন্য কোম্পানির শাখার আইডি দিয়ে তার বিলের চেহারা বদলানো যায় না।
     */
    private function branchFrom(mixed $asked): ?int
    {
        if (! is_numeric($asked)) {
            return null;
        }

        $id = (int) $asked;

        return Branch::query()->whereKey($id)->where('company_id', CompanyContext::id())->exists() ? $id : null;
    }

    /**
     * বিলের লোগো তোলা বা মোছা — কোম্পানির পাতার সেই একই ইঞ্জিনে ছোট করে ([[CompanyController::keepLogo()]])।
     * ⓘ কোম্পানিতে সেটিংয়ে, শাখায় শাখার বদলে; পথটা `public` ডিস্কে।
     */
    private function keepInvoiceLogo(Request $request, ?int $branch): void
    {
        $save = fn (?string $path) => match (true) {
            $path === null && $branch === null => $this->settings->reset(BranchSettings::INVOICE_LOGO),
            $path === null => $this->branches->reset(BranchSettings::INVOICE_LOGO, (int) $branch),
            $branch === null => $this->settings->set(BranchSettings::INVOICE_LOGO, $path),
            default => $this->branches->set(BranchSettings::INVOICE_LOGO, $branch, $path),
        };

        if ($request->boolean('remove_invoice_logo')) {
            $save(null);

            return;
        }

        $file = $request->file('invoice_logo');

        if ($file === null) {
            return;
        }

        $name = CompanyContext::id().'-'.($branch ?? 'co').'-'.now()->format('Ymd-His');
        $source = $file->getRealPath();

        if ($source !== false) {
            try {
                $mark = (new ImageEngine)->mark($source);
                $path = 'invoice-logos/'.$name.'.'.$mark['extension'];
                Storage::disk('public')->put($path, $mark['bytes']);
                $save($path);

                return;
            } catch (\Throwable) {
                // নিচে পড়ে যায়।
            }
        }

        /* ⛔ ছোট করা গেল না, অথচ ফাইলটা বড় — প্রতিটা কাগজে base64 হয়ে বসত; নীরবে রাখা যায় না */
        if ((int) $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['invoice_logo' => __('core.image.logo_not_processed')]);
        }

        $save($file->storeAs('invoice-logos', $name.'.'.$file->extension(), ['disk' => 'public']));
    }

    /** ঘরের নাম — সইয়ের চারটা ঘর একই নাম নেয়, কেবল ক্রমিক আলাদা */
    private function label(array $definition): string
    {
        return (string) __((string) $definition['label'], ['n' => $definition['label_n'] ?? '']);
    }

    /**
     * পরের বিক্রি নম্বর — কেবল দেখানোর জন্য; সিরিজ এখানে বদলায় না।
     *
     * ⓘ ২৯ সেপ্টেম্বর থেকে বিলের নম্বরই বিক্রি নম্বর (S-0001), তাই সিরিজ `S`। ⭐ শাখা ধরে (মালিক, ৬ অক্টোবর ২০২৬):
     * শাখার ট্যাবে সেই শাখার নিজের সিরিজ, না থাকলে সবার সাধারণটা আর সাথে "এই নম্বর সব শাখার"; কোম্পানির ট্যাবে
     * সাধারণটা। ⛔ আগে যেকোনো ট্যাবে প্রথম শাখার সিরিজটা দেখাত — লায়নের ট্যাবে সুপারের নম্বর।
     *
     * @return array{next: ?string, nextShared: bool}
     */
    private function nextSaleNumber(?int $branch): array
    {
        $active = fn () => NumberSeries::query()->where('doc_type', 'S')->where('is_active', true)->orderByDesc('financial_year_id');

        $own = $branch === null ? null : $active()->where('branch_id', $branch)->first();
        $shared = $active()->whereNull('branch_id')->first();
        $series = $own ?? $shared;

        return [
            'next' => $series === null ? null : $this->numbers->preview($series),
            'nextShared' => $own === null && $shared !== null,
        ];
    }
}
