<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Modules\Mail\Models\MailAccount;
use RuntimeException;

/**
 * ⓘ পরীক্ষার মেইল সার্ভার — স্মৃতিতে কয়েকটা মেইল; আসল সার্ভারে কখনো যায় না। পাঠানো মেইল `$sent`-এ জমে, পরীক্ষা মেলায়।
 * ⓘ `$fail = true` হলে সংযোগ ভুল — "পাসওয়ার্ড ভুল" পথের পরীক্ষা।
 */
final class FakeMailServer implements MailServer
{
    public bool $fail = false;

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $boxes = [];

    public function __construct()
    {
        $this->boxes = [
            'INBOX' => [
                ['uid' => 3, 'messageId' => '<m3@test>', 'fromName' => 'করিম ট্রেডার্স', 'fromEmail' => 'karim@example.com', 'to' => 'me@example.com',
                    'subject' => 'বিল S-0014-এর টাকা', 'date' => '2026-11-26T10:42:00+06:00', 'seen' => false, 'flagged' => true, 'attach' => true, 'snippet' => '',
                    'html' => '<p>আজ টাকা জমা দিয়েছি।</p><img src="https://tracker.example/p.gif">', 'text' => '',
                    'files' => [['part' => '2', 'name' => 'slip.jpg', 'mime' => 'image/jpeg', 'size' => 12, 'inline' => false, 'cid' => '']]],
                ['uid' => 2, 'messageId' => '<m2@test>', 'fromName' => 'প্রাণ ফুডস', 'fromEmail' => 'pran@example.com', 'to' => 'me@example.com',
                    'subject' => 'নতুন দরের তালিকা', 'date' => '2026-11-26T09:15:00+06:00', 'seen' => true, 'flagged' => false, 'attach' => false, 'snippet' => '',
                    'html' => '', 'text' => "১ ডিসেম্বর থেকে নতুন দর।", 'files' => []],
            ],
            'Sent' => [],
            'Archive' => [],
            'Trash' => [],
        ];
    }

    public function check(MailAccount $account): void
    {
        $this->guard();
    }

    public function folders(MailAccount $account): array
    {
        $this->guard();

        return [
            ['path' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox', 'unread' => count(array_filter($this->boxes['INBOX'], fn ($m) => ! $m['seen'])), 'total' => count($this->boxes['INBOX'])],
            ['path' => 'Sent', 'name' => 'Sent', 'role' => 'sent', 'unread' => 0, 'total' => count($this->boxes['Sent'])],
            ['path' => 'Archive', 'name' => 'Archive', 'role' => 'archive', 'unread' => 0, 'total' => count($this->boxes['Archive'] ?? [])],
            ['path' => 'Trash', 'name' => 'Trash', 'role' => 'trash', 'unread' => 0, 'total' => count($this->boxes['Trash'] ?? [])],
        ];
    }

    public function list(MailAccount $account, string $folder, int $page, int $perPage, string $search = ''): array
    {
        $this->guard();
        $want = [];
        foreach (['is:unread' => ['seen', false], 'is:flagged' => ['flagged', true], 'is:later' => ['later', true]] as $k => $v) {
            if (str_contains($search, $k)) {
                $want[] = $v;
                $search = trim(str_replace($k, '', $search));
            }
        }
        $all = array_values(array_filter($this->boxes[$folder] ?? [], fn ($m) => ($search === '' || str_contains($m['subject'].$m['fromName'], $search))
            && collect($want)->every(fn ($w) => (bool) ($m[$w[0]] ?? false) === $w[1])));
        $items = array_map(fn ($m) => array_diff_key($m, ['html' => 1, 'text' => 1, 'files' => 1]), array_slice($all, ($page - 1) * $perPage, $perPage));

        return ['items' => $items, 'total' => count($all)];
    }

    public function read(MailAccount $account, string $folder, int $uid): array
    {
        $this->guard();

        foreach ($this->boxes[$folder] ?? [] as $i => $m) {
            if ($m['uid'] === $uid) {
                $this->boxes[$folder][$i]['seen'] = true;

                return ['uid' => $uid, 'messageId' => $m['messageId'], 'inReplyTo' => '', 'subject' => $m['subject'],
                    'from' => [['name' => $m['fromName'], 'email' => $m['fromEmail']]], 'to' => [['name' => '', 'email' => $m['to']]], 'cc' => [], 'replyTo' => [],
                    'date' => $m['date'], 'flagged' => $m['flagged'], 'html' => $m['html'], 'text' => $m['text'], 'files' => $m['files']];
            }
        }

        throw new RuntimeException('message gone');
    }

    public function attachment(MailAccount $account, string $folder, int $uid, string $part): array
    {
        $this->guard();

        return ['name' => 'slip.jpg', 'mime' => 'image/jpeg', 'bytes' => 'JPEGBYTES'];
    }

    public function raw(MailAccount $account, string $folder, int $uid): string
    {
        $this->guard();

        foreach ($this->boxes[$folder] ?? [] as $m) {
            if ($m['uid'] === $uid) {
                return 'Message-ID: '.$m['messageId']."\r\nFrom: ".$m['fromEmail']."\r\nSubject: ".$m['subject']."\r\n\r\n".($m['html'] ?: $m['text']);
            }
        }

        throw new RuntimeException('gone');
    }

    public function flag(MailAccount $account, string $folder, int $uid, string $flag, bool $on): void
    {
        $this->guard();

        foreach ($this->boxes[$folder] ?? [] as $i => $m) {
            if ($m['uid'] === $uid && in_array($flag, ['seen', 'flagged', 'later'], true)) {
                $this->boxes[$folder][$i][$flag] = $on;
            }
        }
    }

    public function move(MailAccount $account, string $folder, int $uid, string $to): void
    {
        $this->guard();

        foreach ($this->boxes[$folder] ?? [] as $i => $m) {
            if ($m['uid'] === $uid) {
                $this->boxes[$to][] = $m;
                unset($this->boxes[$folder][$i]);
                $this->boxes[$folder] = array_values($this->boxes[$folder]);
            }
        }
    }

    public function send(MailAccount $account, array $mail): string
    {
        $this->guard();
        $this->sent[] = ['from' => $account->email] + $mail;

        return '<sent'.count($this->sent).'@test>';
    }

    private function guard(): void
    {
        if ($this->fail) {
            throw new RuntimeException('AUTHENTICATIONFAILED');
        }
    }
}
