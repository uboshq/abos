<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Http\Controllers\Controller;
use App\Models\NoticeCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * নোটিশের ধরন — আর তার নিজের ডিফল্ট।
 *
 * ── ⚠️ কেন এই পর্দাটা লাগল ──────────────────────────────────────────
 * ⓘ ক্যাটাগরির টেবিল আর মডেল আগেই বসেছিল, কিন্তু **বানানোর কোনো পথ
 * ছিল না**। ⛔ ফলে `notice_category_id` চিরকাল খালি থাকত, আর ক্যাটাগরির
 * ডিফল্ট অগ্রাধিকার কোনোদিন কাজেই লাগত না।
 *
 * ⚠️ ABOS-এ বাগটা প্রায় সবসময় এই আকারের — কাজটা হয়ে আছে, জোড়াটা নেই,
 * আর কোথাও কিছু লাল হয় না।
 */
final class NoticeCategoryController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.notice.manage')];
    }

    public function index(Request $request): View
    {
        return view('system_admin::notice.categories', [
            'menu' => $this->menu->forUser($request->user()),
            'categories' => NoticeCategory::query()->orderBy('code')->paginate(50)->withQueryString(),
            'priorities' => collect(NoticePriority::cases())
                ->mapWithKeys(fn (NoticePriority $p) => [$p->value => $p->label()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            /*
             * ⓘ কোডটা কোম্পানিপ্রতি একবারই — আর `unique` নিয়মে কোম্পানিটা
             * বসানো আছে।
             *
             * ⚠️ না বসালে দুইটা কোম্পানির একই কোড হলে দ্বিতীয়টা আটকে যেত,
             * ⛔ আর ভুলটা দেখা যেত কেবল দ্বিতীয় কোম্পানির প্রথম দিনে।
             */
            'code' => ['required', 'string', 'max:32',
                Rule::unique('notice_categories', 'code')->where('company_id', $companyId)],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'default_priority' => ['nullable', 'string',
                Rule::in(array_column(NoticePriority::cases(), 'value'))],

            /*
             * ⓘ এই ধরনের নোটিশে সই লাগে কি না।
             *
             * ⚠️ অনুমোদনের আসল প্রবাহ [[ApprovalFlow]]-এ; এটা কেবল বলে
             * *"এখানে সই লাগবে"*। ⛔ দুইটা এক করলে প্রবাহের নিয়ম বদলালে
             * ক্যাটাগরিও ছুঁতে হত।
             */
            'needs_approval' => ['nullable', 'boolean'],
        ]);

        NoticeCategory::query()->create([
            ...$data,
            'company_id' => $companyId,
            'needs_approval' => (bool) ($data['needs_approval'] ?? false),
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('saved', __('core.notice.category_saved'));
    }
}
