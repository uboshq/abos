<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\NotificationService;
use App\Models\Notification;
use App\Models\User;

/**
 * ⭐ ফোনের খবর চাপলে কোথায় যায় — ঘণ্টার তালিকা ([[NotificationApiController]]) আর দুই পুশ ([[MobilePushChannel]],
 * [[TrackingNotices]]) একই উত্তর দেয় (চুক্তি: `docs/Contract — ফোনের খবর কোথায় খোলে.md`)।
 *
 * ── ⛔ নিয়ম ───────────────────────────────────────────────────────────
 *   · কেবল যোগ — পুরনো ঘর আর পুশের পুরনো `data` যেমন ছিল তেমন; পুরনো অ্যাপ নতুন ঘর চেনে না, তাই কিছু ভাঙে না।
 *   · কাগজ খোলা না গেলে ([[NotificationService::mayOpen()]]) কাগজের `public_id` বাইরে যায় না, আর গন্তব্য `list` — অ্যাপ খবরটাই দেখায়।
 *   · কাগজের নাম নতুন তালিকা নয় — ড্রিলের উৎস-নাম ([[DrillResolver]], `drillSourceType()`), যা খবরে আগে থেকেই বসে (`subject_type`)।
 *   · ওয়েবের ঠিকানা ভেঙে পথ বের করা নয় — অ্যাপ `destination` দেখে চলে।
 */
final class PhoneNoticeTarget
{
    /** কাগজটা খোলো — `paper.type` + `paper.public_id` */
    public const PAPER = 'paper';

    /** সইয়ের তালিকা খোলো — খবরটা কারও সইয়ের অপেক্ষা বা সইয়ের ফল */
    public const APPROVAL = 'approval';

    /** বিক্রির ট্র্যাকিং খোলো — চালান (`delivery_challan`) বা আদেশ (`sales_order`) */
    public const TRACKING = 'tracking';

    /** খোলার কিছু নেই বা খোলার অনুমতি নেই — খবরটাই দেখাও */
    public const LIST = 'list';

    /**
     * ⭐ যে ধরনগুলোর ফোন-পুশ নিজের পথে যায় — [[TrackingNotices]] নিজেই `SendPushToUser` পাঠায় (`open: tracking`, পুরনো অ্যাপ এটাই
     * চেনে)। ⛔ তাই বিজ্ঞপ্তির মোবাইল-পুশ মাধ্যম এগুলো আবার পাঠায় না — নইলে নিয়মে মোবাইল পুশ চালু থাকলে একই মানুষ দুইটা পুশ পেতেন
     * ([[ATrackingNoticePushesOnceTest]])। ⓘ কোর মডিউল চেনে না, তাই নাম লেখা — [[NotificationKinds]]-এর মতোই; দাবিটা মেলায়
     * এগুলো TrackingNotices-এর ধ্রুবকের সাথে এক কি না।
     */
    public const PUSHES_ITSELF = ['sales.delivery_stage', 'sales.order_credit_held', 'sales.order_awaits_you'];

    /** এই ধরনগুলোর গন্তব্য ট্র্যাকিং — সইয়ের অপেক্ষার আদেশ ছাড়া (সেটা সইয়ের তালিকায়) */
    private const TRACKED = ['sales.delivery_stage', 'sales.order_credit_held'];

    /** সইয়ের ফল — শ্রেণি "অনুমোদন", কিন্তু সইয়ের তালিকায় খোলার কিছু নেই */
    private const RESULTS = ['approval.approved', 'approval.rejected'];

    public function __construct(
        private readonly DrillResolver $drill,
        private readonly NotificationService $notices,
    ) {}

    /**
     * ঘণ্টার তালিকার নতুন ঘরগুলো।
     *
     * ⭐ `closed` — খোলা না গেলে কেন: `gone` (কাগজটা আর নেই) বা `reach` (নাগালের বাইরে), খোলা গেলে `null` (a4-এর অনুরোধ, fe,
     * ২৪ অক্টোবর ২০২৬)। ⓘ কেবল চাবি, লেখা নয় — অ্যাপ নিজের ভাষার ফাইল থেকে ওয়েবের একই বাক্য দেখায়। ⛔ `may_open` আর `closed`
     * একই উত্তর থেকে ([[NotificationService::whyClosed()]]), তাই কখনো দুই কথা বলে না।
     *
     * @return array{may_open: bool, closed: ?string, paper: array{type: ?string, public_id: ?string}, destination: string}
     */
    public function of(Notification $notice, User $user): array
    {
        $closed = $this->notices->whyClosed($notice, $user);
        $mayOpen = $closed === null;
        $type = $this->paperType($notice);
        $publicId = $mayOpen ? $this->paperPublicId($notice) : null;

        return [
            'may_open' => $mayOpen,
            'closed' => $closed,
            'paper' => ['type' => $type, 'public_id' => $publicId],
            'destination' => $this->destination($notice, $mayOpen, $publicId),
        ];
    }

    /**
     * ⭐ পুশের নতুন `data` ঘর — ⛔ সব মান লেখা (FCM-এর নিয়ম), না থাকলে ফাঁকা লেখা, `null` নয়।
     *
     * @return array{notification_id: string, type: string, paper_type: string, paper_id: string, destination: string, closed: string}
     */
    public function pushFields(Notification $notice, ?User $user = null): array
    {
        $user ??= User::query()->withoutGlobalScope('company')->find($notice->user_id);
        $target = $user === null
            ? ['closed' => null, 'paper' => ['type' => $this->paperType($notice), 'public_id' => null], 'destination' => self::LIST]
            : $this->of($notice, $user);

        return [
            // ⛔ `notification_id`, `notification` নয় — FCM-এর পুরনো নিয়মে `from`/`notification`/`message_type` সংরক্ষিত শব্দ
            'notification_id' => (string) ($notice->public_id ?? ''),
            'type' => (string) ($notice->type ?? ''),
            'paper_type' => (string) ($target['paper']['type'] ?? ''),
            'paper_id' => (string) ($target['paper']['public_id'] ?? ''),
            'destination' => $target['destination'],
            // ⓘ খোলা গেলে ফাঁকা লেখা — FCM-এ null চলে না
            'closed' => (string) ($target['closed'] ?? ''),
        ];
    }

    /**
     * ⛔ এই খবরের ফোন-পুশ নিজের পথে গেছে কি — মাধ্যম তখন আর পাঠায় না। ⓘ ছুটির বদলির কপি ([[Notification::$on_behalf_of]])
     * TrackingNotices-এর পুশ পায় না, তাই সেটা মাধ্যমেই যায়।
     */
    public static function pushedItself(Notification $notice): bool
    {
        return in_array((string) $notice->type, self::PUSHES_ITSELF, true) && $notice->on_behalf_of === null;
    }

    private function destination(Notification $notice, bool $mayOpen, ?string $publicId): string
    {
        return match (true) {
            ! $mayOpen => self::LIST,
            // ⓘ সইয়ের ফল (অনুমোদিত/ফেরত) যিনি চেয়েছিলেন তাঁর কাছে যায় — তাঁর সই বাকি নেই, তাই কাগজটাই
            $notice->category === 'approval' && ! in_array((string) $notice->type, self::RESULTS, true) => self::APPROVAL,
            in_array((string) $notice->type, self::TRACKED, true) && $publicId !== null => self::TRACKING,
            $publicId !== null => self::PAPER,
            default => self::LIST,
        };
    }

    /**
     * ⛔ কাগজের ধরন কেবল ড্রিলের নাম — খবরে ড্রিল-ছাড়া কাগজের (ডকুমেন্ট, বীমা পলিসি) মডেলের নাম বসে কেবল "কাগজটা আছে কি"
     * দেখার জন্য ([[NotificationService::whyClosed()]]); সেটা ফোনের চুক্তির নাম নয়, তাই বাইরে যায় না (২৪ অক্টোবর ২০২৬)।
     */
    private function paperType(Notification $notice): ?string
    {
        $type = $notice->subject_type === null ? null : (string) $notice->subject_type;

        return $type !== null && $this->drill->knows($type) ? $type : null;
    }

    /** কাগজের `public_id` — কোম্পানির দেয়াল মেনে, মাথার শাখা না মেনে ([[NotificationService::mayOpen()]]-এর একই খোঁজ) */
    private function paperPublicId(Notification $notice): ?string
    {
        if ($notice->subject_type === null || $notice->subject_id === null) {
            return null;
        }

        $class = $this->drill->map()[(string) $notice->subject_type] ?? null;

        if ($class === null) {
            return null;
        }

        $paper = $class::query()->withoutGlobalScope('user-branch')->find($notice->subject_id);
        $publicId = $paper?->getAttribute('public_id');

        return $publicId === null || $publicId === '' ? null : (string) $publicId;
    }
}
