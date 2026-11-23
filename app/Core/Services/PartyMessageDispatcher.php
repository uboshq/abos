<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\SmsOutbox;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Which road a party message takes, and what happens when a road fails — step 3 (owner "ok" 23 Nov 2026,
 * sample HUXqpfhxng8823eEFrvH54, tab ঘ "কোন পথ আগে"): WhatsApp (Cloud API) → not delivered → SMS (office phone)
 * → not delivered → "ব্যর্থ", with the reason.
 *
 * ⓘ The wa.me button is not a road here: it opens WhatsApp in the person's own browser and the row stays "opened"
 *   ([[PaperShareController::whatsapp()]]).
 * ⓘ Every state move goes through [[PartyMessage::moveTo()]] — forward only.
 */
final class PartyMessageDispatcher
{
    /** settings: the three roads (sample tab ঘ switches) */
    public const WAME_ON = 'notification.party.wame';

    public const SMS_ON = 'notification.party.sms';

    public const DEFAULT_LOCALE = 'notification.party.default_locale';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SmsOutboxService $outbox,
        private readonly WhatsAppCloud $cloud,
    ) {}

    public function wameOn(): bool
    {
        return (bool) $this->settings->get(self::WAME_ON, true);
    }

    /** ⓘ the SMS road is open when it is switched on AND an office phone is set up */
    public function smsOn(): bool
    {
        return (bool) $this->settings->get(self::SMS_ON, true) && $this->outbox->hasPhone();
    }

    public function cloudOn(): bool
    {
        return $this->cloud->ready();
    }

    public function defaultLocale(): string
    {
        $l = (string) $this->settings->get(self::DEFAULT_LOCALE, 'bn');

        return in_array($l, ['bn', 'en'], true) ? $l : 'bn';
    }

    /**
     * ⭐ An automatic send (or "আবার পাঠান" on the log): the first open road, in the fixed order.
     *
     * The row comes in unsaved or saved with its body, phone, locale, template and party already set.
     * ⓘ A template may be written for one channel only; the road decides `channel` on the row.
     *
     * @param  array<string, string>  $values  the template's values, for the Cloud API's numbered parameters
     */
    public function deliver(PartyMessage $message, ?PartyMessageTemplate $template, array $values): PartyMessage
    {
        if ($this->cloudOn() && $template !== null && $this->cloud->canSend($template)) {
            return $this->viaCloud($message, $template, $values);
        }

        if ($this->smsOn() && self::isBangladeshMobile((string) $message->phone)) {
            return $this->viaSms($message);
        }

        $message->forceFill([
            'channel' => $message->channel ?: PartyMessageTemplate::WHATSAPP,
            'route' => $message->route ?: PartyMessage::CLOUD,
            'status' => PartyMessage::WAITING,
        ])->save();
        $message->moveTo(PartyMessage::FAILED, (string) __('notification::party.no_road'));

        return $message;
    }

    /** The Cloud API road — sent now, delivered/read/failed later through the webhook ([[onCloudStatus()]]). */
    public function viaCloud(PartyMessage $message, PartyMessageTemplate $template, array $values): PartyMessage
    {
        $message->forceFill([
            'channel' => PartyMessageTemplate::WHATSAPP,
            'route' => PartyMessage::CLOUD,
            'status' => PartyMessage::WAITING,
        ])->save();

        $result = $this->cloud->send((string) $message->phone, $template, (string) $message->locale, $values);

        if ($result['ok']) {
            $message->forceFill(['provider_ref' => $result['id']])->save();
            $message->moveTo(PartyMessage::SENT);

            return $message;
        }

        $message->moveTo(PartyMessage::FAILED, $result['error']);

        return $this->fallBack($message) ?? $message;
    }

    /** The office phone road — waiting until a phone takes it ([[SmsOutboxService::take()]]). */
    public function viaSms(PartyMessage $message): PartyMessage
    {
        return DB::transaction(function () use ($message): PartyMessage {
            $message->forceFill([
                'channel' => PartyMessageTemplate::SMS,
                'route' => PartyMessage::PHONE,
                'status' => PartyMessage::WAITING,
            ])->save();

            $row = $this->outbox->queue((string) $message->phone, (string) $message->body, SmsOutbox::PARTY, (int) $message->id);
            $message->forceFill(['sms_outbox_id' => $row->id])->save();

            return $message;
        });
    }

    /**
     * ⭐ "WhatsApp নেই → SMS-এ গেছে": a failed Cloud message becomes a new SMS row pointing back at it.
     * ⓘ Once only, and only from the Cloud road; a failed SMS is the end ("ব্যর্থ", with its reason).
     */
    public function fallBack(PartyMessage $failed): ?PartyMessage
    {
        if ($failed->route !== PartyMessage::CLOUD || ! $this->smsOn() || ! self::isBangladeshMobile((string) $failed->phone)
            || PartyMessage::query()->where('fallback_of_id', $failed->id)->exists()) {
            return null;
        }

        $copy = $failed->replicate(['public_id', 'status', 'error', 'sent_at', 'delivered_at', 'read_at', 'provider_ref', 'sms_outbox_id']);
        $copy->forceFill(['fallback_of_id' => $failed->id]);

        return $this->viaSms($copy);
    }

    /** ⭐ The office phone's report on a party SMS ([[SmsOutboxService::report()]]). */
    public function onSmsReport(SmsOutbox $row, string $event, ?string $reason): void
    {
        if ($row->source !== SmsOutbox::PARTY || $row->source_id === null) {
            return;
        }

        $message = PartyMessage::query()->withoutGlobalScopes()
            ->where('company_id', $row->company_id)->find($row->source_id);

        if ($message === null) {
            return;
        }

        match ($event) {
            'sent' => $message->moveTo(PartyMessage::SENT),
            'delivered' => $message->moveTo(PartyMessage::DELIVERED),
            'failed' => $message->moveTo(PartyMessage::FAILED, $reason),
            default => null,
        };
    }

    /** ⭐ Meta's status webhook — sent · delivered · read · failed ([[WhatsAppCloud::webhook()]]). */
    public function onCloudStatus(int $companyId, string $providerRef, string $status, ?string $error): void
    {
        CompanyContext::forCompany($companyId, function () use ($providerRef, $status, $error): void {
            $message = PartyMessage::query()->withoutGlobalScopes()
                ->where('company_id', CompanyContext::id())->where('provider_ref', $providerRef)->first();

            if ($message === null) {
                return;
            }

            match ($status) {
                'sent' => $message->moveTo(PartyMessage::SENT),
                'delivered' => $message->moveTo(PartyMessage::DELIVERED),
                'read' => $message->moveTo(PartyMessage::READ),
                'failed' => $this->failedCloud($message, $error),
                default => null,
            };
        });
    }

    private function failedCloud(PartyMessage $message, ?string $error): void
    {
        $message->moveTo(PartyMessage::FAILED, $error);
        $this->fallBack($message);
    }

    /** ⓘ the office SIM sends only inside Bangladesh — abroad is costly and often blocked */
    public static function isBangladeshMobile(string $phone): bool
    {
        return preg_match('/^8801[3-9]\d{8}$/', $phone) === 1;
    }
}
