<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NoticeLifecycle;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Http\Controllers\Controller;
use App\Models\NoticeTemplate;
use App\Http\Controllers\Controller as Base;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * নোটিশের ছাঁচ — বারবার লেখা কথাগুলো একবার লিখে রাখা।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ২০ ────────────────────
 * ছুটি · অফিস বন্ধ · সার্ভার রক্ষণাবেক্ষণ · নিরাপত্তা সতর্কতা · জরুরি।
 *
 * ── ⚠️ কেন ছাঁচে অগ্রাধিকারও থাকে ───────────────────────────────────
 * ⓘ কেবল লেখাটা রাখলে অর্ধেক কাজ হত। ⛔ *"সার্ভার রক্ষণাবেক্ষণ"* নোটিশে
 * প্রতিবার হাতে `CRITICAL` বাছতে হলে কোনো একদিন কেউ ভুলতেন, আর ঐ
 * নোটিশটা বারেই যেত না — অথচ ঠিক ওটাই বারে সবচেয়ে বেশি দরকার।
 */
final class NoticeTemplateController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.notice.manage')];
    }

    public function index(Request $request): View
    {
        return view('system_admin::notice.templates', [
            'menu' => $this->menu->forUser($request->user()),
            'templates' => NoticeTemplate::query()->orderBy('code')->paginate(50)->withQueryString(),
            'priorities' => collect(NoticePriority::cases())
                ->mapWithKeys(fn (NoticePriority $p) => [$p->value => $p->label()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            /*
             * ⓘ কোডটা কোম্পানিপ্রতি একবারই।
             *
             * ⚠️ `unique` নিয়মে কোম্পানি না বসালে দুইটা কোম্পানির একই
             * কোড হলে দ্বিতীয়টা আটকে যেত — ⛔ আর ভুলটা দেখা যেত কেবল
             * দ্বিতীয় কোম্পানির প্রথম দিনে।
             */
            'code' => ['required', 'string', 'max:32',
                Rule::unique('notice_templates', 'code')->where('company_id', $companyId)],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:4000'],
            'priority' => ['nullable', 'string', Rule::in(array_column(NoticePriority::cases(), 'value'))],
        ]);

        NoticeTemplate::query()->create($data + ['company_id' => $companyId, 'created_by' => $request->user()?->id]);

        return back()->with('saved', __('core.notice.template_saved'));
    }

    /**
     * ⛔ ছাঁচ মোছা যায়, নোটিশ যায় না — আর তফাতটা ইচ্ছাকৃত।
     *
     * ⓘ ছাঁচ কেউ পড়েনি; ওটা একটা সুবিধা, ঘটনা নয়। ⚠️ নোটিশের বেলায়
     * নিয়মটা উল্টো ([[Notice]]-এর `deleting` পাহারা)।
     */
    /**
     * ⭐ ছাঁচ থেকে একটা খসড়া বানানো।
     *
     * ── ⚠️ কেন খসড়া, সরাসরি প্রকাশ নয় ────────────────────
     * ⓘ ছাঁচে লেখা থাকে *"অফিস বন্ধ থাকবে"*, কিন্তু **কবে**
     * সেটা থাকে না। ⛔ সরাসরি প্রকাশ করলে অর্ধেক লেখা একটা নোটিশ
     * গোটা অফিসের চোখের সামনে চলে যেত।
     *
     * ⭐ তাই মানুষ সম্পাদনার পর্দায় গিয়ে শেষ করেন।
     */
    public function use(Request $request, int $template): RedirectResponse
    {
        $notice = app(NoticeLifecycle::class)
            ->fromTemplate(NoticeTemplate::query()->findOrFail($template));

        return redirect()
            ->route('system_admin.notice.edit', $notice->id)
            ->with('saved', __('core.notice.started_from_template'));
    }

    public function destroy(Request $request, int $template): RedirectResponse
    {
        NoticeTemplate::query()->findOrFail($template)->delete();

        return back()->with('saved', __('core.notice.template_removed'));
    }
}
