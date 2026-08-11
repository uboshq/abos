<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\SalaryHeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * বেতনের খাতের তালিকা ও ফর্ম।
 */
class SalaryHeadController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly SalaryHeadService $heads,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:hr.salary.manage')];
    }

    public function index(Request $request): View
    {
        $query = SalaryHead::query()
            ->when(! $request->boolean('inactive'), fn ($q) => $q->active());

        $sort = $this->applySort($query, $request, $this->sorts());

        $heads = $query->get();

        return view('hr::salary_head.index', [
            'menu' => $this->menu->forUser($request->user()),
            'heads' => $heads,
            // সব খাত খালি হলেই কেবল "প্রমিত খাত বসান" দেখানো হয়
            'canInstallDefaults' => $heads->isEmpty() && ! $request->boolean('inactive'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
        ]);
    }

    /**
     * পে-স্লিপের ক্রমেই ডিফল্ট — আয় আগে, তারপর কর্তন।
     *
     * ── কেন বর্ণানুক্রম নয় ──────────────────────────────────────────
     * এই তালিকাটা যা দেখায় সেটাই কাগজে ছাপা হয়। বর্ণানুক্রমে সাজালে
     * পর্দায় এক ক্রম আর পে-স্লিপে আরেক ক্রম হত, আর কেউ মিলিয়ে দেখতে
     * গেলে বিভ্রান্ত হত।
     *
     * @return array<string, \Closure>
     */
    private function sorts(): array
    {
        return [
            'payslip' => fn ($q) => $q->orderBy('kind')->orderBy('sort_order')->orderBy('code'),
            'code' => fn ($q) => $q->orderBy('code'),
            'name' => fn ($q) => $q->orderBy('name_en'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'payslip' => __('hr::sort.payslip_order'),
            'code' => __('hr::field.code'),
            'name' => __('hr::field.name'),
        ];
    }

    public function create(Request $request): View
    {
        return view('hr::salary_head.form', [
            'menu' => $this->menu->forUser($request->user()),
            'head' => new SalaryHead([
                'kind' => SalaryHead::EARNING,
                'calculation' => SalaryHead::FIXED,
                'prorated_by_attendance' => true,
                'is_active' => true,
            ]),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->heads->create($this->validated($request));

        return redirect()->route('hr.salary_head.index')
            ->with('saved', __('hr::message.head_created'));
    }

    public function edit(Request $request, int $head): View
    {
        return view('hr::salary_head.form', [
            'menu' => $this->menu->forUser($request->user()),
            'head' => SalaryHead::query()->findOrFail($head),
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, int $head): RedirectResponse
    {
        $this->heads->update(SalaryHead::query()->findOrFail($head), $this->validated($request));

        return redirect()->route('hr.salary_head.index')
            ->with('saved', __('hr::message.head_updated'));
    }

    public function destroy(int $head): RedirectResponse
    {
        $this->heads->deactivate(SalaryHead::query()->findOrFail($head));

        return back()->with('saved', __('hr::message.head_deactivated'));
    }

    public function installDefaults(): RedirectResponse
    {
        $this->heads->installDefaults();

        return back()->with('saved', __('hr::message.heads_installed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $companyId = CompanyContext::id();

        $request->merge([
            'is_basic' => $request->boolean('is_basic'),
            'prorated_by_attendance' => $request->boolean('prorated_by_attendance'),
        ]);

        return $request->validate([
            // খালি রাখলে সিরিজ থেকে বসে — SalaryHeadService::create() দেখুন
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'kind' => ['required', Rule::in(SalaryHead::KINDS)],
            'calculation' => ['required', Rule::in(SalaryHead::CALCULATIONS)],
            'is_basic' => ['boolean'],
            'prorated_by_attendance' => ['boolean'],
            'account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'accounts' => Account::query()->postable()->active()->orderBy('code')->get(),
            'kinds' => SalaryHead::KINDS,
            'calculations' => SalaryHead::CALCULATIONS,
        ];
    }
}
