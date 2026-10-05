<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Reports\LoanLedgerReports;
use App\Modules\MasterData\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * ⭐ অর্থের খাতাগুলো — হাতধার (আর পরে ব্যাংক ঋণ), গ্রাহকের খাতার একই পাতায় (`accounts::report.show`): তারিখ, খোলা জের,
 * সারি, চলমান আর শেষ জের, ছাপা/PDF/Excel (মালিকের সরাসরি আদেশ, ৫ অক্টোবর ২০২৬, সমন্বয়কের মারফত)।
 *
 * ⓘ স্লাগ থেকে রিপোর্ট ([[SLUGS]]) — বাকি মডিউলের রিপোর্ট-দরজার একই ধাঁচ, তাই দরজার চাবি আর রিপোর্টের চাবি এক কি না
 * পাহারা নিজেই মেলায় ([[EveryReportNamesTheKeyItsWebDoorAsksForTest]])। ⛔ চাবি রিপোর্টের নিজের ঘোষণা থেকে —
 * প্রতিটা খাতার আলাদা (হাতধার `finance.hand_loan.view`), তাই দরজায় একটা বাঁধা চাবি নয়।
 */
class FinanceReportController extends Controller
{
    public const SLUGS = [
        'hand-loan-book' => LoanLedgerReports::HAND_LOAN,
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);
        Gate::authorize($definition->permission);

        $result = $this->reports->run($key, $request->only($definition->requestKeys()), page: max(1, (int) $request->query('page', 1)));
        $personId = (int) ($result->filters['person_id'] ?? 0);

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            // ⓘ মানুষ বাছার ঘর — খাতার পাতা থেকেই অন্য জনের খাতায়
            'partyFilter' => 'person_id',
            'parties' => Person::query()->orderBy('name_en')->get()
                ->map(fn (Person $p) => (object) ['id' => (int) $p->id, 'name' => $p->name()]),
            // ⓘ খাতার পাশে কাজের দরজা — নতুন দেওয়া/নেওয়া, আর সব খাত মিলিয়ে তাঁর খতিয়ান
            'extraFilters' => 'finance::hand-loan.partials.book-actions',
            'bookPerson' => $personId > 0 ? Person::query()->find($personId) : null,
            'bookAccount' => $personId > 0
                ? HandLoanAccount::query()->open()->where('person_id', $personId)->latest('id')->first()
                : null,
        ]);
    }
}
