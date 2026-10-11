<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ডকুমেন্টের রিপোর্টের দরজা (§১৭; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬) — বাকি মডিউলের মতোই ঠিকানা-নাম থেকে রিপোর্ট।
 *
 * ⓘ পাতা ABOS-এর এক রিপোর্টের পাতা (`accounts::report.show`) — ছাঁকনি, পাতা ভাগ, শাখা-ভাগ, রপ্তানি সব একই।
 * ⛔ প্রতিটা রিপোর্ট নিজে দেয়াল বসায় ([[DocumentReports]]); এখানে কেবল চাবি আর শাখা-ভাগ।
 */
final class DocumentReportController extends Controller
{
    /** @var array<string, array{key: string, permission: string}> */
    public const SLUGS = [
        'register' => ['key' => 'documents.register', 'permission' => 'documents.report'],
        'summary' => ['key' => 'documents.summary', 'permission' => 'documents.report'],
        'by-type' => ['key' => 'documents.by_type', 'permission' => 'documents.report'],
        'by-department' => ['key' => 'documents.by_department', 'permission' => 'documents.report'],
        'by-branch' => ['key' => 'documents.by_branch', 'permission' => 'documents.report'],
        'by-owner' => ['key' => 'documents.by_owner', 'permission' => 'documents.report'],
        'activity' => ['key' => 'documents.activity', 'permission' => 'documents.report'],
        'access-history' => ['key' => 'documents.access_history', 'permission' => 'documents.report'],
        'approvals' => ['key' => 'documents.approvals', 'permission' => 'documents.report'],
        'signatures' => ['key' => 'documents.signatures', 'permission' => 'documents.report'],
        'expiry' => ['key' => 'documents.expiry', 'permission' => 'documents.report'],
        'archive' => ['key' => 'documents.archive', 'permission' => 'documents.report'],
        'versions' => ['key' => 'documents.versions', 'permission' => 'documents.report'],
        'storage' => ['key' => 'documents.storage', 'permission' => 'documents.report'],
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    /** সব রিপোর্টের তালিকা — মেনুর একটা সারি, ভিতরে চৌদ্দটা */
    public function center(Request $request): View
    {
        $this->authorize('documents.report');

        return view('documents::reports', [
            'menu' => $this->menu->forUser($request->user()),
            'slugs' => array_keys(self::SLUGS),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $this->authorize(self::SLUGS[$slug]['permission']);

        $key = self::SLUGS[$slug]['key'];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            $request->only($definition->requestKeys()),
            page: max(1, (int) $request->query('page', 1)),
            byBranch: true,
        );

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => $definition->hasFilter('branch')
                ? Branch::query()->active()->orderBy('name_en')->get()
                : collect(),
            'accounts' => collect(),
            'partyTypes' => collect(),
        ]);
    }
}
