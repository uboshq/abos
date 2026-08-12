<?php

namespace App\Providers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\ListExport;
use App\Core\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * সেটিং পড়ার সেবাটা একটাই — প্রতি অনুরোধে একবার।
         *
         * ── কেন singleton ────────────────────────────────────────────
         * SettingsService নিজের ভেতরে পড়া মানগুলো জমিয়ে রাখে, যাতে একই
         * সুইচ দশ জায়গায় দেখলেও কোয়েরি একটাই হয়। কিন্তু বাঁধন না থাকলে
         * প্রতিটা `app(SettingsService::class)` নতুন বস্তু বানাত আর নিজের
         * আলাদা জমা রাখত — ফলে জমানোর লাভটাই থাকত না।
         *
         * আসল ফাঁদটা আরো সূক্ষ্ম: Laravel রুটের কন্ট্রোলার বস্তুটা Route-এ
         * ধরে রাখে, তাই এক প্রক্রিয়ায় দ্বিতীয় অনুরোধে সেই পুরোনো
         * কন্ট্রোলারই চলে — তার সাথে তার পুরোনো SettingsService-ও। একটাই
         * বস্তু হলে set()-এর অকার্যকর-করা সবার জন্যই কাজ করে।
         */
        $this->app->singleton(SettingsService::class);

        /*
         * রপ্তানির সংগ্রাহক — অনুরোধ প্রতি একটা।
         *
         * টেবিল কম্পোনেন্ট সারিগুলো এতে জমা দেয়, আর মিডলওয়্যার সেখান
         * থেকেই ফাইলটা বানায়। দুইজন একই বস্তু না পেলে মিডলওয়্যার সবসময়
         * খালি হাতে ফিরত, আর রপ্তানি নীরবে কাজ করা বন্ধ করে দিত।
         *
         * scoped, singleton নয়: পরের অনুরোধে আগের পর্দার সারিগুলো পড়ে
         * থাকা চলবে না।
         */
        $this->app->scoped(ListExport::class);

        /*
         * অনুমোদনের ইঞ্জিনও অনুরোধ প্রতি একটা।
         *
         * ছকগুলো ভেতরে জমিয়ে রাখে (একই ছক বিশবার খোঁজা হয় না)। বাঁধন
         * না থাকলে প্রতিটা app(ApprovalEngine::class) নতুন বস্তু বানাত
         * আর জমানোটা বৃথা যেত।
         *
         * scoped, singleton নয়: পরের অনুরোধে আগের ছক ধরে রাখা চলবে না —
         * মালিক ছক বদলালে সেটা সাথে সাথেই কার্যকর হওয়া দরকার।
         */
        $this->app->scoped(ApprovalEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * নতুন ঘর $fillable-এ না বসালে চুপ করে হারিয়ে যায় — আর নয়।
         *
         * ── কেন এটা এখানে বসল ─────────────────────────────────────────
         * এই ভুলটা তিনবার হয়েছে: batch_id, idempotency_key, parked_at।
         * প্রতিবার মাইগ্রেশন হয়েছে, সার্ভিস ঘরটা পাঠিয়েছে, কোথাও কোনো
         * ভুলের বার্তা আসেনি — শুধু ঘরটা খালি থেকে গেছে। ব্যাচের বেলায়
         * মজুদ শূন্য দেখিয়েছে, আর চাবির বেলায় একই বিল দুইবার বসেছে।
         *
         * Eloquent-এর নিয়ম হলো, $fillable-এ না থাকা চাবি নীরবে ফেলে
         * দেওয়া। এই সুইচটা সেই নীরবতা তুলে দেয়: তখন ওটা ব্যতিক্রম হয়ে
         * পরীক্ষায় ধরা পড়ে, চালু ব্যবসার খাতায় নয়।
         *
         * ── কেন কেবল local আর testing ────────────────────────────────
         * চালু সার্ভারে এটা চালু থাকলে একটা ভুলে-পাঠানো বাড়তি চাবি
         * পুরো পাতাটা ভেঙে দিত — যেখানে আগে কেবল ওই ঘরটা বাদ পড়ত।
         * ভুল ধরার জায়গা উন্নয়ন আর পরীক্ষা, ক্রেতার সামনে নয়।
         */
        Model::preventSilentlyDiscardingAttributes(
            $this->app->environment(['local', 'testing']),
        );

        /*
         * প্রতিটা নতুন টেবিলে বাইরের কী — এক লাইনে।
         *
         * ম্যাক্রো হিসেবে রাখার কারণ: হাতে `char('public_id', 36)->unique()`
         * লিখতে বললে একদিন কেউ ভুলে যেত, আর ভোলা কলামটা কোনো ভুল দেখাত
         * না — শুধু ওই টেবিলটা API-তে অদৃশ্য থেকে যেত। PublicIdTest
         * পাহারা দেয়, তবু ভোলার সুযোগ না রাখাই ভালো।
         */
        Blueprint::macro('publicId', function (): void {
            /** @var Blueprint $this */
            $this->char('public_id', 36)->nullable()->unique();
        });
    }
}
