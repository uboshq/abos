<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Notifications\ChannelRegistry;
use App\Core\Notifications\DeliveryService;
use App\Core\Notifications\NotificationVariables;
use App\Core\Notifications\PreferenceBook;
use App\Core\Notifications\PreferenceWriter;
use App\Core\Notifications\RecipientResolver;
use App\Core\Notifications\RuleEngine;
use App\Core\Notifications\RuleWriter;
use App\Core\Notifications\TemplateStudio;
use App\Core\Services\NotificationAudit;
use App\Core\Services\NotificationService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationJob;
use App\Models\NotificationPreference;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ⭐ বিজ্ঞপ্তির বাকি API — মালিকের স্পেক §১২: খবরের বিস্তারিত, নিজের পছন্দ, নিয়ম, টেমপ্লেট, ডেলিভারি, মাধ্যমের স্বাস্থ্য
 * (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩–৪-এর অনুসরণ)।
 *
 * ── ⛔ নিয়ম ───────────────────────────────────────────────────────────
 *   · প্রতিটা দরজায় পর্দার সমান চাবি (রুটের `can:`); নিজের খবর আর নিজের পছন্দে চাবি লাগে না, অন্যেরটায় ৪০৪।
 *   · যাচাই আর সংরক্ষণ পর্দার সাথে একই পথে ([[RuleWriter]], [[PreferenceWriter]], [[TemplateStudio]]) — ভুলে সাধারণ ৪২২ JSON।
 *   · তালিকা পাতায় ভাগ (`page`, `per_page` ≤ ৫০); হারের সীমা API দলের (`throttle:app`)।
 *   · গোপন কিছু ফেরত যায় না — মাধ্যমের চাবি, ব্রাউজারের চাবি, কারও ঠিকানা নয়।
 */
class NotificationManageApiController extends Controller
{
    private const MAX_PER_PAGE = 50;

    /** ⭐ একটা খবরের বিস্তারিত — নিজের, আর কাগজ এখনো নাগালে থাকলে খোলার ঠিকানা (স্পেক §১২ `details`) */
    public function show(Request $request, string $notification): JsonResponse
    {
        $user = $request->user();
        $row = Notification::query()->wherePublicId($notification)->firstOrFail();
        abort_unless($row->user_id === $user->id, 404);

        $notify = app(NotificationService::class);
        $notify->markSeen($user, [(int) $row->id]);

        return response()->json(['data' => [
            'id' => (string) $row->public_id,
            'type' => (string) $row->type,
            'title' => (string) $row->title,
            'body' => (string) ($row->body ?? ''),
            'priority' => (string) $row->priority,
            'category' => (string) $row->category,
            'module' => (string) $row->module,
            'source' => NotificationKinds::sourceLabel($row->module),
            'read' => ! $row->isUnread(),
            'archived' => $row->isArchived(),
            'on_behalf_of' => $row->on_behalf_of === null ? null : (string) $row->onBehalfOf?->name,
            'at' => $row->created_at?->toIso8601String(),
            // ⓘ কাগজ আর নাগালে না থাকলে ঠিকানা নয় — পর্দার মতোই
            'url' => $notify->mayOpen($row, $user) ? $row->url : null,
        ]]);
    }

    /** ⭐ নিজের পছন্দ (স্পেক §১২ `notification-preferences` GET) */
    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferenceJson(
            app(PreferenceBook::class)->for((int) $request->user()->id, (int) CompanyContext::id()),
        )]);
    }

    /** ⭐ নিজের পছন্দ বদল (স্পেক §১২ `notification-preferences` PUT) */
    public function savePreferences(Request $request): JsonResponse
    {
        $writer = app(PreferenceWriter::class);
        $pref = $writer->save((int) $request->user()->id, $writer->validate((int) $request->user()->id, $request->all()));

        return response()->json(['data' => $this->preferenceJson($pref)]);
    }

    /** ⭐ নিয়মের তালিকা (স্পেক §১২ `notification-rules` list) */
    public function rules(Request $request): JsonResponse
    {
        $page = NotificationRule::query()->orderBy('id')->paginate($this->perPage($request));

        return $this->paged($page, fn (NotificationRule $r) => $this->ruleJson($r));
    }

    /** ⭐ নতুন নিয়ম (স্পেক §১২ `notification-rules` create) */
    public function storeRule(Request $request): JsonResponse
    {
        $writer = app(RuleWriter::class);

        return response()->json(['data' => $this->ruleJson($writer->save($writer->validate($request->all())))], 201);
    }

    /** ⭐ নিয়ম বদল — নতুন সংস্করণ (স্পেক §১২ `notification-rules` update) */
    public function updateRule(Request $request, string $rule): JsonResponse
    {
        // ⓘ রুটের বাঁধন নয় — কোম্পানি ঠিক হওয়ার পরে খোঁজা, তাই অন্য কোম্পানির নিয়ম ৪০৪
        $rule = NotificationRule::query()->findOrFail((int) $rule);
        $writer = app(RuleWriter::class);

        return response()->json(['data' => $this->ruleJson($writer->save($writer->validate($request->all()), $rule))]);
    }

    /** ⭐ শুকনো পরীক্ষা — খাটত কি না, কারা পেতেন; কিছু পাঠায় না (স্পেক §১২ `notification-rules` test) */
    public function testRule(Request $request, string $rule): JsonResponse
    {
        $rule = NotificationRule::query()->findOrFail((int) $rule);
        $sample = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(NotificationKinds::PRIORITIES)],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:191'],
        ]);
        $branch = isset($sample['branch_id']) ? (int) $sample['branch_id'] : null;
        $priority = $sample['priority'] ?? NotificationKinds::classify((string) $rule->event)['priority'];
        $values = NotificationVariables::clean((array) ($sample['values'] ?? []));
        $matches = app(RuleEngine::class)->matches($rule, $branch, $priority, $values, now());

        return response()->json(['data' => [
            'matches' => $matches,
            'would_reach' => $matches
                ? app(RecipientResolver::class)->resolve((array) ($rule->recipients ?? []), $branch)->map(fn ($u) => ['name' => (string) $u->name])->values()
                : [],
        ]]);
    }

    /** ⭐ টেমপ্লেটের তালিকা (স্পেক §১২ `notification-templates` list) */
    public function templates(Request $request): JsonResponse
    {
        $page = NotificationTemplate::query()->with('published')->orderBy('id')->paginate($this->perPage($request));

        return $this->paged($page, fn (NotificationTemplate $t) => $this->templateJson($t));
    }

    /** ⭐ নতুন টেমপ্লেট, প্রথম খসড়াসহ (স্পেক §১২ `notification-templates` create) */
    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_\.\-]+$/',
                Rule::unique('notification_templates', 'code')->where('company_id', CompanyContext::id())],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(NotificationKinds::CATEGORIES)],
            'subject_bn' => ['nullable', 'string', 'max:191'], 'subject_en' => ['nullable', 'string', 'max:191'],
            'title_bn' => ['required', 'string', 'max:191'], 'title_en' => ['required', 'string', 'max:191'],
            'body_bn' => ['nullable', 'string', 'max:1000'], 'body_en' => ['nullable', 'string', 'max:1000'],
        ]);

        $template = DB::transaction(function () use ($data): NotificationTemplate {
            $template = NotificationTemplate::query()->create(['code' => $data['code'], 'name' => $data['name'], 'category' => $data['category'], 'is_active' => true]);
            app(TemplateStudio::class)->draft($template, $data);

            return $template;
        });

        app(NotificationAudit::class)->record('template_save', $template, 'done', ['template_id' => $template->id, 'version' => 1]);

        return response()->json(['data' => $this->templateJson($template->fresh('published'))], 201);
    }

    /** ⭐ একটা সংস্করণ প্রকাশ — লেখক নিজে নয় (স্পেক §১২ `notification-templates` publish) */
    public function publishTemplate(Request $request, string $template): JsonResponse
    {
        $template = NotificationTemplate::query()->findOrFail((int) $template);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $version = NotificationTemplateVersion::query()->where('template_id', $template->id)->where('version', (int) $data['version'])->firstOrFail();

        if ((bool) app(SettingsService::class)->get('notification.templates_four_eyes', true)
            && (int) $version->created_by === (int) $request->user()->id) {
            app(NotificationAudit::class)->record('template_publish', $template, 'denied', ['template_id' => $template->id, 'version' => (int) $version->version]);

            return response()->json(['message' => __('core.notify.own_version')], 403);
        }

        app(TemplateStudio::class)->publish($template, $version);

        return response()->json(['data' => $this->templateJson($template->fresh('published'))]);
    }

    /** ⭐ ডেলিভারির তালিকা — অবস্থা আর মাধ্যম ধরে (স্পেক §১২ `notification-deliveries` list) */
    public function deliveries(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([NotificationJob::QUEUED, NotificationJob::PROCESSING, NotificationJob::SENT, NotificationJob::RETRYING,
                NotificationJob::HELD, NotificationJob::DEAD, NotificationJob::CANCELLED])],
            'channel' => ['nullable', Rule::in(NotificationChannel::ALL)],
        ]);

        $page = NotificationJob::query()->with(['user:id,name', 'notification:id,title'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->orderByDesc('id')->paginate($this->perPage($request));

        return $this->paged($page, fn (NotificationJob $j) => [
            'id' => (string) $j->public_id,
            'channel' => (string) $j->channel,
            'status' => (string) $j->status,
            'attempts' => (int) $j->attempts,
            'max_attempts' => (int) $j->max_attempts,
            'next_attempt_at' => $j->next_attempt_at?->toIso8601String(),
            'provider' => $j->provider,
            'provider_ref' => $j->provider_ref,
            'last_error' => $j->last_error,
            'recipient' => (string) ($j->user?->name ?? ''),
            'notification' => (string) ($j->notification?->title ?? ''),
            'sent_at' => $j->sent_at?->toIso8601String(),
        ]);
    }

    /** ⭐ হাতে আবার চেষ্টা (স্পেক §১২ `notification-deliveries` retry) */
    public function retryDelivery(Request $request, string $job): JsonResponse
    {
        $row = NotificationJob::query()->wherePublicId($job)->firstOrFail();

        if (! app(DeliveryService::class)->retryByHand($row)) {
            return response()->json(['message' => __('core.notify.not_retryable')], 422);
        }

        return response()->json(['data' => ['status' => (string) $row->fresh()->status]]);
    }

    /** ⭐ মাধ্যমের স্বাস্থ্য (স্পেক §১২ `notification-health`) */
    public function health(Request $request): JsonResponse
    {
        $company = (int) CompanyContext::id();
        $registry = app(ChannelRegistry::class);
        $out = [];

        foreach ($registry->all() as $key => $channel) {
            $config = $registry->config($company, $key);
            $tries = NotificationDeliveryAttempt::query()->where('channel', $key)->where('created_at', '>=', now()->subDay());
            $total = (clone $tries)->count();
            $sent = (clone $tries)->where('outcome', 'sent')->count();

            $out[] = [
                'channel' => $key,
                'connected' => $channel->connected($config),
                'last_checked_at' => $config?->last_checked_at?->toIso8601String(),
                'last_check_ok' => $config?->last_check_ok,
                'attempts_24h' => $total,
                'success_24h' => $total === 0 ? null : intdiv($sent * 100, $total),
                'queued' => NotificationJob::query()->where('channel', $key)->whereIn('status', NotificationJob::OPEN)->count(),
                'dead' => NotificationJob::query()->where('channel', $key)->where('status', NotificationJob::DEAD)->count(),
            ];
        }

        return response()->json(['data' => $out]);
    }

    /** @return array<string, mixed> */
    private function preferenceJson(NotificationPreference $p): array
    {
        return [
            'channels' => array_combine(NotificationChannel::ALL, array_map(fn ($c) => $p->allowsChannel($c), NotificationChannel::ALL)),
            'muted' => array_values((array) ($p->muted_categories ?? [])),
            'frequency' => (string) ($p->frequency ?: 'instant'),
            'digest_hour' => (int) $p->digest_hour,
            'digest_day' => (int) $p->digest_day,
            'quiet_enabled' => (bool) $p->quiet_enabled,
            'quiet_start' => $p->quiet_start === null ? null : substr((string) $p->quiet_start, 0, 5),
            'quiet_end' => $p->quiet_end === null ? null : substr((string) $p->quiet_end, 0, 5),
            'timezone' => $p->timezone,
            'delegate_user_id' => $p->delegate_user_id === null ? null : (int) $p->delegate_user_id,
            'delegate_from' => $p->delegate_from?->toDateString(),
            'delegate_until' => $p->delegate_until?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function ruleJson(NotificationRule $r): array
    {
        return [
            'id' => (int) $r->id,
            'name' => (string) $r->name,
            'module' => (string) $r->module,
            'event' => (string) $r->event,
            'branch_id' => $r->branch_id === null ? null : (int) $r->branch_id,
            'conditions' => array_values((array) ($r->conditions ?? [])),
            'recipients' => (object) ($r->recipients ?? []),
            'channels' => array_values((array) ($r->channels ?? [])),
            'priority' => $r->priority,
            'template_id' => $r->template_id === null ? null : (int) $r->template_id,
            'delay_minutes' => (int) $r->delay_minutes,
            'expires_minutes' => $r->expires_minutes === null ? null : (int) $r->expires_minutes,
            'cooldown_minutes' => (int) $r->cooldown_minutes,
            'is_active' => (bool) $r->is_active,
            'effective_from' => $r->effective_from?->toDateString(),
            'effective_to' => $r->effective_to?->toDateString(),
            'version' => (int) $r->version,
        ];
    }

    /** @return array<string, mixed> */
    private function templateJson(NotificationTemplate $t): array
    {
        return [
            'id' => (int) $t->id,
            'code' => (string) $t->code,
            'name' => (string) $t->name,
            'category' => (string) $t->category,
            'is_active' => (bool) $t->is_active,
            'published_version' => $t->published === null ? null : (int) $t->published->version,
            'latest_version' => (int) NotificationTemplateVersion::query()->where('template_id', $t->id)->max('version'),
        ];
    }

    private function perPage(Request $request): int
    {
        return max(1, min(self::MAX_PER_PAGE, (int) $request->query('per_page', 20)));
    }

    private function paged(LengthAwarePaginator $page, \Closure $map): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }
}
