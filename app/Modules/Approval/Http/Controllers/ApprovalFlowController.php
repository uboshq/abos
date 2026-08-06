<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\User;
use App\Modules\Approval\Http\Requests\ApprovalFlowRequest;
use App\Modules\Approval\Services\ApprovalFlowService;
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
            'role' => Role::query()->pluck('name', 'id')->all(),
            'user' => User::query()->pluck('name', 'id')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'choices' => $this->flows->choices(),
            'roles' => Role::query()->orderBy('name')->get(),

            // ব্যক্তি ধরে ছক বসানো যায়, কিন্তু রোল ধরে বসানোই টেকে:
            // মানুষ চাকরি ছাড়েন, রোল থেকে যায়
            'users' => User::query()->orderBy('name')->get(),
        ];
    }
}
