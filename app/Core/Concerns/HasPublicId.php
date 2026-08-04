<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * বাইরের জগতের জন্য একটা UUID — ভেতরের কাজ চলে bigint দিয়েই।
 *
 * কেন দুইটা কী:
 *
 * MySQL-এ প্রাইমারি কী মানে ক্লাস্টার্ড ইনডেক্স। এলোমেলো UUID প্রাইমারি
 * কী দিলে প্রতিটা ইনসার্ট বি-ট্রির যেকোনো জায়গায় গিয়ে পড়ে, পেজ ভাঙে,
 * আর ইনডেক্স কয়েক গুণ বড় হয়। প্রতিটা ফরেন কী ১৬ বাইট, প্রতিটা JOIN তত
 * ভারী। ledger টেবিলে বছরে লক্ষ সারি বসবে আর ট্রায়াল ব্যালেন্স ও
 * বয়সভিত্তিক রিপোর্ট সেগুলোর উপর GROUP BY করবে — দামটা ঠিক ওখানেই।
 * সার্ভার অফিসের একটা মেশিন, ক্লাউড ক্লাস্টার নয়।
 *
 * তাই ভেতরে bigint, বাইরে UUID। বাইরের কেউ — API, webhook, ইভেন্ট,
 * ইমপোর্ট/এক্সপোর্ট, ইন্টিগ্রেশন — কখনো `id` দেখে না।
 *
 * UUIDv7, v4 নয়: v7 সময়-ক্রমানুসারী, তাই ইউনিক ইনডেক্সটাও ক্রমে ভরে,
 * এলোমেলোভাবে নয়। v4 দিলে সেই একই বি-ট্রি ভাঙার সমস্যা ফিরে আসত, শুধু
 * প্রাইমারি কী-র বদলে সেকেন্ডারি ইনডেক্সে।
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (Model $model) {
            if (blank($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', (string) Str::uuid7());
            }
        });
    }

    /**
     * বাইরের কী দিয়ে খোঁজা।
     *
     * getRouteKeyName() ইচ্ছাকৃতভাবে বদলানো হয়নি: ওয়েব রুট আগের মতোই
     * id দিয়ে চলে। বদলালে প্রতিটা লিংক, প্রতিটা রিডাইরেক্ট ও প্রতিটা
     * টেস্ট একদিনে ভাঙত, অথচ লাভ কিছুই হত না — ব্রাউজারের ঠিকানায়
     * সংখ্যা দেখা কোনো সমস্যা নয়। সমস্যা হল বাইরের সিস্টেমকে সংখ্যা
     * দেওয়া, কারণ সেটা গোনা যায় ("আমার আগে কতজন গ্রাহক ছিল")।
     */
    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where($this->getTable().'.public_id', $publicId);
    }

    public static function findByPublicId(string $publicId): ?static
    {
        return static::query()->wherePublicId($publicId)->first();
    }

    public function getPublicIdAttributeName(): string
    {
        return 'public_id';
    }
}
