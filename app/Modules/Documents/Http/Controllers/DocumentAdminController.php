<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\AbeRule;
use App\Modules\Documents\Models\DocumentCategory;
use App\Modules\Documents\Models\DocumentTag;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\MetadataField;
use App\Modules\Documents\Services\DocumentAdministration;
use App\Modules\Documents\Services\DocumentChoices;
use App\Modules\Documents\Services\DocumentFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ডকুমেন্ট প্রশাসন — ধরন, ফোল্ডার, ট্যাগ, বাড়তি ঘর, আর বাকি ব্যবস্থার দরজা
 * (§২০; দ্বিতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ চাবি `documents.admin` রুটে। ⭐ নম্বর সিরিজ, ফাইলের সীমা আর অনুমোদনের ধারা নতুন করে
 * বানানো নয় — পাতা থেকে ABOS-এর নিজের পর্দায় লিংক ([[NumberSeriesEngine]], [[SettingsService]])।
 */
final class DocumentAdminController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DocumentAdministration $admin,
        private readonly DocumentChoices $choices,
    ) {}

    /**
     * প্রশাসনের পাতা — নিয়ন্ত্রণ প্যানেলের মতো একটা সেটিংসের পাতা, লেনদেনের তালিকা নয়।
     *
     * ⓘ চারটা তালিকাই কোম্পানির নিজের যোগ করা নাম (ধরন, ফোল্ডার, ট্যাগ, ঘর) — কয়েক ডজন, কাগজের
     * মতো রোজ বাড়ে না; তাই পাতা ভাগ নেই। ⚠️ কোনোদিন শতের ঘরে গেলে আলাদা পর্দায় ভাগ করতে হবে।
     */
    public function show(Request $request): View
    {
        return view('documents::admin', [
            'menu' => $this->menu->forUser($request->user()),
            'types' => DocumentType::query()->orderBy('name_en')->get(),
            'categories' => DocumentCategory::query()->orderBy('name_en')->get(),
            'tags' => DocumentTag::query()->orderBy('name')->get(),
            'fields' => MetadataField::query()->orderBy('name_en')->get(),
            'abeRules' => AbeRule::query()->orderBy('kind')->orderBy('doc_type')->orderBy('id')->get(),
            'allTypes' => $this->choices->types(true),
            'storage' => DocumentFiles::limits(),
        ]);
    }

    /** নতুন ধরন, ফোল্ডার, ট্যাগ বা বাড়তি ঘর — `kind` বলে কোনটা */
    public function store(Request $request, string $kind): RedirectResponse
    {
        $this->admin->add($kind, $request->all());

        return redirect()->to(route('documents.admin').'#'.$kind)->with('saved', __('documents::message.admin_saved'));
    }

    /** চালু/বন্ধ — ধরন, ফোল্ডার, বাড়তি ঘর; ট্যাগ মোছে */
    public function toggle(string $kind, int $id): RedirectResponse
    {
        $this->admin->toggle($kind, $id);

        return redirect()->to(route('documents.admin').'#'.$kind)->with('saved', __('documents::message.admin_saved'));
    }
}
