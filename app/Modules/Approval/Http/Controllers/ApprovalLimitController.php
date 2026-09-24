<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\ApprovalLimit;
use App\Models\Branch;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * কোন রোল কত টাকা পর্যন্ত সই দিতে পারে — ধাপ ৪।
 *
 * ── ⚠️ কেন চাবিটা `approval.flow.manage` ────────────────────────────
 * ⓘ সীমা বসানো আর প্রবাহ বসানো একই ক্ষমতা: দুইটাই বলে *"কে কী অনুমোদন
 * করতে পারবে"*। ⛔ আলাদা চাবি দিলে এমন কেউ সীমা তুলে দিতে পারতেন যিনি
 * প্রবাহ ছুঁতেই পারেন না — অর্থাৎ পিছনের দরজা দিয়ে একই কাজ।
 *
 * ── ⓘ কেন এটা `settings`-এ, ইনবক্সে নয় ──────────────────────────────
 * সীমাটা দিনের কাজ নয়, প্রতিষ্ঠানের গঠন — বছরে দুইবার বদলায়।
 */
final class ApprovalLimitController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ApprovalFlowService $flows,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:approval.flow.manage')];
    }

    public function index(Request $request): View
    {
        return view('approval::limit.index', [
            'menu' => $this->menu->forUser($request->user()),

            /*
             * ⓘ নির্দিষ্ট সারি আগে — পর্দাটা ঠিক সেই ক্রমেই দেখায় যে
             * ক্রমে [[AuthorityService::limitFor()]] সারিগুলো বাছে।
             *
             * ⛔ অন্য ক্রমে দেখালে মালিক উপরের সারিটা পড়ে ধরে নিতেন ওটাই
             * খাটছে, অথচ নিচের একটা বেশি নির্দিষ্ট সারি ওটাকে ছাপিয়ে
             * যেত — আর পর্দাটা তখন সত্যি বলেও ভুল বোঝাত।
             */
            'limits' => ApprovalLimit::query()
                ->with(['role', 'branch'])
                ->get()
                ->sortByDesc(fn (ApprovalLimit $l) => $l->weight())
                ->values(),

            'roles' => Role::query()->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name_en')->get(),
            'choices' => $this->flows->choices(),
            'labels' => $this->flows->labels(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],

            /*
             * ⓘ খালি মানে *"সব মডিউলে"* — একটা আসল অর্থ, অনুপস্থিতি নয়।
             * ⚠️ তাই `nullable`, আর নিচে ফাঁকা লেখাকে `null` করা হয়:
             * HTML খালি `<option>` ফাঁকা লেখা পাঠায়, আর সেটা ডাটাবেজে
             * বসলে `fits()` কোনোদিন মিলত না।
             */
            'module' => ['nullable', 'string', 'max:64'],
            'action' => ['nullable', 'string', 'max:129'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],

            /*
             * ⛔ খালি সীমা মানে *"সীমা নেই"* — শূন্য নয়।
             *
             * ⚠️ শূন্য বসলে ঐ রোল **কোনো অঙ্কেই** সই দিতে পারত না, আর
             * সেটা দেখতে ঠিক "সীমা নেই"-এর মতোই লাগত।
             */
            'max_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->assertPossible($data['module'] ?? null, $data['action'] ?? null);

        ApprovalLimit::create([
            'role_id' => $data['role_id'],
            'module' => ($data['module'] ?? '') !== '' ? $data['module'] : null,
            'action' => ($data['action'] ?? '') !== '' ? $data['action'] : null,
            'branch_id' => $data['branch_id'] ?? null,
            'max_amount' => ($data['max_amount'] ?? '') !== '' ? $data['max_amount'] : null,
        ]);

        return redirect()
            ->route('approval.limit.index')
            ->with('saved', __('approval::message.limit_saved'));
    }

    /**
     * ⛔ মডিউল আর কাজ একে অপরকে মানে কি না।
     *
     * ── ⚠️ দুইটা ঘরই আলাদাভাবে বৈধ ────────────────────────
     * ⓘ মডিউল `sales`, কাজ `purchase.payment` — দুইটাই তালিকায়
     * আছে, তাই সারিটা সংরক্ষিত হয়ে যেত।
     *
     * ⛔ কিন্তু [[ApprovalLimit::fits()]] দুইটা মেলায়, তাই ওই সারি
     * **কোনোদিন কোনো কাগজে খাটত না**। ⚠️ আর ফলটা উদার দিকে:
     * মালিক ভাবতেন সীমা বসানো আছে, অথচ ঐ রোল যেকোনো অঙ্কে
     * সই দিতে পারত — আর সেটা পর্দায় দেখে বোঝার কোনো উপায় নেই।
     *
     * ⓘ একই আকারের ভুল প্রবাহের ফর্মে আগেই ধরা হয়েছে: সেখানে
     * ধরন আর ব্যক্তি **একটাই ঘরে** রাখা হয়েছে।
     */
    private function assertPossible(?string $module, ?string $action): void
    {
        if ($module === null || $action === null || $module === '' || $action === '') {
            return;   // ⓘ যেকোনো একটা খালি মানে *"সব"* — অসম্ভব কিছু নেই
        }

        if (! str_starts_with($action, $module.'.')) {
            throw ValidationException::withMessages([
                'action' => __('approval::validation.limit_action_not_in_module'),
            ]);
        }
    }

    public function destroy(ApprovalLimit $limit): RedirectResponse
    {
        /*
         * ⓘ সীমা মোছা নিরাপদ দিকে নিয়ে যায় না, **উদার** দিকে: সারিটা
         * গেলে ঐ রোলের আর কোনো সীমা থাকে না।
         *
         * ⚠️ তাই মোছাটা `IsAudited`-এ লেখা থাকে, আর কে কবে তুলে দিল
         * সেটা পরে জানা যায়।
         */
        $limit->delete();

        return redirect()
            ->route('approval.limit.index')
            ->with('saved', __('approval::message.limit_removed'));
    }
}
