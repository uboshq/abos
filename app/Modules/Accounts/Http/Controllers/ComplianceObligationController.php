<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\ComplianceObligation;
use App\Modules\Accounts\Services\ComplianceObligations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ করণীয় — কোষাগারে জমা আর মূসক-৬.৬, শেষ তারিখসহ; "করা হয়েছে" চালান বা সনদের নম্বরে (টাকা দেওয়ার মিল-তালিকা #৩; fe, ২২ অক্টোবর
 * ২০২৬)।
 *
 * ⓘ তালিকা হেডারে বাছা শাখা ধরে ([[ListedInViewedBranch]]); "করা হয়েছে"-ও কেবল সেই শাখার দায়ে — শাখায় সীমিত হিসাবরক্ষক অন্য শাখার
 * দায় চিহ্নিত করেন না।
 */
final class ComplianceObligationController extends Controller implements HasMiddleware
{
    use GrandTotals;

    /** ট্যাব — খোলা (ডিফল্ট), সময় পেরোনো, করা হয়েছে, বাতিল */
    private const TABS = ['open', 'overdue', 'done', 'cancelled'];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ComplianceObligations $obligations,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.obligation.view', only: ['index']),
            new Middleware('can:accounts.obligation.update', only: ['done']),
        ];
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'open';
        $base = fn () => ComplianceObligation::query()->inViewedBranch();

        $term = trim((string) $request->query('q'));
        $query = $base()
            ->when($tab === 'open', fn ($q) => $q->open())
            ->when($tab === 'overdue', fn ($q) => $q->overdue())
            ->when($tab === 'done', fn ($q) => $q->whereNotNull('done_at'))
            ->when($tab === 'cancelled', fn ($q) => $q->whereNotNull('cancelled_at')->whereNull('done_at'))
            // ⓘ খোঁজা — কাগজের নম্বর বা চালান/সনদের নম্বর ধরে
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('source_no', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%')
                ->orWhere('ref_no', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%')))
            // ⭐ প্যানেলের ছাঁকনি — কী করতে হবে (ধরন_বিষয়) আর শেষ তারিখ; সর্বমোটও এই একই প্রশ্ন থেকে
            ->when(is_string($request->query('kind')) && preg_match('/^('.implode('|', ComplianceObligation::KINDS).')_([a-z]+)$/', $request->query('kind')) === 1,
                function ($q) use ($request) {
                    preg_match('/^('.implode('|', ComplianceObligation::KINDS).')_([a-z]+)$/', (string) $request->query('kind'), $m);
                    $q->where('kind', $m[1])->where('subject', $m[2]);
                })
            ->when($this->day($request, 'from'), fn ($q, $day) => $q->whereDate('due_on', '>=', $day))
            ->when($this->day($request, 'to'), fn ($q, $day) => $q->whereDate('due_on', '<=', $day))
            // ⭐ পুরো বারের সাজানো ([[ListSort]], ২৭ নভেম্বর ২০২৬) — প্রথমটা আগের ক্রম (খোলা: শেষ দিন আগে; সম্পন্ন: শেষ কাজ আগে)
            ->tap(fn ($q) => \App\Core\ListFilter\ListSort::apply($q, $request, [
                'due_soon' => $tab === 'done' ? ['done_at', 'desc'] : ['due_on', 'asc'],
                'newest' => ['id', 'desc'],
                'oldest' => ['id', 'asc'],
            ]));

        $rows = (clone $query)->with('doneBy')->paginate(\App\Core\Support\PerPage::of())->withQueryString();

        $counts = [
            'open' => $base()->open()->count(),
            'overdue' => $base()->overdue()->count(),
        ];

        return view('accounts::obligation.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            // ⭐ সর্বমোট — ছাঁকা সব দায়ের টাকা, পাতার নয় ([[GrandTotals]])
            'grand' => $this->grandTotals($query, ['amount' => 't.amount']),
            'q' => $term,
            'tab' => $tab,
            'tabs' => collect(self::TABS)->map(fn (string $key) => [
                'key' => $key,
                'label' => __('accounts::obligation.tab.'.$key),
                'url' => route('accounts.obligation.index', ['tab' => $key]),
                'count' => $counts[$key] ?? null,
                'active' => $key === $tab,
            ])->all(),
        ]);
    }

    /** ঠিকানার তারিখ, কেবল সত্যিকারের Y-m-d হলে */
    private function day(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    public function done(Request $request, int $obligation): RedirectResponse
    {
        $data = $request->validate([
            'ref_no' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // ⛔ কেবল হেডারে বাছা শাখার দায় — অন্য শাখার id দিলে ৪০৪, যেন নেই
        $row = ComplianceObligation::query()->inViewedBranch()->findOrFail($obligation);

        $this->obligations->markDone($row, $request->user(), $data['ref_no'], $data['note'] ?? null);

        return back()->with('saved', __('accounts::obligation.marked_done', ['no' => $row->source_no ?? '—']));
    }
}
