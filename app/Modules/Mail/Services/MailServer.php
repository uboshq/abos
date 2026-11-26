<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Modules\Mail\Models\MailAccount;

/**
 * ⭐ মেইল সার্ভারের সাথে কথা — ফোল্ডার, তালিকা, একটা মেইল, সংযুক্তি, পতাকা, সরানো, পাঠানো।
 * ⓘ আসলটা [[ImapMailServer]] (PHP-র imap); পরীক্ষায় নকল ([[FakeMailServer]]) — পরীক্ষা কখনো আসল সার্ভারে যায় না।
 *
 * @phpstan-type Folder array{path: string, name: string, role: string, unread: int, total: int}
 * @phpstan-type Header array{uid: int, messageId: string, fromName: string, fromEmail: string, to: string, subject: string, date: ?string, seen: bool, flagged: bool, attach: bool, snippet: string}
 */
interface MailServer
{
    /** ⓘ সংযোগ হয় কিনা — যোগ করার সময় যাচাই; ভুল হলে কারণসহ ব্যতিক্রম */
    public function check(MailAccount $account): void;

    /** @return list<array<string, mixed>> */
    public function folders(MailAccount $account): array;

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(MailAccount $account, string $folder, int $page, int $perPage, string $search = ''): array;

    /** @return array<string, mixed> — হেডার, html/text লেখা, সংযুক্তির তালিকা */
    public function read(MailAccount $account, string $folder, int $uid): array;

    /** @return array{name: string, mime: string, bytes: string} */
    public function attachment(MailAccount $account, string $folder, int $uid, string $part): array;

    public function flag(MailAccount $account, string $folder, int $uid, string $flag, bool $on): void;

    public function move(MailAccount $account, string $folder, int $uid, string $to): void;

    /**
     * @param  array{to: list<string>, cc?: list<string>, bcc?: list<string>, subject: string, html: string, inReplyTo?: string,
     *               attachments?: list<array{name: string, mime: string, bytes: string}>}  $mail
     * @return string  পাঠানো মেইলের Message-ID
     */
    public function send(MailAccount $account, array $mail): string;
}
