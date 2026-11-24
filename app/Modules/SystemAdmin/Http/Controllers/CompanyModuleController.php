<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\ListFilter\Facets;
use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Support\PerPage;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\SystemAdmin\Support\ModuleBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ কোম্পানির মডিউল — বাঁয়ে কোম্পানি, ডানে মডিউল; অনেকগুলো একসাথে (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4)।
 *
 * ⛔ কেবল যে কোম্পানিতে এই মানুষের সেটিং বদলানোর চাবি আছে ([[ModuleBoard::companiesFor()]]) — অন্যটার নম্বর পাঠালে ৪০৪।
 * ⓘ লেখার আগে সবসময় তালিকা দেখায় (preview), তারপর সেই তালিকাটাই লেখে (apply)।
 */
class CompanyModuleController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ModuleBoard $board, private readonly MenuBuilder $menu, private readonly ListExport $export) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function index(Request $request): View
    {
        $mine = $this->board->companiesFor($request->user());
        $off = $this->board->companyOff($mine->pluck('id')->map(fn ($id) => (int) $id)->all());

        $q = is_string($request->query('q')) ? mb_strtolower(trim($request->query('q'))) : '';
        $state = $request->query('state');
        $shown = $mine->filter(fn (Company $c) => ($q === '' || str_contains(mb_strtolower($c->code.' '.$c->name_en.' '.$c->name_bn), $q))
            && ($state !== 'diff' || $off[$c->id] !== []) && ($state !== 'same' || $off[$c->id] === []));
        if ($request->query('sort') === 'off') {
            $shown = $shown->sortByDesc(fn (Company $c) => count($off[$c->id]));
        }
        $shown = $shown->values();

        $per = PerPage::of();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $rows = (new LengthAwarePaginator($shown->forPage($page, $per)->values(), $shown->count(), $per, $page, ['path' => $request->url()]))->withQueryString();

        $ids = ModuleBoard::picked($request->query('pick', []), $shown->pluck('id')->map(fn ($id) => (int) $id)->all());
        if ($ids === [] && $shown->isNotEmpty()) {
            // ⓘ খোলা কোম্পানিটাই আগে — নামের প্রথমটা নয়
            $here = (int) \App\Core\Support\CompanyContext::id();
            $ids = [$shown->contains('id', $here) ? $here : (int) $shown->first()->id];
        }
        $names = $mine->mapWithKeys(fn (Company $c) => [(int) $c->id => $c->name()])->all();

        $this->offerTheMatrix($rows, $off);

        $copy = (int) $request->query('copy_from', 0);

        return view('system_admin::module-board.board', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => 'company',
            'rows' => $rows,
            'off' => $off,
            'packages' => [],
            'ids' => $ids,
            'names' => $names,
            'groups' => $this->board->rows($ids, isset($off[$copy]) ? array_fill_keys($ids, $off[$copy]) : $off),
            'was' => $this->board->rows($ids, $off),
            'copyable' => $names,
            'copied' => isset($names[$copy]) ? $names[$copy] : null,
            'history' => $this->board->companyHistory($ids, $names),
            'companies' => [],
            'company' => null,
            'facets' => Facets::panel([
                Facets::pick('state', __('core.table.status'), ['diff' => __('system_admin::module_board.some_off'), 'same' => __('system_admin::module_board.all_on')]),
            ]),
        ]);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        [$names, $plan] = $this->planned($request);

        if ($plan['changes'] === []) {
            return redirect()->route('system_admin.module.companies', ['pick' => array_keys($names)])->with('saved', __('system_admin::module_board.nothing'));
        }

        return view('system_admin::module-board.preview', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => 'company', 'plan' => $plan, 'board' => $this->board, 'stamp' => $this->board->stamp($plan['changes']),
            'apply' => route('system_admin.module.companies.apply'),
            'back' => route('system_admin.module.companies', ['pick' => array_keys($names)]),
            'payload' => $request->only(['pick', 'scope', 'modules', 'was', 'all_on']),
            'stale' => false,
        ]);
    }

    public function apply(Request $request): View|RedirectResponse
    {
        [$names, $plan] = $this->planned($request);

        // ⛔ দেখার পরে অন্য কেউ বদলেছেন — নতুন তালিকা আবার দেখাও, চুপচাপ লেখা নয়
        if ($this->board->stamp($plan['changes']) !== $request->input('stamp')) {
            return $this->preview($request)->with('stale', true);
        }

        $count = $this->board->writeCompanies($plan['changes']);

        return redirect()->route('system_admin.module.companies', ['pick' => array_keys($names)])
            ->with('saved', __('system_admin::module_board.saved', ['count' => $count]));
    }

    /** @return array{0: array<int, string>, 1: array<string, mixed>} */
    private function planned(Request $request): array
    {
        $data = $request->validate([
            'pick' => ['required', 'array', 'min:1'], 'pick.*' => ['integer'],
            'scope' => ['array'], 'scope.*' => ['string', 'max:64'], 'modules' => ['array'], 'was' => ['array'], 'all_on' => ['array'],
        ]);
        $mine = $this->board->companiesFor($request->user());
        $names = [];
        foreach ($data['pick'] as $id) {
            $company = $mine->firstWhere('id', (int) $id);
            abort_if($company === null, 404);
            $names[(int) $id] = $company->name();
        }
        $wanted = $this->board->wanted($data['scope'] ?? [], $data['modules'] ?? [], $data['was'] ?? [], $data['all_on'] ?? []);

        return [$names, $this->board->plan($names, $this->board->companyOff(array_keys($names)), $wanted)];
    }

    /** নামানো ফাইলে কোম্পানি × মডিউল, প্রতিটার অবস্থা — পর্দার দুই কলাম নয় */
    private function offerTheMatrix(LengthAwarePaginator $rows, array $off): void
    {
        if (! $this->export->wanted()) {
            return;
        }

        $columns = [['key' => 'name', 'label' => __('core.table.name'), 'render' => null]];
        foreach ($this->board->groups() as $group) {
            foreach ($group['codes'] as $code) {
                $columns[] = ['key' => $code, 'label' => $this->board->label($code), 'render' => null];
            }
        }

        $this->export->describe(['title' => __('system_admin::module_board.companies')]);
        $this->export->capture($columns, $rows, fn (Company $c, array $col) => $col['key'] === 'name' ? $c->name()
            : __(! $this->board->locked($col['key']) && in_array($col['key'], $off[$c->id], true) ? 'system_admin::module_board.off' : 'system_admin::module_board.on'));
    }
}
