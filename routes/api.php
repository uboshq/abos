<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SyncController;
use App\Http\Middleware\ResolveCompanyContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ফোনের দরজা — /api/v1/**
|--------------------------------------------------------------------------
|
| ── কেন আলাদা ফাইল, web.php-তে নয় ───────────────────────────────────
| দুইটা আলাদা দর্শক, দুইটা আলাদা পরিচয়ের ব্যবস্থা। ওয়েব চলে সেশন
| কুকিতে, ফোন চলে টোকেনে — একই গ্রুপে রাখলে একটার মিডলওয়্যার অন্যটার
| উপর চলত, আর CSRF টোকেন ফোনের কাছে চাওয়া হত (যেটা তার নেই)।
|
| ── ⚠️ ResolveCompanyContext এখানে রুট-মিডলওয়্যার, গ্রুপে নয় ─────────
| গ্রুপের মিডলওয়্যার `auth:sanctum`-এর **আগে** চলে, আর তখন
| `$request->user()` এখনো null — অর্থাৎ প্রসঙ্গ বসত না, আর প্রথম
| `BelongsToCompany` কোয়েরিই ব্যতিক্রম ছুঁড়ত।
|
| রুট-মিডলওয়্যার তালিকার ক্রমেই চলে, তাই `auth:sanctum`-এর পরে বসানো
| যায়। ওয়েবের দিকে ঠিক এই সমস্যাটার জন্যই bootstrap/app.php-তে একটা
| `prependToPriorityList` আছে — একই ফাঁদ, ভিন্ন দরজা।
|
| ── অনুমতি কোথায় ─────────────────────────────────────────────────────
| এই দরজাগুলোয় কর্মীর `can:` চাবি নেই, আর সেটা ইচ্ছাকৃত — কারণ
| **এক অ্যাপ সবার জন্য** (মালিক, কর্মী, ডিলার, সরবরাহকারী), আর কে কী
| দেখবে সেটা রেকর্ডের ধরন ধরে ঠিক হয়, দরজার ধরন ধরে নয়।
|
| ছাঁকনিটা এক ধাপ ভেতরে: প্রতিটা [[SyncsToDevices]] হ্যান্ডলার
| `$user` পায় আর নিজের রেকর্ডগুলো তাঁর রোল অনুযায়ী ছাঁকে। ডিলার সিঙ্ক
| করলে তিনি নিজের বকেয়াই পান, পুরো গ্রাহক-তালিকা নয়।
|
| দরজায় একটা মডিউল-চাবি বসালে দুইটা জিনিস ভাঙত: সব রোলের জন্য কর্মীর
| চাবি লাগত (ডিলারের যা নেই), আর ছাঁকনিটা দুই জায়গায় থাকত — দরজায়
| আর হ্যান্ডলারে — যার একটা একদিন অন্যটার সাথে অমিল হত।
|
| `EveryRouteIsGuardedTest`-এ এই সিদ্ধান্তটা কারণসহ লেখা আছে।
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum', ResolveCompanyContext::class])
    ->name('api.')
    ->group(function (): void {

        Route::prefix('sync')->name('sync.')->group(function (): void {

            // এই সার্ভার কী সিঙ্ক করতে পারে — ফোন এখান থেকেই পরিকল্পনা বানায়।
            Route::get('/capabilities', [SyncController::class, 'capabilities'])
                ->name('capabilities');

            /*
             * দ্বন্দ্বের দরজা দুইটা মডিউলের রুটের **আগে**, ইচ্ছে করে।
             *
             * নিচের `{module}` যেকোনো শব্দ ধরে, তাই `conflicts` পরে
             * বসালে সেটা একটা মডিউলের নাম হিসেবে পড়া হত আর ৪০৪ দিত।
             * ঠিক এই ফাঁদটা Accounts-এর `/reports/{slug}`-এও আছে, আর
             * সেখানেও একই সমাধান।
             */
            /*
             * ⚠️ এই দুইটাই বাকিগুলোর ব্যতিক্রম — এখানে দরজাতেই চাবি।
             *
             * একটা দ্বন্দ্বের সারিতে **ফোনের রূপ আর সার্ভারের রূপ
             * দুইটাই** থাকে, অর্থাৎ ওটা দুই পাশের যোগফলের চেয়ে বেশি
             * গোপন। যিনি অর্ডার দেখতে পান তিনি এটা দেখতে পাওয়ার কথা নয়,
             * তাই ছাঁকনিটা রেকর্ডের ধরন ধরে নয়, দরজা ধরেই।
             *
             * অডিটের চাবিটাই ধার করা হয়েছে, নতুন একটা বানানো হয়নি —
             * প্রশ্নটা একই ধরনের ("আগে কী ছিল, পরে কী হলো"), আর নতুন
             * `PermissionKey` মানে কাউকে আলাদা করে দিতে হত, নাহলে
             * পর্দাটা সবার জন্য ৪০৩ হত।
             */
            Route::get('/conflicts', [SyncController::class, 'conflicts'])
                ->middleware('can:governance.audit.view')
                ->name('conflicts');
            Route::post('/conflicts/{conflict}/resolve', [SyncController::class, 'resolveConflict'])
                ->middleware('can:governance.audit.view')
                ->name('conflicts.resolve');

            Route::post('/{module}/push', [SyncController::class, 'push'])
                ->name('push');
            Route::get('/{module}/pull', [SyncController::class, 'pull'])
                ->name('pull');
            Route::post('/{module}/pull-complete', [SyncController::class, 'pullComplete'])
                ->name('pull-complete');
            Route::get('/{module}/last-sync', [SyncController::class, 'lastSync'])
                ->name('last-sync');
        });
    });
