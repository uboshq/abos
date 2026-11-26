<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Models\User;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Models\MailSend;
use Illuminate\Support\Collection;

/**
 * ⭐ পাঠানো মেইলের খাতা (মালিকের অডিট নির্দেশ, ২৬ নভেম্বর ২০২৬) — প্রতিটা পাঠানো এক সারি, আর মেইলবক্সের অডিটে এক লাইন।
 * ⓘ কাগজ থেকে পাঠালে কাগজের পাতা জানে "মেইলে পাঠানো" ([[forPaper]])।
 */
final class MailSends
{
    public function __construct(private readonly AuditEngine $audit) {}

    /**
     * @param  array{to: list<string>, cc?: list<string>, subject: string, messageId?: string, paper?: ?array{type: string, id: int, no: string},
     *               template_id?: ?int, files?: int, auto?: bool}  $mail
     */
    public function record(MailAccount $account, ?User $by, array $mail): MailSend
    {
        $row = MailSend::query()->create([
            'mail_account_id' => $account->id,
            'from_email' => $account->email,
            'to' => mb_substr(implode(', ', $mail['to']), 0, 1000),
            'cc' => ($mail['cc'] ?? []) !== [] ? mb_substr(implode(', ', $mail['cc']), 0, 1000) : null,
            'subject' => mb_substr($mail['subject'], 0, 255),
            'message_id' => $mail['messageId'] ?? null,
            'paper_type' => $mail['paper']['type'] ?? null,
            'paper_id' => $mail['paper']['id'] ?? null,
            'paper_no' => $mail['paper']['no'] ?? null,
            'template_id' => $mail['template_id'] ?? null,
            'files' => (int) ($mail['files'] ?? 0),
            'auto' => (bool) ($mail['auto'] ?? false),
            'sent_by' => $by?->id,
            'sent_at' => now(),
        ]);

        $what = __('mail::field.history.sent_line', ['to' => $row->to, 'subject' => $row->subject])
            .($row->paper_no ? ' · '.$row->paper_no : '');
        $this->audit->recordAction($account, 'mail_sent', mb_substr($what, 0, 500));

        return $row;
    }

    /** ⓘ দলের মেইলবক্সে মোছা বা সরানো — কে, কোন মেইল (বিষয়), কোথায় */
    public function recordMove(MailAccount $account, string $action, string $subject, string $to): void
    {
        if ($account->kind !== MailAccount::SHARED) {
            return;
        }

        $this->audit->recordAction($account, $action, mb_substr(__('mail::field.history.move_line', ['subject' => $subject, 'to' => $to]), 0, 500));
    }

    /** @return Collection<int, MailSend> */
    public function forPaper(string $type, int $id): Collection
    {
        return MailSend::query()->where('paper_type', $type)->where('paper_id', $id)->with('sender:id,name')->latest('sent_at')->get();
    }
}
