<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
