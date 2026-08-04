<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * মাস্টার তালিকার সাধারণ আচরণ — কোড, দুই ভাষার নাম, সক্রিয়তা, ডিফল্ট।
 *
 * সাতটা মাস্টার (এলাকা, একক, কর, শর্ত, দর তালিকা, পক্ষের ধরন, কারণ
 * কোড) একসাথে লেখা হয়েছে, আর সাতটাতেই এই চারটা জিনিস হুবহু এক। এটাই
 * শেয়ার্ড করার সঠিক মুহূর্ত: সেকশন ১৯.৮ বলে এক ব্যবহারকারী দেখে
 * শেয়ার্ড কিছু বানানো যাবে না, কিন্তু এখানে সাতজন একসাথে দাঁড়িয়ে আছে
 * আর কারও দাবিই আলাদা নয়।
 *
 * যা এখানে নেই: প্রতিটা মাস্টারের নিজস্ব ঘরগুলো (করের হার, এককের
 * factor, এলাকার স্তর)। ওগুলো এখানে টানলে trait-টা শর্তে ভরে যেত।
 */
trait IsMasterRecord
{
    /** ব্যবহারকারীর ভাষায় নাম — বাংলা না থাকলে ইংরেজি (সেকশন ১৮.৩)। */
    public function name(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->name_bn)) {
            return $this->name_bn;
        }

        return (string) $this->name_en;
    }

    /** কোড ও নাম একসাথে — ড্রপডাউনে ও রিপোর্টে এই রূপেই দেখা যায়। */
    public function label(?string $locale = null): string
    {
        return $this->code.' — '.$this->name($locale);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('code', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('name_bn', 'like', $like);
        });
    }

    /**
     * এই মাস্টারে ডিফল্ট বলে কিছু আছে কি না।
     *
     * ছয়টার চারটায় আছে, দুইটায় নেই: একটা এককই "ডিফল্ট একক" হওয়ার
     * কোনো মানে নেই (পণ্য নিজের একক বলে), আর কারণ কোডেও নয় (কারণটা
     * ঘটনার উপর নির্ভর করে, ডিফল্ট থাকলে কেউ না ভেবেই ওটাই রাখত)।
     *
     * শর্ত ছাড়া is_default ধরে নিলে ওই দুইটা তালিকা ৫০০ দিত — আর
     * ঠিক সেটাই হয়েছিল।
     */
    public static function supportsDefault(): bool
    {
        return in_array('is_default', (new static)->getFillable(), true);
    }

    /** ডিফল্ট রেকর্ডটা — যে তালিকায় ডিফল্ট আছে সেখানে একটাই। */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * ডিফল্ট আগে, তারপর কোড।
     *
     * যে তালিকায় ডিফল্ট নেই সেখানে শুধু কোড — ডাকার জায়গায় প্রতিবার
     * শর্ত লিখতে হয় না, আর লিখতে ভুলে যাওয়ার সুযোগও থাকে না।
     */
    public function scopeDefaultFirst(Builder $query): Builder
    {
        if (static::supportsDefault()) {
            $query->orderByDesc('is_default');
        }

        return $query->orderBy('code');
    }

    /**
     * এই রেকর্ডটাকে ডিফল্ট করা — আগেরটা নেমে যায়।
     *
     * এক ট্রানজেকশনে দুই ধাপ: আগে সবার পতাকা নামানো, তারপর এটার তোলা।
     * উল্টো ক্রমে করলে মাঝখানে এক মুহূর্তের জন্য দুইটা ডিফল্ট থাকত, আর
     * ঠিক তখন কেউ নতুন রেকর্ড বানালে কোনটা বসবে তা নির্ধারিত হত না।
     */
    public function makeDefault(): static
    {
        DB::transaction(function () {
            static::query()
                ->where('is_default', true)
                ->whereKeyNot($this->getKey())
                ->get()
                ->each(fn ($other) => $other->forceFill(['is_default' => false])->save());

            // refresh() দরকার: ডাকার জায়গায় ধরে রাখা পুরনো ইনস্ট্যান্সে
            // is_default ইতিমধ্যেই true হলে save() কোনো UPDATE পাঠাত না,
            // অথচ ডাটাবেজে উল্টোটা থাকত। AccountService-এও একই ফাঁদ ছিল।
            $this->refresh()->forceFill(['is_default' => true, 'is_active' => true])->save();
        });

        return $this->fresh();
    }
}
