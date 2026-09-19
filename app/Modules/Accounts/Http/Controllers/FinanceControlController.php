<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NumberSeriesCatchUp;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ErrorEvent;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\DuplicateParties;
use App\Modules\Accounts\Services\MonthEndChecklist;
use App\Modules\Backup\Models\BackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

/**
 * খাতার নিয়ন্ত্রণ — কী উঠল, কী আটকে আছে, কী ভাঙল, মাস শেষ হলো কি না।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: *"Finance map 100% complate korba"*, তারপর *"bachai koro kongulo
 * jororin"*। ফিন্যান্স মানচিত্রের §২, §২৪, §৩১, §৩৩-এর লাইনগুলো ("পোস্টিং
 * মনিটর", "মাস-শেষের চেকলিস্ট", "ব্যর্থ পোস্টিংয়ের সারি", "পটভূমির কাজ ও
 * সতর্কতা", "নম্বর সিরিজ মেলানো") এক জিনিস চায়: যে কাজ চোখের আড়ালে হয়,
 * সেটা একটা পর্দায় দেখা।
 *
 * ⓘ সবগুলো কেবল পড়ে। ⛔ একমাত্র লেখা হলো নম্বর সিরিজ সামনে আনা, আর সেটাও
 * [[NumberSeriesCatchUp]]-এর হিসাব, কমান্ডটা যেটা চালায় সেটাই।
 */
class FinanceControlController extends Controller implements HasMiddleware
{
    /** কত দিনের পুরনো খসড়াকে "আটকে আছে" বলা হয়। */
    public const STUCK_AFTER_DAYS = 2;

    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.report', except: ['catchUp']),
            new Middleware('can:system_admin.settings.manage', only: ['numbers', 'catchUp']),
        ];
    }

    /**
     * পোস্টিং মনিটর — একটা দিনে কোন কাগজ কতবার খাতায় উঠল, আর কী আটকে আছে।
     */
    public function posting(Request $request): View
    {
        $tab = $request->query('tab') === 'stuck' ? 'stuck' : 'posted';
        $date = $this->date($request->query('date'));

        /*
         * ⓘ উল্টো-পোস্টও খাতার সারি — তাই "কতগুলো কাগজ" গোনা হয় আলাদা
         * source_id ধরে, সারি ধরে নয়। একটা বিলে দশটা লাইন থাকলেও সেটা একটা বিল।
         */
        $posted = LedgerEntry::query()
            ->whereDate('trx_date', $date)
            ->select('source_type')
            ->selectRaw('COUNT(DISTINCT source_id) as documents')
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('SUM(debit) as debit')
            ->selectRaw('MAX(created_at) as last_at')
            ->groupBy('source_type')
            ->orderByDesc('documents')
            ->get()
            ->map(fn ($row) => [
                'source' => $this->sourceLabel((string) $row->source_type),
                'documents' => (int) $row->documents,
                'lines' => (int) $row->line_count,
                'debit' => (string) $row->debit,
                'last_at' => $row->last_at,
            ])
            ->all();

        $awaitingIds = Approval::query()
            ->where('approvable_type', Voucher::class)
            ->pending()
            ->pluck('approvable_id');

        $stuck = Voucher::query()
            ->where('status', DocumentStatus::DRAFT)
            ->where(fn (Builder $q) => $q
                ->whereIn('id', $awaitingIds)
                ->orWhere('created_at', '<', now()->subDays(self::STUCK_AFTER_DAYS)))
            ->orderBy('trx_date')
            ->limit(200)
            ->get();

        return view('accounts::control.posting', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'date' => $date,
            'posted' => $posted,
            'stuck' => $stuck,
            'awaitingIds' => $awaitingIds->map(fn ($id) => (int) $id)->all(),
            'failedCount' => $this->failedQuery()->count(),
        ]);
    }

    /**
     * ব্যর্থ পোস্টিংয়ের সারি — খাতায় লিখতে গিয়ে যে ভুল হয়েছে, এখনো কেউ দেখেননি।
     *
     * ⓘ ভুলগুলো আগে থেকেই [[ErrorLogController]]-এর খাতায় লেখা হয়; এখানে
     * কেবল খাতায় লেখার পথের ভুলগুলো ছেঁকে দেখানো হয়, স্ট্যাক ট্রেস ছাড়া।
     * পুরো বিবরণ ভুলের খাতায়, নিজের চাবিতে।
     */
    public function failed(Request $request): View
    {
        return view('accounts::control.failed', [
            'menu' => $this->menu->forUser($request->user()),
            'failures' => $this->failedQuery()->orderByDesc('last_seen_at')->limit(200)->get(),
        ]);
    }

    public function monthEnd(Request $request, MonthEndChecklist $checklist): View
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month'))
            : CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();

        return view('accounts::control.month-end', [
            'menu' => $this->menu->forUser($request->user()),
            'month' => $month,
            'checks' => $checklist->run($month),
        ]);
    }

    /**
     * পটভূমির কাজ ও সতর্কতা — কী কখন নিজে চলে, আর কোনটা শেষবার ব্যর্থ হয়েছে।
     */
    public function jobs(Request $request, Schedule $schedule): View
    {
        $tasks = collect($schedule->events())
            ->map(fn ($event) => [
                'what' => $this->taskName($event),
                'when' => $event->expression,
                'next' => CarbonImmutable::instance($event->nextRunDate()),
            ])
            ->sortBy('next')
            ->values()
            ->all();

        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(50)->get(['id', 'queue', 'exception', 'failed_at'])
            : collect();

        // ⓘ রাতের ব্যাকআপ গোটা ডাটাবেসের, কোম্পানিহীন সারি — দুইটাই গোনা
        $lastBackup = BackupRun::query()
            ->withoutGlobalScopes()
            ->where(fn (Builder $q) => $q->where('company_id', CompanyContext::id())->orWhereNull('company_id'))
            ->latest('started_at')
            ->first();

        return view('accounts::control.jobs', [
            'menu' => $this->menu->forUser($request->user()),
            'tasks' => $tasks,
            'waiting' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failedJobs' => $failedJobs,
            'lastBackup' => $lastBackup,
            'unseenErrors' => $this->errorsOfThisCompany()->whereNull('acknowledged_at')->count(),
        ]);
    }

    /**
     * ডুপ্লিকেট খোঁজা — একই মোবাইল বা একই নামে দুইটা সারি ([[DuplicateParties]])।
     */
    public function duplicates(Request $request, DuplicateParties $finder): View
    {
        $kind = array_key_exists((string) $request->query('tab'), DuplicateParties::KINDS)
            ? (string) $request->query('tab')
            : 'customer';

        return view('accounts::control.duplicates', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $kind,
            'counts' => $finder->counts(),
            'groups' => $finder->groups($kind),
        ]);
    }

    public function numbers(Request $request, NumberSeriesCatchUp $catchUp): View
    {
        return view('accounts::control.numbers', [
            'menu' => $this->menu->forUser($request->user()),
            'behind' => $catchUp->behind(CompanyContext::id()),
        ]);
    }

    public function catchUp(NumberSeriesCatchUp $catchUp): RedirectResponse
    {
        $moved = $catchUp->apply($catchUp->behind(CompanyContext::id()));

        return redirect()
            ->route('accounts.control.numbers')
            ->with('saved', trans_choice('accounts::control.numbers_moved', $moved, ['count' => $moved]));
    }

    /**
     * খাতায় লেখার পথের ভুল — এই কোম্পানির, আর যেটা কেউ দেখেননি।
     *
     * ⚠️ ক্লাস বা স্ট্যাকে Posting / Ledger থাকলেই ধরা হয়। নাম ধরে মেলানো
     * নিখুঁত নয়, কিন্তু ভুলের খাতায় কোনো "কোন ইঞ্জিন" কলাম নেই, আর পুরো
     * খাতা দেখানো হলে বিক্রির পর্দার বানান ভুলও এখানে আসত।
     */
    private function failedQuery(): Builder
    {
        return $this->errorsOfThisCompany()
            ->whereNull('acknowledged_at')
            ->where(fn (Builder $q) => $q
                ->where('class', 'like', '%Posting%')
                ->orWhere('class', 'like', '%Ledger%')
                ->orWhere('trace', 'like', '%PostingEngine%')
                ->orWhere('trace', 'like', '%LedgerEntry%'));
    }

    private function errorsOfThisCompany(): Builder
    {
        return ErrorEvent::query()->where('company_id', CompanyContext::id());
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        }

        return CarbonImmutable::today();
    }

    private function sourceLabel(string $type): string
    {
        return Lang::has('core.source.'.$type) ? __('core.source.'.$type) : $type;
    }

    /** কমান্ড হলে তার নাম (`abos:backup-due`), নাহলে বর্ণনা। */
    private function taskName(object $event): string
    {
        /*
         * ⓘ উইন্ডোজে পথটা দুই-উদ্ধৃতিতে আসে ("php.exe" "artisan" abos:books-check),
         * লিনাক্সে এক-উদ্ধৃতিতে — তাই দুই রকম উদ্ধৃতিই ছাড় দেওয়া হয়।
         */
        if (isset($event->command) && is_string($event->command)
            && preg_match('/artisan["\']?\s+["\']?([\w:.-]+)/', $event->command, $m)) {
            return $m[1];
        }

        return (string) ($event->description ?: __('accounts::control.unnamed_task'));
    }
}
