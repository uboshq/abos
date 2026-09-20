<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Services\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বাজেট — পরিকল্পনা, বাজেট বনাম প্রকৃত, বিভাগভিত্তিক, আর রিপোর্ট।
 * ফিন্যান্সের মানচিত্র §১৬ ও §২৯, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ তিনটা তালিকা একই পাতার তিনটা ট্যাব (Capital পাতার ধরনে), কিন্তু
 * প্রতিটার নিজের ঠিকানা — মানচিত্রের প্রতিটা লাইন একটা আসল রুটে পৌঁছায়।
 * ফর্ম নিজের পাতায়: নতুন আর সম্পাদনা একই ফর্ম, খাত-বছর-বিভাগ দিয়ে চেনা।
 */
class BudgetController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly BudgetService $budgets,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.budget.view', only: ['index', 'actual', 'centers', 'report']),
            new Middleware('can:finance.budget.create', only: ['create', 'store']),
        ];
    }

    /** পরিকল্পনা — এক বছরের, খাত ধরে বারো মাস। */
    public function index(Request $request): View
    {
        $year = $this->year($request);
        $center = $request->integer('center') ?: null;

        return view('finance::budget.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => 'plan',
            'year' => $year,
            'center' => $center,
            'centers' => $this->centerList(),
            'plan' => $this->budgets->plan($year, $center),
        ]);
    }

    /** বাজেট বনাম প্রকৃত — বছর, আর চাইলে এক মাস বা বছরের শুরু থেকে আজ পর্যন্ত। */
    public function actual(Request $request): View
    {
        return $this->comparison($request, 'actual', byCenter: false);
    }

    /** বিভাগভিত্তিক — একই তুলনা, খরচের কেন্দ্র ধরে ভাগ করা। */
    public function centers(Request $request): View
    {
        return $this->comparison($request, 'centers', byCenter: true);
    }

    /**
     * বাজেটের রিপোর্ট — বছরের প্রতিটা খাত, বাজেট বনাম প্রকৃত, ছাপা ও নামানোর
     * জন্য (টুলবারের ছাপা/CSV)।
     */
    public function report(Request $request): View
    {
        return $this->comparison($request, 'report', byCenter: $request->boolean('by_center'));
    }

    /**
     * নতুন বা সম্পাদনা — একই ফর্ম।
     *
     * ⓘ `?account=&year=&center=` দিলে সেই সারির বারো মাস আগে থেকে বসে; না
     * দিলে ফাঁকা। ⚠️ আলাদা "edit/{id}" নেই, কারণ বাজেটের এক "সারি" আসলে বারোটা
     * মাসের সারি — কোনো একটার id দিয়ে গোটা বছরটা চেনা যায় না।
     */
    public function create(Request $request): View
    {
        $year = $this->year($request);
        $accountId = $request->integer('account') ?: null;
        $center = $request->integer('center') ?: null;

        $months = array_fill(1, 12, '');

        if ($accountId !== null) {
            Budget::query()
                ->where('year', $year)
                ->where('account_id', $accountId)
                ->when($center === null, fn ($q) => $q->whereNull('cost_center_id'), fn ($q) => $q->where('cost_center_id', $center))
                ->get()
                ->each(function (Budget $b) use (&$months) {
                    $months[$b->month] = rtrim(rtrim((string) $b->amount, '0'), '.');
                });
        }

        return view('finance::budget.form', [
            'menu' => $this->menu->forUser($request->user()),
            'year' => $year,
            'accountId' => $accountId,
            'center' => $center,
            'months' => $months,
            'accounts' => Account::query()->postable()->whereIn('type', BudgetService::TYPES)->orderBy('code')->get(),
            'centers' => $this->centerList(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'account_id' => ['required', 'integer'],
            'cost_center_id' => ['nullable', 'integer'],
            'months' => ['nullable', 'array'],
            'months.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->budgets->saveYear(
            (int) $data['year'],
            (int) $data['account_id'],
            isset($data['cost_center_id']) ? (int) $data['cost_center_id'] : null,
            (array) ($data['months'] ?? []),
        );

        return redirect()
            ->route('finance.budget.index', ['year' => $data['year']])
            ->with('saved', __('finance::budget.saved'));
    }

    private function comparison(Request $request, string $tab, bool $byCenter): View
    {
        $year = $this->year($request);

        /*
         * সময়: `month` = ১..১২ মানে সেই এক মাস; `ytd` মানে বছরের শুরু থেকে
         * চলতি মাস; না দিলে গোটা বছর। ⓘ চলতি বছরে ডিফল্ট ytd — ডিসেম্বরের
         * বাজেটের সাথে সেপ্টেম্বরের প্রকৃত মেলালে সবসময় "কম খরচ" দেখাত।
         */
        $month = $request->integer('month');
        $scope = $request->query('scope', $year === (int) now()->year && $month === 0 ? 'ytd' : 'year');

        [$from, $to] = match (true) {
            $month >= 1 && $month <= 12 => [$month, $month],
            $scope === 'ytd' => [1, $year === (int) now()->year ? (int) now()->month : 12],
            default => [1, 12],
        };

        $center = $request->integer('center') ?: null;

        return view('finance::budget.actual', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'year' => $year,
            'month' => $month,
            'scope' => $scope,
            'from' => $from,
            'to' => $to,
            'center' => $center,
            'centers' => $this->centerList(),
            'byCenter' => $byCenter,
            'rows' => $this->budgets->vsActual($year, $from, $to, $center, $byCenter),
        ]);
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year');

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }

    private function centerList()
    {
        return CostCenter::query()->where('is_active', true)->orderBy('code')->get();
    }
}
