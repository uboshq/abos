<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Notifications\NotificationVariables;
use App\Core\Notifications\TemplateStudio;
use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Core\Services\NotificationService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ টেমপ্লেট স্টুডিও — বাংলা ও ইংরেজি লেখা, অনুমোদিত চলক, পূর্বরূপ, পরীক্ষার পাঠানো, সংস্করণ, প্রকাশ, ফেরা
 * (মালিকের স্পেক §৪ "Templates", §৯গ; ধাপ ৩)।
 *
 * ⓘ লেখা আর প্রকাশ আলাদা চাবিতে: `notification.templates` লেখে (প্রতিটা সংরক্ষণ নতুন খসড়া), `notification.templates.publish`
 * প্রকাশ করে বা আগের সংস্করণে ফেরায়। কাজ [[TemplateStudio]]-এ; দুইটাই নিরীক্ষায়।
 */
class NotificationTemplateController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly TemplateStudio $studio,
    ) {}

    public function index(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        $rows = NotificationTemplate::query()
            ->with('published')
            ->withCount('versions')
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($s, '%_\\').'%')
                ->orWhere('code', 'like', '%'.addcslashes($s, '%_\\').'%')))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('notification::templates.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'filters' => $f,
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new NotificationTemplate(['category' => 'task', 'is_active' => true]), null);
    }

    public function store(Request $request): RedirectResponse
    {
        $meta = $this->meta($request);
        $text = $this->text($request);

        $template = DB::transaction(function () use ($meta, $text, $request): NotificationTemplate {
            $template = NotificationTemplate::query()->create($meta);
            $this->studio->draft($template, $text, $request->input('note'));

            return $template;
        });

        app(NotificationAudit::class)->record('template_save', $template, 'done', ['template_id' => $template->id, 'version' => 1]);

        return redirect()->route('notification.templates.edit', $template)->with('saved', __('notification::template.saved'));
    }

    public function edit(Request $request, NotificationTemplate $template): View
    {
        $version = $request->filled('version')
            ? $template->versions()->where('version', (int) $request->query('version'))->firstOrFail()
            : $template->latest();

        return $this->form($request, $template, $version);
    }

    public function update(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $meta = $this->meta($request, $template);
        $text = $this->text($request);

        $version = DB::transaction(function () use ($template, $meta, $text, $request): NotificationTemplateVersion {
            $template->fill($meta)->save();

            return $this->studio->draft($template, $text, $request->input('note'));
        });

        app(NotificationAudit::class)->record('template_save', $template, 'done', ['template_id' => $template->id, 'version' => (int) $version->version]);

        return redirect()->route('notification.templates.edit', $template)->with('saved', __('notification::template.saved'));
    }

    /** ⭐ প্রকাশ — নতুন বা পুরনো সংস্করণ (পুরনোটা প্রকাশ মানেই ফেরা) */
    public function publish(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $data = $request->validate(['version_id' => ['required', 'integer']]);
        $version = NotificationTemplateVersion::query()->where('template_id', $template->id)->findOrFail((int) $data['version_id']);

        // ⭐ লেখক নিজের লেখা প্রকাশ করেন না — দ্বিতীয় একজন দেখে প্রকাশ করেন (স্পেক §৯গ "Approval"); সুইচ দিয়ে বন্ধ করা যায়
        if ($this->needsSecondPerson() && (int) $version->created_by === (int) $request->user()->id) {
            app(NotificationAudit::class)->record('template_publish', $template, 'denied', ['template_id' => $template->id, 'version' => (int) $version->version]);

            return back()->with('failed', __('notification::template.own_version'));
        }

        $this->studio->publish($template, $version);

        return redirect()->route('notification.templates.edit', $template)
            ->with('saved', __('notification::template.published_flash', ['version' => $version->version]));
    }

    /** ⭐ পরীক্ষার পাঠানো — কেবল নিজের কাছে, নমুনা মান দিয়ে, পুরো পথ ধরে (ঘণ্টা, আর মাধ্যম চালু থাকলে চিঠি) */
    public function test(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $data = $request->validate(['version_id' => ['required', 'integer']]);
        $version = NotificationTemplateVersion::query()->where('template_id', $template->id)->findOrFail((int) $data['version_id']);

        app(NotificationService::class)->send(
            $request->user(), 'notification.template_test',
            (string) $version->part('title', app()->getLocale()), $version->part('body', app()->getLocale()),
            route('notification.templates.edit', $template),
            evenToSelf: true, data: NotificationVariables::samples(), template: $version,
        );

        app(NotificationAudit::class)->record('template_test', $template, 'done', ['template_id' => $template->id, 'version' => (int) $version->version]);

        return back()->with('saved', __('notification::template.test_sent'));
    }

    private function needsSecondPerson(): bool
    {
        return (bool) app(SettingsService::class)->get('notification.templates_four_eyes', true);
    }

    private function form(Request $request, NotificationTemplate $template, ?NotificationTemplateVersion $version): View
    {
        return view('notification::templates.form', [
            'menu' => $this->menu->forUser($request->user()),
            'template' => $template,
            'version' => $version,
            'versions' => $template->exists ? $template->versions()->with('author')->limit(30)->get() : collect(),
            'previews' => $version === null ? [] : ['bn' => $this->studio->preview($version, 'bn'), 'en' => $this->studio->preview($version, 'en')],
            'variables' => NotificationVariables::ALL,
            'canPublish' => $request->user()->can('notification.templates.publish')
                && ! ($version !== null && $this->needsSecondPerson() && (int) $version->created_by === (int) $request->user()->id),
            'ownVersion' => $version !== null && $this->needsSecondPerson() && (int) $version->created_by === (int) $request->user()->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function meta(Request $request, ?NotificationTemplate $template = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_\.\-]+$/',
                Rule::unique('notification_templates', 'code')->where('company_id', CompanyContext::id())->ignore($template?->id)],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(NotificationKinds::CATEGORIES)],
            'channels' => ['nullable', 'array'], 'channels.*' => [Rule::in(NotificationChannel::ALL)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'category' => $data['category'],
            'channels' => array_values(array_unique((array) ($data['channels'] ?? []))) ?: null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /** @return array<string, ?string> */
    private function text(Request $request): array
    {
        return $request->validate([
            'subject_bn' => ['nullable', 'string', 'max:191'],
            'subject_en' => ['nullable', 'string', 'max:191'],
            'title_bn' => ['required', 'string', 'max:191'],
            'title_en' => ['required', 'string', 'max:191'],
            'body_bn' => ['nullable', 'string', 'max:1000'],
            'body_en' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);
    }
}
