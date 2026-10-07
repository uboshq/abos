<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Support\DocumentPlan;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ডকুমেন্ট ম্যানেজমেন্টের পাতাগুলো — পরিকল্পনা দেখায়, কিছু করে না।
 *
 * ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"ei plan soho live e ekta module
 * baniye rakho but code pore korbo"*। ⓘ প্রতিটা মেনুর সারি এখানে আসে, আর
 * পাতাটা সৎভাবে বলে পর্দাটা এখনো তৈরি হয়নি, কী করবে, আর ABOS-এর কোন
 * ব্যবস্থার উপর দাঁড়াবে।
 *
 * ⛔ কোনো কোয়েরি নেই, কোনো লেখা নেই — চাবি `documents.view` রুটেই
 * (`can:` মিডলওয়্যার, [[web.php]])। ⚠️ পর্দা তৈরির দিন তার সারি নিজের রুটে
 * সরবে আর নামটা [[DocumentPlan::SCREENS]] থেকে মুছবে।
 */
final class PlanController extends Controller
{
    public function __construct(private readonly MenuBuilder $menu) {}

    /** গোটা পরিকল্পনা — ২৫টা অংশ, প্রতিটার অবস্থা। */
    public function dashboard(Request $request): View
    {
        return view('documents::dashboard', [
            'menu' => $this->menu->forUser($request->user()),
            'sections' => DocumentPlan::SECTIONS,
            'screens' => DocumentPlan::SCREENS,
            'systems' => DocumentPlan::SYSTEMS,
        ]);
    }

    /** একটা পর্দার পরিকল্পনা। */
    public function show(Request $request, string $screen): View
    {
        /* ⓘ রুটের `whereIn` আগেই ছেঁকেছে; এটা দ্বিতীয় তালা, যাতে রুট বদলালেও
           অচেনা নাম একটা ফাঁকা পাতা না খোলে */
        abort_unless(isset(DocumentPlan::SCREENS[$screen]), 404);

        return view('documents::screen', [
            'menu' => $this->menu->forUser($request->user()),
            'screen' => $screen,
            'plan' => DocumentPlan::SCREENS[$screen],
            'sections' => DocumentPlan::SECTIONS,
            'systems' => DocumentPlan::SYSTEMS,
        ]);
    }
}
