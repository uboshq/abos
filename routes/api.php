<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MeController;
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

/*
 * ── ঢোকার দরজা — লগইনের আগে, তাই `auth` ছাড়া ──────────────────────
 *
 * ⚠️ **throttle এখানে বাধ্যতামূলক**, আর ওয়েবের দরজার চেয়ে ঢিলা নয়।
 * ওয়েবে `throttle:10,1`; এখানে কম হলে আক্রমণকারী কেবল এই দরজাটাই
 * ব্যবহার করতেন, আর তালা দুইটার একটাতে মানে তালা নেই।
 *
 * যাচাইয়ের বাকি সবকিছু — নামের উপর তালা, ডামি হ্যাশ, MFA,
 * `login_history` — [[CredentialCheck]]-এ, ওয়েবের দরজার সাথে ভাগ করা।
 */
Route::prefix('v1/auth')
    ->middleware('throttle:10,1')
    ->name('api.auth.')
    ->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->name('login');

        /*
         * নবায়ন `abilities:refresh` চায়, `sync` নয় — আর এটাই পুরো
         * দুই-টোকেন ব্যবস্থাটার ভিত্তি। `auth:sanctum` একা **যেকোনো**
         * বৈধ টোকেন মেনে নেয়, তাই ability ছাড়া একটা access টোকেন
         * দিয়েই নিজেকে চিরকাল নবায়ন করা যেত।
         */
        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware(['auth:sanctum', 'abilities:'.AuthController::REFRESH])
            ->name('refresh');

        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('logout');
    });

/*
 * অ্যাপের নিজের দরজা — সিঙ্ক নয়।
 *
 * ── কেন আলাদা গ্রুপ, নিচের গ্রুপের ভিতরে নয় ─────────────────────────
 * নিচের গ্রুপটা `abilities:sync` চায়। `/me` ওখানে বসালে **সিঙ্ক নয়
 * এমন একটা দরজা সিঙ্কের চাবি চাইত** — আজ কাজ করত, কিন্তু কাল অ্যাপের
 * প্রতিটা নতুন দরজা (মেনু, প্রোফাইল, বিজ্ঞপ্তি) একই ভুল নামে বসত, আর
 * তখন `sync` নামটার আর কোনো মানে থাকত না।
 *
 * ⓘ চাবিটা নাম দিয়ে বসানো গেছে কারণ মেপে দেখা গেছে খরচ কম: `ACCESS`
 * ধ্রুবকটা ছিল মাত্র দুই জায়গায়, আর ফোনের কোডে বা টেস্টে নামটা কোথাও
 * হাতে লেখা নেই। **সস্তা হলে ঠিক নামটাই বসানো উচিত।**
 *
 * ⚠️ দুইটা চাবিই একই access টোকেনে বসে (`AuthController::login()`),
 * তাই ফোনকে দুইটা টোকেন রাখতে হয় না — কিন্তু refresh টোকেনে
 * কোনোটাই নেই, তাই চুরি যাওয়া refresh টোকেনে এই দরজাও খোলে না।
 */
Route::prefix('v1')
    ->middleware([
        'auth:sanctum',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
    ])
    ->name('api.')
    ->group(function (): void {
    /*
     * "আমি কে, আর আমি কী দেখব" — অ্যাপের প্রথম প্রশ্ন।
     *
     * ── কেন কোনো `can:` চাবি নেই ────────────────────────────────
     * এই দরজাটা কোনো ব্যবসার ডেটা দেয় না; সে কেবল বলে **তুমি কে**।
     * আলাদা অনুমতি চাওয়া মানে ঢুকতে পারা মানুষকে নিজের নাম জানতে
     * বাধা দেওয়া — ঠিক যে কারণে `logout` আর `profile`-এও চাবি নেই।
     *
     * ⓘ পাহারা তবু আছে, আর দুইটা: `auth:sanctum` (কে), আর গ্রুপের
     * `abilities:` (কোন টোকেন)। refresh টোকেনে এটা খোলে না।
     *
     * ⚠️ এখানে যা যায় তা **কী দেখানো যাবে**, "কী করা যাবে" নয় —
     * প্রতিটা দরজা নিজে নিজের অনুমতি দেখে ([[MeController]])।
     */
    Route::get('/me', MeController::class)->name('me');
    });

Route::prefix('v1')
    ->middleware([
        'auth:sanctum',

        /*
         * ⚠️ `abilities:sync` — refresh টোকেন এখানে ঢুকতে পারবে না।
         *
         * এটা না থাকলে চুরি যাওয়া একটা refresh টোকেন দিয়েই সরাসরি
         * সিঙ্কের সব দরজা খোলা যেত, আর access টোকেনের ছোট মেয়াদটার
         * পুরো মানেই থাকত না।
         */
        'abilities:'.AuthController::ACCESS,

        ResolveCompanyContext::class,
    ])
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
