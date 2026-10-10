<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Services\NotificationAudit;
use App\Core\Support\Actor;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ টেমপ্লেট স্টুডিও — খসড়া, প্রকাশ, আগের সংস্করণে ফেরা, পূর্বরূপ (মালিকের স্পেক §৯গ; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩)।
 *
 * ── ⛔ নিয়ম ───────────────────────────────────────────────────────────
 *   · প্রতিটা সংরক্ষণ একটা নতুন সংস্করণ — পুরনোটা কখনো বদলায় না, তাই কোন খবর কোন লেখায় গিয়েছিল তা সবসময় জানা যায়।
 *   · লেখায় কেবল অনুমোদিত চলক ([[NotificationVariables::ALL]]); অচেনা চলক থাকলে সংরক্ষণ হয় না, কারণসহ।
 *   · প্রকাশ আর ফেরা আলাদা চাবিতে (`notification.templates.publish`), আর দুইটাই নিরীক্ষায়।
 */
final class TemplateStudio
{
    /** @var list<string> লেখার ঘরগুলো */
    public const PARTS = ['subject_bn', 'subject_en', 'title_bn', 'title_en', 'body_bn', 'body_en'];

    /**
     * নতুন সংস্করণ (খসড়া)।
     *
     * @param  array<string, ?string>  $text
     */
    public function draft(NotificationTemplate $template, array $text, ?string $note = null): NotificationTemplateVersion
    {
        $this->check($text);

        return DB::transaction(function () use ($template, $text, $note): NotificationTemplateVersion {
            $next = (int) NotificationTemplateVersion::query()->where('template_id', $template->id)->lockForUpdate()->max('version') + 1;
            $names = [];

            foreach (self::PARTS as $part) {
                $names = array_merge($names, NotificationVariables::namesIn($text[$part] ?? null));
            }

            return NotificationTemplateVersion::query()->create([
                'company_id' => $template->company_id,
                'template_id' => $template->id,
                'version' => $next,
                'variables' => array_values(array_unique($names)),
                'note' => $note === null ? null : mb_substr($note, 0, 191),
                'created_by' => Actor::userId(),
            ] + array_map(fn ($v) => $v === null || $v === '' ? null : (string) $v, array_intersect_key($text, array_flip(self::PARTS))));
        });
    }

    /** ⭐ একটা সংস্করণ প্রকাশ — নতুন হোক বা পুরনো (পুরনো হলে সেটাই "ফেরা") */
    public function publish(NotificationTemplate $template, NotificationTemplateVersion $version): void
    {
        abort_unless((int) $version->template_id === (int) $template->id, 404);

        $rollback = $template->published_version_id !== null && (int) $version->version < (int) ($template->published?->version ?? 0);

        DB::transaction(function () use ($template, $version): void {
            $version->forceFill(['published_at' => now(), 'published_by' => Actor::userId()])->save();
            $template->forceFill(['published_version_id' => $version->id])->save();
        });

        app(NotificationAudit::class)->record($rollback ? 'template_rollback' : 'template_publish', $template, 'done', [
            'template_id' => $template->id, 'version' => (int) $version->version,
        ]);
    }

    /**
     * পূর্বরূপ — নমুনা মান বসিয়ে, চাওয়া ভাষায়।
     *
     * @return array{subject: string, title: string, body: string}
     */
    public function preview(NotificationTemplateVersion $version, string $locale, ?array $values = null): array
    {
        $values ??= NotificationVariables::samples();

        return [
            'subject' => NotificationVariables::render($version->part('subject', $locale) ?? $version->part('title', $locale), $values),
            'title' => NotificationVariables::render($version->part('title', $locale), $values),
            'body' => NotificationVariables::render($version->part('body', $locale), $values),
        ];
    }

    /** @param  array<string, ?string>  $text */
    private function check(array $text): void
    {
        $errors = [];

        foreach (self::PARTS as $part) {
            if ($unknown = NotificationVariables::unknownIn($text[$part] ?? null)) {
                $errors[$part] = (string) __('core.notify.var_unknown', ['names' => implode(', ', array_map(fn ($n) => '{'.$n.'}', $unknown))]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
