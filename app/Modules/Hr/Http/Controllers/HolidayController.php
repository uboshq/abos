<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\BranchSettings;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Support\BranchReach;
use App\Modules\Hr\Support\WorkCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ ছুটির দিনের তালিকা — সরকারি ছুটি আর উৎসব, সব শাখার বা একটা শাখার (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন
 * ২০০৬ ধারা ১১৮; [[WorkCalendar]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── চাবি ───────────────────────────────────────────────────────────────
 * দেখা `hr.attendance.view` বা `hr.leave.view` (fe-র উত্তর ৫), বসানো-বদলানো-মোছা কেবল `hr.attendance.manage` — হাজিরার চাবি, কারণ তালিকাটা হাজিরা আর বেতনের কাটা দিন
 * বদলায় ([[AttendanceService::unpaidDays()]]); যিনি কামাই লিখতে পারেন, কেবল তিনিই কোন দিন কাজের দিন নয় তা বলতে পারেন।
 *
 * ── ⛔ শাখার দেয়াল ─────────────────────────────────────────────────────
 * শাখায় সীমিত মানুষ কেবল নিজের নাগালের শাখার ছুটি বসান, বদলান, মোছেন; সব শাখার (শাখা খালি) ছুটি কেবল যাঁর শাখার সীমা নেই
 * ([[BranchReach::branches()]] = null — মালিক, বা সীমাহীন HR)। ⓘ নইলে এক শাখার HR "সব শাখা"-র একটা ছুটি বসিয়ে অন্য শাখার
 * কামাই মুছে দিতেন। তালিকায় সব-শাখার ছুটি আর নাগালের শাখার ছুটি — হেডারের বাছা শাখা ধরে ([[BranchReach::viewing()]])।
 *
 * ── অডিট ─────────────────────────────────────────────────────────────
 * মডেলের ঘটনা থেকে ([[IsAudited]]): বসানো, বদলানো আর মোছা — আগের ও নতুন মানসহ।
 */
class HolidayController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly BranchReach $reach,
    ) {}

    public static function middleware(): array
    {
        return [
            // ⓘ দেখা দুই চাবির যেকোনোটায় — `can:` একটাই চাবি নেয়, তাই পদ্ধতিতে ([[authorizeViewing()]])
            new Middleware('can:hr.attendance.manage', only: ['edit', 'store', 'update', 'destroy', 'storeWeeklyOff']),
        ];
    }

    public function index(Request $request): View
    {
        // ⭐ দেখা হাজিরার বা ছুটির চাবিতে — ছুটির দিন দুই পাতারই প্রশ্ন (fe-র উত্তর ৫, ২১ অক্টোবর ২০২৬); লেখা কেবল hr.attendance.manage
        $this->authorizeViewing($request, ['hr.attendance.view', 'hr.leave.view']);

        return $this->screen($request, new Holiday);
    }

    /** @param  list<string>  $keys  যেকোনো একটা থাকলেই — নইলে ৪০৩ */
    private function authorizeViewing(Request $request, array $keys): void
    {
        abort_unless((bool) $request->user()?->canAny($keys), 403);
    }

    public function edit(Request $request, int $holiday): View
    {
        return $this->screen($request, $this->writable($request, $holiday));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Holiday::query()->create([...$data, 'created_by' => $request->user()?->id]);

        return redirect()->route('hr.holiday.index', ['year' => Carbon::parse($data['holiday_date'])->year])
            ->with('saved', __('hr::message.holiday_saved'));
    }

    public function update(Request $request, int $holiday): RedirectResponse
    {
        $row = $this->writable($request, $holiday);
        $data = $this->validated($request, $row);

        $row->fill($data)->save();

        return redirect()->route('hr.holiday.index', ['year' => Carbon::parse($data['holiday_date'])->year])
            ->with('saved', __('hr::message.holiday_updated'));
    }

    public function destroy(Request $request, int $holiday): RedirectResponse
    {
        $row = $this->writable($request, $holiday);
        $year = $row->holiday_date->year;

        $row->delete();

        return redirect()->route('hr.holiday.index', ['year' => $year])
            ->with('saved', __('hr::message.holiday_deleted'));
    }

    /**
     * ⭐ একটা শাখার সাপ্তাহিক ছুটি — কোম্পানির নিয়মের উপরে বদল, বা খালি পাঠালে কোম্পানির মতো (fe-র উত্তর ৬, ২১ অক্টোবর ২০২৬; শ্রম আইন
     * ধারা ১০৩, ১১৪; [[WorkCalendar::weeklyOff()]])। ⓘ সারিটা [[BranchSettings]]-এ, নিরীক্ষিত; ⛔ সীমিত নাগালে কেবল নিজের শাখা।
     */
    public function storeWeeklyOff(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();
        $limited = $this->reach->branches($request->user());

        /*
         * ⭐ কোম্পানির ডিফল্টও এখানেই — শাখা খালি পাঠালে (fe-র উত্তর ৪, ২১ অক্টোবর ২০২৬): এক নিয়ম, এক জায়গা। সেটিংসের পাতা আর কন্ট্রোল
         * প্যানেল ঘরটা দেখায়ই না (`owned_by`)। ⛔ শাখায় সীমিত মানুষ কোম্পানির নিয়ম বদলান না — তাতে অন্য শাখার কামাই বদলাত।
         */
        if (blank($request->input('branch_id'))) {
            $value = $request->validate([
                'weekly_off' => ['required', Rule::in(array_keys(WorkCalendar::WEEKLY_OFF))],
            ])['weekly_off'];

            if ($limited !== null) {
                throw ValidationException::withMessages(['branch_id' => __('hr::validation.weekly_off_company_needs_all_branches')]);
            }

            app(\App\Core\Services\SettingsService::class)->set('hr.weekly_off', $value);

            return back()->with('saved', __('hr::message.weekly_off_saved'));
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId),
                function (string $attribute, mixed $value, \Closure $fail) use ($limited): void {
                    if ($limited !== null && ! in_array((int) $value, $limited, true)) {
                        $fail(__('hr::validation.holiday_branch_out_of_reach'));
                    }
                }],
            'weekly_off' => ['nullable', Rule::in(array_keys(WorkCalendar::WEEKLY_OFF))],
        ]);

        $branches = app(BranchSettings::class);

        if (blank($data['weekly_off'] ?? null)) {
            $branches->reset('hr.weekly_off', (int) $data['branch_id']);
        } else {
            $branches->set('hr.weekly_off', (int) $data['branch_id'], $data['weekly_off']);
        }

        return back()->with('saved', __('hr::message.weekly_off_saved'));
    }

    private function screen(Request $request, Holiday $editing): View
    {
        $year = $request->integer('year', $editing->exists ? $editing->holiday_date->year : (int) now()->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
        $view = $this->reach->viewing($request->user());

        $holidays = Holiday::query()
            ->with('branch')
            ->whereBetween('holiday_date', [$year.'-01-01', $year.'-12-31'])
            // ⓘ সব-শাখার ছুটি সবসময়; শাখার ছুটি কেবল দেখার শাখায় ([[BranchReach::viewing()]])
            ->when($view !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $view['ids'])))
            ->orderBy('holiday_date')
            ->orderBy('id')
            ->paginate(\App\Core\Support\PerPage::of())
            ->withQueryString();

        return view('hr::holiday.index', [
            'menu' => $this->menu->forUser($request->user()),
            'holidays' => $holidays,
            'editing' => $editing,
            'year' => $year,
            'branches' => $this->branchesFor($request->user()),
            'mayAllBranches' => $this->reach->branches($request->user()) === null,
            'weeklyOff' => __('hr::settings.weekly_off_'.app(WorkCalendar::class)->weeklyOffKey()),
            // ⭐ শাখার নিজের সাপ্তাহিক ছুটি — শাখা → মান (না থাকলে কোম্পানির মতো)
            'branchWeeklyOff' => \App\Models\BranchSetting::query()->where('company_id', CompanyContext::id())
                ->where('key', 'hr.weekly_off')->pluck('value', 'branch_id')->all(),
            'weeklyOffOptions' => array_keys(WorkCalendar::WEEKLY_OFF),
            'weeklyOffKey' => app(WorkCalendar::class)->weeklyOffKey(),
        ]);
    }

    /**
     * @return array{holiday_date: string, name: string, branch_id: int|null}
     */
    private function validated(Request $request, ?Holiday $row = null): array
    {
        $companyId = CompanyContext::id();
        $limited = $this->reach->branches($request->user());

        $data = $request->validate([
            'holiday_date' => ['required', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => [
                // ⛔ সীমিত নাগালে "সব শাখা" নয় — উপরের দেয়াল
                Rule::requiredIf($limited !== null),
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
                function (string $attribute, mixed $value, \Closure $fail) use ($limited): void {
                    if ($limited !== null && ! in_array((int) $value, $limited, true)) {
                        $fail(__('hr::validation.holiday_branch_out_of_reach'));
                    }
                }],
        ], [
            'branch_id.required' => __('hr::validation.holiday_branch_required'),
        ]);

        $data['branch_id'] = filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null;
        $data['holiday_date'] = Carbon::parse($data['holiday_date'])->toDateString();

        /*
         * ⛔ একই দিনে একই শাখায় দুইবার নয় — আর সব-শাখার ছুটির দিনে শাখার আলাদা সারিও নয় (উল্টোটাও): দুইটার একটা মুছলে অন্যটা
         * দিনটাকে ছুটি রাখত, আর মোছা "কাজ করল না" মনে হত।
         */
        $twice = Holiday::query()
            ->whereDate('holiday_date', $data['holiday_date'])
            ->when($row !== null, fn ($q) => $q->whereKeyNot($row->id))
            // ⓘ সব-শাখার ছুটি সেদিনের যেকোনো সারির সাথে মেলে; শাখার ছুটি কেবল সব-শাখার আর নিজের শাখার সাথে
            ->when($data['branch_id'] !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $data['branch_id'])))
            ->exists();

        if ($twice) {
            throw ValidationException::withMessages([
                'holiday_date' => __('hr::validation.holiday_twice'),
            ]);
        }

        return $data;
    }

    /** ⛔ বদলানো বা মোছা কেবল নিজের নাগালের — উপরের দেয়াল; বাইরের হলে ৪০৩ */
    private function writable(Request $request, int $holiday): Holiday
    {
        $row = Holiday::query()->findOrFail($holiday);
        $limited = $this->reach->branches($request->user());

        abort_unless($limited === null || ($row->branch_id !== null && in_array((int) $row->branch_id, $limited, true)), 403);

        return $row;
    }

    /** @return \Illuminate\Support\Collection<int, Branch> */
    private function branchesFor(?User $user): \Illuminate\Support\Collection
    {
        $limited = $this->reach->branches($user);

        return Branch::query()
            ->when($limited !== null, fn ($q) => $q->whereKey($limited))
            ->orderBy('code')
            ->get();
    }
}
