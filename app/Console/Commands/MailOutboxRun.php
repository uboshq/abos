<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Mail\Models\MailOutbox;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Mail\Services\MailSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * ⭐ "পরে পাঠান" (নমুনা v4 পর্দা ৩খ, 🕒) — সময় হলে মেইল যায়, পাঠানো কর্মীর নিজের নামে আর নিজের অনুমতিতে।
 *
 * ⛔ পাঠানোর মুহূর্তে আবার দেখা হয় কর্মী এখনো এই মেইলবক্স থেকে পাঠাতে পারেন কিনা — মাঝে অনুমতি গেলে মেইল যায় না।
 * ⓘ পাঠানো হলেই সারি মোছে (লেখা ABOS-এ থাকে না); 5 বার না গেলে সারি মোছে আর মেইলবক্সের অডিটে কারণ লেখা থাকে।
 */
final class MailOutboxRun extends Command
{
    protected $signature = 'abos:mail-outbox';

    protected $description = 'Mail: send the scheduled mails whose time has come';

    public function handle(MailSender $sender, MailAccess $access, AuditEngine $audit): int
    {
        $sent = 0;

        foreach (MailOutbox::query()->withoutGlobalScopes()->where('send_at', '<=', now())->orderBy('send_at')->limit(100)->get() as $row) {
            CompanyContext::forCompany((int) $row->company_id, function () use ($row, $sender, $access, $audit, &$sent) {
                $box = $row->account;
                $user = $row->user;

                if (! $box || ! $user || ! $access->canSendFrom($user, $box)) {
                    if ($box) {
                        $audit->recordAction($box, 'mail_send_failed', mb_substr(__('mail::message.schedule_lost'), 0, 500));
                    }
                    $row->delete();

                    return;
                }

                Auth::guard('web')->setUser($user);

                try {
                    $sender->send($user, $box, (array) $row->payload);
                    $row->delete();
                    $sent++;
                } catch (\Throwable $e) {
                    $tries = $row->tries + 1;

                    if ($tries >= 5) {
                        $audit->recordAction($box, 'mail_send_failed', mb_substr((string) (($row->payload['subject'] ?? '').' — '.$e->getMessage()), 0, 500));
                        $row->delete();
                    } else {
                        $row->forceFill(['tries' => $tries, 'last_error' => mb_substr($e->getMessage(), 0, 500), 'send_at' => now()->addMinutes(10)])->save();
                    }
                } finally {
                    Auth::guard('web')->forgetUser();
                }
            });
        }

        $this->info("sent {$sent}");

        return self::SUCCESS;
    }
}
