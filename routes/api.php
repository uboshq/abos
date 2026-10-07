<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ApprovalApiController;
use App\Http\Controllers\Api\AppVersionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardTodayController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NoticeApiController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\ReportExportApiController;
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
    ->name('api.auth.')
    ->group(function (): void {
        /*
         * ⛔ ওয়েবের লগইনের একই থলে ('login') — নাম+IP ধরে। ⓘ নবায়ন আর বেরোনো
         * নিচে **টোকেনের পরে** গোনা হয়, অর্থাৎ ব্যবহারকারী ধরে: আগে গোটা
         * গ্রুপে IP ধরে ছিল, আর দোকানের ফোনগুলো একে অন্যের নবায়ন আটকাত।
         */
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');

        /*
         * নবায়ন `abilities:refresh` চায়, `sync` নয় — আর এটাই পুরো
         * দুই-টোকেন ব্যবস্থাটার ভিত্তি। `auth:sanctum` একা **যেকোনো**
         * বৈধ টোকেন মেনে নেয়, তাই ability ছাড়া একটা access টোকেন
         * দিয়েই নিজেকে চিরকাল নবায়ন করা যেত।
         */
        Route::post('/refresh', [AuthController::class, 'refresh'])
            /*
             * ⓘ টোকেন নিয়ামক নিজে খোঁজে — হেডার, না পেলে body-র `refreshToken` (অ্যাপ ০.৪.৮ পর্যন্ত ওভাবেই পাঠায়); যাচাই
             * একই চার পাহারা: আছে, মেয়াদ আছে, ক্ষমতা `refresh`, কর্মী ([[AuthController::refreshTokenFrom()]], ৪ অক্টোবর ২০২৬)।
             * ⚠️ `auth:sanctum` এখানে নয়: Laravel সেটা অগ্রাধিকারে সবার আগে চালায়, তাই body-র টোকেন দেখার সুযোগই থাকত না।
             */
            ->middleware(['throttle:30,1,api-token'])
            ->name('refresh');

        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'throttle:30,1,api-token'])
            ->name('logout');
    });

/*
 * সংস্করণ — টোকেন ছাড়া, ইচ্ছা করে (চুক্তি §৬)।
 *
 * ⚠️ যে পুরনো বিল্ড লগইনই করতে পারে না, তাকেই বলা দরকার সে পুরনো; auth-এর
 * ভেতরে রাখলে বার্তাটা ঠিক সেখানে পৌঁছাত না। ⓘ কোনো ব্যবসার ডেটা নেই —
 * কেবল .env-এর চারটা মান। throttle আছে, কারণ খোলা দরজা।
 */
Route::get('v1/app/version', AppVersionController::class)
    ->middleware('throttle:60,1,app-version')
    ->name('api.app.version');

/*
 * ⭐ ফোনের ক্র্যাশের খবর — টোকেন ছাড়াও (লগইনের পর্দাতেও অ্যাপ ভাঙে), তাই সীমা কড়া: মিনিটে ১০টা, আর প্রতিটা ঘরের
 * আকারের সীমা দরজায় ([[AppCrashController]])। কেবল ভুলের খাতায় যায়, কিছু ফেরে না (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬)।
 */
Route::post('v1/app/crash', \App\Http\Controllers\Api\AppCrashController::class)
    ->middleware('throttle:10,1,app-crash')
    ->name('api.app.crash');

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
        // ⛔ জনপ্রতি থলে, টোকেনের পরে ([[AppServiceProvider]]-এর `app`; অডিট ফোন ⓘ১৯)
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
    ])
    ->name('api.')
    ->group(function (): void {
        /*
         * নোটিশ — পড়া, মেনে নেওয়া, আর বারের তালিকা।
         *
         * ── ⚠️ কেন এখানে কোনো `can:` চাবি নেই ──────────────
         * ⓘ এই দরজাগুলো ব্যবসার ডেটা দেয় না — দেয় **তাঁর নিজের
         * নোটিশ**। ⛔ চাবি চাইলে ডিলার বা সরবরাহকারী — যাঁদের
         * কর্মীর চাবি নেই — নিজেদের নোটিশও পড়তে পারতেন না।
         *
         * ⓘ ছাঁকনিটা এক ধাপ ভেতরে — [[NoticeAudience]], আর সেটাই
         * পর্দা, বার আর API তিনটার একমাত্র উত্তরদাতা।
         */
        Route::get('/notices', [NoticeApiController::class, 'index'])->name('notices.index');
        Route::get('/notices/bar', [NoticeApiController::class, 'bar'])->name('notices.bar');
        Route::post('/notices/{notice}/read', [NoticeApiController::class, 'read'])->name('notices.read');
        Route::post('/notices/{notice}/acknowledge', [NoticeApiController::class, 'acknowledge'])
            ->name('notices.acknowledge');

        /*
         * ⭐ ঘণ্টার খবর — ফোনের মাথার ঘণ্টা (মালিক, ৬ অক্টোবর ২০২৬)। ⓘ নিজের খবর, তাই চাবি নেই, নোটিশের মতোই;
         * অন্যের খবর ৪০৪ ([[NotificationApiController]])।
         */
        Route::get('/notifications', [NotificationApiController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationApiController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationApiController::class, 'read'])->name('notifications.read');

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

        // ⭐ ফোনের FCM টোকেন — নিজের ফোনে নিজের টোকেন ([[PushTokenController]], ২ অক্টোবর ২০২৬)
        Route::post('/devices/push-token', \App\Http\Controllers\Api\PushTokenController::class)->name('devices.push_token');

        /*
         * ⭐ কোম্পানি ও শাখা বদল — ফোনের সুইচার (১ অক্টোবর ২০২৬)।
         *
         * ⓘ `/me`-র মতোই `can:` নেই: এটা নিজের জায়গা বদলানো, ব্যবসার ডেটা নয় —
         * ওয়েবের `company.switch`/`branch.switch`-ও কেবল লগইন চায়। ⛔ পাহারা
         * নিয়মে: সদস্যপদ আর শাখার নাগাল ([[User::switchCompany()]]), আর
         * `abilities:app` — refresh টোকেনে খোলে না।
         */
        Route::post('/workspace', \App\Http\Controllers\Api\WorkspaceApiController::class)->name('workspace');

        /*
         * "আজ কেমন গেল" — চুক্তি §৮। ⚠️ `can:` নেই, ইচ্ছা করে: প্রতিটা ঘর
         * নিজের চাবি দেখে, আর চাবি না থাকলে ঘরটাই যায় না
         * ([[DashboardTodayController]])।
         */
        Route::get('/dashboard/today', DashboardTodayController::class)->name('dashboard.today');

        /*
         * ⭐ মডিউলের ড্যাশবোর্ড — মজুদ, বিক্রি, হিসাব … (মালিক, ৪ অক্টোবর ২০২৬; [[DashboardApiController]])। ওয়েবের একই
         * ইঞ্জিন, একই দরজা: চাবি মডিউলের নিজের মেনু-সারি থেকে, পদ্ধতির ভেতরে — রুটে `can:` নেই, ইচ্ছা করে।
         * ⚠️ `today`-এর পরে, যাতে `{module}` ওটা গিলে না ফেলে।
         */
        Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardApiController::class, 'index'])->name('dashboard.index');
        Route::get('/dashboard/{module}', [\App\Http\Controllers\Api\DashboardApiController::class, 'show'])
            ->where('module', '[a-z_]+')->name('dashboard.module');

        /*
         * অনুমোদন — চুক্তি §৫। ⚠️ সিঙ্ক নয়: অফলাইনে কাজ করে না।
         *
         * ⓘ এখানে `can:` আছে, আর সেটা ওয়েবের সমান চাবি: ইনবক্সের মেনু আর
         * সই-দরজা দুইটাই `approval.decide` চায়। ⛔ তবু পাহারা কেবল এটা
         * নয় — কোন কাগজে কে সই দেবেন সেটা `canDecide()` ঠিক করে
         * ([[ApprovalApiController]]), আর বেতন ফোনে আসেই না।
         */
        Route::get('/approvals/pending', [ApprovalApiController::class, 'pending'])
            ->middleware(['can:approval.decide', \App\Http\Middleware\RefuseModulesOffOnThePhone::class.':approval'])
            ->name('approvals.pending');
        // ⭐ সইয়ের আগে কাগজের বিস্তারিত — মালিক, ৭ অক্টোবর ২০২৬ ("approval e kono kichui details dekhay na")
        Route::get('/approvals/{approval}/sheet', [ApprovalApiController::class, 'sheet'])
            ->middleware(['can:approval.decide', \App\Http\Middleware\RefuseModulesOffOnThePhone::class.':approval'])
            ->name('approvals.sheet');
        Route::post('/approvals/{approval}/approve', [ApprovalApiController::class, 'approve'])
            ->middleware(['can:approval.decide', \App\Http\Middleware\RefuseModulesOffOnThePhone::class.':approval'])
            ->name('approvals.approve');
        Route::post('/approvals/{approval}/reject', [ApprovalApiController::class, 'reject'])
            ->middleware(['can:approval.decide', \App\Http\Middleware\RefuseModulesOffOnThePhone::class.':approval'])
            ->name('approvals.reject');

        /*
         * রিপোর্ট — চুক্তি §৯। ⚠️ `can:` নেই, ইচ্ছা করে: প্রতিটা রিপোর্টের
         * নিজের চাবি আছে (`ReportDefinition::$permission`), আর দরজা একটাই।
         * ⓘ তালিকা ছাঁকা হয়, পাতায় চাবি না থাকলে ৪০৩ ([[ReportApiController]])।
         */
        Route::get('/reports', [ReportApiController::class, 'index'])->name('reports.index');
        Route::get('/reports/{key}', [ReportApiController::class, 'show'])->name('reports.show');

        /*
         * নথি ও রপ্তানি — চুক্তি §১০। ⚠️ `can:` নেই, ইচ্ছা করে: কাগজ এগারো
         * রকম, প্রতিটার চাবি ওয়েবের ছাপার রুটের নিজের `can:` ([[DocumentApiController]]),
         * আর রপ্তানির চাবি রিপোর্টের নিজের ([[ReportExportApiController]])।
         *
         * ⓘ `/reports/{slug}/export` উপরের `/reports/{key}`-এর সাথে ঠোকে না:
         * `{key}` একটা অংশই ধরে (স্ল্যাশ নয়), তাই `…/export` ওখানে মেলেই না।
         */
        Route::get('/documents/{type}/{id}/pdf', [DocumentApiController::class, 'pdf'])->name('documents.pdf');
        Route::get('/documents/{type}/{id}/papers', [DocumentApiController::class, 'papers'])->name('documents.papers');
        Route::get('/reports/{slug}/export', ReportExportApiController::class)->name('reports.export');
    });

Route::prefix('v1')
    ->middleware([
        'auth:sanctum',
        // ⛔ জনপ্রতি থলে, টোকেনের পরে ([[AppServiceProvider]]-এর `app`; অডিট ফোন ⓘ১৯)
        'throttle:app',

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
            /*
             * ⛔ মেটানো একটা **কাজ**, পড়া নয় — নিজের চাবি (৩০ সেপ্টেম্বর ২০২৬)। আগে
             * নিরীক্ষকের পড়ার চাবিতেই দ্বন্দ্ব মিটিয়ে সারি থেকে সরানো যেত।
             */
            Route::post('/conflicts/{conflict}/resolve', [SyncController::class, 'resolveConflict'])
                ->middleware('can:governance.sync.resolve')
                ->name('conflicts.resolve');

            Route::post('/{module}/push', [SyncController::class, 'push'])
                ->middleware(\App\Http\Middleware\RefuseModulesOffOnThePhone::class)
                ->name('push');
            Route::get('/{module}/pull', [SyncController::class, 'pull'])
                ->middleware(\App\Http\Middleware\RefuseModulesOffOnThePhone::class)
                ->name('pull');
            Route::post('/{module}/pull-complete', [SyncController::class, 'pullComplete'])
                ->middleware(\App\Http\Middleware\RefuseModulesOffOnThePhone::class)
                ->name('pull-complete');
            Route::get('/{module}/last-sync', [SyncController::class, 'lastSync'])
                ->middleware(\App\Http\Middleware\RefuseModulesOffOnThePhone::class)
                ->name('last-sync');
        });
    });
