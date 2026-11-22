<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\PartyMessageDispatcher;
use App\Core\Services\PartyMessageGuard;
use App\Core\Services\PartyMessageResender;
use App\Core\Services\PartyMessages;
use App\Core\Services\PhoneModules;
use App\Core\Services\PhonePaperDoor;
use App\Http\Controllers\Controller;
use App\Models\DocumentShare;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\SmsGatewayPhone;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ পার্টিকে WhatsApp ও SMS — ফোনের দরজা (ধাপ ৩, মালিকের "ok" ২৩ নভেম্বর ২০২৬, নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব ক ও গ;
 * চুক্তি: docs/"Contract — পার্টিকে WhatsApp ও SMS (অ্যাপ).md")।
 *
 * ── ⭐ ওয়েবের একই পাহারা, নকল নয় ─────────────────────────────────────────
 * কাগজটা ফোনের নামে খোঁজা ([[PhonePaperDoor::find()]] — `type` = মডেলের ইংরেজি নাম, `id` = public_id), তারপর ওয়েবের
 * "পাঠান" বাক্সের পাহারা ([[PartyMessageGuard]]): ছাপার চাবি (৪০৩), দেখার নাগাল (৪০৪), লেখা আর নম্বর (৪২২)।
 * ⛔ এখানে আলাদা নিয়ম লিখলে একদিন ফোন এমন কাগজ পাঠাত যা ওয়েবে পাঠানো যায় না।
 *
 * ── ⓘ মডিউলের সুইচ ─────────────────────────────────────────────────────
 * কাগজের নিজের মডিউল ফোনে বন্ধ হলে ৪০৩ `module_off` — ছাপার দরজার একই নিয়ম ([[DocumentApiController]])।
 *
 * ── ⓘ রুটে `can:` নেই, ইচ্ছা করে ─────────────────────────────────────────
 * কাগজ সতেরো রকম, প্রতিটার চাবি তার ওয়েবের ছাপার রুটের নিজের — দরজা একটাই, চাবি পদ্ধতির ভেতরে।
 */
final class PartyMessageApiController extends Controller
{
    /** ⓘ কাগজের "পার্টিকে জানানো" ঘরে সর্বশেষ এতগুলো */
    public const LIMIT = 20;

    public function __construct(
        private readonly PhonePaperDoor $door,
        private readonly PartyMessageGuard $guard,
        private readonly PartyMessages $messages,
        private readonly PartyMessageDispatcher $dispatcher,
    ) {}

    /**
     * `GET /party-messages/compose?type=&id=&channel=` — পাঠানোর পপআপের সব: পার্টি, নম্বর, ঐ পথের লেখা (দুই ভাষায় আসল মান বসানো),
     * কোন পথ খোলা, কোন অফিসের ফোন।
     */
    public function compose(Request $request): JsonResponse
    {
        $channel = $request->validate(['channel' => ['required', Rule::in(PartyMessageTemplate::CHANNELS)]])['channel'];
        [$document, $share] = $this->paper($request);

        $party = $this->messages->partyOf($document);
        $phones = array_map(fn (array $p) => ['number' => $p['phone'], 'shown' => $p['shown'], 'label' => $p['label']],
            $this->messages->phonesOf($document));

        if ($party['phone'] !== null && ! in_array($party['phone'], array_column($phones, 'number'), true)) {
            array_unshift($phones, ['number' => $party['phone'], 'shown' => PartyMessages::shown($party['phone']),
                'label' => (string) __('notification::party_log.party_phone')]);
        }

        $smsOn = $this->dispatcher->smsOn();

        return $this->noStore(response()->json([
            'data' => [
                'party' => ['name' => $party['name']],
                'phones' => $phones,
                'templates' => $this->messages->choicesFor($document, $share, $channel),
                'locale' => $this->dispatcher->defaultLocale(),
                'wame_on' => $this->dispatcher->wameOn(),
                'sms_on' => $smsOn,
                'office_phone' => $smsOn ? SmsGatewayPhone::query()->where('is_active', true)
                    ->orderByDesc('last_seen_at')->orderBy('id')->value('name') : null,
            ],
        ]));
    }

    /**
     * `POST /party-messages/whatsapp` — খাতায় সারি ("খোলা হয়েছে", পথ wa.me), আর ফোন যে wa.me লিংক খুলবে।
     * ⓘ ওয়েবের [[PaperShareController::whatsapp()]]-এর একই কাজ; ফোন ব্রাউজারের বদলে নিজে লিংকটা খোলে।
     */
    public function whatsapp(Request $request): JsonResponse
    {
        abort_unless($this->dispatcher->wameOn(), 403);

        [$document, $share] = $this->paper($request);
        [$template, $phone, $locale] = $this->guard->choice($request->all(), $document, $share, PartyMessageTemplate::WHATSAPP);

        $message = $this->messages->record($document, $share, $template, $locale, $phone);
        $message->forceFill(['channel' => PartyMessageTemplate::WHATSAPP, 'route' => PartyMessage::WAME,
            'status' => PartyMessage::OPENED, 'via' => PartyMessage::BUTTON])->save();

        return response()->json([
            'url' => 'https://wa.me/'.$phone.'?text='.rawurlencode((string) $message->body),
            'data' => $this->row($message, false),
        ], 201);
    }

    /**
     * `POST /party-messages/sms` — অফিসের ফোনের সারিতে, পাঠানোর একমাত্র দরজা দিয়ে ([[PartyMessageDispatcher::viaSms()]])।
     * ⛔ কেবল বাংলাদেশের মোবাইলে; পথ বন্ধ বা অফিসের ফোন নেই তো ৪২২, কারণসহ।
     */
    public function sms(Request $request): JsonResponse
    {
        [$document, $share] = $this->paper($request);
        [$template, $phone, $locale] = $this->guard->choice($request->all(), $document, $share, PartyMessageTemplate::SMS);

        if (! PartyMessageDispatcher::isBangladeshMobile($phone)) {
            throw ValidationException::withMessages(['phone' => __('core.print.sms_phone_bad')]);
        }

        if (! $this->dispatcher->smsOn()) {
            throw ValidationException::withMessages(['channel' => __('notification::sms.no_gateway')]);
        }

        $message = $this->dispatcher->viaSms(
            $this->messages->record($document, $share, $template, $locale, $phone, PartyMessageTemplate::SMS));

        return response()->json(['data' => $this->row($message->refresh(), false)], 201);
    }

    /**
     * `GET /party-messages?type=&id=` — ঐ কাগজের পার্টিকে কী গেল, প্রতিটার অবস্থা (অ্যাপের "পার্টিকে জানানো" ঘর)।
     * ⓘ খোলার চাবি পাঠানোরই — যিনি কাগজটা পাঠাতে পারেন, তিনিই দেখেন কী গেছে (ওয়েবের খাতার চাবি এখানে লাগে না)।
     */
    public function index(Request $request): JsonResponse
    {
        [$document, , $documentType] = $this->paper($request, withShare: false);

        $rows = PartyMessage::query()
            ->where('document_type', $documentType)
            ->where('document_id', (int) $document->getKey())
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $fellBack = PartyMessage::query()->whereIn('fallback_of_id', $rows->pluck('id')->all() ?: [0])
            ->pluck('fallback_of_id')->map(fn ($id) => (int) $id)->flip();

        return $this->noStore(response()->json([
            'data' => $rows->map(fn (PartyMessage $m) => $this->row($m, $fellBack->has((int) $m->id)))->values(),
        ]));
    }

    /**
     * `POST /party-messages/{public_id}/resend` — একই পথে আবার, নতুন সারি ([[PartyMessageResender::again()]]); wa.me-র সারিতে
     * আবার wa.me লিংক ফেরে।
     * ⛔ কাগজের সারিতে কাগজের ছাপার চাবি আর নাগাল (পাঠানোর একই অধিকার); পার্টির হিসাবের সারিতে ওয়েবের খাতার চাবি।
     */
    public function resend(Request $request, string $publicId, PartyMessageResender $resender): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // ⓘ শাখার দেয়াল মডেলের নিজের ([[ScopedToUserBranch]]) — বাইরের সারি ৪০৪
        $row = PartyMessage::query()->wherePublicId($publicId)->first() ?? abort(404);

        if (PartyMessageResender::isPaper($row)) {
            $this->moduleOfPaperIsOn((string) $row->document_type);
        } else {
            abort_unless($user->can('notification.party_messages'), 403);
        }

        $sent = $resender->again($row);

        return response()->json([
            'url' => $sent['url'],
            'data' => $this->row($sent['message']->refresh(), false),
        ], 201);
    }

    /**
     * ফোনের নামে কাগজ → ওয়েবের পাহারা → জীবিত লিংক।
     *
     * @return array{0: Model, 1: ?DocumentShare, 2: string} [কাগজ, লিংক, ছাপার তালিকার ধরন]
     */
    private function paper(Request $request, bool $withShare = true): array
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:60'],
            'id' => ['required', 'string', 'max:64'],
        ]);

        // ⓘ ফোনের নামে খোঁজা — মডেলের নিজের দেয়ালের ভিতরে; না পেলে ৪০৪ (অচেনা ধরন, অন্য কোম্পানি, নাগালের বাইরে)
        [$documentType, $class, $found] = $this->door->find((string) $data['type'], (string) $data['id']) ?? abort(404);

        $phone = app(PhoneModules::class);
        $phone->refuseUnlessReachable($phone->moduleOfClass($class)?->code);

        // ⛔ ওয়েবের পাঠানোর পাহারা — ছাপার চাবি (৪০৩), ছাপার দরজার চোখে নাগাল (৪০৪)
        $document = $this->guard->paper($documentType, (int) $found->getKey());

        return [$document, $withShare ? $this->guard->shareFor($documentType, $document) : null, $documentType];
    }

    private function moduleOfPaperIsOn(string $documentType): void
    {
        foreach ($this->door->printable() as [$type, $class]) {
            if ($type === $documentType) {
                $phone = app(PhoneModules::class);
                $phone->refuseUnlessReachable($phone->moduleOfClass($class)?->code);

                return;
            }
        }
    }

    /**
     * একটা সারি — অ্যাপের ঘরে যা দেখায়: পথ, অবস্থা, সময়, কারণ।
     *
     * @return array<string, mixed>
     */
    private function row(PartyMessage $m, bool $fellBack): array
    {
        $at = match ($m->status) {
            PartyMessage::READ => $m->read_at,
            PartyMessage::DELIVERED => $m->delivered_at,
            PartyMessage::SENT => $m->sent_at,
            default => $m->created_at,
        };

        return [
            'id' => (string) $m->public_id,
            'channel' => (string) $m->channel,
            'route' => (string) $m->route,
            'via' => (string) $m->via,
            'status' => (string) $m->status,
            'time' => $at instanceof Carbon ? $at->toIso8601String() : null,
            'created_at' => $m->created_at?->toIso8601String(),
            'phone' => (string) $m->phone,
            'reason' => $m->status === PartyMessage::FAILED ? ($m->error !== null && $m->error !== '' ? (string) $m->error : null) : null,
            'fell_back' => $fellBack,
        ];
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
