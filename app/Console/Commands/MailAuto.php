<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use App\Modules\Mail\Http\Controllers\MailController;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Models\MailFestival;
use App\Modules\Mail\Models\MailSend;
use App\Modules\Mail\Models\MailTemplate;
use App\Modules\Mail\Services\MailFields;
use App\Modules\Mail\Services\MailSends;
use App\Modules\Mail\Services\MailServer;
use App\Modules\Mail\Services\MailTemplates;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Console\Command;

/**
 * ⭐ নিজে থেকে পাঠানো (নমুনা v4 পর্দা ৫–৬) — ⛔ প্রশাসক চালু করলে তবেই, প্রথমে সব বন্ধ।
 *   · বকেয়ার স্মরণ: বলা দিনে, বলা ঘণ্টায় — যে গ্রাহকের সবচেয়ে পুরনো খোলা বিল 30 দিন পেরিয়েছে;
 *   · উৎসব: উৎসবের তারিখে, বলা ঘণ্টায় — মেইল থাকা সব চালু গ্রাহককে (জাতীয় দিবসে কেবল শ্রদ্ধা)।
 * ⓘ পাঠায় কোম্পানির ঠিক করা মেইলবক্স থেকে (`mail.auto_from`); না থাকলে কিছুই যায় না।
 * ⛔ একই গ্রাহককে একই টেমপ্লেট একদিনে দুবার নয় — খাতা দেখে; প্রতিটা পাঠানো খাতায় ("নিজে থেকে")।
 */
final class MailAuto extends Command
{
    protected $signature = 'abos:mail-auto';

    protected $description = 'Mail: send the switched-on automatic mails (due reminders, festival greetings) for this hour';

    private const DAYS = ['sat' => 6, 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5];

    public function handle(MailServer $server, MailTemplates $templates, MailFields $fields, MailSends $sends, SettingsService $settings): int
    {
        $sent = 0;
        $skipped = [];

        foreach (Company::query()->where('is_active', true)->pluck('id') as $companyId) {
            CompanyContext::forCompany((int) $companyId, function () use (&$sent, &$skipped, $server, $templates, $fields, $sends, $settings, $companyId) {
                $from = (string) $settings->get('mail.auto_from', '');
                $box = $from !== '' ? MailAccount::query()->where('public_id', $from)->first() : null;

                if (! $box || ! $box->isUsable()) {
                    return;
                }

                $company = (string) (Company::query()->whereKey($companyId)->value('name_en') ?? '');

                foreach (MailTemplate::query()->whereNull('user_id')->where('active', true)->whereNotNull('auto')->get() as $t) {
                    $auto = (array) $t->auto;

                    if (! ($auto['on'] ?? false) || now()->format('H:i') < (string) ($auto['at'] ?? '10:00') || now()->hour !== (int) substr((string) ($auto['at'] ?? '10:00'), 0, 2)) {
                        continue;
                    }

                    foreach ($this->recipients($t, $auto) as $c) {
                        if (MailSend::query()->where('template_id', $t->id)->where('to', mb_strtolower((string) $c->email))->whereDate('sent_at', now()->toDateString())->exists()) {
                            continue;
                        }

                        $mail = $templates->render($t, $fields->forCustomer($c) + $fields->values(new \App\Models\User(['name' => $company]), null), $company);

                        // ⛔ ভরেনি এমন ঘর থাকলে এই গ্রাহককে যায় না — বাদ পড়ার কারণ খাতায় নয়, লগে (NEXUS-এর পর্যালোচনা R1)
                        if (($gaps = MailFields::missing($mail['subject'], $mail['html'])) !== []) {
                            $skipped[] = $c->id;
                            $this->warn("skip customer {$c->id} ({$t->key}): ".implode(', ', $gaps));

                            continue;
                        }

                        // ⓘ ধীরে ধীরে — মিনিটে 30টার বেশি নয় (NEXUS-এর পর্যালোচনা S1): মেইল সার্ভার একসাথে অনেক মেইলকে স্প্যাম ধরে।
                        //   সময় কোম্পানির ঘড়িতে (config app.timezone = Asia/Dhaka)।
                        if ($sent > 0) {
                            \Illuminate\Support\Sleep::for(2)->seconds();
                        }

                        try {
                            $id = $server->send($box, ['to' => [(string) $c->email], 'subject' => $mail['subject'] ?: $t->name,
                                'html' => MailController::safe($mail['html'], true), 'fromName' => $box->sender_name ?: $company]);
                            $sends->record($box, null, ['to' => [mb_strtolower((string) $c->email)], 'subject' => $mail['subject'] ?: $t->name,
                                'messageId' => $id, 'template_id' => $t->id, 'auto' => true]);
                            $templates->used($t);
                            $sent++;
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                }
            });
        }

        $this->info("sent {$sent}, skipped ".count($skipped).' (fields not filled)');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $auto
     * @return iterable<Customer>
     */
    private function recipients(MailTemplate $t, array $auto): iterable
    {
        $customers = Customer::query()->whereNotNull('email')->where('email', '!=', '')->where('is_active', true);

        if (($auto['rule'] ?? '') === 'festival') {
            $fest = MailFestival::query()->where('key', substr((string) $t->key, 5))->where('active', true)->first();

            return $fest && $fest->on_date?->isToday() ? $customers->get() : [];
        }

        if (now()->dayOfWeek !== (self::DAYS[(string) ($auto['day'] ?? 'mon')] ?? 1)) {
            return [];
        }

        $cut = now()->subDays(30)->toDateString();

        return $customers->get()->filter(fn (Customer $c) => SalesInvoice::query()->where('customer_id', $c->id)->posted()->withCollected()
            ->whereDate('trx_date', '<=', $cut)->get()->contains(fn ($b) => bccomp((string) $b->dueAmount(), '0', 2) > 0));
    }
}
