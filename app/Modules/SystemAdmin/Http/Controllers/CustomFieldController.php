<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\CustomFieldService;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\CustomField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * নিজস্ব ঘর সাজানো — System Management-এ, এক জায়গায়।
 *
 * ── কেন এখানে, প্রতিটা মডিউলের সেটিংসে নয় ───────────────────────────
 * "গ্রাহকের ঘর গ্রাহকের পর্দায়, পণ্যের ঘর পণ্যের পর্দায়" — শুনতে
 * যুক্তিসঙ্গত, কিন্তু তাতে মালিককে সাতটা পর্দা ঘুরে দেখতে হত তিনি কী
 * কী ঘর বানিয়েছেন, আর একটা ভুলে গেলে সেটা কোথায় আছে তা খুঁজতে হত।
 * সেটিংস এক জায়গায় থাকে (নিয়ম: এক পর্দা, এক মালিক)।
 */
class CustomFieldController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CustomFieldService $fields,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function index(Request $request): View
    {
        return view('system_admin::custom_fields.index', [
            'menu' => $this->menu->forUser($request->user()),
            'entities' => $this->fields->entities(),
            'fields' => CustomField::query()
                ->orderBy('entity')
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->groupBy('entity'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $entities = array_keys($this->fields->entities());

        $validated = $request->validate([
            'entity' => ['required', 'string', Rule::in($entities)],

            /*
             * চাবির নিয়ম কড়া, আর ইচ্ছাকৃতভাবে।
             *
             * চাবিটা ঠিকানায় ও ফর্মের ঘরের নামে যায়, তাই ফাঁকা জায়গা
             * বা বাংলা অক্ষর থাকলে ব্রাউজারভেদে আলাদা আচরণ করত। লেবেলটা
             * যেকোনো ভাষায় হতে পারে — মানুষ ওটাই দেখে।
             */
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label_en' => ['required', 'string', 'max:120'],
            'label_bn' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(CustomFieldService::TYPES)],
            'options' => ['nullable', 'string', 'max:1000'],
            'is_required' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $options = $validated['type'] === 'select'
            ? $this->splitOptions($validated['options'] ?? '')
            : null;

        if ($validated['type'] === 'select' && $options === []) {
            return back()->withInput()->withErrors([
                'options' => __('core.custom_field.select_needs_options'),
            ]);
        }

        CustomField::create([
            'entity' => $validated['entity'],
            'key' => $validated['key'],
            'label_en' => $validated['label_en'],
            'label_bn' => $validated['label_bn'],
            'type' => $validated['type'],
            'options' => $options,
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'sort' => (int) ($validated['sort'] ?? 0),
            'is_active' => true,
        ]);

        return back()->with('saved', __('core.custom_field.created'));
    }

    /**
     * লেবেল, ক্রম, বাধ্যতামূলক কি না — এগুলো বদলায়। চাবি ও ধরন নয়।
     *
     * ── কেন ধরনও নয় ─────────────────────────────────────────────────
     * লেখার ঘরকে তারিখ বানালে আগের মানগুলো হঠাৎ অবৈধ হয়ে যেত, অথচ
     * ওগুলো কেউ ভুল করে লেখেনি — নিয়মটাই বদলেছে। ধরন বদলাতে হলে নতুন
     * ঘর বানিয়ে পুরনোটা নিষ্ক্রিয় করাই সৎ পথ।
     */
    public function update(Request $request, CustomField $field): RedirectResponse
    {
        $validated = $request->validate([
            'label_en' => ['required', 'string', 'max:120'],
            'label_bn' => ['required', 'string', 'max:120'],
            'options' => ['nullable', 'string', 'max:1000'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $field->update([
            'label_en' => $validated['label_en'],
            'label_bn' => $validated['label_bn'],
            'options' => $field->type === 'select'
                ? $this->splitOptions($validated['options'] ?? '')
                : $field->options,
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort' => (int) ($validated['sort'] ?? 0),
        ]);

        return back()->with('saved', __('core.custom_field.updated'));
    }

    /**
     * ঘর মোছা হয় না, নিষ্ক্রিয় হয়।
     *
     * মুছে ফেললে ওই ঘরে লেখা প্রতিটা রেকর্ডের তথ্যও চলে যেত — আর সেটা
     * এমন তথ্য যা কোম্পানি নিজে যোগ করেছিল বলেই দরকারি ছিল। নিষ্ক্রিয়
     * করলে ফর্মে আর দেখা যায় না, কিন্তু পুরনো মান থেকে যায়, আর ঘরটা
     * আবার চালু করলে সব ফিরে আসে।
     */
    public function destroy(CustomField $field): RedirectResponse
    {
        $field->update(['is_active' => false]);

        return back()->with('saved', __('core.custom_field.deactivated'));
    }

    /**
     * প্রতি লাইনে একটা বিকল্প — কমা নয়।
     *
     * কমা দিলে "ঢাকা, উত্তর" লেখা একটা বিকল্প দুইটা হয়ে যেত।
     *
     * @return list<string>
     */
    private function splitOptions(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: []),
            fn (string $option) => $option !== '',
        ));
    }
}
