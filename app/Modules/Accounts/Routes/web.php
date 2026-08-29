<?php

declare(strict_types=1);

use App\Modules\Accounts\Http\Controllers\AccountsDashboardController;
use App\Modules\Accounts\Http\Controllers\AccountsSettingsController;
use App\Modules\Accounts\Http\Controllers\BalanceSheetController;
use App\Modules\Accounts\Http\Controllers\BankReconciliationController;
use App\Modules\Accounts\Http\Controllers\BooksIntegrityController;
use App\Modules\Accounts\Http\Controllers\CashCountController;
use App\Modules\Accounts\Http\Controllers\CashTillController;
use App\Modules\Accounts\Http\Controllers\ChartOfAccountsController;
use App\Modules\Accounts\Http\Controllers\ChequeController;
use App\Modules\Accounts\Http\Controllers\FixedAssetController;
use App\Modules\Accounts\Http\Controllers\LoanController;
use App\Modules\Accounts\Http\Controllers\MoneyCustodyController;
use App\Modules\Accounts\Http\Controllers\MoneyTransferController;
use App\Modules\Accounts\Http\Controllers\MoneyTransferPrintController;
use App\Modules\Accounts\Http\Controllers\PeriodLockController;
use App\Modules\Accounts\Http\Controllers\ReportController;
use App\Modules\Accounts\Http\Controllers\VoucherController;
use App\Modules\Accounts\Http\Controllers\VoucherPrintController;
use App\Modules\Accounts\Http\Controllers\YearEndController;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Facades\Route;

/*
 * Accounts মডিউলের নিজের রুট — ModuleServiceProvider নিজে থেকে নিবন্ধন
 * করে (সেকশন ১৯.৩)। নামের উপসর্গ "accounts." আপনাআপনি বসে।
 */

Route::middleware('auth')->prefix('accounts')->group(function () {

    Route::get('/', [AccountsDashboardController::class, 'show'])->name('dashboard');

    Route::get('/settings', [AccountsSettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [AccountsSettingsController::class, 'update'])->name('settings.update');

    Route::prefix('chart-of-accounts')->name('coa.')->group(function () {
        Route::get('/', [ChartOfAccountsController::class, 'index'])->name('index');
        Route::get('/create', [ChartOfAccountsController::class, 'create'])->name('create');
        Route::post('/', [ChartOfAccountsController::class, 'store'])->name('store');

        /*
         * install রুটটা {account} প্যাটার্নের আগে — নাহলে
         * /chart-of-accounts/install-standard কে একটা খাতের id ভেবে
         * বাইন্ডিং ৪০৪ দিত।
         */
        Route::post('/install-standard', [ChartOfAccountsController::class, 'installStandardChart'])
            ->middleware('can:create,'.Account::class)
            ->name('install');

        Route::get('/{account}', [ChartOfAccountsController::class, 'show'])->name('show');
        Route::get('/{account}/edit', [ChartOfAccountsController::class, 'edit'])->name('edit');
        Route::put('/{account}', [ChartOfAccountsController::class, 'update'])->name('update');
        Route::delete('/{account}', [ChartOfAccountsController::class, 'destroy'])->name('destroy');
    });

    /*
     * ঋণ — টার্ম লোন ও CC।
     *
     * মোছার কোনো রুট নেই: ঋণ একটা চুক্তি, আর তার প্রতিটা কিস্তি
     * খতিয়ানে বসে গেছে। ভুল হলে বিপরীত দাখিলা, মুছে ফেলা নয়।
     */
    Route::prefix('loans')->name('loan.')->group(function () {
        Route::get('/', [LoanController::class, 'index'])->name('index');
        Route::get('/create', [LoanController::class, 'create'])->name('create');
        Route::post('/', [LoanController::class, 'store'])->name('store');
        Route::get('/{loan}', [LoanController::class, 'show'])->whereNumber('loan')->name('show');

        // টাকা তোলা ও জমা — CC-তে যতবার খুশি, টার্ম লোনে একবারই
        Route::post('/{loan}/draw', [LoanController::class, 'drawDown'])
            ->whereNumber('loan')->name('draw');
        Route::post('/{loan}/repay', [LoanController::class, 'repay'])
            ->whereNumber('loan')->name('repay');
        Route::post('/{loan}/interest', [LoanController::class, 'chargeInterest'])
            ->whereNumber('loan')->name('interest');
        Route::post('/{loan}/instalments/{instalment}', [LoanController::class, 'payInstalment'])
            ->whereNumber('loan')->whereNumber('instalment')->name('instalment.pay');
    });

    Route::prefix('cash-tills')->name('till.')->group(function () {
        Route::get('/', [CashTillController::class, 'index'])->name('index');
        Route::get('/create', [CashTillController::class, 'create'])->name('create');
        Route::post('/', [CashTillController::class, 'store'])->name('store');
        Route::get('/{till}', [CashTillController::class, 'show'])->name('show');
        Route::get('/{till}/edit', [CashTillController::class, 'edit'])->name('edit');
        Route::put('/{till}', [CashTillController::class, 'update'])->name('update');
        Route::delete('/{till}', [CashTillController::class, 'destroy'])->name('destroy');

        // resource ছকের বাইরে, তাই অনুমতি কন্ট্রোলারে হাতে যাচাই করা হয়
        Route::post('/{till}/primary', [CashTillController::class, 'makePrimary'])->name('primary');
    });

    /*
     * ভাউচার — ধরনটা URL-এ।
     *
     * /vouchers/receipt, /vouchers/journal — মেনুর পাঁচটা সারি পাঁচটা
     * আলাদা ঠিকানায় যায়, অথচ কন্ট্রোলার এক। ধরনটা ক্যোয়ারি প্যারামিটার
     * হলে ছাপার শিরোনাম ও ব্রেডক্রাম্ব দুইটাই অনিশ্চিত হত।
     *
     * {voucher} রুটগুলো ধরন ছাড়া, কারণ একটা ভাউচার নিজেই জানে সে কোন
     * ধরনের — ঠিকানায় ধরনটা আবার লিখলে দুইটা অমিল হতে পারত।
     */
    Route::prefix('vouchers')->name('voucher.')->group(function () {
        Route::get('/{voucher}', [VoucherController::class, 'show'])
            ->whereNumber('voucher')->name('show');

        /*
         * কাগজটা — /vouchers/{id}/print?paper=80mm
         *
         * স্থির পথটা {voucher}-এর পরে বসেছে কিন্তু নিজের অংশ নিয়ে,
         * তাই সংঘাত নেই: /{voucher} শুধু সংখ্যা মানে (whereNumber)।
         */
        Route::get('/{voucher}/print', VoucherPrintController::class)
            ->whereNumber('voucher')->name('print');
        Route::get('/{voucher}/edit', [VoucherController::class, 'edit'])
            ->whereNumber('voucher')->name('edit');
        Route::put('/{voucher}', [VoucherController::class, 'update'])
            ->whereNumber('voucher')->name('update');
        Route::post('/{voucher}/post', [VoucherController::class, 'post'])
            ->whereNumber('voucher')->name('post');
        Route::post('/{voucher}/cancel', [VoucherController::class, 'cancel'])
            ->whereNumber('voucher')->name('cancel');

        // ধরনভিত্তিক রুটগুলো শেষে — নাহলে /vouchers/receipt কে একটা
        // ভাউচারের id ভেবে বাইন্ডিং ৪০৪ দিত
        Route::get('/{type}', [VoucherController::class, 'index'])->name('index');
        Route::get('/{type}/create', [VoucherController::class, 'create'])->name('create');
        Route::post('/{type}', [VoucherController::class, 'store'])->name('store');
    });

    /*
     * টাকা ও হেফাজত — কোন টাকা কার কাছে আছে।
     *
     * হস্তান্তরের আগে, কারণ পড়ার ক্রমটাই তাই: আগে জানতে হয় টাকা কোথায়,
     * তারপর সেটা সরানোর কথা ওঠে।
     */
    Route::get('money-custody', MoneyCustodyController::class)->name('custody');

    /*
     * খাতা নিজেই মেলে কি না — চালিয়ে দেখা।
     *
     * পরীক্ষা বলে কোডটা ঠিক; এটা বলে **এই কোম্পানির আজকের খাতাটা**
     * ঠিক। দুইটা আলাদা প্রশ্ন, আর কোড সারালে পুরনো সারিগুলো নিজে থেকে
     * ঠিক হয় না।
     */
    Route::get('books-check', BooksIntegrityController::class)->name('integrity');

    Route::prefix('money-transfers')->name('transfer.')->group(function () {
        Route::get('/', [MoneyTransferController::class, 'index'])->name('index');
        Route::get('/create', [MoneyTransferController::class, 'create'])->name('create');
        Route::post('/', [MoneyTransferController::class, 'store'])->name('store');
        Route::get('/{transfer}', [MoneyTransferController::class, 'show'])->name('show');
        Route::post('/{transfer}/confirm', [MoneyTransferController::class, 'confirm'])->name('confirm');
        Route::post('/{transfer}/cancel', [MoneyTransferController::class, 'cancel'])->name('cancel');

        /*
         * স্লিপটা — /money-transfers/{id}/print?paper=80mm
         *
         * দুইজনের সইয়ের কাগজ। কেন এটা দরকার, সেটা কন্ট্রোলারে লেখা।
         */
        Route::get('/{transfer}/print', MoneyTransferPrintController::class)
            ->whereNumber('transfer')->name('print');
    });

    /*
     * রিপোর্ট — আটটা, একটাই কন্ট্রোলার।
     *
     * ঠিকানায় engine-এর ভেতরের কী নয়, পড়ার মতো নাম: /reports/day-book।
     * ভেতরের কী বদলালে বুকমার্ক ভাঙত।
     */
    /*
     * স্থিতিপত্র — সাধারণ রিপোর্টের পথের **আগে**, ইচ্ছাকৃতভাবে।
     *
     * নিচের `/reports/{slug}` যেকোনো slug ধরে, তাই এটা পরে বসালে
     * কোনোদিন ডাকা হত না। ক্রমটাই এখানে নিয়ম।
     *
     * ── কেন আলাদা পাতা ──────────────────────────────────────────────
     * রিপোর্ট-ইঞ্জিন সাধারণ টেবিল আঁকে; স্থিতিপত্র বিবৃতি — দুই পক্ষ,
     * ভেতরে ভাগ, উপমোট, আর শেষে সমতার দাবি।
     */
    Route::get('/reports/balance-sheet', [BalanceSheetController::class, 'show'])
        ->name('balance_sheet');

    Route::get('/reports/{slug}', [ReportController::class, 'show'])->name('report.show');

    /*
     * বছর সমাপনী।
     *
     * {year} সংখ্যায় বাঁধা, আর index আলাদা পথে — নাহলে ভবিষ্যতে
     * /year-end/settings জাতীয় কিছু যোগ করলে সেটাকে একটা id ভেবে
     * বাইন্ডিং ৪০৪ দিত।
     */
    Route::prefix('year-end')->name('year_end.')->group(function () {
        Route::get('/', [YearEndController::class, 'index'])->name('index');
        Route::post('/{year}/close', [YearEndController::class, 'close'])
            ->whereNumber('year')->name('close');
    });

    /*
     * মাস বন্ধ করা ও খোলা।
     *
     * খোলার রুটটা তালার সারিটাই ধরে (`{lock}`), মাস-বছর নয় — যে মাস
     * বন্ধই নয় তাকে খোলার অনুরোধ তখন রুট-স্তরেই ৪০৪ হয়।
     */
    /*
     * চেকের খাতা।
     *
     * তালিকাই প্রধান পর্দা — বসানো হয় উপরের ফর্ম থেকে, আর জমা/পাশ/
     * ফেরত তিনটাই সারি থেকে। আলাদা পাতায় পাঠালে প্রতিটা সিদ্ধান্তে
     * যাওয়া-আসা করতে হত, আর দিনে দশটা চেকে সেটা অসহ্য।
     */
    Route::prefix('cheques')->name('cheque.')->group(function () {
        Route::get('/', [ChequeController::class, 'index'])->name('index');
        Route::post('/', [ChequeController::class, 'store'])->name('store');
        Route::post('/{cheque}/deposit', [ChequeController::class, 'deposit'])
            ->whereNumber('cheque')->name('deposit');
        Route::post('/{cheque}/clear', [ChequeController::class, 'clear'])
            ->whereNumber('cheque')->name('clear');
        Route::post('/{cheque}/bounce', [ChequeController::class, 'bounce'])
            ->whereNumber('cheque')->name('bounce');
    });

    /*
     * ব্যাংক মিলকরণ।
     *
     * চেকের মতো এক পাতায় নয়: ওখানে সিদ্ধান্ত সারিপ্রতি, এখানে সিদ্ধান্ত
     * পুরো কাগজটা নিয়ে। তাই তালিকা আর কাজের পর্দা আলাদা।
     */
    Route::prefix('reconciliations')->name('reconciliation.')->group(function () {
        Route::get('/', [BankReconciliationController::class, 'index'])->name('index');
        Route::post('/', [BankReconciliationController::class, 'store'])->name('store');
        Route::get('/{reconciliation}', [BankReconciliationController::class, 'show'])
            ->whereNumber('reconciliation')->name('show');
        Route::post('/{reconciliation}/mark', [BankReconciliationController::class, 'mark'])
            ->whereNumber('reconciliation')->name('mark');
        Route::post('/{reconciliation}/confirm', [BankReconciliationController::class, 'confirm'])
            ->whereNumber('reconciliation')->name('confirm');
        Route::post('/{reconciliation}/reopen', [BankReconciliationController::class, 'reopen'])
            ->whereNumber('reconciliation')->name('reopen');
    });

    /*
     * স্থায়ী সম্পদ ও অবচয়।
     *
     * মাস শেষের দৌড়টা তালিকার রুটেই (POST /assets/depreciate), আলাদা
     * পর্দায় নয় — ওটা একটা বোতাম, একটা পাতা নয়।
     */
    Route::prefix('assets')->name('asset.')->group(function () {
        Route::get('/', [FixedAssetController::class, 'index'])->name('index');
        Route::post('/', [FixedAssetController::class, 'store'])->name('store');
        Route::post('/depreciate', [FixedAssetController::class, 'depreciate'])->name('depreciate');
        Route::get('/{asset}', [FixedAssetController::class, 'show'])
            ->whereNumber('asset')->name('show');
        Route::post('/{asset}/dispose', [FixedAssetController::class, 'dispose'])
            ->whereNumber('asset')->name('dispose');
    });

    Route::prefix('periods')->name('period.')->group(function () {
        Route::get('/', [PeriodLockController::class, 'index'])->name('index');
        Route::post('/close', [PeriodLockController::class, 'close'])->name('close');
        Route::post('/{lock}/reopen', [PeriodLockController::class, 'reopen'])
            ->whereNumber('lock')->name('reopen');
    });

    Route::prefix('cash-counts')->name('count.')->group(function () {
        Route::get('/', [CashCountController::class, 'index'])->name('index');
        Route::get('/create', [CashCountController::class, 'create'])->name('create');
        Route::post('/', [CashCountController::class, 'store'])->name('store');
        Route::get('/{count}', [CashCountController::class, 'show'])->name('show');
        Route::post('/{count}/approve', [CashCountController::class, 'approve'])->name('approve');
    });
});
