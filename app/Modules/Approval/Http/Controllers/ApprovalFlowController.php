<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\User;
use App\Modules\Approval\Http\Requests\ApprovalFlowRequest;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * অনুমোদনের ছক সাজানো — সেটিংসে, এক জায়গায়।
 *
 * প্রতিটা মডিউলের পর্দায় নিজের নিজের "অনুমোদন লাগবে?" সুইচ বসালে
 * মালিককে সাতটা পর্দা ঘুরে দেখতে হত কোথায় কী বসানো আছে — আর একটা
 * ভুলে গেলে সেখানে অনুমোদন ছাড়াই সব চলত।
 */
class ApprovalFlowController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ApprovalFlowService $flows,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:approval.flow.manage')];
    }

    public function index(Request $request): View
    {
        return view('approval::flow.index', [
            'menu' => $this->menu->forUser($request->user()),
            'flows' => ApprovalFlow::query()->with('steps')->orderBy('module')->orderBy('action')->get(),
            'choices' => $this->flows->choices(),
            'names' => $this->approverNames(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('approval::flow.form', [
            'menu' => $this->menu->forUser($request->user()),
            'flow' => new ApprovalFlow(['is_active' => true]),
            ...$this->formData(),
        ]);
    }

    public function store(ApprovalFlowRequest $request): RedirectResponse
    {
        $this->flows->create($request->validated(), $request->steps());

        return redirect()->route('approval.flow.index')->with('saved', __('approval::message.flow_saved'));
    }

    public function edit(Request $request, int $flow): View
    {
        return view('approval::flow.form', [
            'menu' => $this->menu->forUser($request->user()),
            'flow' => ApprovalFlow::query()->with('steps')->findOrFail($flow),
            ...$this->formData(),
        ]);
    }

    public function update(ApprovalFlowRequest $request, int $flow): RedirectResponse
    {
        $this->flows->update(
            ApprovalFlow::query()->findOrFail($flow),
            $request->validated(),
            $request->steps(),
        );

        return redirect()->route('approval.flow.index')->with('saved', __('approval::message.flow_saved'));
    }

    public function destroy(int $flow): RedirectResponse
    {
        $this->flows->delete(ApprovalFlow::query()->findOrFail($flow));

        return redirect()->route('approval.flow.index')->with('saved', __('approval::message.flow_deleted'));
    }

    /**
     * অনুমোদনকারীদের নাম — ধরন ধরে।
     *
     * ছকের তালিকায় শুধু id দেখালে মালিককে মনে রাখতে হত কোন নম্বরটা কে,
     * আর তখন ছকটা পড়াই যেত না।
     *
     * @return array<string, array<int, string>>
     */
    private function approverNames(): array
    {
        return [
            /*
             * রোলে কোম্পানির ছাঁকনি নেই, আর সেটা ঠিক — মেপে দেখা হয়েছে।
             *
             * `roles` টেবিলে `company_id` নেই আর Spatie-র teams বন্ধ,
             * অর্থাৎ রোলগুলো গোটা ইনস্টলেশনের, কোম্পানিভিত্তিক নয়।
             * ⓘ পাশাপাশি দুইটা লাইনের একটায় ছাঁকনি আছে আর অন্যটায় নেই —
             * সেটা ধরে নেওয়া যায় না, তাই লিখে রাখা।
             */
            'role' => Role::query()->pluck('name', 'id')->all(),

            'user' => $this->companyUsers()->pluck('name', 'id')->all(),
        ];
    }

    /**
     * এই কোম্পানির ব্যবহারকারীরাই — বহু-টেন্যান্টে এটা সুবিধা নয়, শর্ত।
     *
     * ⚠️ ── কী ভাঙা ছিল ──────────────────────────────────────────────
     * এখানে ছিল সরল `User::query()`, আর `User`-এ কোনো global scope নেই
     * (একজন মানুষ একাধিক কোম্পানিতে থাকতে পারেন, তাই ওটা pivot-এ)।
     * ফল: ছকের ফর্মে **অন্য কোম্পানির মানুষের নাম** দেখা যেত।
     *
     * ⛔ আর ক্ষতিটা নাম দেখার চেয়ে বড়: ওই তালিকা থেকে অন্য কোম্পানির
     * কাউকে **অনুমোদনের ছকে বসিয়েও দেওয়া যেত**, আর তখন তাঁর কাছে এই
     * কোম্পানির কাগজ সইয়ের জন্য যেত।
     *
     * ⓘ ছাঁচটা `CashTillController::holderOptions()`-এর — নতুন কিছু
     * বানানো হয়নি, কারণ দুই রকম করলে তৃতীয় জায়গায় তৃতীয় রকম হয়।
     *
     * @return Builder<User>
     */
    private function companyUsers(): Builder
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->orderBy('name');
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'choices' => $this->flows->choices(),
            'roles' => Role::query()->orderBy('name')->get(),

            // ব্যক্তি ধরে ছক বসানো যায়, কিন্তু রোল ধরে বসানোই টেকে:
            // মানুষ চাকরি ছাড়েন, রোল থেকে যায়
            //
            // ⚠️ এই কোম্পানির মানুষই — কারণটা [[companyUsers]]-এ
            'users' => $this->companyUsers()->get(),
        ];
    }
}
