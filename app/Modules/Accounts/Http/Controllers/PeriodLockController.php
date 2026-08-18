<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\FinancialYear;
use App\Models\PeriodLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * মাস বন্ধ করা ও খোলা।
 *
 * ── কেন বছর সমাপনীর সাথে এক পর্দায় নয় ──────────────────────────────
 * বছর সমাপনী বছরে একবার ঘটে, আর তাতে খোলা জের পরের বছরে টানা হয়, নম্বর
 * সিরিজ রিসেট হয় — একটা বড় ঘটনা। মাস বন্ধ করা রোজকার শৃঙ্খলা: রিপোর্ট
 * পাঠানো হয়ে গেছে, তাই মাসটা আর নড়বে না। একই পর্দায় রাখলে মাসিক কাজটা
 * করতে গিয়ে কেউ বছরের বোতামে হাত দিতেন।
 *
 * ── খোলার অনুমতি আলাদা ──────────────────────────────────────────────
 * বন্ধ করা শৃঙ্খলা; খোলা একটা ব্যতিক্রম। যিনি রোজ মাস বন্ধ করেন তাঁর
 * হাতে খোলার ক্ষমতা থাকলে তালাটা আর তালা নয় — এক মুহূর্তের জন্য খুলে
 * এন্ট্রি বসিয়ে আবার বন্ধ করে দেওয়া যেত, আর কেউ জানত না।
 */
class PeriodLockController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AuditEngine $audit,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.period.close', only: ['index', 'close']),
            new Middleware('can:accounts.period.reopen', only: ['reopen']),
        ];
    }

    public function index(Request $request): View
    {
        $year = FinancialYear::query()->where('is_current', true)->first()
            ?? FinancialYear::query()->orderByDesc('starts_on')->first();

        return view('accounts::period.index', [
            'menu' => $this->menu->forUser($request->user()),
            'year' => $year,
            'months' => $this->monthsOf($year),
        ]);
    }

    /**
     * অর্থবছরের মাসগুলো — আজ পর্যন্ত, ভবিষ্যতেরগুলো নয়।
     *
     * ভবিষ্যতের মাস বন্ধ করার কোনো অর্থ নেই: ওখানে এখনো কিছু ঘটেনি,
     * আর বন্ধ করলে কাল সকালেই কাজ থেমে যেত।
     *
     * @return list<array{year: int, month: int, label: string, lock: ?PeriodLock}>
     */
    private function monthsOf(?FinancialYear $year): array
    {
        if ($year === null) {
            return [];
        }

        $locks = PeriodLock::query()->get()
            ->keyBy(fn (PeriodLock $lock) => $lock->year.'-'.$lock->month);

        $cursor = Carbon::parse($year->starts_on)->startOfMonth();
        $last = Carbon::parse($year->ends_on)->startOfMonth();
        $today = Carbon::today()->startOfMonth();

        $months = [];

        while ($cursor->lte($last) && $cursor->lte($today)) {
            $months[] = [
                'year' => (int) $cursor->year,
                'month' => (int) $cursor->month,
                'label' => $cursor->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
                'lock' => $locks->get($cursor->year.'-'.$cursor->month),
            ];

            $cursor->addMonth();
        }

        return array_reverse($months);
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * চলতি মাস বন্ধ করা যায় না।
         *
         * বন্ধ করলে আজকের বিক্রিই থেমে যেত, আর প্রথম যিনি বিল কাটতে
         * যেতেন তিনি বুঝতেই পারতেন না কী হয়েছে।
         */
        if ($data['year'] === (int) now()->year && $data['month'] === (int) now()->month) {
            return back()->withErrors(['month' => __('accounts::validation.cannot_close_this_month')]);
        }

        $lock = PeriodLock::query()->firstOrCreate(
            ['company_id' => CompanyContext::id(), 'year' => $data['year'], 'month' => $data['month']],
            ['reason' => $data['reason'] ?? null, 'locked_by' => $request->user()?->id, 'locked_at' => now()],
        );

        return back()->with('saved', __('accounts::message.period_closed', ['month' => $lock->label()]));
    }

    /**
     * মাসটা আবার খোলা — কারণ ছাড়া নয়।
     *
     * ── কেন কারণটা বাধ্যতামূলক ──────────────────────────────────────
     * বন্ধ মাস খোলা মানে ছাপা হয়ে যাওয়া হিসাব বদলানোর দরজা খোলা। ছয়
     * মাস পরে নিরীক্ষক যখন জিজ্ঞেস করবেন "জুন মাসটা অক্টোবরে খোলা
     * হয়েছিল কেন", তখন উত্তরটা কোথাও থাকতে হবে। অডিটে কে খুলেছেন তা
     * লেখা থাকে, কেন তা নয়।
     */
    public function reopen(Request $request, PeriodLock $lock): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $label = $lock->label();

        $this->audit->recordAction($lock, 'reopened', $data['reason']);

        $lock->delete();

        return back()->with('saved', __('accounts::message.period_reopened', ['month' => $label]));
    }
}
