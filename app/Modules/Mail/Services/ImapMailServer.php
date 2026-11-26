<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Modules\Mail\Models\MailAccount;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * ⭐ আসল মেইল সার্ভার — PHP-র imap দিয়ে পড়া, SMTP দিয়ে পাঠানো (কোম্পানির মেইল, Yahoo, অ্যাপ-পাসওয়ার্ডে Gmail, যেকোনো IMAP)।
 *
 * ⓘ প্রতিটা ডাকে নতুন সংযোগ, কাজ শেষে বন্ধ — সার্ভারে খোলা সংযোগ জমে না। সময়সীমা ১০ সেকেন্ড, তাই সার্ভার না পেলে পাতা আটকে থাকে না।
 * ⛔ মেইলের লেখা কোথাও জমা হয় না — পড়ে দেখানো হয়, ব্যস। ⛔ পাসওয়ার্ড লগে বা ভুলবার্তায় যায় না।
 * ⓘ Gmail আর Hotmail-এর নিজের সাইন-ইন (OAuth) দ্বিতীয় ধাপে — PHP-র imap-এ ওটা নেই।
 */
final class ImapMailServer implements MailServer
{
    private const ROLES = [
        'inbox' => ['inbox'],
        'sent' => ['sent', 'sent items', 'sent mail', 'sent messages', '[gmail]/sent mail'],
        'drafts' => ['drafts', 'draft', '[gmail]/drafts'],
        'trash' => ['trash', 'deleted', 'deleted items', 'bin', '[gmail]/trash'],
        'junk' => ['junk', 'spam', 'junk e-mail', 'bulk mail', '[gmail]/spam'],
        'archive' => ['archive', 'archives', '[gmail]/all mail'],
    ];

    public function check(MailAccount $account): void
    {
        $this->close($this->open($account, 'INBOX'));
    }

    public function folders(MailAccount $account): array
    {
        $stream = $this->open($account, 'INBOX');

        try {
            $server = $this->server($account);
            $boxes = imap_getmailboxes($stream, $server, '*') ?: [];
            $out = [];

            foreach ($boxes as $box) {
                if (($box->attributes & LATT_NOSELECT) === LATT_NOSELECT) {
                    continue;
                }

                $path = substr($box->name, strlen($server));
                $name = mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP');
                $status = @imap_status($stream, $box->name, SA_UNSEEN | SA_MESSAGES);

                $out[] = [
                    'path' => $path,
                    'name' => $this->leaf($name, (string) $box->delimiter),
                    'role' => $this->role($name, (int) $box->attributes),
                    'unread' => (int) ($status->unseen ?? 0),
                    'total' => (int) ($status->messages ?? 0),
                ];
            }

            $order = array_flip(['inbox', 'drafts', 'sent', 'archive', 'junk', 'trash', '']);
            usort($out, fn ($a, $b) => [$order[$a['role']] ?? 9, $a['name']] <=> [$order[$b['role']] ?? 9, $b['name']]);

            return $out;
        } finally {
            $this->close($stream);
        }
    }

    public function list(MailAccount $account, string $folder, int $page, int $perPage, string $search = ''): array
    {
        $stream = $this->open($account, $folder);

        try {
            $criteria = $this->criteria($search);
            $uids = imap_sort($stream, SORTARRIVAL, 1, SE_UID | SE_NOPREFETCH, $criteria, 'UTF-8') ?: [];
            $total = count($uids);
            $slice = array_slice($uids, max(0, ($page - 1) * $perPage), $perPage);

            if ($slice === []) {
                return ['items' => [], 'total' => $total];
            }

            $overview = imap_fetch_overview($stream, implode(',', $slice), FT_UID) ?: [];
            $byUid = [];

            foreach ($overview as $o) {
                $byUid[(int) $o->uid] = $o;
            }

            $items = [];

            foreach ($slice as $uid) {
                $o = $byUid[(int) $uid] ?? null;

                if ($o === null) {
                    continue;
                }

                [$fromName, $fromEmail] = $this->person((string) ($o->from ?? ''));
                $structure = @imap_fetchstructure($stream, (int) $uid, FT_UID);

                $items[] = [
                    'uid' => (int) $uid,
                    'messageId' => trim((string) ($o->message_id ?? '')),
                    'fromName' => $fromName,
                    'fromEmail' => $fromEmail,
                    'to' => $this->decode((string) ($o->to ?? '')),
                    'subject' => $this->decode((string) ($o->subject ?? '')),
                    'date' => isset($o->date) ? $this->iso((string) $o->date) : null,
                    'seen' => (bool) ($o->seen ?? false),
                    'flagged' => (bool) ($o->flagged ?? false),
                    'attach' => $structure ? $this->hasAttachment($structure) : false,
                    'snippet' => '',
                ];
            }

            return ['items' => $items, 'total' => $total];
        } finally {
            $this->close($stream);
        }
    }

    public function read(MailAccount $account, string $folder, int $uid): array
    {
        $stream = $this->open($account, $folder);

        try {
            $msgno = imap_msgno($stream, $uid);

            if ($msgno === 0) {
                throw new RuntimeException('message gone');
            }

            $head = imap_headerinfo($stream, $msgno);
            $structure = imap_fetchstructure($stream, $uid, FT_UID);
            $parts = ['html' => '', 'text' => '', 'files' => []];
            $this->walk($stream, $uid, $structure, '', $parts);

            // ⓘ পড়া হলো — পড়ার চিহ্ন বসে (লেখা আনার সময় সার্ভার নিজেও বসায়; এখানে নিশ্চিত করা)
            imap_setflag_full($stream, (string) $uid, '\\Seen', ST_UID);

            return [
                'uid' => $uid,
                'messageId' => trim((string) ($head->message_id ?? '')),
                'inReplyTo' => trim((string) ($head->in_reply_to ?? '')),
                'subject' => $this->decode((string) ($head->subject ?? '')),
                'from' => $this->addresses($head->from ?? []),
                'to' => $this->addresses($head->to ?? []),
                'cc' => $this->addresses($head->cc ?? []),
                'replyTo' => $this->addresses($head->reply_to ?? []),
                'date' => isset($head->date) ? $this->iso((string) $head->date) : null,
                'flagged' => trim((string) ($head->Flagged ?? '')) === 'F',
                'html' => $parts['html'],
                'text' => $parts['text'],
                'files' => $parts['files'],
            ];
        } finally {
            $this->close($stream);
        }
    }

    public function attachment(MailAccount $account, string $folder, int $uid, string $part): array
    {
        $stream = $this->open($account, $folder);

        try {
            $structure = imap_bodystruct($stream, imap_msgno($stream, $uid), $part);

            if (! $structure) {
                throw new RuntimeException('part gone');
            }

            $raw = imap_fetchbody($stream, $uid, $part, FT_UID | FT_PEEK);

            return [
                'name' => $this->fileName($structure) ?: 'file',
                'mime' => $this->mime($structure),
                'bytes' => $this->body((string) $raw, (int) $structure->encoding),
            ];
        } finally {
            $this->close($stream);
        }
    }

    public function raw(MailAccount $account, string $folder, int $uid): string
    {
        $stream = $this->open($account, $folder);

        try {
            return (string) imap_fetchheader($stream, $uid, FT_UID).(string) imap_body($stream, $uid, FT_UID | FT_PEEK);
        } finally {
            $this->close($stream);
        }
    }

    public function flag(MailAccount $account, string $folder, int $uid, string $flag, bool $on): void
    {
        // ⓘ "পরে দেখব" — মেইল সার্ভারের নিজের চিহ্ন (IMAP keyword), তাই অন্য যন্ত্রেও একই
        $name = ['seen' => '\\Seen', 'flagged' => '\\Flagged', 'later' => '$Later'][$flag] ?? null;

        if ($name === null) {
            return;
        }

        $stream = $this->open($account, $folder);

        try {
            $on ? imap_setflag_full($stream, (string) $uid, $name, ST_UID) : imap_clearflag_full($stream, (string) $uid, $name, ST_UID);
        } finally {
            $this->close($stream);
        }
    }

    public function move(MailAccount $account, string $folder, int $uid, string $to): void
    {
        $stream = $this->open($account, $folder);

        try {
            if (! imap_mail_move($stream, (string) $uid, $to, CP_UID)) {
                throw new RuntimeException('move failed');
            }

            imap_expunge($stream);
        } finally {
            $this->close($stream);
        }
    }

    public function send(MailAccount $account, array $mail): string
    {
        $email = (new Email())
            ->from(new Address($account->email, (string) ($mail['fromName'] ?? $account->label ?? '')))
            ->to(...$mail['to'])
            ->subject($mail['subject'])
            ->html($mail['html'])
            ->text(trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $mail['html'])))));

        foreach (($mail['cc'] ?? []) as $cc) {
            $email->addCc($cc);
        }
        foreach (($mail['bcc'] ?? []) as $bcc) {
            $email->addBcc($bcc);
        }
        foreach (($mail['attachments'] ?? []) as $file) {
            $email->attach($file['bytes'], $file['name'], $file['mime']);
        }
        // ⓘ সইয়ের ছবি আর লোগো মেইলের ভিতরে (cid) — বাইরের ঠিকানা থেকে টানা নয়
        foreach (($mail['embeds'] ?? []) as $file) {
            $email->embed($file['bytes'], $file['cid'], $file['mime']);
        }
        if (! empty($mail['inReplyTo'])) {
            $email->getHeaders()->addIdHeader('In-Reply-To', trim($mail['inReplyTo'], '<>'));
            $email->getHeaders()->addIdHeader('References', trim($mail['inReplyTo'], '<>'));
        }

        $mailer = new Mailer(Transport::fromDsn($this->smtpDsn($account)));
        $mailer->send($email);

        $messageId = (string) ($email->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '');

        // ⓘ পাঠানো মেইলের কপি "পাঠানো" ফোল্ডারে — মেইল অ্যাপের মতোই; না গেলে পাঠানো তবু সফল
        try {
            $stream = $this->open($account, 'INBOX');
            $sent = collect($this->folders($account))->firstWhere('role', 'sent');

            if ($sent) {
                imap_append($stream, $this->server($account).$sent['path'], $email->toString()."\r\n", '\\Seen');
            }

            $this->close($stream);
        } catch (\Throwable) {
            // কপি না গেলেও মেইল চলে গেছে
        }

        return $messageId;
    }

    // ── ভিতরের কাজ ───────────────────────────────────────────────────────

    private function server(MailAccount $account): string
    {
        $secure = $account->imap_secure === 'none' ? '/novalidate-cert/notls' : '/'.($account->imap_secure ?: 'ssl');

        return '{'.$account->imap_host.':'.($account->imap_port ?: 993).'/imap'.$secure.'}';
    }

    /** @return resource|\IMAP\Connection */
    private function open(MailAccount $account, string $folder)
    {
        if (! function_exists('imap_open')) {
            throw new RuntimeException(__('mail::message.no_imap'));
        }

        imap_timeout(IMAP_OPENTIMEOUT, 10);
        imap_timeout(IMAP_READTIMEOUT, 15);

        $box = $this->server($account).mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        $stream = @imap_open($box, (string) ($account->username ?: $account->email), (string) $account->secret, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

        if ($stream === false) {
            $why = (string) (imap_last_error() ?: 'connect failed');
            imap_errors();
            imap_alerts();

            throw new RuntimeException(mb_substr($why, 0, 300));
        }

        return $stream;
    }

    private function close($stream): void
    {
        @imap_close($stream);
        imap_errors();
        imap_alerts();
    }

    private function smtpDsn(MailAccount $account): string
    {
        $scheme = ($account->smtp_secure ?: 'ssl') === 'ssl' ? 'smtps' : 'smtp';

        return $scheme.'://'.rawurlencode((string) ($account->username ?: $account->email)).':'.rawurlencode((string) $account->secret)
            .'@'.$account->smtp_host.':'.($account->smtp_port ?: 465);
    }

    private function criteria(string $search): string
    {
        $search = trim(str_replace('"', '', $search));

        if ($search === '') {
            return 'ALL';
        }

        $flags = [];
        foreach (['is:unread' => 'UNSEEN', 'is:flagged' => 'FLAGGED', 'is:later' => 'KEYWORD $Later'] as $k => $v) {
            if (str_contains($search, $k)) {
                $flags[] = $v;
                $search = trim(str_replace($k, '', $search));
            }
        }

        if ($search !== '') {
            $flags[] = 'TEXT "'.$search.'"';
        }

        return implode(' ', $flags) ?: 'ALL';
    }

    private function role(string $name, int $attributes): string
    {
        // ⓘ নাম দেখে চেনা — বিশেষ-ফোল্ডারের ধ্রুবক সব PHP-তে নেই, তাই ভরসা নাম
        unset($attributes);
        $low = mb_strtolower(trim($name));
        $leaf = mb_strtolower(preg_replace('/^.*[.\/]/u', '', $low) ?? $low);

        foreach (self::ROLES as $role => $names) {
            if (in_array($low, $names, true) || in_array($leaf, $names, true)) {
                return $role;
            }
        }

        return '';
    }

    private function leaf(string $name, string $delimiter): string
    {
        if ($delimiter === '' || ! str_contains($name, $delimiter)) {
            return $name;
        }

        return (string) substr($name, (int) strrpos($name, $delimiter) + 1);
    }

    private function decode(string $value): string
    {
        $out = '';

        foreach (imap_mime_header_decode($value) ?: [] as $piece) {
            $charset = strtoupper((string) $piece->charset);
            $out .= in_array($charset, ['DEFAULT', 'UTF-8', 'US-ASCII', ''], true)
                ? $piece->text
                : (string) @mb_convert_encoding($piece->text, 'UTF-8', $charset);
        }

        return trim($out !== '' ? $out : $value);
    }

    /** @return array{0: string, 1: string} */
    private function person(string $raw): array
    {
        $list = imap_rfc822_parse_adrlist($this->decode($raw), 'localhost') ?: [];
        $first = $list[0] ?? null;

        if ($first === null || empty($first->mailbox)) {
            return [$this->decode($raw), ''];
        }

        $email = $first->mailbox.'@'.($first->host ?? '');

        return [trim((string) ($first->personal ?? '')) ?: $email, $email];
    }

    /** @return list<array{name: string, email: string}> */
    private function addresses(array $list): array
    {
        $out = [];

        foreach ($list as $a) {
            if (empty($a->mailbox)) {
                continue;
            }

            $email = $a->mailbox.'@'.($a->host ?? '');
            $out[] = ['name' => isset($a->personal) ? $this->decode((string) $a->personal) : '', 'email' => $email];
        }

        return $out;
    }

    private function iso(string $date): ?string
    {
        $time = strtotime($date);

        return $time ? date(DATE_ATOM, $time) : null;
    }

    private function hasAttachment(object $structure): bool
    {
        if (isset($structure->disposition) && strtolower((string) $structure->disposition) === 'attachment') {
            return true;
        }

        foreach (($structure->parts ?? []) as $part) {
            if ($this->hasAttachment($part)) {
                return true;
            }
        }

        return false;
    }

    /** ⓘ MIME-এর গাছ বেয়ে লেখা (html, text) আর সংযুক্তির তালিকা */
    private function walk($stream, int $uid, object $part, string $number, array &$out): void
    {
        $type = $this->mime($part);
        $file = $this->fileName($part);
        $isAttachment = $file !== '' && (strtolower((string) ($part->disposition ?? '')) === 'attachment' || ! str_starts_with($type, 'text/'));

        if (! empty($part->parts)) {
            foreach ($part->parts as $i => $child) {
                $this->walk($stream, $uid, $child, $number === '' ? (string) ($i + 1) : $number.'.'.($i + 1), $out);
            }

            return;
        }

        $section = $number === '' ? '1' : $number;

        if ($isAttachment) {
            $out['files'][] = ['part' => $section, 'name' => $file, 'mime' => $type, 'size' => (int) ($part->bytes ?? 0),
                'inline' => strtolower((string) ($part->disposition ?? '')) === 'inline', 'cid' => trim((string) ($part->id ?? ''), '<>')];

            return;
        }

        if ($type !== 'text/html' && $type !== 'text/plain') {
            return;
        }

        $raw = (string) imap_fetchbody($stream, $uid, $section, FT_UID | FT_PEEK);
        $text = $this->body($raw, (int) ($part->encoding ?? 0));
        $charset = $this->param($part, 'charset');

        if ($charset !== '' && strtoupper($charset) !== 'UTF-8') {
            $text = (string) @mb_convert_encoding($text, 'UTF-8', $charset);
        }

        $key = $type === 'text/html' ? 'html' : 'text';
        if ($out[$key] === '') {
            $out[$key] = $text;
        }
    }

    private function body(string $raw, int $encoding): string
    {
        return match ($encoding) {
            ENCBASE64 => (string) base64_decode($raw),
            ENCQUOTEDPRINTABLE => quoted_printable_decode($raw),
            default => $raw,
        };
    }

    private function mime(object $part): string
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other'];

        return ($types[(int) ($part->type ?? 0)] ?? 'application').'/'.strtolower((string) ($part->subtype ?? 'octet-stream'));
    }

    private function fileName(object $part): string
    {
        $name = $this->param($part, 'filename', true) ?: $this->param($part, 'name');

        return $name === '' ? '' : $this->decode($name);
    }

    private function param(object $part, string $key, bool $disposition = false): string
    {
        $list = $disposition ? ($part->dparameters ?? []) : ($part->parameters ?? []);

        foreach ((array) $list as $p) {
            $attr = strtolower((string) $p->attribute);

            if ($attr === $key || $attr === $key.'*') {
                $value = (string) $p->value;

                // ⓘ RFC 2231 — utf-8''নাম.pdf
                if (preg_match("/^([\\w-]+)''(.*)$/", $value, $m)) {
                    return (string) @mb_convert_encoding(rawurldecode($m[2]), 'UTF-8', $m[1]);
                }

                return $value;
            }
        }

        return '';
    }
}
