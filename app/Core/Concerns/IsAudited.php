<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Engines\Audit\AuditEngine;
use App\Models\AuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * এই মডেলের প্রতিটা পরিবর্তন অডিটে যায়।
 *
 * ── কেন মডেলের ঘটনা থেকে, সেবা স্তর থেকে নয় ──────────────────────────
 * সেবা স্তরে লিখলে যে পথটা সেবা হয়ে যায় না — সিডার, কনসোল কমান্ড,
 * টিঙ্কার, বা কোনো তাড়াহুড়োর কোড — সেই পথে কিছুই লগ হত না। আর ঠিক
 * তাড়াহুড়োর পরিবর্তনগুলোই পরে খোঁজা হয়।
 *
 * ── যা এই ট্রেইট ধরতে পারে না ───────────────────────────────────────
 * "অনুমোদিত" বা "বাতিল" — ঘরের পরিবর্তন দেখে এগুলো সাধারণ সম্পাদনা
 * থেকে আলাদা করা যায় না। সেগুলো সেবা স্তর নিজে বলে দেয়:
 *
 *     $document->auditAction(AuditTrail::CANCELLED, $reason);
 */
trait IsAudited
{
    public static function bootIsAudited(): void
    {
        /*
         * তৈরিতে ঘটনাটা লেখা হয়, ঘর ধরে ধরে মান নয়।
         *
         * ── কেন নয় ──────────────────────────────────────────────────
         * নতুন রেকর্ডের "নতুন মান" রেকর্ডটাতেই আছে, আর রেকর্ড কখনো
         * শক্ত করে মোছা হয় না (নিয়ম ৫) — তাই কপিটা কোনো নতুন তথ্য
         * দিত না, শুধু প্রতিটা তৈরিতে পঁচিশটা বাড়তি সারি জমাত।
         *
         * ঘর ধরে ধরে ইতিহাস দরকার হয় *পরিবর্তনে*: "দর ১২০ → ১২৫"।
         * সেটাই এই টেবিলটার কাজ, আর সেখানেই সে পুরোটা লেখে।
         */
        static::created(function (Model $model) {
            app(AuditEngine::class)->record($model, AuditTrail::CREATED);
        });

        static::updated(function (Model $model) {
            $engine = app(AuditEngine::class);

            /*
             * নরম-মোছা ফেরানো আলাদা ঘটনা।
             *
             * Eloquent-এ restore() একটা সাধারণ আপডেট (deleted_at → null),
             * তাই আলাদা না করলে তালিকায় "সম্পাদনা" দেখাত — অথচ কাজটা
             * ছিল মুছে ফেলা একটা রেকর্ড ফিরিয়ে আনা।
             */
            $changes = $engine->changesOf($model);

            if (array_key_exists('deleted_at', $changes) && $changes['deleted_at'][1] === null) {
                $engine->record($model, AuditTrail::RESTORED);

                return;
            }

            /*
             * অবস্থা বদলালে কাজটার নাম অবস্থা থেকেই আসে।
             *
             * ── কেন এখানে, সেবা স্তরে হাতে নয় ──────────────────────
             * আঠারোটা সেবায় "এটা বাতিল" বলে দেওয়া যেত, কিন্তু উনিশতম
             * সেবাটা লেখার দিনে কেউ ভুলত — আর তখন ওই ডকুমেন্টের বাতিল
             * তালিকায় সাধারণ সম্পাদনা হয়ে বসত। এখানে থাকলে ভোলার
             * সুযোগই নেই।
             *
             * কারণটাও সাথে যায়: বাতিলের কারণ ডকুমেন্টেই লেখা থাকে,
             * তাই অডিটে আলাদা করে পাঠাতে হয় না।
             */
            $engine->record(
                $model,
                $engine->actionForStatus($changes) ?? AuditTrail::UPDATED,
                $changes,
                $model->getAttribute('cancel_reason'),
            );
        });

        static::deleted(function (Model $model) {
            $engine = app(AuditEngine::class);

            /*
             * নরম-মোছায় deleted_at বসে, তাই updated ঘটনাও চলে —
             * কিন্তু এখানে আসল কাজটা "মোছা", আর সেটাই লেখা হয়।
             */
            $engine->record($model, AuditTrail::DELETED, [], $model->getAttribute('cancel_reason'));
        });
    }

    /**
     * ব্যবসার একটা কাজ অডিটে বসানো — অনুমোদন, বাতিল, নিশ্চিতকরণ।
     *
     * সেবা স্তর ডাকে, কারণ কাজটার নাম কেবল সেই-ই জানে।
     */
    public function auditAction(string $action, ?string $reason = null): void
    {
        app(AuditEngine::class)->recordAction($this, $action, $reason);
    }

    /**
     * এই রেকর্ডের ইতিহাস — নতুন আগে।
     *
     * সম্পর্ক নয়, কোয়েরি: অডিটের সারিগুলো polymorphic, আর প্রতিটা
     * মডেলে একটা morphMany বসালে মডেলগুলো অডিটের গড়ন জানতে বাধ্য হত।
     *
     * @return Builder<AuditTrail>
     */
    public function auditTrail()
    {
        return AuditTrail::query()
            ->forRecord(static::class, (int) $this->getKey())
            ->with(['changes', 'user'])
            ->orderByDesc('id');
    }
}
