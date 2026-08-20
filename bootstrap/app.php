<?php

use App\Http\Middleware\ExportListing;
use App\Http\Middleware\NormalizeUnicodeInput;
use App\Http\Middleware\RefuseSwitchedOffScreens;
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
        /*
         * সামনের প্রক্সিটাকে বিশ্বাস করা হয়।
         *
         * ── কী ভেঙেছিল ─────────────────────────────────────────────────
         * সার্ভারে ABOS একটা রিভার্স প্রক্সির পেছনে বসে, আর TLS ওখানেই
         * শেষ হয়। তাই Laravel-এর কাছে অনুরোধটা পৌঁছায় সাধারণ HTTP হয়ে,
         * আর সে প্রতিটা ঠিকানা `http://` দিয়ে বানায় — CSS, JS, ছবি,
         * পাসওয়ার্ড রিসেটের লিংক, সব।
         *
         * ব্রাউজার HTTPS পাতার ভেতরে http:// ফাইল আনতে দেয় না। ফল:
         * লগইনের পর্দা সাজসজ্জাহীন খালি HTML হয়ে এল, কোনো ত্রুটিবার্তা
         * ছাড়াই — সার্ভারে সবকিছু ২০০ দিচ্ছিল, ব্রাউজারই ফাইলগুলো
         * আটকে দিচ্ছিল।
         *
         * প্রক্সি `X-Forwarded-Proto: https` পাঠাচ্ছিল ঠিকই; বলা ছিল না
         * যে ওটাকে বিশ্বাস করা যায়।
         *
         * ── '*' কেন, আর কখন নয় ─────────────────────────────────────────
         * প্রক্সিটা একই মেশিনে, লুপব্যাকে। বাইরের কেউ সরাসরি অ্যাপে
         * পৌঁছাতেই পারে না, তাই মিথ্যা হেডার পাঠানোর কোনো পথ নেই।
         *
         * অ্যাপটা যদি কখনো সরাসরি ইন্টারনেটে খোলা থাকে, '*' বিপজ্জনক:
         * তখন যে কেউ X-Forwarded-For পাঠিয়ে নিজের IP লুকাতে পারত, আর
         * অডিট ট্রেইলে ভুল ঠিকানা লেখা থাকত। সেদিন এখানে প্রক্সির
         * ঠিকানাটা লিখতে হবে।
         */
        $middleware->trustProxies(at: '*');

        /*
         * লগইন না থাকলে কাকে কোথায় পাঠানো হবে।
         *
         * ── কেন এটা লাগে ────────────────────────────────────────────
         * ডিফল্টে Laravel সবাইকে `login`-এ পাঠায়, অর্থাৎ কর্মীর পর্দায়।
         * ডিলার তাঁর পোর্টালের লিংকে ক্লিক করে সেশন শেষ হয়ে গেলে
         * কর্মীর লগইন পর্দা দেখতেন — যেখানে তাঁর কোনো পরিচয়ই কাজ করে
         * না। তিনি ধরে নিতেন ব্যবস্থাটা ভেঙে গেছে, আর ফোন করতেন।
         *
         * ঠিকানার শুরু দেখে সিদ্ধান্ত, গার্ড দেখে নয়: এই মুহূর্তে কেউ
         * লগইন নেই, তাই "ইনি কে" প্রশ্নের উত্তরও নেই। কিন্তু তিনি
         * কোথায় যেতে চাইছিলেন সেটা জানা আছে।
         */
        $middleware->redirectGuestsTo(fn ($request) => $request->is('portal', 'portal/*')
            ? route('sales.portal.login')
            : route('login'));

        // প্রতিটা ওয়েব রিকোয়েস্টে কোম্পানি, শাখা, অর্থবছর ও ভাষা বসে।
        // এখানে না বসালে BelongsToCompany ব্যতিক্রম ছুঁড়বে — সেটাই উদ্দেশ্য,
        // কারণ প্রসঙ্গ ছাড়া টেন্যান্ট ডাটা ছোঁয়া মানে সব কোম্পানির রো দেখা।
        $middleware->web(append: [
            ResolveCompanyContext::class,

            /*
             * Control Panel-এ বন্ধ করা পর্দা রুট-স্তরেও বন্ধ।
             *
             * প্রসঙ্গের পরেই, কারণ সুইচগুলো কোম্পানিভিত্তিক — কোন
             * কোম্পানি তা না জেনে কোন সুইচ পড়তে হবে বলা যায় না।
             */
            RefuseSwitchedOffScreens::class,

            /*
             * ?export=csv থাকলে পর্দার তালিকাটা ফাইল হয়ে নামে।
             *
             * ভিউ রেন্ডার হওয়ার পরে কাজ করে, তাই সবার শেষে।
             */
            ExportListing::class,
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

        /*
         * ভ্যালিডেশন ব্যর্থ হলে ইনপুট সেশনে ফ্ল্যাশ হয় — পাসওয়ার্ড ছাড়া।
         *
         * Laravel নিজে থেকে `password` ও `password_confirmation` বাদ
         * দেয়। কাউন্টারে ম্যানেজারের অনুমোদনের ঘরটার নাম
         * `approver_password`, যা ওই তালিকায় পড়ে না — অর্থাৎ ভুল
         * পাসওয়ার্ড টাইপ হলে **সেটা হুবহু সেশনে গিয়ে বসত**, আর
         * সেশন ফাইল/টেবিল যাঁরা দেখতে পান তাঁরা পড়তে পারতেন।
         *
         * নামটা `password` রাখলে এই লাইনটা লাগত না, কিন্তু তখন
         * লগইন ফর্মের ঘরের সাথে নাম মিলে যেত — একই পাতায় দুইটা
         * আলাদা অর্থের `password`।
         */
        $exceptions->dontFlash(['approver_password']);
    })->create();
