<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\DataScope;
use App\Core\Services\ExportJournal;
use App\Core\Services\MenuBuilder;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\DayBookPaper;
use App\Modules\Accounts\Services\DayBookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ দৈনিক খতিয়ান — এক দিনের খাতা, জার্নাল ধরে (মালিক, ১৬ নভেম্বর ২০২৬: Day Closing v7 + Day Book "ok"; ১৮ নভেম্বর: "এখনই করে লাইভে দাও")।
 *
 * ⓘ পুরনো "Day Book" (রিপোর্ট-ইঞ্জিনের সমতল তালিকা, `/accounts/reports/day-book`) থাকে — তারিখের পরিসর ধরে খোঁজার কাজে, আর
 *   অনেক জায়গা ওর ঠিকানা চেনে। মেনুর "দৈনিক খতিয়ান" এখন এখানে আসে।
 * ⓘ চাবি পুরনোটার একই — `accounts.report`; শাখার নিয়ম স্থিতিপত্রের অবিকল ([[BalanceSheetController]])।
 */
class DayBookController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DayBookService $book,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report')];
    }

    public function show(Request $request): View|Response
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'branch_id' => ['nullable', 'integer']]);

        $date = is_string($request->query('date')) && $request->query('date') !== '' ? (string) $request->query('date') : now()->toDateString();

        $asked = $request->integer('branch_id') ?: null;
        $scope = app(DataScope::class);
        abort_if($asked !== null && ! $scope->allows($request->user(), UserDataScope::BRANCH, $asked), 404);

        // ⓘ নিজে না বাছলে হেডারে বাছা শাখা — নিয়ম স্থিতিপত্রের মতোই
        $branchId = $asked ?? ViewedBranch::one($request->user());
        $book = $this->book->build($date, $branchId);

        $how = $request->query('export');
        if ($how === 'print' || $how === 'pdf') {
            return $this->paper($request, $book, $branchId, (string) $how);
        }

        $reach = $scope->idsFor($request->user(), UserDataScope::BRANCH);

        return view('accounts::report.day-book', [
            'menu' => $this->menu->forUser($request->user()),
            'book' => $book,
            'branchId' => $branchId,
            'branches' => Branch::query()->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))->orderBy('name_en')->get(),
        ]);
    }

    /** @param  array<string, mixed>  $book */
    private function paper(Request $request, array $book, ?int $branchId, string $how): Response
    {
        $pdf = app(PrintEngine::class)->renderDocument('accounts::print.day-book', [
            'title' => (string) __('accounts::daybook.title'),
            'book' => $book,
            'pages' => DayBookPaper::pages($book),
            'branch' => $branchId !== null ? (string) Branch::query()->whereKey($branchId)->first()?->name() : (string) __('core.listing.all_branches'),
            'printedBy' => (string) $request->user()->name,
            'printedAt' => now()->format('d-m-Y, h:i A'),
        ], PaperSize::A4)['pdf'];

        app(ExportJournal::class)->wrote(1);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($how === 'print' ? 'inline' : 'attachment').'; filename="day-book-'.$book['date'].'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
