<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\SyncDevice;

/**
 * ⭐ রিয়েল-টাইম সিঙ্ক, ফোনের দিক — মালিক, ১০ অক্টোবর ২০২৬: "Real Time sync app r web dutotei koro", পথ (ক)।
 *
 * ── কী করে ──────────────────────────────────────────────────────────────
 * কোনো কোম্পানিতে কিছু লেখা হলে ([[NudgePhonesAfterWrite]]) ঐ কোম্পানির প্রতিটা ফোনে একটা নীরব FCM
 * বার্তা যায় — `{abos: sync}`; খোলা অ্যাপ শুনে তখনই সিঙ্ক চালায় ([[AutoSync]])। আলাদা কোনো ইঞ্জিন নয়:
 * লাইভ cPanel-এ সারাক্ষণ-চালু প্রক্রিয়া চলে না, আর পুশ আগে থেকেই আছে ([[FcmSender]])।
 *
 * ── ⓘ একসাথে অনেক বদলে একটাই ডাক ─────────────────────────────────────
 * একটা বিল সংরক্ষণে দশটা সারি বদলায়, আর এক মিনিটে দশটা বিল হয়। ⚠️ প্রতিটায় ডাকলে ফোন জাগতেই থাকত।
 * তাই কোম্পানিপ্রতি [[WINDOW]] সেকেন্ডে একবার; জানালার ভেতরের পরের বদল ধরবে পরের ডাক বা অ্যাপের
 * দশ মিনিটের নিজের গোল।
 *
 * ── ⛔ যা যায় না ──────────────────────────────────────────────────────────
 * কোনো ব্যবসার তথ্য নয় — কোন কাগজ, কত টাকা, কার নাম, কিছুই না। ফোন নিজের অনুমতিতে নিজেই টেনে নেয়, তাই
 * যিনি যা দেখার অধিকার রাখেন না তা এই ডাকে তাঁর কাছে পৌঁছায় না।
 */
final class SyncNudge
{
    /** কোম্পানিপ্রতি এই কয় সেকেন্ডে একটাই ডাক। */
    public const WINDOW = 15;

    public const DATA = ['abos' => 'sync'];

    public function __construct(private readonly FcmSender $fcm) {}

    /**
     * এই কোম্পানিতে কিছু বদলেছে — জানালা খোলা থাকলে উত্তর পাঠানোর পরে ডাক।
     *
     * ⓘ উত্তরের পরে, যাতে FCM ধীর হলেও পর্দা অপেক্ষা না করে। ⓘ কখনো ছোড়ে না — ডাক না গেলে অ্যাপের
     * নিজের গোল আছে।
     */
    public function touch(int $companyId): void
    {
        try {
            // ⓘ পুশের চাবি বসানো নেই (ডেমো, পরীক্ষা, স্থানীয় যন্ত্র) — কিছুই নয়, একটা প্রশ্নও নয়
            if ((string) config('services.firebase.credentials') === ''
                || ! cache()->add('sync-nudge:'.$companyId, true, self::WINDOW)) {
                return;
            }

            dispatch(fn () => app(self::class)->send($companyId))->afterResponse();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return int কয়টা ফোনে গেল */
    public function send(int $companyId): int
    {
        $sent = 0;

        $devices = SyncDevice::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('push_token')
            ->get();

        foreach ($devices as $device) {
            $result = $this->fcm->sendData((string) $device->push_token, self::DATA);

            if ($result === FcmSender::OFF) {
                return 0;
            }

            if ($result === FcmSender::GONE) {
                $device->forceFill(['push_token' => null, 'push_token_at' => null])->save();
            }

            $sent += $result === FcmSender::SENT ? 1 : 0;
        }

        return $sent;
    }
}
