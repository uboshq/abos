<?php

use App\Http\Middleware\NormalizeUnicodeInput;
use App\Http\Middleware\ResolveCompanyContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // প্রতিটা ওয়েব রিকোয়েস্টে কোম্পানি, শাখা, অর্থবছর ও ভাষা বসে।
        // এখানে না বসালে BelongsToCompany ব্যতিক্রম ছুঁড়বে — সেটাই উদ্দেশ্য,
        // কারণ প্রসঙ্গ ছাড়া টেন্যান্ট ডাটা ছোঁয়া মানে সব কোম্পানির রো দেখা।
        $middleware->web(append: [
            ResolveCompanyContext::class,
        ]);

        /*
         * ইউনিকোড নিয়মিতকরণ সবার আগে — ভ্যালিডেশন, খোঁজা ও সংরক্ষণ
         * তিনটাই যেন একই বাইট দেখে। পরে বসালে ভ্যালিডেশন এক রূপ দেখত
         * আর ডাটাবেজে আরেক রূপ যেত।
         */
        $middleware->prepend(NormalizeUnicodeInput::class);

        /*
         * রুট-মডেল বাইন্ডিং-এর আগে।
         *
         * append করলে এটা SubstituteBindings-এর পরে চলত, আর তখন
         * /customers/4 খুলতে গেলে বাইন্ডিং Customer খুঁজতে যেত এমন সময়ে
         * যখন কোনো কোম্পানি বসানো হয়নি — BelongsToCompany ঠিক কাজটাই
         * করত, ব্যতিক্রম ছুঁড়ত, আর পাতাটা ৫০০ দিত।
         *
         * StartSession-এর পরেই থাকতে হবে (ব্যবহারকারী কে জানা দরকার),
         * কিন্তু বাইন্ডিং-এর আগে। priority তালিকা ঠিক এই কাজের জন্য।
         *
         * টেস্টে ধরা পড়েনি কারণ setUp()-এ CompanyContext::set() ডাকা
         * হত — আসল রিকোয়েস্টে যা কখনো ঘটে না। CustomerTest-এ এখন একটা
         * পরীক্ষা আছে যা লগইন থেকে শুরু করে, প্রসঙ্গ নিজে বসায় না।
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCompanyContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
