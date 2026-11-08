<?php

declare(strict_types=1);

namespace App\Modules\Executive\Notifications;

use App\Core\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ⭐ প্রতিদিনের সারাংশের রাতের ইমেইল — মালিক, ৭ নভেম্বর ২০২৬ ("email e daw", নমুনা "ok"; [[DailySummary]]):
 * বিষয়ের লাইনেই দিনের মূল খবর, ভেতরে ছয় অঙ্ক আর "নজরে রাখুন", সাথে পুরো রিপোর্টের A4 PDF, আর সফটওয়্যারে খোলার বোতাম।
 * ⓘ এক কোম্পানি, এক ইমেইল ([[DailySummaryMailCommand]])।
 */
final class DailySummaryMail extends Notification
{
    /**
     * @param  array<string, mixed>  $summary  [[DailySummary::for()]]
     */
    public function __construct(
        private readonly array $summary,
        private readonly string $pdf,
        private readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->summary;
        $t = fn (string $k, array $r = []) => __('executive::daily.'.$k, $r, 'bn');
        $m = fn ($v) => Money::format((string) $v);
        $date = $s['date']->format('d-m-Y');
        $company = (string) ($s['company']?->name('bn') ?? '');

        $mail = (new MailMessage)
            ->subject(__('executive::mail.daily_subject', [
                'date' => $date, 'company' => $company,
                'delivered' => $m($s['kpis']['delivered']['value']),
                'collected' => $m($s['kpis']['collected']['value']),
                'reorder' => $s['kpis']['reorder']['value'],
            ], 'bn'))
            ->greeting($t('title').' — '.$date.' · '.$company)
            ->line(__('executive::mail.daily_intro', [], 'bn'));

        foreach (['delivered', 'collected', 'fare', 'received', 'cash_left'] as $key) {
            $mail->line('**'.$t('k_'.$key).':** ৳ '.$m($s['kpis'][$key]['value']));
        }
        $mail->line('**'.$t('k_reorder').':** '.$t('products', ['count' => $s['kpis']['reorder']['value']]));

        $mail->line('—')
            ->line($t('fare_outstanding').': ৳ '.$m($s['fare']['outstanding']))
            ->line($t('crossed', ['count' => $s['watch']['crossed_customers']]).': ৳ '.$m($s['watch']['crossed_amount']))
            ->line($t('pending', ['count' => $s['watch']['pending_count']]).': ৳ '.$m($s['watch']['pending_amount']))
            ->line($t('profit').': ৳ '.$m($s['watch']['profit']))
            ->action(__('executive::mail.daily_open', [], 'bn'), $this->url)
            ->attachData($this->pdf, 'daily-summary-'.$s['date']->toDateString().'.pdf', ['mime' => 'application/pdf'])
            ->salutation(__('executive::mail.daily_footer', [], 'bn'));

        return $mail;
    }
}
