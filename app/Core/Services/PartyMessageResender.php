<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\Actor;
use App\Models\DocumentShare;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ "আবার পাঠান" and "অন্য পথে পাঠান" — the log's two row actions, and the phone's resend (WhatsApp/SMS step 3, owner "ok"
 * 23 Nov 2026, sample HUXqpfhxng8823eEFrvH54, tab গ).
 *
 * ⓘ A resend is always a NEW row — the old row keeps what happened to it. The road is the dispatcher's
 *   ([[PartyMessageDispatcher::viaSms()]], [[PartyMessageDispatcher::viaCloud()]]); a wa.me row reopens wa.me (the row is
 *   "opened", nothing more is knowable).
 * ⓘ For a paper the text is rendered again from its template with the paper's LIVING link — the old text may carry a link
 *   that has died since. Without the template or the paper the old text goes as it was.
 * ⛔ Who may: a paper's row asks the paper's own print keys and reach ([[PartyMessageGuard::paper()]]) — the same right as
 *   sending it the first time; the caller asks its own door key first.
 */
final class PartyMessageResender
{
    public function __construct(
        private readonly PartyMessageDispatcher $dispatcher,
        private readonly PartyMessages $messages,
        private readonly PartyMessageGuard $guard,
    ) {}

    /** Is the row about one paper (not a party's account, as a due reminder is)? */
    public static function isPaper(PartyMessage $row): bool
    {
        return isset(PaperTrail::DOCUMENT_ROUTES[(string) $row->document_type]) && (int) $row->document_id > 0;
    }

    /** ⛔ The paper's keys and reach — 403/404 as the web share box; null for a row that is not about a paper. */
    public function paperOf(PartyMessage $row): ?Model
    {
        return self::isPaper($row) ? $this->guard->paper((string) $row->document_type, (int) $row->document_id) : null;
    }

    /**
     * The same road again.
     *
     * @return array{message: PartyMessage, url: ?string} `url` = the wa.me link to open (wa.me rows only)
     *
     * @throws ValidationException when the road is closed now
     */
    public function again(PartyMessage $row): array
    {
        $row->loadMissing('template');
        $document = $this->paperOf($row);

        return match (true) {
            $row->route === PartyMessage::WAME => $this->wame($row, $document),
            $row->route === PartyMessage::CLOUD => $this->cloud($row, $document),
            default => $this->sms($row, $document),
        };
    }

    /**
     * The other road — SMS ↔ WhatsApp (the Cloud API when ready for this template, else wa.me).
     *
     * @return array{message: PartyMessage, url: ?string}
     *
     * @throws ValidationException
     */
    public function otherRoad(PartyMessage $row): array
    {
        $row->loadMissing('template');
        $document = $this->paperOf($row);

        if ($row->channel === PartyMessageTemplate::SMS) {
            return $this->cloudReady($row) ? $this->cloud($row, $document) : $this->wame($row, $document);
        }

        return $this->sms($row, $document);
    }

    /**
     * Why "আবার পাঠান" cannot run now — null when it can (the menu greys the item and shows the reason).
     */
    public function againBlockedBy(PartyMessage $row): ?string
    {
        return match (true) {
            $row->route === PartyMessage::WAME => $this->wameOn() ? null : (string) __('notification::party_log.road_off.whatsapp'),
            $row->route === PartyMessage::CLOUD => $this->cloudReady($row) ? null : (string) __('notification::party_log.road_off.cloud'),
            default => $this->smsBlockedBy($row),
        };
    }

    /**
     * Which road "অন্য পথে পাঠান" would take now; null when none is open (with the reason).
     *
     * @return array{road: ?string, why: ?string}
     */
    public function otherRoadOf(PartyMessage $row): array
    {
        if ($row->channel === PartyMessageTemplate::SMS) {
            if ($this->cloudReady($row)) {
                return ['road' => PartyMessage::CLOUD, 'why' => null];
            }

            return $this->wameOn()
                ? ['road' => PartyMessage::WAME, 'why' => null]
                : ['road' => null, 'why' => (string) __('notification::party_log.road_off.whatsapp')];
        }

        $why = $this->smsBlockedBy($row);

        return ['road' => $why === null ? PartyMessage::PHONE : null, 'why' => $why];
    }

    private function smsBlockedBy(PartyMessage $row): ?string
    {
        return match (true) {
            ! $this->smsOn() => (string) __('notification::party_log.road_off.sms'),
            ! PartyMessageDispatcher::isBangladeshMobile((string) $row->phone) => (string) __('notification::party_log.road_off.sms_abroad'),
            default => null,
        };
    }

    /** ⓘ the road switches are read once per request, not once per row of the log */
    private function wameOn(): bool
    {
        return $this->roads['wame'] ??= $this->dispatcher->wameOn();
    }

    private function smsOn(): bool
    {
        return $this->roads['sms'] ??= $this->dispatcher->smsOn();
    }

    private function cloudOn(): bool
    {
        return $this->roads['cloud'] ??= $this->dispatcher->cloudOn();
    }

    /** @var array<string, bool> */
    private array $roads = [];

    /** @var array<int, bool> template id => may the Cloud API send it */
    private array $cloudTemplates = [];

    /** @return array{message: PartyMessage, url: ?string} */
    private function wame(PartyMessage $row, ?Model $document): array
    {
        if (! $this->wameOn()) {
            throw ValidationException::withMessages(['road' => __('notification::party_log.road_off.whatsapp')]);
        }

        $copy = $this->copyOf($row, $document);
        $copy->forceFill([
            'channel' => PartyMessageTemplate::WHATSAPP,
            'route' => PartyMessage::WAME,
            'status' => PartyMessage::OPENED,
        ])->save();

        return ['message' => $copy, 'url' => 'https://wa.me/'.$copy->phone.'?text='.rawurlencode((string) $copy->body)];
    }

    /** @return array{message: PartyMessage, url: ?string} */
    private function sms(PartyMessage $row, ?Model $document): array
    {
        $why = $this->smsBlockedBy($row);

        if ($why !== null) {
            throw ValidationException::withMessages(['road' => $why]);
        }

        return ['message' => $this->dispatcher->viaSms($this->copyOf($row, $document)), 'url' => null];
    }

    /** @return array{message: PartyMessage, url: ?string} */
    private function cloud(PartyMessage $row, ?Model $document): array
    {
        $template = $row->template;

        if (! $this->cloudReady($row) || $template === null) {
            throw ValidationException::withMessages(['road' => __('notification::party_log.road_off.cloud')]);
        }

        $copy = $this->copyOf($row, $document);

        return ['message' => $this->dispatcher->viaCloud($copy, $template, $this->valuesFor($copy, $document)), 'url' => null];
    }

    private function cloudReady(PartyMessage $row): bool
    {
        $template = $row->template;

        return $template !== null && $this->cloudOn()
            && ($this->cloudTemplates[(int) $template->id] ??= app(WhatsAppCloud::class)->canSend($template));
    }

    /**
     * A new, unsaved row — same party, number, language and template; the person who pressed the button; the text
     * rendered again with the paper's living link when the paper and the template are there.
     */
    private function copyOf(PartyMessage $row, ?Model $document): PartyMessage
    {
        $copy = new PartyMessage([
            'company_id' => $row->company_id,
            'branch_id' => $row->branch_id,
            'channel' => $row->channel,
            'document_type' => $row->document_type,
            'document_id' => $row->document_id,
            'document_no' => $row->document_no,
            'party_type' => $row->party_type,
            'party_id' => $row->party_id,
            'party_name' => $row->party_name,
            'phone' => $row->phone,
            'locale' => $row->locale,
            'template_id' => $row->template_id,
            'body' => $row->body,
            'share_id' => $row->share_id,
            'sent_by' => Actor::userId(),
            'via' => PartyMessage::BUTTON,
        ]);

        $template = $row->template;

        if ($document !== null && $template !== null) {
            $share = $this->liveShare($row, $document);
            $copy->forceFill([
                'share_id' => $share->id,
                'body' => PartyMessages::render($template->body((string) $row->locale), $this->messages->values($document, $share, (string) $row->locale)),
            ]);
        }

        return $copy;
    }

    /** @return array<string, string> the template's values for the Cloud API's numbered parameters */
    private function valuesFor(PartyMessage $copy, ?Model $document): array
    {
        if ($document === null) {
            return [];
        }

        $share = $copy->share_id !== null ? DocumentShare::query()->find($copy->share_id) : null;

        return $share === null ? [] : $this->messages->values($document, $share, (string) $copy->locale);
    }

    private function liveShare(PartyMessage $row, Model $document): DocumentShare
    {
        $old = $row->share_id !== null ? DocumentShare::query()->find($row->share_id) : null;

        if ($old !== null && $old->isAlive() && PaperTrail::isBoundToItsDocument($old)) {
            return $old;
        }

        return $this->guard->shareFor((string) $row->document_type, $document, $old?->paper);
    }
}
