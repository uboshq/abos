<?php

namespace App\Providers;

use App\Core\Services\SettingsService;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
