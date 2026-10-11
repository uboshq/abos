<?php

declare(strict_types=1);

namespace App\Modules\Executive\Notifications;

use App\Core\Support\Money;
use App\Modules\Executive\Services\Figures;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ⭐ সকালের সারাংশ — মালিকের উত্তর ৪, ১০ অক্টোবর ২০২৬: "যাবে alaminsuv@gmail.com এ"।
 *
 * আগের দিনের রাতের হিসাব ([[Snapshots]]) থেকে প্রতিটা কোম্পানির আটটা সংখ্যা — পর্দার "আজ"-এর একই সংখ্যা,
 * নতুন কোনো হিসাব নয়। ⓘ বাংলায়, কারণ মালিক কেবল বাংলা পড়েন।
 */
final class MorningSummary extends Notification
{
    /**
     * @param  list<array{company_name: string, values: array<string, ?string>}>  $companies
     */
    public function __construct(
        private readonly string $date,
        private readonly array $companies,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('executive::mail.subject', ['date' => $this->date], 'bn'))
            ->greeting(__('executive::mail.greeting', [], 'bn'))
            ->line(__('executive::mail.intro', ['date' => $this->date], 'bn'));

        if ($this->companies === []) {
            return $mail->line(__('executive::mail.nothing', [], 'bn'));
        }

        foreach ($this->companies as $company) {
            $mail->line('**'.$company['company_name'].'**');

            foreach (Figures::KEYS as $key) {
                $value = $company['values'][$key] ?? null;
                $shown = $value === null ? '—' : ($key === Figures::SIGNATURES ? $value : '৳ '.Money::format($value));
                $mail->line(__('executive::figure.'.$key, [], 'bn').': '.$shown);
            }
        }

        return $mail->line(__('executive::mail.outro', [], 'bn'));
    }
}
