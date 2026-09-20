<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\BankFacilityController;
use App\Modules\Finance\Http\Controllers\AccountAnalysisController;
use App\Modules\Finance\Http\Controllers\BankChargeController;
use App\Modules\Finance\Http\Controllers\CarrierAndLabourController;
use App\Modules\Finance\Http\Controllers\CapitalController;
use App\Modules\Finance\Http\Controllers\DepositController;
use App\Modules\Finance\Http\Controllers\ExpenseController;
use App\Modules\Finance\Http\Controllers\HandLoanController;
use App\Modules\Finance\Http\Controllers\IncomeController;
use App\Modules\Finance\Http\Controllers\InstitutionController;
use App\Modules\Finance\Http\Controllers\InsuranceController;
use App\Modules\Finance\Http\Controllers\PlanController;
use App\Modules\Finance\Http\Controllers\RentalContractController;
use App\Modules\Finance\Http\Controllers\WithdrawalController;
use App\Modules\Finance\Models\DepositKind;
use Illuminate\Support\Facades\Route;

/*
 * অর্থের পর্দাগুলো।
 *
 * ── কেন `finance/` উপসর্গ, `accounts/` নয় ────────────────────────────
 * ঠিকানাটাই বলে দেয় জিনিসটা কার। কেউ `accounts/capital` বুকমার্ক করে
 * রাখলে সে নতুন জায়গায় পৌঁছায় — নিচের পুনর্নির্দেশটা সেজন্যই, আর
 * সেটা মডিউল ভাগ করার সময়কার একটা নিয়ম: পুরনো ঠিকানা ভাঙে না।
 */
/*
 * `web` ও `finance.` উপসর্গ দুইটাই প্রদানকারী বসায়
 * ([[ModuleServiceProvider::registerRoutes()]])। এখানে আবার লিখলে
 * নামটা `finance.finance.capital.index` হয়ে যেত — প্রথম চেষ্টায় ঠিক
 * তাই হয়েছিল, আর রুটের তালিকাতেই ধরা পড়ল।
 */
Route::middleware('auth')->prefix('finance')->group(function () {
    Route::get('/plan', [PlanController::class, 'index'])->name('plan');

    /*
     * খরচ — কোন খাতে কত গেল।
     *
     * লেখার পথ নেই: খরচ লেখা হয় ভাউচারেই। দুইটা পথ থাকলে দুইটার
     * যাচাই একদিন আলাদা হয়ে যেত।
     */
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expense.index');

    /*
     * আয় — খরচের আয়না, একই প্রশ্ন উল্টো দিক থেকে।
     *
     * লেখার পথ নেই, খরচের মতোই: আয় বসে বিক্রয়ের বিলে বা ভাউচারে।
     * দুইটা পথ থাকলে দুইটার যাচাই একদিন আলাদা হয়ে যেত।
     */
    Route::get('/income', [IncomeController::class, 'index'])->name('income.index');

    /*
     * মূলধন ও বিনিয়োগ।
     *
     * `post` আলাদা একটা POST, কারণ ওটাই আসল ঘটনা: লিখে রাখা নিরীহ,
     * পোস্ট করা মানে খাতায় টাকা বসে যাওয়া।
     */
    Route::prefix('capital')->name('capital.')->group(function () {
        Route::get('/', [CapitalController::class, 'index'])->name('index');
        Route::get('/create', [CapitalController::class, 'create'])->name('create');
        Route::post('/', [CapitalController::class, 'store'])->name('store');
        /*
         * সম্পাদনা ও মোছা — ⛔ কেবল খসড়া, আর পাহারাটা কন্ট্রোলারে।
         *
         * ⓘ `edit` তালিকার পাতাটাই আবার আঁকে, ঘরগুলো ভরা অবস্থায় —
         * আলাদা পাতা নয়, কারণ ফর্মটা ঐ পাতাতেই বসে।
         */
        Route::get('/{entry}/edit', [CapitalController::class, 'edit'])
            ->whereNumber('entry')->name('edit');
        Route::put('/{entry}', [CapitalController::class, 'update'])
            ->whereNumber('entry')->name('update');
        Route::delete('/{entry}', [CapitalController::class, 'destroy'])
            ->whereNumber('entry')->name('destroy');

        Route::post('/{entry}/post', [CapitalController::class, 'post'])
            ->whereNumber('entry')->name('post');
    });

    /*
     * সঞ্চয় ও বিনিয়োগ — এক পর্দা, তিনটা ঠিকানা।
     *
     * ── কেন ইস্যুয়ারটা পথের অংশ, প্রশ্নচিহ্নের পরে নয় ────────────────
     * মেনু কোন সারিটা সক্রিয় তা রুটের প্যারামিটার মিলিয়ে বলে
     * ([[MenuBuilder::paramsMatch()]])। কোয়েরি স্ট্রিং হলে তিনটা সারিই
     * একসাথে সক্রিয় দেখাত, আর ব্যবহারকারী জানত না সে কোথায় আছে।
     */
    /*
     * ── কেন প্রতিটা ঠিকানায় ইস্যুয়ারটা থেকে যায়, রেকর্ডের পাতাতেও ────
     * মেনুর সারিটা সক্রিয় থাকে ইস্যুয়ার মিললে। একটা FD খুলে ভেতরে
     * ঢুকলে যদি প্যারামিটারটা হারিয়ে যেত, বাঁ পাশের মেনুতে "ব্যাংক
     * আমানত" নিভে যেত — আর ব্যবহারকারী জানত না সে কোথায় আছে।
     */
    /*
     * উত্তোলন — মালিক/অংশীদারের টাকা তোলা।
     *
     * ── কেন `cap` আলাদা POST, একই পর্দায় ────────────────────────────
     * সীমা বদলানো উত্তোলন লেখার চেয়ে কড়া ক্ষমতা: যে কেরানি উত্তোলন
     * লিখতে পারেন, তাঁর সীমা বদলানোর ক্ষমতা থাকার কথা নয়। আলাদা
     * পথ মানে আলাদা চাবি।
     */
    Route::prefix('withdrawals')->name('withdrawal.')->group(function () {
        Route::get('/', [WithdrawalController::class, 'index'])->name('index');
        /*
         * ⭐ লেখার পাতা — নমুনার কাঠামোয়, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ `index` রয়ে গেছে **পড়ার** পাতা হিসেবে: মাসের হিসাব, কে
         * কোথায় দাঁড়িয়ে, মাসিক সীমা, আর তোলা টাকার তালিকা।
         *
         * ⛔ দুইটা এক পাতায় ছিল, আর নমুনার সাথে মিলত না: নমুনায়
         * উত্তোলন হলো মালিকের পুঁজির **দ্বিতীয় দিক**, আলাদা চেহারার
         * পাতা নয়।
         */
        Route::get('/create', [WithdrawalController::class, 'create'])->name('create');
        Route::post('/', [WithdrawalController::class, 'store'])->name('store');
        Route::post('/cap', [WithdrawalController::class, 'cap'])->name('cap');

        Route::post('/{withdrawal}/post', [WithdrawalController::class, 'post'])
            ->whereNumber('withdrawal')->name('post');
    });

    /*
     * হাতধার — নিজের মেনু, নিজের পূর্ণাঙ্গ হিসাব।
     *
     * মালিকের নির্দেশ: *"hand loan আলাদা মেনু করো মানে পূর্ণাঙ্গ হিসাব
     * আলাদা"*। ঋণের ফর্মে এটা একটা `kind` ছিল, আর HP-র রিপোর্টে ওই
     * ব্যবস্থার ফলটাই ধরা পড়েছে: "Hand loan" বাছলে সেভ হত "CC" হিসেবে।
     */
    /*
     * ব্যাংকের সুবিধা — ১৬ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ হাতধারের সাথে এক পর্দায় রাখা যেত না: মঞ্জুরিপত্র · জামানত ·
     * ড্রয়িং পাওয়ার · বার্ষিক নবায়ন — একটাও হাতধারে নেই।
     *
     * ⚠️ আর `move`/`settle` এখানে **নেই**, ইচ্ছাকৃতভাবে: টাকা নাড়ে
     * ভাউচার, খাতা নয়। ⓘ এখানে কেবল নথিটা খোলা ও বন্ধ করা যায়।
     */
    Route::prefix('bank-facilities')->name('bank_facility.')->group(function () {
        Route::get('/', [BankFacilityController::class, 'index'])->name('index');
        Route::post('/', [BankFacilityController::class, 'store'])->name('store');

        Route::get('/{bankFacility}', [BankFacilityController::class, 'show'])
            ->whereNumber('bankFacility')->name('show');

        Route::post('/{bankFacility}/close', [BankFacilityController::class, 'close'])
            ->whereNumber('bankFacility')->name('close');
    });

    Route::prefix('hand-loans')->name('hand_loan.')->group(function () {
        Route::get('/', [HandLoanController::class, 'index'])->name('index');
        Route::post('/', [HandLoanController::class, 'store'])->name('store');

        Route::get('/{handLoan}', [HandLoanController::class, 'show'])
            ->whereNumber('handLoan')->name('show');

        Route::post('/{handLoan}/move', [HandLoanController::class, 'move'])
            ->whereNumber('handLoan')->name('move');

        Route::post('/{handLoan}/settle', [HandLoanController::class, 'settle'])
            ->whereNumber('handLoan')->name('settle');
    });

    /*
     * ভাড়ার চুক্তি ও জামানত।
     *
     * ⓘ হাতধারের ঠিক পরে, কারণ দুইটাই একই আকৃতির: একটা চলমান হিসাব,
     * তার উপরে ঘটনা, আর প্রতিটা ঘটনা একটা ভাউচার।
     */
    Route::prefix('rentals')->name('rental.')->group(function () {
        Route::get('/', [RentalContractController::class, 'index'])->name('index');
        Route::post('/', [RentalContractController::class, 'store'])->name('store');

        Route::get('/{contract}', [RentalContractController::class, 'show'])
            ->whereNumber('contract')->name('show');

        Route::post('/{contract}/adjust', [RentalContractController::class, 'adjust'])
            ->whereNumber('contract')->name('adjust');

        /*
         * ⓘ শর্ত বদল `PUT` — সে বিদ্যমান কিছু বদলায়, নতুন কিছু বানায়
         * না। বাকিগুলো `POST`, কারণ প্রতিটা একটা **নতুন ঘটনা**: এক
         * মাসের ভাড়া, জামানতে টাকা, চুক্তি শেষ।
         */
        Route::put('/{contract}', [RentalContractController::class, 'revise'])
            ->whereNumber('contract')->name('revise');

        Route::post('/{contract}/top-up', [RentalContractController::class, 'topUp'])
            ->whereNumber('contract')->name('topup');

        Route::post('/{contract}/close', [RentalContractController::class, 'close'])
            ->whereNumber('contract')->name('close');
    });

    Route::prefix('deposits')->name('deposit.')->group(function () {
        /*
         * সব জমা — ড্যাশবোর্ডের টালি এখানে নামে।
         *
         * ⓘ `/{issuer}`-এর সাথে সংঘর্ষ নেই: ওটা `whereIn(ISSUERS)` দিয়ে
         * বাঁধা, তাই কেবল তিনটা নামই মানে।
         */
        Route::get('/', [DepositController::class, 'all'])->name('all');

        Route::get('/{issuer}', [DepositController::class, 'index'])
            ->whereIn('issuer', DepositKind::ISSUERS)->name('index');

        Route::post('/{issuer}', [DepositController::class, 'store'])
            ->whereIn('issuer', DepositKind::ISSUERS)->name('store');

        /*
         * একটা জমার নিজের পাতা।
         *
         * ── কেন তালিকার ঘরে কাজগুলো নয় ─────────────────────────────
         * কিস্তি দিতে চাই তারিখ, টাকা আর কোন খাত — তিনটা ঘর। ওগুলো
         * তালিকার শেষ কলামে গুঁজলে কলামটা এত সরু হত যে ছোট পর্দায়
         * একটার ঘাড়ে আরেকটা পড়ত, আর টেবিলের স্ক্রলার প্যানেলটা
         * কেটে দিত — টপ-নেভে ঠিক এই ভুলটাই ধরা পড়েছিল।
         *
         * আর চলাচলের ইতিহাসটাও এখানেই: প্রতিটা সংখ্যা তার ভাউচারে
         * নামায় (নিয়ম ১), আর তালিকার একটা ঘরে ষাটটা কিস্তি ধরত না।
         */
        Route::get('/{issuer}/{deposit}', [DepositController::class, 'show'])
            ->whereIn('issuer', DepositKind::ISSUERS)->whereNumber('deposit')->name('show');

        Route::post('/{issuer}/{deposit}/movement', [DepositController::class, 'movement'])
            ->whereIn('issuer', DepositKind::ISSUERS)->whereNumber('deposit')->name('movement');

        Route::post('/{issuer}/{deposit}/close', [DepositController::class, 'close'])
            ->whereIn('issuer', DepositKind::ISSUERS)->whereNumber('deposit')->name('close');

        /*
         * ভুল এন্ট্রি ফিরিয়ে নেওয়া — `close` থেকে আলাদা পথ।
         *
         * একই পথে রাখলে একটা পতাকা দিয়ে দুইটা আলাদা ঘটনা আলাদা করতে
         * হত, আর অনুমতিও এক হয়ে যেত। ভাঙা রোজকার কাজ; ভুল ফেরানো
         * খাতায় হাত দেওয়া।
         */
        Route::post('/{issuer}/{deposit}/cancel', [DepositController::class, 'cancel'])
            ->whereIn('issuer', DepositKind::ISSUERS)->whereNumber('deposit')->name('cancel');
    });

    /*
     * ব্যাংক চার্জ — মানচিত্র §৯; কেবল দেখা, চার্জ বসে ভাউচারে।
     */
    Route::get('/bank-charges', [BankChargeController::class, 'index'])->name('bank_charge.index');

    /*
     * খাত বিশ্লেষণ — মানচিত্র §৪; খতিয়ানের দাখিলা মাস/কাগজ/পক্ষ ধরে।
     */
    Route::get('/account-analysis', [AccountAnalysisController::class, 'index'])->name('account_analysis.index');

    /*
     * পরিবহন ও শ্রমিকের খতিয়ান — মানচিত্র §৬; ২১১৬/২১১৭ পক্ষ ধরে।
     */
    Route::get('/carrier-labour', [CarrierAndLabourController::class, 'index'])->name('carrier_labour.index');
    /*
     * আর্থিক প্রতিষ্ঠান — ব্যাংক, আর্থিক প্রতিষ্ঠান/লিজিং, বীমা, মোবাইল ব্যাংকিং।
     * ⓘ মালিকের কথায় তালিকাটা কেবল অর্থে (*"eta sudu ekhanei bebohar hobe"*)।
     */
    Route::prefix('institutions')->name('institution.')->group(function () {
        Route::get('/', [InstitutionController::class, 'index'])->name('index');
        Route::get('/create', [InstitutionController::class, 'create'])->name('create');
        Route::post('/', [InstitutionController::class, 'store'])->name('store');
        Route::get('/{institution}/edit', [InstitutionController::class, 'edit'])->whereNumber('institution')->name('edit');
        Route::put('/{institution}', [InstitutionController::class, 'update'])->whereNumber('institution')->name('update');
        Route::patch('/{institution}/toggle', [InstitutionController::class, 'toggle'])->whereNumber('institution')->name('toggle');
        Route::get('/{institution}', [InstitutionController::class, 'show'])->whereNumber('institution')->name('show');
        // ⓘ "খাত জোড়ো" — ব্যাংক/MFS খাত এই প্রতিষ্ঠানের
        Route::post('/{institution}/accounts', [InstitutionController::class, 'link'])->whereNumber('institution')->name('link');
        Route::delete('/{institution}/accounts/{account}', [InstitutionController::class, 'unlink'])
            ->whereNumber('institution')->whereNumber('account')->name('unlink');
    });

    /*
     * বীমা পলিসি — প্রিমিয়াম দেওয়া হয় পরিশোধ ভাউচারে, এখানে নয়।
     */
    Route::prefix('insurance')->name('insurance.')->group(function () {
        Route::get('/', [InsuranceController::class, 'index'])->name('index');
        Route::get('/create', [InsuranceController::class, 'create'])->name('create');
        Route::post('/', [InsuranceController::class, 'store'])->name('store');
        Route::get('/{policy}', [InsuranceController::class, 'show'])->whereNumber('policy')->name('show');
        Route::get('/{policy}/edit', [InsuranceController::class, 'edit'])->whereNumber('policy')->name('edit');
        Route::put('/{policy}', [InsuranceController::class, 'update'])->whereNumber('policy')->name('update');
        Route::get('/{policy}/renew', [InsuranceController::class, 'renewForm'])->whereNumber('policy')->name('renew_form');
        Route::post('/{policy}/renew', [InsuranceController::class, 'renew'])->whereNumber('policy')->name('renew');
        Route::patch('/{policy}/toggle', [InsuranceController::class, 'toggle'])->whereNumber('policy')->name('toggle');
    });
});

/*
 * পুরনো ঠিকানা — একদিনের জন্য মূলধন `accounts/capital`-এ ছিল।
 *
 * ── কেন পুনর্নির্দেশ, আর কেন স্থায়ী নয় ──────────────────────────────
 * ওই ঠিকানাটা মাত্র কয়েক ঘণ্টা লাইভে ছিল, তবু কেউ বুকমার্ক করে
 * থাকতে পারেন। ৩০২ (স্থায়ী নয়), কারণ ঠিকানাটা ভুল ছিল না — কেবল
 * মডিউল ভাগ হওয়ায় সরেছে, আর ব্রাউজারের ক্যাশে চিরকাল বসিয়ে রাখার
 * মতো কিছু নয়।
 */
/*
 * নামটা স্পষ্ট, ইচ্ছাকৃতভাবে।
 *
 * নাম না দিলে প্রদানকারীর উপসর্গটাই পুরো নাম হয়ে যেত — `finance.` —
 * আর [[EveryRouteIsGuardedTest]]-এর তালিকায় ওটা এমন একটা এন্ট্রি হত
 * যার মানে পরের জন বুঝত না।
 */
Route::middleware('auth')
    ->get('/accounts/capital', fn () => redirect()->route('finance.capital.index'))
    ->name('capital.moved');
