<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Models\User;
use App\Modules\Mail\Http\Controllers\MailController;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Models\MailTemplate;

/**
 * ⭐ মেইল পাঠানোর এক দরজা — পর্দা থেকে এখনই, বা "পরে পাঠান"-এর সারি থেকে ([[MailOutboxRun]])।
 *
 * ⓘ ধাপ: কাগজের PDF (পাঠানো কর্মীর নিজের নামে, ছাপার পাতা থেকে) → লেখা নিরাপদ → লেখার ভিতরের ছবি মেইলে বসানো (cid) → সই (ছবি-লোগো cid) →
 *   প্রেরকের নাম (দলের মেইলবক্সে "ঠিকানার নাম" বা "কর্মী · ঠিকানার নাম") → পাঠানো → খাতা ও অডিট ([[MailSends]])।
 * ⛔ কাগজ ছাপা না গেলে মেইল যায় না — কারণসহ ফেরে।
 */
final class MailSender
{
    public function __construct(
        private readonly MailServer $server,
        private readonly MailPapers $papers,
        private readonly MailSignatures $signatures,
        private readonly MailSends $sends,
        private readonly MailTemplates $templates,
    ) {}

    /**
     * @param  array{to: list<string>, cc?: list<string>, bcc?: list<string>, subject: string, html: string, inReplyTo?: ?string,
     *               signature?: ?string, template?: ?string, papers?: list<array{kind: string, id: int, party?: ?string}>}  $mail
     * @param  list<array{name: string, mime: string, bytes: string}>  $files
     */
    public function send(User $user, MailAccount $box, array $mail, array $files = []): string
    {
        $paper = null;

        foreach ((array) ($mail['papers'] ?? []) as $p) {
            $pdf = $this->papers->pdf($user, (string) $p['kind'], (int) $p['id'], (string) ($p['party'] ?? 'customer'));
            $files[] = ['name' => $pdf['name'], 'mime' => $pdf['mime'], 'bytes' => $pdf['bytes']];
            $paper ??= ['type' => (string) $p['kind'], 'id' => (int) $p['id'], 'no' => $pdf['no']];
        }

        [$html, $embeds] = $this->inlineImages(MailController::safe($mail['html'], true));
        $sig = $mail['signature'] ?? 'new';

        if ($sig !== 'none') {
            $profile = $this->signatures->profile($user);
            $style = $sig === 'reply' ? (int) ($profile->style_reply ?: 10) : (int) ($profile->style_new ?: 1);
            $html .= $this->signatures->inline($user, $this->signatures->html($user, $style, $box->email), false);
            $embeds = array_merge($embeds, $this->signatures->embeds($user, $html));
        }

        // ⭐ অফারের মেইল (উৎসব, অফার, দামের তালিকা) — নিচে "বন্ধ করুন" লিংক আর RFC 8058 header (NEXUS-এর পর্যালোচনা R2)
        // ⓘ লিংক একজন প্রাপকের নামে হয় — একজনকে পাঠালে তবে বসে; অনেককে একসাথে পাঠানোর পথ নিজে থেকে পাঠানো ([[MailAuto]])
        $template = ! empty($mail['template']) ? MailTemplate::query()->where('public_id', $mail['template'])->first() : null;
        $headers = [];

        if (MailOptOuts::isMarketing($template) && count($mail['to']) === 1 && empty($mail['cc']) && empty($mail['bcc'])) {
            $optOuts = app(MailOptOuts::class);
            $link = $optOuts->link((string) $mail['to'][0], (string) $template->key);
            $html .= $optOuts->footer($link);
            $headers = $optOuts->headers($link);
        }

        $fromName = $box->kind === MailAccount::SHARED
            ? trim(($box->sender_with_person ? $user->name.' · ' : '').($box->sender_name ?: $box->boxTitle()))
            : (string) $user->name;

        $id = $this->server->send($box, [
            'to' => $mail['to'], 'cc' => $mail['cc'] ?? [], 'bcc' => $mail['bcc'] ?? [],
            'subject' => $mail['subject'], 'html' => $html, 'inReplyTo' => $mail['inReplyTo'] ?? null,
            'attachments' => $files, 'embeds' => $embeds, 'fromName' => $fromName, 'headers' => $headers,
        ]);

        if ($template) {
            $this->templates->used($template);
        }

        $this->sends->record($box, $user, ['to' => $mail['to'], 'cc' => $mail['cc'] ?? [], 'subject' => $mail['subject'], 'messageId' => $id,
            'paper' => $paper, 'template_id' => $template?->id, 'files' => count($files)]);

        return $id;
    }

    /**
     * ⓘ লেখায় বসানো ছবি (data:) — মেইলের ভিতরে আলাদা অংশ (cid) হয়ে যায়; অনেক মেইল প্রোগ্রাম data: ছবি দেখায় না।
     *
     * @return array{0: string, 1: list<array{cid: string, name: string, mime: string, bytes: string}>}
     */
    public function inlineImages(string $html): array
    {
        $embeds = [];
        $html = (string) preg_replace_callback('#src\s*=\s*(["\'])data:(image/(?:png|jpe?g|gif|webp));base64,([A-Za-z0-9+/=\s]+)\1#i', function ($m) use (&$embeds) {
            $bytes = base64_decode(preg_replace('/\s+/', '', $m[3]) ?? '', true);

            if ($bytes === false || strlen($bytes) > 3 * 1024 * 1024) {
                return 'src=""';
            }

            $cid = 'img'.(count($embeds) + 1).'-'.substr(sha1($bytes), 0, 10);
            $embeds[] = ['cid' => $cid, 'name' => $cid.'.'.(str_contains($m[2], 'png') ? 'png' : 'jpg'), 'mime' => strtolower($m[2]), 'bytes' => $bytes];

            return 'src="cid:'.$cid.'"';
        }, $html);

        return [$html, $embeds];
    }
}
