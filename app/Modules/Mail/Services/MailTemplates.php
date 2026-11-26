<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Modules\Mail\Models\MailFestival;
use App\Modules\Mail\Models\MailTemplate;

/**
 * ⭐ টেমপ্লেট থেকে মেইল — বিষয় আর লেখা, ঘর বসিয়ে (নমুনা v4 পর্দা ৫–৬)।
 * ⓘ "নকশা করা" টেমপ্লেটে মাথায় কোম্পানির রঙের পট্টি; উৎসবের টেমপ্লেটে উৎসবের ছবি-পট্টি; "সাধারণ লেখা" শুধু লেখা।
 * ⛔ জাতীয় দিবসে (quiet) বোতাম বা অফার বসে না।
 */
final class MailTemplates
{
    public function __construct(private readonly MailFields $fields) {}

    /** @var \Illuminate\Support\Collection<string, MailFestival>|null */
    private ?\Illuminate\Support\Collection $festivals = null;

    /**
     * @param  array<string, string>  $values  [[MailFields::values]]
     * @return array{subject: string, html: string}
     */
    public function render(MailTemplate $t, array $values, string $company = ''): array
    {
        $subject = $this->fields->fill($t->subject, $values, false);

        // ⓘ নিজের HTML — লেখা যেমন, কেবল ঘর বসে (মান নিরাপদ) আর আবার নিরাপদ করা
        if ($t->format === 'html') {
            return ['subject' => $subject, 'html' => \App\Modules\Mail\Http\Controllers\MailController::safe($this->fields->fill($t->body, $values), true)];
        }

        $paragraphs = array_filter(array_map('trim', explode("\n", $this->fields->fill(e($t->body), $values))), fn ($p) => $p !== '');
        $text = implode('', array_map(fn ($p) => '<p style="margin:0 0 8px">'.$this->highlight($p).'</p>', $paragraphs));

        if ($t->format === 'plain') {
            return ['subject' => $subject, 'html' => '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.7;color:#24324a">'.$text.'</div>'];
        }

        // ⓘ উৎসবগুলো একবারে — লেখার পর্দা প্রায় 30টা টেমপ্লেট আঁকে
        $this->festivals ??= MailFestival::query()->get()->keyBy('key');
        $festival = str_starts_with((string) $t->key, 'fest_') ? $this->festivals->get(substr((string) $t->key, 5)) : null;

        $head = $festival !== null
            ? $this->festivalHead($festival)
            : '<tr><td style="background:'.e($t->colour ?: '#1a3fd0').';padding:14px 18px;color:#fff;font:700 15px Segoe UI,Arial,sans-serif">'.e($company)
                .'<span style="float:right;font-weight:600;opacity:.95">'.e($t->name).'</span></td></tr>';

        $button = '';

        if ($t->button && ! ($festival?->quiet) && isset($values['{লিংক}']) && $values['{লিংক}'] !== '') {
            $button = '<p style="margin:12px 0 0"><a href="'.e($values['{লিংক}']).'" style="background:'.e($t->colour ?: '#00ab3f').';color:#fff;border-radius:8px;padding:8px 16px;font-weight:700;text-decoration:none">'.e($t->button).'</a></p>';
        }

        $html = '<table cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:620px;border:1px solid #dde2ee;border-radius:10px;overflow:hidden;font-family:Segoe UI,Arial,sans-serif">'
            .$head.'<tr><td style="padding:14px 18px;font-size:14px;line-height:1.7;color:#24324a">'.$text.$button.'</td></tr></table>';

        return ['subject' => $subject, 'html' => $html];
    }

    public function used(MailTemplate $t): void
    {
        MailTemplate::query()->whereKey($t->id)->increment('used');
    }

    private function festivalHead(MailFestival $f): string
    {
        [$from, $to, $accent] = array_pad((array) $f->colours, 3, '#ffffff');

        return '<tr><td style="background:'.e($to).';background-image:linear-gradient(135deg,'.e($from).','.e($to).');padding:22px 20px;text-align:center;color:#fff">'
            .'<div style="font-size:44px;line-height:1">'.e((string) $f->mark).'</div>'
            .'<b style="display:block;font-size:24px;color:'.e($accent).';margin-top:4px">'.e($f->greeting).'</b>'
            .'<div style="font-size:13px;opacity:.95">'.e($f->line).'</div></td></tr>';
    }

    /** ⓘ টাকার অঙ্ক চোখে পড়ুক — ৳ দিয়ে শুরু হওয়া অংশ মোটা */
    private function highlight(string $p): string
    {
        return (string) preg_replace('/(৳[\d,]+(?:\.\d+)?)/u', '<b>$1</b>', $p);
    }
}
