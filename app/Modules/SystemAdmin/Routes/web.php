<?php

declare(strict_types=1);

use App\Modules\SystemAdmin\Http\Controllers\BranchModuleController;
use App\Modules\SystemAdmin\Http\Controllers\CompanyController;
use App\Modules\SystemAdmin\Http\Controllers\ControlPanelController;
use App\Modules\SystemAdmin\Http\Controllers\CustomFieldController;
use App\Modules\SystemAdmin\Http\Controllers\ImportController;
use App\Modules\SystemAdmin\Http\Controllers\LookController;
use App\Modules\SystemAdmin\Http\Controllers\NoticeCategoryController;
use App\Modules\SystemAdmin\Http\Controllers\NoticeController;
use App\Modules\SystemAdmin\Http\Controllers\NoticeReportController;
use App\Modules\SystemAdmin\Http\Controllers\NoticeTemplateController;
use App\Modules\SystemAdmin\Http\Controllers\OwnershipController;
use App\Modules\SystemAdmin\Http\Controllers\PrintControlController;
use App\Modules\SystemAdmin\Http\Controllers\ReportDownloadController;
use App\Modules\SystemAdmin\Http\Controllers\ReportScheduleController;
use App\Modules\SystemAdmin\Http\Controllers\RoleController;
use App\Modules\SystemAdmin\Http\Controllers\SettingsController;
use App\Modules\SystemAdmin\Http\Controllers\SetupController;
use App\Modules\SystemAdmin\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
 * System Administration-এর রুট।
 *
 * ব্যাকআপের পর্দা এখনো লেখা হয়নি, আর module.php-তে সেটা planned
 * হিসেবেই আছে। মেনুতে মৃত সারি রাখা হয় না।
 */

/*
 * প্রথম দরজা — একদম নতুন ইনস্টলের একমাত্র প্রবেশপথ।
 *
 * ── কেন এই দুইটা রুট `auth`-এর বাইরে ────────────────────────────────
 * এটাই সেই মুহূর্ত যখন লগইন করার মতো কেউ নেই: `migrate` চলে গেছে,
 * লগইনের পর্দা আসে, কিন্তু `users` টেবিল খালি আর ব্যবহারকারী বানানোর
 * পর্দাটা `auth`-এর পিছনে। **লগইন করতে ব্যবহারকারী লাগে, ব্যবহারকারী
 * বানাতে লগইন লাগে** — এই বৃত্তটা কাটার জন্যই দরজাটা।
 *
 * আমাদের সার্ভারে বিক্রি হলে ফাঁকটা কেউ টের পেত না, কারণ প্রথম
 * ব্যবহারকারীটা হাতে বসিয়ে দেওয়া হয়। ক্রেতার নিজের সার্ভারে সেই
 * লোকটাই নেই (৩ সেপ্টেম্বর ২০২৬-এর সিদ্ধান্ত: ABOS দুইভাবেই বিক্রি হয়)।
 *
 * ── পাহারা কোথায় ────────────────────────────────────────────────────
 * চাবিতে নয়, সময়ে: একটাও ব্যবহারকারী বসে গেলে দুইটা রুটই ৪০৪
 * ([[SetupController]])। আর দুইটা অনুরোধ একই মুহূর্তে এলে সেটা থামায়
 * ডাটাবেস, `FirstRun::open()`-এর তালা ([[FirstRun]]) — throttle হারের
 * সীমা, পারমাণবিকতা নয়।
 *
 * ⚠️ তবু throttle আছে, আর লগইনের সমান (`throttle:10,1`,
 * `routes/auth.php:43`)। কারণ গার্ডের নিজের ভাষায়: **দুইটার একটাতে
 * তালা মানে তালা নেই** — দরজা দুইটা হলে ঢিলাটাই ব্যবহার হয়।
 *
 * ── কেন `guest` মিডলওয়্যার নয় ───────────────────────────────────────
 * `guest` লগইন-করা মানুষকে ড্যাশবোর্ডে ফেরত পাঠায়। কিন্তু এখানে
 * লগইন-করা কেউ থাকতেই পারে না (থাকলে দরজাটা এমনিতেই ৪০৪), আর
 * `store`-এর শেষে আমরা নিজেরাই `Auth::login()` করি — `guest` থাকলে
 * সেই redirect-টাই আটকাত।
 */
Route::prefix('setup')->name('setup.')->group(function () {
    Route::get('/', [SetupController::class, 'show'])->name('show');
    Route::post('/', [SetupController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('store');
});

Route::middleware('auth')->prefix('system')->group(function () {
    /*
     * ব্যাকআপের দরজা এখান থেকে সরেছে — ৩ সেপ্টেম্বর ২০২৬।
     *
     * এখন `app/Modules/Backup/Routes/web.php`-এ, আর নাম
     * `system_admin.backup.*` থেকে `backup.*`। উপসর্গটা
     * [[ModuleServiceProvider]] বসায় ফোল্ডারের নাম থেকে
     * (`->name($code.'.')`), তাই ফাইল সরালে নামও সরে।
     *
     * ⚠️ "ফিরিয়ে আনার রুট নেই" নিয়মটা নতুন মডিউলেও অক্ষত — ফিরিয়ে
     * আনা মানে আজকের সব কাজ মুছে ফেলা, আর পর্দায় ভুল ক্লিক হয়।
     */

    Route::get('/control-panel', [ControlPanelController::class, 'edit'])->name('control-panel');
    Route::put('/control-panel', [ControlPanelController::class, 'update'])->name('control-panel.update');

    /*
     * শাখার মডিউল — ২৮ নভেম্বর ২০২৬।
     *
     * ⓘ কন্ট্রোল প্যানেল বলে *"এই ব্যবসা মডিউলটা নেয়ইনি"*; এটা বলে
     * *"এই ডিপোতে লাগে না, ঐ ডিপোতে লাগে"*। ⚠️ আগে দ্বিতীয় কথাটা বলার
     * কোনো উপায় ছিল না — সুইচ ছিল কেবল কোম্পানির স্তরে।
     *
     * ⛔ একই চাবি (`settings.manage`), কারণ দুইটা পর্দা একই প্রশ্নের
     * দুই অর্ধেক, আর অর্ধেক উত্তর বদলানোর অধিকার কোনো অধিকার নয়।
     */
    Route::get('/branch-modules', [BranchModuleController::class, 'edit'])->name('branch-module');
    Route::put('/branch-modules', [BranchModuleController::class, 'update'])->name('branch-module.update');

    /*
     * নোটিশ — প্রতিষ্ঠানের নিজের কথা, ২২ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ `index` আর `show`-এ কোনো চাবি নেই, আর সেটা ইচ্ছাকৃত: নোটিশ
     * **সবার জন্য**। ⓘ চাবি চাইলে ঠিক তাঁরাই বাদ পড়তেন যাঁদের জন্য
     * নোটিশটা লেখা। ⚠️ কে কোনটা দেখবেন সেটা ভূমিকা ঠিক করে
     * ([[NoticeBoard::forUser()]]), আর `show()` নিজে সেটা মেলায়।
     *
     * ⓘ লেখা-বদলানোর দরজাগুলো চাবির পিছনে।
     */
    Route::get('/notices', [NoticeController::class, 'index'])->name('notice.index');

    /*
     * নোটিশের হিসাব — নিজের চাবি।
     *
     * ⓘ সংখ্যাগুলো কর্মীদের নাম ধরে বলে *"কে এখনো মানেননি"* —
     * ⚠️ ওটা সবার দেখার জিনিস নয়, তাই লেখার চাবির সাথেও এটা
     * মেলানো হয়নি।
     *
     * ⛔ পথটা `{notice}`-এর **আগে**, নাহলে `analytics` কথাটাকে একটা
     * নোটিশের নম্বর ভাবা হত।
     */
    /*
     * ⛔ নোটিশের রিপোর্ট — আর এই সারিটা লাইভে একটা পর্দা গিলে ফেলেছিল।
     *
     * ── ⚠️ কী হয়েছিল, ২৪ সেপ্টেম্বর ২০২৬ ────────────────────────────
     * এটা ছিল `/reports/schedules`-এর **উপরে**, আর `{slug}` যেকোনো শব্দ
     * ধরে। ⓘ ফলে রিপোর্টের সময়সূচির পর্দাটা এই কন্ট্রোলারে যেত, সে
     * `schedules` নামে কোনো রিপোর্ট না পেয়ে ৪০৪ দিত।
     *
     * ⛔ আর ভুলটা কোথাও লাল হত না: কন্ট্রোলারটা **ঠিক কাজই** করছিল
     * (অচেনা slug-এ ৪০৪ দেওয়াই তার দাবি), রুটটাও ঠিক ছিল, কেবল **ক্রমটা**
     * ভুল ছিল। ⓘ ধরা পড়েছে একমাত্র লাইভের স্বাস্থ্য-হাঁটায়।
     *
     * ⭐ তাই প্যারামিটারওয়ালা পথ সবসময় **সব লেখা পথের পরে** — ঠিক যে
     * নিয়মটা নিচে `{notice}`-এর বেলায় আগে থেকেই লেখা আছে।
     */
    /* ⓘ সারিটা এখন সময়সূচির রুটগুলোর **নিচে** — কারণসহ ওখানেই লেখা। */

    Route::get('/notices/analytics', [NoticeController::class, 'analytics'])
        ->middleware('can:system_admin.notice.analytics')->name('notice.analytics');

    /*
     * বার থেকে সরিয়ে দেওয়া — লেখার চাবির বাইরে, ইচ্ছাকৃত।
     *
     * ⓘ সরানোটা পাঠকের কাজ, আর সরালে কেবল নিজের পর্দা থেকে
     * সরে। ⚠️ লেখার চাবি চাইলে গুদামের কেউ একটা পুরনো নোটিশ
     * চিরকাল চোখের সামনে নিয়ে ঘুরতেন।
     */
    /*
     * সই দেওয়া — লেখার চাবির বাইরে, সরানোর মতোই।
     *
     * ⓘ সই দেন পাঠক। ⚠️ লেখার চাবি চাইলে যাঁদের জন্য নোটিশ
     * তাঁরাই সই দিতে পারতেন না, আর সংখ্যাটা চিরকাল শূন্য থাকত।
     */
    Route::post('/notices/{notice}/sign', [NoticeController::class, 'sign'])
        ->whereNumber('notice')->name('notice.sign');

    Route::post('/notices/{notice}/dismiss', [NoticeController::class, 'dismiss'])
        ->whereNumber('notice')->name('notice.dismiss');

    Route::middleware('can:system_admin.notice.manage')->group(function () {
        /*
         * নোটিশের ছাঁচ — লেখার চাবির নিচেই।
         *
         * ⓘ ছাঁচ বানানো মানে ভবিষ্যতের নোটিশের শুরুটা ঠিক করা —
         * ⚠️ অগ্রাধিকারও ওখানে বসে, তাই এটা লেখার কাজই।
         */
        /*
         * প্রত্যাহার · সংরক্ষণাগার · ফেরত — মোছার বদলে তিনটা পথ।
         *
         * ⓘ `Route::delete` নেই, আর সেটা ইচ্ছাকৃত: ⛔ প্রকাশিত নোটিশ
         * মোছা যায় না ([[Notice]]-এর `deleting` পাহারা), তাই একটা মোছার
         * বোতাম থাকলে সেটা প্রতিবার একটা ত্রুটি দেখাত।
         */
        /*
         * ⓘ তিনটাই `notice.recall` চায়, লেখার চাবির উপরে।
         *
         * ⚠️ যিনি নোটিশ লেখেন আর যিনি প্রকাশিত নোটিশ তুলে নেন —
         * দুইজন এক হতে হবে এমন কোনো কথা নেই। ⛔ লেখার চাবিই যথেষ্ট
         * ধরলে গুদামের কেউ গোটা অফিসের পড়া একটা নোটিশ এক ক্লিকে
         * তুলে নিতে পারতেন।
         */
        Route::middleware('can:system_admin.notice.recall')->group(function () {
            Route::post('/notices/{notice}/recall', [NoticeController::class, 'recall'])
                ->whereNumber('notice')->name('notice.recall');
            Route::post('/notices/{notice}/archive', [NoticeController::class, 'archive'])
                ->whereNumber('notice')->name('notice.archive');
            Route::post('/notices/{notice}/restore', [NoticeController::class, 'restore'])
                ->whereNumber('notice')->name('notice.restore');
        });

        /*
         * নোটিশের ধরন — লেখার চাবির নিচেই।
         *
         * ⓘ ক্যাটাগরি বলে এই ধরনের নোটিশ সাধারণত কতটা জরুরি —
         * ⚠️ সেটা ঠিক করা লেখার কাজ, পড়ার নয়।
         */
        Route::get('/notices/categories', [NoticeCategoryController::class, 'index'])
            ->name('notice.category.index');
        Route::post('/notices/categories', [NoticeCategoryController::class, 'store'])
            ->name('notice.category.store');

        Route::get('/notices/templates', [NoticeTemplateController::class, 'index'])
            ->name('notice.template.index');
        Route::post('/notices/templates', [NoticeTemplateController::class, 'store'])
            ->name('notice.template.store');
        Route::post('/notices/templates/{template}/use', [NoticeTemplateController::class, 'use'])
            ->whereNumber('template')->name('notice.template.use');
        Route::delete('/notices/templates/{template}', [NoticeTemplateController::class, 'destroy'])
            ->whereNumber('template')->name('notice.template.destroy');

        Route::get('/notices/new', [NoticeController::class, 'create'])->name('notice.create');
        Route::post('/notices', [NoticeController::class, 'store'])->name('notice.store');
        Route::get('/notices/{notice}/edit', [NoticeController::class, 'edit'])
            ->whereNumber('notice')->name('notice.edit');
        Route::put('/notices/{notice}', [NoticeController::class, 'update'])
            ->whereNumber('notice')->name('notice.update');
    });

    /* ⚠️ `{notice}`-টা সবার শেষে — নাহলে `/notices/new` এখানে ধরা পড়ত। */
    Route::get('/notices/{notice}', [NoticeController::class, 'show'])
        ->whereNumber('notice')->name('notice.show');

    /*
     * প্রতিষ্ঠানের সেটিংস — ৭ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ মডিউলগুলো ৭৪টা সুইচ ঘোষণা করত আর **৬৯টার কোনো পর্দাই ছিল না**;
     * লাইভের `settings` টেবিলে তিনটা সারি ছিল, কারণটা এটাই।
     *
     * ⚠️ Control Panel-এর পাশে, তার বদলে নয়: ওখানে পর্দা চালু/বন্ধ হয়
     * (আর "কাগজ ধরা আছে কি না" পাহারাটা ওখানেই), এখানে এন্ট্রির নিয়ম।
     */
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    /*
     * ⭐ ছাপার নিয়ন্ত্রণ — মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬।
     *
     * *"ইনভয়েজের জন্য কন্ট্রোল প্যানেলে আলাদা ট্যাব করো … কি কি প্রিন্টে
     * আসবে কি কি কলাম দিবে কোনটার পর কোনটা সব কিছুই নিয়ন্ত্রণ হবে সুইচে।"*
     *
     * ⓘ সেটিংসের পাশে, তার ভিতরে নয়: ঐ পর্দা সুইচ ও লেখার ঘর আঁকে, আর
     * **ক্রম** ওই দুইটার কোনোটাই নয়। ⚠️ একই চাবি (`settings.manage`),
     * কারণ প্রশ্নটা একই — প্রতিষ্ঠান তার কাগজ কেমন চায়।
     */
    Route::get('/print-control', [PrintControlController::class, 'edit'])->name('print_control');
    Route::put('/print-control', [PrintControlController::class, 'update'])->name('print_control.update');

    /*
     * ⭐ রূপের নমুনা — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।
     *
     * *"print e invoice template vew kore deke select korar bebosta koro.
     * zate age sample dekha zay tarpor select kora zay"*।
     *
     * ⓘ একই চাবি, কারণ এটা ঐ পর্দারই একটা অংশ — নিয়ন্ত্রণের পাতাটা
     * নিজের iframe-এ এটাকেই ডাকে। ⚠️ কাগজটা [[PrintSample]]-এর বানানো,
     * ডেটাবেস থেকে তোলা কারও বিল নয় — নাহলে এই রুটটা বিক্রয়ের কাগজ
     * দেখার একটা দ্বিতীয় দরজা হত।
     */
    Route::get('/print-control/preview', [PrintControlController::class, 'preview'])
        ->name('print_control.preview');

    /*
     * নির্ধারিত রিপোর্ট — সূচি ব্যবস্থাপনা ও ফাইল নামানো।
     *
     * ব্যবস্থাপনার রুটগুলো `system_admin.reports.schedule` চাবিতে (can:);
     * download আলাদা, কারণ প্রাপক ব্যবস্থাপক না-ও হতে পারেন — ওখানে
     * অনুমতি রেকর্ড দেখে (ReportRunPolicy), স্থির চাবি নয়। মোছার রুট নেই:
     * সূচি নিষ্ক্রিয় হয় (toggle), মোছে না — ইতিহাস অক্ষত।
     */
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/schedules', [ReportScheduleController::class, 'index'])->name('schedule.index');
        Route::get('/schedules/create', [ReportScheduleController::class, 'create'])->name('schedule.create');
        Route::post('/schedules', [ReportScheduleController::class, 'store'])->name('schedule.store');
        Route::get('/schedules/{schedule}/edit', [ReportScheduleController::class, 'edit'])
            ->whereNumber('schedule')->name('schedule.edit');
        Route::put('/schedules/{schedule}', [ReportScheduleController::class, 'update'])
            ->whereNumber('schedule')->name('schedule.update');
        Route::post('/schedules/{schedule}/toggle', [ReportScheduleController::class, 'toggle'])
            ->whereNumber('schedule')->name('schedule.toggle');

        Route::get('/runs/{run}/download', [ReportDownloadController::class, 'download'])
            ->whereNumber('run')->name('download');
    });

    /*
     * ⛔ নোটিশের রিপোর্ট — আর এই সারিটা লাইভে একটা পর্দা গিলে ফেলেছিল।
     *
     * ── ⚠️ কী হয়েছিল, ২৪ সেপ্টেম্বর ২০২৬ ────────────────────────────
     * সারিটা ছিল উপরে, সময়সূচির রুটগুলোর **আগে**, আর `{slug}` যেকোনো
     * শব্দ ধরে। ⓘ ফলে `/system/reports/schedules` এই কন্ট্রোলারে যেত,
     * সে `schedules` নামে কোনো রিপোর্ট না পেয়ে ৪০৪ দিত — আর রিপোর্টের
     * সময়সূচির পর্দাটা **লাইভে পুরোপুরি অদৃশ্য** ছিল।
     *
     * ⛔ ভুলটা কোথাও লাল হয়নি, কারণ প্রতিটা টুকরো আলাদাভাবে ঠিক ছিল:
     * কন্ট্রোলারটা অচেনা slug-এ ৪০৪ দেয় — ওটাই তার ঘোষিত দাবি; রুট
     * দুইটাও ঠিক। ⚠️ ভুল ছিল কেবল **ক্রম**, আর ক্রম কোনো ফাইলের ভিতরে
     * দেখা যায় না।
     *
     * ⓘ ধরা পড়েছে একমাত্র লাইভের স্বাস্থ্য-হাঁটায় — ৩৮২টা পাতার একটা।
     * ⭐ নিয়মটা এই ফাইলেই আগে থেকে লেখা আছে (`{notice}`-এর বেলায়):
     * প্যারামিটারওয়ালা পথ সবসময় সব লেখা পথের পরে।
     */
    Route::get('/reports/{slug}', [NoticeReportController::class, 'show'])->name('report.show');

    /*
     * কোম্পানি ও শাখা।
     *
     * মোছার কোনো রুট নেই, ইচ্ছাকৃতভাবে — একটা কোম্পানি মানে তার প্রতিটা
     * বিল, চালান ও খতিয়ানের সারি। নিষ্ক্রিয় করা যায় (toggle), তাতে
     * সুইচার থেকে সরে যায় কিন্তু কাগজপত্র অক্ষত থাকে।
     */
    Route::prefix('companies')->name('company.')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->name('index');
        Route::get('/create', [CompanyController::class, 'create'])->name('create');
        Route::post('/', [CompanyController::class, 'store'])->name('store');
        Route::get('/{company}/edit', [CompanyController::class, 'edit'])->whereNumber('company')->name('edit');
        Route::put('/{company}', [CompanyController::class, 'update'])->whereNumber('company')->name('update');
        Route::post('/{company}/branches', [CompanyController::class, 'storeBranch'])
            ->whereNumber('company')->name('branch.store');
        Route::post('/{company}/toggle', [CompanyController::class, 'toggle'])
            ->whereNumber('company')->name('toggle');
    });

    /*
     * ব্যবহারকারী ও রোল।
     *
     * মোছার কোনো রুট নেই, দুইটাতেই — ব্যবহারকারীর নাম প্রতিটা বিলে ও
     * অডিটের সারিতে বসে আছে, আর রোল মুছলে যাঁরা ওটা ধরে আছেন তাঁরা
     * নীরবে সব অধিকার হারাতেন। নিষ্ক্রিয় করাই যথেষ্ট।
     */
    Route::prefix('users')->name('user.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->whereNumber('user')->name('update');
    });

    /*
     * মালিকানা হস্তান্তর — নিজের পাতা, ইচ্ছাকৃতভাবে।
     *
     * ⓘ কাজটা ব্যবহারকারী-সম্পাদনার পর্দাতেও বসানো যেত, রোলের আরেকটা
     * চেকবক্স হিসেবে। ⛔ কিন্তু তাতে ব্যবস্থার সবচেয়ে বড় চাবিটা হাতবদল
     * করা দেখতে হত বাকি দশটা সিদ্ধান্তের মতোই — আর যে জিনিস দেখতে
     * সাধারণ, সেটা সাবধানে করা হয় না।
     *
     * ⚠️ অনুমতিটা কেবল দরজার নামফলক; আসল তালাটা পরিচয়ে — কন্ট্রোলার
     * দেখে অনুরোধকারী সত্যিই এই কোম্পানির মালিক কি না, আর পাসওয়ার্ড
     * চায়। খোলা রেখে যাওয়া একটা স্ক্রিনই নইলে যথেষ্ট হত।
     */
    Route::prefix('ownership')->name('ownership.')->group(function () {
        Route::get('/', [OwnershipController::class, 'show'])->name('show');
        Route::put('/', [OwnershipController::class, 'update'])->name('update');
    });

    Route::prefix('roles')->name('role.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('index');
        Route::get('/create', [RoleController::class, 'create'])->name('create');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::get('/{role}/edit', [RoleController::class, 'edit'])->whereNumber('role')->name('edit');
        Route::put('/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('update');
    });

    /*
     * কোম্পানির নিজের রূপ — থিম ইঞ্জিনের ধাপ ৩।
     *
     * ── মোছার রুট নেই, আর এখানে কারণটা আলাদা ─────────────────────────
     * অন্য পর্দাগুলোয় মোছা হয় না কারণ কাগজপত্র সারিটার নাম ধরে আছে।
     * এখানে কারণটা হলো: একটা রূপ মুছলে যাঁরা ওটা পরে আছেন তাঁদের
     * পর্দা ওই মুহূর্তে বদলে যেত, আর কেউ জানত না কেন। রূপ পুরনো হয়,
     * ব্যবহার বন্ধ হয় — কিন্তু সারিটা থাকে।
     *
     * ── প্রিভিউ POST, GET নয় ─────────────────────────────────────────
     * প্রিভিউ সেশন বদলায়, তাই ওটা একটা কাজ — দেখা নয়। GET রাখলে
     * উপরের speculationrules ব্লকটা মাউস ছোঁয়া মাত্র প্রিভিউ চালু
     * করে দিত, আর ব্যবহারকারী কিছু না করেই গোটা ERP অন্য রঙে দেখতেন।
     */
    Route::prefix('looks')->name('look.')->group(function () {
        Route::get('/', [LookController::class, 'index'])->name('index');
        Route::get('/create', [LookController::class, 'create'])->name('create');
        Route::post('/', [LookController::class, 'store'])->name('store');
        Route::post('/preview/stop', [LookController::class, 'previewStop'])->name('preview.stop');

        /*
         * আমদানি — POST, কারণ এটা একটা সারি বসায়।
         *
         * রপ্তানি GET, আর সেটাও ঠিক: সে কিছু বদলায় না। তবে পাতাটার
         * speculationrules ব্লকে ডাউনলোড-লিংকগুলো আগেই বাদ দেওয়া আছে
         * (`[download]`), তাই মাউস ছোঁয়া মাত্র ফাইলটা নেমে আসে না।
         */
        Route::post('/import', [LookController::class, 'import'])->name('import');
        Route::get('/{skin}/edit', [LookController::class, 'edit'])->whereNumber('skin')->name('edit');
        Route::put('/{skin}', [LookController::class, 'update'])->whereNumber('skin')->name('update');
        Route::post('/{skin}/publish', [LookController::class, 'publish'])
            ->whereNumber('skin')->name('publish');
        Route::get('/{skin}/export', [LookController::class, 'export'])
            ->whereNumber('skin')->name('export');
        Route::post('/{skin}/preview', [LookController::class, 'preview'])
            ->whereNumber('skin')->name('preview');
        Route::post('/{skin}/revert/{version}', [LookController::class, 'revert'])
            ->whereNumber('skin')->whereNumber('version')->name('revert');
    });

    /*
     * নিজস্ব ঘর — এক পর্দায় সব।
     *
     * ঘর সাজানো সেটিংসের কাজ, তাই এখানে; কিন্তু ঘরগুলো ব্যবহার হয়
     * গ্রাহক, পণ্য ও সরবরাহকারীর ফর্মে।
     */
    Route::prefix('custom-fields')->name('custom_field.')->group(function () {
        Route::get('/', [CustomFieldController::class, 'index'])->name('index');
        Route::post('/', [CustomFieldController::class, 'store'])->name('store');
        Route::put('/{field}', [CustomFieldController::class, 'update'])
            ->whereNumber('field')->name('update');
        Route::delete('/{field}', [CustomFieldController::class, 'destroy'])
            ->whereNumber('field')->name('destroy');
    });
});

/*
 * পুরনো খাতা থেকে আনা।
 *
 * template রুটটা {kind} নিয়ে, আর সেটা check/store-এর আগে — নাহলে
 * ভবিষ্যতে কোনো স্থির পথ যোগ করলে সেটাকে kind ভেবে ৪০৪ দিত।
 */
Route::middleware('auth')->prefix('import')->name('import.')->group(function () {
    Route::get('/', [ImportController::class, 'index'])->name('index');
    Route::get('/template/{kind}', [ImportController::class, 'template'])->name('template');
    Route::post('/check', [ImportController::class, 'check'])->name('check');
    Route::post('/', [ImportController::class, 'store'])->name('store');
});
