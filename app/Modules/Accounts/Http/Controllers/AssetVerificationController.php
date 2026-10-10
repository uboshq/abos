<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Branch;
use App\Modules\Accounts\Models\AssetVerification;
use App\Modules\Accounts\Models\AssetVerificationLine;
use App\Modules\Accounts\Services\AssetVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ সরেজমিন গোনার অভিযান — শাখা ধরে, ফল আর ছবিসহ, শেষে পার্থক্য (স্থায়ী সম্পদ ধাপ ৪)।
 *
 * ⓘ দেখা `accounts.asset.view`; গোনা, খোলা আর বন্ধ করা `accounts.asset.verify` — গুদামের মানুষ গুনতে পারেন, সম্পদ বদলাতে
 * পারেন না। শাখার দেয়াল অভিযানের মডেলের নিজের ([[ScopedToUserBranch]])।
 */
class AssetVerificationController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AssetVerificationService $verify,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.asset.view', only: ['index', 'show']),
            new Middleware('can:accounts.asset.verify', only: ['store', 'mark', 'close']),
            // ⓘ গোনার সারির নীতি — ছবির দরজাও এটাই জিজ্ঞেস করে ([[AssetVerificationLinePolicy]])
            new Middleware('can:view,line', only: ['mark']),
        ];
    }

    public function index(Request $request): View
    {
        return view('accounts::asset.verify.index', [
            'menu' => $this->menu->forUser($request->user()),
            'campaigns' => AssetVerification::query()->with(['branch', 'creator'])->withCount([
                'lines',
                'lines as checked_count' => fn ($q) => $q->whereNotNull('result'),
                'lines as exception_count' => fn ($q) => $q->whereNotNull('result')->where('result', '!=', AssetVerificationLine::FOUND),
            ])->orderByDesc('started_on')->orderByDesc('id')->paginate(50)->withQueryString(),
            'branches' => Branch::query()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:191'],
            'started_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $campaign = $this->verify->open((int) $data['branch_id'], $data['title'] ?? null, $data['started_on']);

        return redirect()->route('accounts.asset.verify.show', $campaign)->with('status', __('accounts::asset.verify_opened'));
    }

    public function show(Request $request, AssetVerification $verification): View
    {
        $variance = $this->verify->variance($verification);
        $lines = $verification->lines()->with(['asset', 'checker'])->get();

        return view('accounts::asset.verify.show', [
            'menu' => $this->menu->forUser($request->user()),
            'campaign' => $verification->load(['branch', 'creator']),
            'lines' => $lines,
            'variance' => $variance,
            'people' => app(PartyRegistry::class)->labelsOf($lines->whereNotNull('expected_custodian_id')
                ->map(fn ($l) => ['employee', (int) $l->expected_custodian_id])->values()->all()),
            // ⓘ প্রতিটা সারির ছবি — সংযুক্তির ইঞ্জিনের সারি, খোলা হয় তার নিজের দরজা দিয়ে
            'photos' => Attachment::query()->where('source_module', 'accounts')
                ->where('source_entity', AssetVerificationLine::ATTACHMENT_ENTITY)
                ->whereIn('source_entity_id', $lines->pluck('id'))->get()->groupBy('source_entity_id'),
        ]);
    }

    public function mark(Request $request, AssetVerificationLine $line): RedirectResponse
    {
        $data = $request->validate([
            'result' => ['required', Rule::in(AssetVerificationLine::RESULTS)],
            'found_location' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
        ]);

        $this->verify->mark($line, $data['result'], $data['found_location'] ?? null, $data['note'] ?? null, $request->file('photo'));

        return back()->with('status', __('accounts::asset.verify_marked'));
    }

    public function close(AssetVerification $verification): RedirectResponse
    {
        $this->verify->close($verification);

        return back()->with('status', __('accounts::asset.verify_closed_now'));
    }
}
