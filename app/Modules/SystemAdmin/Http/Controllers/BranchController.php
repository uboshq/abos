<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Modules\SystemAdmin\Services\BranchDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * শাখার নিজের পাতা — "সিস্টেম প্রশাসন → শাখা" (মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⭐ চাবি নতুন নয় ───────────────────────────────────────────────────
 * ⓘ `system_admin.company.manage` — কোম্পানির পাতার একই চাবি, কারণ শাখা খোলা
 * এতদিন ঐ পাতাতেই হত। ⚠️ নতুন চাবি হলে লাইভের চলতি ভূমিকায় হাতে বসাতে হত
 * (ছাঁচ পুরনো ভূমিকা চওড়া করে না), আর সেই দিন পর্যন্ত মালিক নিজেই মেনুটা
 * দেখতেন না।
 *
 * ── ⛔ কেবল **আপনার** কোম্পানিগুলোর শাখা ────────────────────────────────
 * ⓘ বহু-কোম্পানির ইনস্টলে প্রতিটা কোম্পানির নিজের super_admin এই চাবি ধরে
 * রাখেন ([[CompanyController::index()]]-এর ২১ সেপ্টেম্বরের শিক্ষা)। তাই তালিকা
 * আর প্রতিটা দরজা [[User::canAccessCompany()]] দিয়ে বাঁধা; অন্য কোম্পানির
 * শাখা ৪০৪।
 */
final class BranchController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly BranchDesk $desk,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.company.manage')];
    }

    public function index(Request $request): View
    {
        $mine = $this->myCompanyIds($request);
        $companyId = (int) $request->query('company');
        $q = trim((string) $request->query('q'));

        $rows = Branch::query()
            /* ⓘ সব কোম্পানির শাখা একসাথে — তাই গ্লোবাল স্কোপ সরিয়ে, নিজের কোম্পানিগুলো দিয়ে বাঁধা */
            ->withoutGlobalScopes()
            ->whereNull('deleted_at') // ⚠️ withoutGlobalScopes() soft-delete-এর ছাঁকনিও সরায়
            ->with(['company' => fn ($c) => $c->withoutGlobalScopes()])
            ->whereIn('company_id', $mine)
            ->when($companyId > 0, fn ($b) => $b->where('company_id', $companyId))
            ->when($q !== '', function ($b) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $b->where(fn ($w) => $w->where('code', 'like', $like)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('name_bn', 'like', $like));
            })
            ->orderBy('company_id')
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return view('system_admin::branch.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'companies' => $this->myCompanies($request),
        ]);
    }

    public function create(Request $request): View
    {
        return view('system_admin::branch.form', [
            'menu' => $this->menu->forUser($request->user()),
            'branch' => new Branch,
            'companies' => $this->myCompanies($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::in($this->myCompanyIds($request))],
            ...BranchDesk::RULES,
        ]);

        $company = Company::query()->findOrFail((int) $data['company_id']);
        unset($data['company_id']);

        $this->desk->open($company, $data);

        return redirect()->route('system_admin.branch.index', ['company' => $company->id])
            ->with('saved', __('system_admin::message.branch_created'));
    }

    public function edit(Request $request, int $branch): View
    {
        return view('system_admin::branch.form', [
            'menu' => $this->menu->forUser($request->user()),
            'branch' => $this->yourBranch($request, $branch),
            'companies' => $this->myCompanies($request),
        ]);
    }

    public function update(Request $request, int $branch): RedirectResponse
    {
        $row = $this->yourBranch($request, $branch);
        $data = $request->validate(BranchDesk::RULES);

        $this->desk->update($row, $data);

        return redirect()->route('system_admin.branch.index', ['company' => $row->company_id])
            ->with('saved', __('system_admin::message.branch_updated'));
    }

    public function toggle(Request $request, int $branch): RedirectResponse
    {
        $row = $this->desk->toggle($this->yourBranch($request, $branch));

        return back()->with('saved', $row->is_active
            ? __('system_admin::message.branch_enabled')
            : __('system_admin::message.branch_disabled'));
    }

    /** ⭐ মুছে ফেলা — নিয়ম [[BranchDesk::remove()]]-এ; ব্যবহৃত শাখা হলে "নিষ্ক্রিয় করুন" বার্তা */
    public function destroy(Request $request, int $branch): RedirectResponse
    {
        $this->desk->remove($this->yourBranch($request, $branch));

        return back()->with('saved', __('system_admin::message.branch_deleted'));
    }

    /**
     * ⛔ শাখাটা আপনার কোনো কোম্পানির — নাহলে ৪০৪, ৪০৩ নয়।
     *
     * ⓘ ৪০৩ বললে অন্যের শাখার অস্তিত্ব ফাঁস হত; ৪০৪ কিছুই বলে না।
     */
    private function yourBranch(Request $request, int $id): Branch
    {
        $branch = Branch::query()->withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($id);

        abort_unless($request->user()?->canAccessCompany((int) $branch->company_id), 404);

        return $branch;
    }

    /** @return list<int> */
    private function myCompanyIds(Request $request): array
    {
        return $request->user()?->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    private function myCompanies(Request $request)
    {
        return Company::query()->whereIn('id', $this->myCompanyIds($request))->orderBy('name_en')->get();
    }
}
