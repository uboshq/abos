<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Reports\BankLoanReports;
use App\Modules\Finance\Reports\HandLoanReports;
use App\Modules\Finance\Reports\InsuranceReports;
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
        'bank-loan-book' => LoanLedgerReports::BANK_LOAN,

        // ⭐ হাতধারের রিপোর্ট ৩–৭ — অর্থ-মডিউলের পরিকল্পনা, ৫ অক্টোবর ২০২৬ ([[HandLoanReports]])
        'hand-loan-reconcile' => HandLoanReports::RECONCILE,
        'hand-loan-receivable' => HandLoanReports::RECEIVABLE,
        'hand-loan-payable' => HandLoanReports::PAYABLE,
        'hand-loan-receivable-age' => HandLoanReports::AGE_RECEIVABLE,
        'hand-loan-payable-age' => HandLoanReports::AGE_PAYABLE,
        'hand-loan-activity' => HandLoanReports::ACTIVITY,
        'hand-loan-schedule' => HandLoanReports::SCHEDULE,

        // ⭐ ব্যাংক ঋণ — কিস্তি আর সীমার ব্যবহার (অর্থ-মডিউলের পরিকল্পনা ৩, ৬ অক্টোবর ২০২৬; [[BankLoanReports]])
        'bank-loan-instalments' => BankLoanReports::INSTALMENTS,
        'bank-limit-usage' => BankLoanReports::LIMITS,

        // ⭐ বীমার প্রিমিয়ামের সূচি (অর্থ-মডিউলের পরিকল্পনা ৬.২; [[InsuranceReports]])
        'insurance-premiums' => InsuranceReports::PREMIUMS,
        // ⭐ বীমার দাবির খাতা (পরিকল্পনা ৬.৪)
        'insurance-claims' => InsuranceReports::CLAIMS,
    ];

    /** ব্যাংক ঋণের রিপোর্টের সারি — রিপোর্টের মাথায় আর ঋণের তালিকায় একই ক্রমে */
    public const BANK_LOAN_REPORTS = [
        'bank-loan-instalments' => 'finance::bank_loan_report.instalments_short',
        'bank-limit-usage' => 'finance::bank_loan_report.limits_short',
    ];

    /** হাতধারের রিপোর্টের সারি — প্রতিটা রিপোর্টের মাথায় আর হাতধারের পাতায় একই ক্রমে */
    public const HAND_LOAN_REPORTS = [
        'hand-loan-receivable' => 'finance::hand_loan_report.receivable_short',
        'hand-loan-payable' => 'finance::hand_loan_report.payable_short',
        'hand-loan-receivable-age' => 'finance::hand_loan_report.age_receivable_short',
        'hand-loan-payable-age' => 'finance::hand_loan_report.age_payable_short',
        'hand-loan-activity' => 'finance::hand_loan_report.activity_short',
        'hand-loan-schedule' => 'finance::hand_loan_report.schedule_short',
        'hand-loan-reconcile' => 'finance::hand_loan_report.reconcile_short',
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

        // ⓘ শাখা ধরে ভাগ চাওয়া হয়, বাকি রিপোর্ট-পর্দার মতো — ভাগ হবে কি না ইঞ্জিন ঠিক করে; এই খাতাগুলো একটাই চলমান জের,
        // তাই সংজ্ঞায় `splitByBranch: false` ([[ReportEngine::branchPlan()]])
        $result = $this->reports->run($key, $request->only($definition->requestKeys()), page: max(1, (int) $request->query('page', 1)), byBranch: true);

        if ($key === LoanLedgerReports::BANK_LOAN) {
            return view('accounts::report.show', [
                'menu' => $this->menu->forUser($request->user()),
                'slug' => $slug,
                'report' => $definition,
                'result' => $result,
                'branches' => collect(),
                'accounts' => collect(),
                'partyTypes' => collect(),
                // ⓘ ঋণ বাছার ঘর — ব্যাংক আর কাগজের নম্বর
                'partyFilter' => 'facility_id',
                'parties' => BankFacility::query()->inViewedBranch()->orderBy('bank')->get()
                    ->map(fn (BankFacility $f) => (object) ['id' => (int) $f->id, 'name' => trim($f->bank.' · '.$f->document_no, ' ·')]),
            ]);
        }

        // ⓘ বীমার প্রিমিয়ামের সূচি আর দাবির খাতা — অবস্থা বাছার ঘর, যার যার নিজের
        if (in_array($slug, ['insurance-premiums', 'insurance-claims'], true)) {
            return view('accounts::report.show', [
                'menu' => $this->menu->forUser($request->user()),
                'slug' => $slug,
                'report' => $definition,
                'result' => $result,
                'branches' => Branch::query()->active()->orderBy('name_en')->get(),
                'accounts' => collect(),
                'partyTypes' => collect(),
                'extraFilters' => $slug === 'insurance-claims'
                    ? 'finance::insurance.partials.claim-state'
                    : 'finance::insurance.partials.premium-state',
            ]);
        }

        // ⓘ ব্যাংক ঋণের তালিকা-রিপোর্ট — ঋণ বাছার ঘর নেই, মাথায় রিপোর্টের সারি
        if (isset(self::BANK_LOAN_REPORTS[$slug])) {
            return view('accounts::report.show', [
                'menu' => $this->menu->forUser($request->user()),
                'slug' => $slug,
                'report' => $definition,
                'result' => $result,
                'branches' => Branch::query()->active()->orderBy('name_en')->get(),
                'accounts' => collect(),
                'partyTypes' => collect(),
                'extraFilters' => 'finance::bank-facility.partials.report-tabs',
            ]);
        }

        // ⓘ হাতধারের তালিকা-রিপোর্টগুলো — কাউকে বাছার ঘর নেই, মাথায় রিপোর্টের সারি
        if (isset(self::HAND_LOAN_REPORTS[$slug])) {
            return view('accounts::report.show', [
                'menu' => $this->menu->forUser($request->user()),
                'slug' => $slug,
                'report' => $definition,
                'result' => $result,
                'branches' => Branch::query()->active()->orderBy('name_en')->get(),
                'accounts' => collect(),
                'partyTypes' => collect(),
                'extraFilters' => 'finance::hand-loan.partials.report-tabs',
            ]);
        }

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
