<?php

declare(strict_types=1);

use App\Core\Contracts\KnowsAUsersEmployee;
use App\Core\Engines\Print\PaperSize;
use App\Core\Events\ApprovalDecided;
use App\Core\Events\ApprovalWithdrawn;
use App\Modules\Hr\Dashboard\HrDashboard;
use App\Modules\Hr\Dashboard\HrWidgets;
use App\Modules\Hr\Listeners\FinishTheClaimOnTheLastSignature;
use App\Modules\Hr\Listeners\CloseThePaymentChangeOnWithdrawal;
use App\Modules\Hr\Listeners\ReleaseHeldWagesWhenTheVoucherFalls;
use App\Modules\Hr\Listeners\FinishThePaymentChangeOnTheLastSignature;
use App\Modules\Hr\Listeners\FinishTheBankUnpaidLineOnTheLastSignature;
use App\Modules\Hr\Listeners\FinishTheSalaryChangeOnTheLastSignature;
use App\Modules\Hr\Listeners\FinishTheFinalSettlementOnTheLastSignature;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Panels\EmployeeFacts;
use App\Modules\Hr\Reports\HrReports;
use App\Modules\Hr\Services\UserEmployee;

/**
 * HR ও পে-রোল — প্ল্যানের ফেজ ৯।
 *
 * প্ল্যানে সফলতার মাপকাঠি একটাই লাইন: "বেতন খাতায় বসে, ব্যাংক ফাইল
 * বের হয়"। তাই এই মডিউলটার কেন্দ্র হাজিরা নয়, ছুটি নয় — বেতনটা যেন
 * সঠিক অঙ্কে হিসাবের বইয়ে বসে আর টাকাটা যেন সত্যিই পৌঁছায়।
 *
 * কর্মী আলাদা টেবিলে, ব্যবহারকারীর সাথে মেলানো নয়: গুদামের শ্রমিকের
 * লগইন লাগে না, অথচ বেতন লাগে।
 */
return [
    'code' => 'hr',

    'name' => [
        'en' => 'HR & Payroll',
        'bn' => 'কর্মী ও বেতন',
    ],

    'version' => '1.0.0',

    /*
     * সাইডবারে কোথায় — নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     *
     * মানুষ আগে, তারপর তাদের উপরের নিয়ম।
     *
     * দলগুলোর তালিকা আর কেন এটা `depends_on`-এর থেকে আলাদা:
     * [[ModuleDefinition::NAV_SECTIONS]].
     */
    'nav' => ['section' => 'people', 'order' => 10],

    /*
     * হিসাব লাগে — বেতন শেষমেশ খরচ ও দায় হয়ে বইয়ে বসে।
     * মাস্টার ডাটা লাগে — বিভাগ, পদবি ও নিয়োগের ধরন ওখানকার তালিকা।
     */
    'depends_on' => ['accounts', 'master_data'],

    'menu' => [
        'dashboard' => [
            ['label' => 'hr::dashboard.title', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'hr'], 'permission' => 'hr.employee.view'],
        ],

        'master' => [
            ['label' => 'hr::menu.employees', 'icon' => 'people', 'route' => 'hr.employee.index', 'permission' => 'hr.employee.view'],
            ['label' => 'hr::menu.salary_heads', 'icon' => 'wallet', 'route' => 'hr.salary_head.index', 'permission' => 'hr.salary.manage'],
            // ⭐ ছুটির দিনের তালিকা — সরকারি ছুটি আর উৎসব (শ্রম আইন ধারা ১১৮; [[HolidayController]]; a6, ২১ অক্টোবর ২০২৬)
            ['label' => 'hr::menu.holidays', 'icon' => 'calendar', 'route' => 'hr.holiday.index', 'permission' => 'hr.attendance.view'],
            // ⭐ ভবিষ্য তহবিলের প্রস্তাবিত বদল — আজকের বনাম প্রস্তাবিত হার, তারিখ ধরে বসানো (a6, ২১ অক্টোবর ২০২৬)
            ['label' => 'hr::menu.pf_proposal', 'icon' => 'wallet', 'route' => 'hr.pf.proposal', 'permission' => 'hr.salary.view'],
        ],

        'transactions' => [
            ['label' => 'hr::menu.payroll', 'icon' => 'cash', 'route' => 'hr.payroll.index', 'permission' => 'hr.payroll.view'],
            ['label' => 'hr::menu.attendance', 'icon' => 'calendar', 'route' => 'hr.attendance.index', 'permission' => 'hr.attendance.view'],
            // ⭐ অধিকালের শিট — হাজিরা থেকে, ব্যবস্থাপকের সই (BLA ধারা ১০৮; a6, ২১ অক্টোবর ২০২৬)
            // ⭐ চাবি না থাকলেও পাতার নিজের নীতি — দল থাকা ব্যবস্থাপক সারি দেখেন আর পাতা খোলে (a6, ২১ অক্টোবর ২০২৬; [[MenuBuilder::pageLets()]])
            ['label' => 'hr::menu.overtime', 'icon' => 'clock', 'route' => 'hr.overtime.index', 'permission' => 'hr.attendance.view',
                'policy' => ['viewAny', \App\Modules\Hr\Models\OvertimeSheet::class]],
            ['label' => 'hr::menu.leave', 'icon' => 'clock', 'route' => 'hr.leave.index', 'permission' => 'hr.leave.view',
                'policy' => ['viewAny', \App\Modules\Hr\Models\LeaveApplication::class]],
            // ⭐ খরচের দাবি আর অগ্রিম — কর্মী নিজে (মালিকের আদেশ, ৭ অক্টোবর ২০২৬; [[ExpenseClaimController]])
            ['label' => 'hr::menu.claims', 'icon' => 'wallet', 'route' => 'hr.claim.index', 'permission' => 'hr.claim.self'],
        ],

        /*
         * ⭐ HR-এর চারটা রিপোর্ট (ঘাটতির তালিকা #১৬; a6, ২১ অক্টোবর ২০২৬) — প্রতিটা তার পর্দার চাবিতে।
         * ⚠️ স্লাগ [[HrReportController::SLUGS]]-এর হুবহু; চাবিও সেখানকার — নইলে সারি দেখা যায় আর চাপলে ৪০৩/৪০৪।
         */
        'reports' => [
            ['label' => 'hr::menu.report_attendance', 'icon' => 'calendar', 'route' => 'hr.report.show',
                'route_params' => ['slug' => 'attendance'], 'permission' => 'hr.attendance.view'],
            ['label' => 'hr::menu.report_payroll', 'icon' => 'cash', 'route' => 'hr.report.show',
                'route_params' => ['slug' => 'payroll'], 'permission' => 'hr.payroll.view'],
            ['label' => 'hr::menu.report_headcount', 'icon' => 'people', 'route' => 'hr.report.show',
                'route_params' => ['slug' => 'headcount'], 'permission' => 'hr.employee.view'],
            ['label' => 'hr::menu.report_leave_balance', 'icon' => 'clock', 'route' => 'hr.report.show',
                'route_params' => ['slug' => 'leave-balance'], 'permission' => 'hr.leave.view'],
            // ⭐ ভবিষ্য তহবিলের খাতা — পুরোটাই টাকা, বেতনের চাবিতে (a6, ২১ অক্টোবর ২০২৬)
            ['label' => 'hr::menu.report_provident_fund', 'icon' => 'wallet', 'route' => 'hr.report.show',
                'route_params' => ['slug' => 'provident-fund'], 'permission' => 'hr.salary.view'],
            // ⭐ গ্র্যাচুইটির দায় — বেতনের চাবিতে (fe, ২১ অক্টোবর ২০২৬, দফা ৮)
            ['label' => 'hr::menu.report_gratuity', 'icon' => 'wallet', 'route' => 'hr.gratuity.report', 'permission' => 'hr.salary.view'],
            // ⭐ চূড়ান্ত পাওনা — চাকরি ছাড়া কর্মীর এক কাগজ, মালিকের অনুমোদনে (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlements]])
            ['label' => 'hr::menu.final_settlements', 'icon' => 'wallet', 'route' => 'hr.final_settlement.index', 'permission' => 'hr.salary.view'],
            /*
             * ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল (WPPF) — শ্রম আইন ২০০৬, অধ্যায় ১৫ (a6, ২১ অক্টোবর ২০২৬; [[WppfService]])।
             * ⛔ সারিটা কোম্পানির সুইচে (`hr.wppf.enabled`, ডিফল্টে বন্ধ) — বন্ধে সারিও নেই, দরজাও ৪০৪ ([[WppfController]])।
             */
            ['label' => 'hr::menu.wppf', 'icon' => 'cash', 'route' => 'hr.wppf.show', 'permission' => 'hr.wppf.view',
                'setting' => 'hr.wppf.enabled'],
        ],
    ],

    'permissions' => [
        'hr.employee.view',
        'hr.employee.manage',

        /*
         * বেতন দেখা আর বেতন বদলানো আলাদা অনুমতি।
         *
         * কারণটা বাস্তব: হিসাবরক্ষককে বেতনশিট দেখতে হয়, কিন্তু কার
         * বেতন কত হবে তা ঠিক করার ক্ষমতা তার থাকা উচিত নয়।
         */
        /*
         * কর্মীর পরিচয় ও টাকা পাঠানোর তথ্য — বেতনের অঙ্ক থেকে আলাদা।
         *
         * ── কেন আলাদা ──────────────────────────────────────────────
         * বেতন কত, আর টাকাটা কোন হিসাবে যায় — দুইটা আলাদা প্রশ্ন, আর
         * প্রায়ই দুইজন আলাদা মানুষের। হিসাবরক্ষককে অঙ্কটা জানতে হয়;
         * **জাতীয় পরিচয়পত্রের নম্বরটা তাঁর লাগে না।**
         *
         * উল্টোদিকে যিনি বেতন পাঠান তাঁর হিসাব নম্বরটা লাগে, কিন্তু
         * কে কত পায় তা নয়।
         *
         * ── কেন এটা কেবল গোপনীয়তা নয় ───────────────────────────────
         * NID, ব্যাংক হিসাব আর MFS নম্বর — তিনটাই এমন তথ্য যা দিয়ে
         * **অন্য কোথাও একজন মানুষের ছদ্মবেশ ধরা যায়**। কর্মীর তালিকা
         * দেখার অনুমতি অনেকের থাকে; এগুলো দেখার অনুমতি অত জনের থাকার
         * কথা নয়।
         */
        'hr.identity.view',

        'hr.salary.view',
        'hr.salary.manage',

        /*
         * বেতনের রান দেখা আর চালানো আলাদা।
         *
         * মাসের শেষে কে কত পাচ্ছে তা অনেকেরই দেখা দরকার, কিন্তু
         * খাতায় বসানোর ও ব্যাংকে ফাইল পাঠানোর ক্ষমতা একজনের।
         */
        'hr.payroll.view',
        'hr.payroll.manage',

        'hr.attendance.view',
        'hr.attendance.manage',

        /*
         * নিজের হাজিরা দেওয়া — "সবার হাজিরা দেখা" (view) থেকে আলাদা কর্তৃত্ব।
         *
         * মাঠকর্মী ফোন থেকে নেট ছাড়াই নিজের উপস্থিতি তোলেন ([[AttendanceSync]]);
         * তার জন্য গোটা দলের হাজিরা দেখার (view) দরকার নেই, আর দিলে সুবিধার
         * জন্য গোপনীয়তা বিক্রি করা হত — কে কবে দেরি করল সব দেখা যেত। তাই
         * সরু একটা চাবি: কেবল নিজেরটা।
         */
        'hr.attendance.self',

        /*
         * ছুটি চাওয়া আর ছুটি মঞ্জুর করা আলাদা।
         *
         * এক অনুমতিতে রাখলে যে কেউ নিজের ছুটি নিজেই মঞ্জুর করতে পারত,
         * আর অনুমোদন বলে কিছু থাকত না।
         */
        'hr.leave.view',
        'hr.leave.manage',
        'hr.leave.approve',

        /*
         * ⭐ খরচের দাবি আর অগ্রিম অনুরোধ — মালিকের আদেশ, ৭ অক্টোবর ২০২৬ ([[ExpenseClaimService]])।
         * ⓘ `self` — নিজের দাবি পাঠানো আর দেখা (মাঠের কর্মী, ক্যাশিয়ার, হিসাবরক্ষক); `view` — সবার দাবি, নাগালের কর্মী
         * ধরে। ⛔ সই এই চাবিতে নয় — সই কোম্পানির নিজের ছকে, আর যিনি চাইলেন তিনি সই দেন না।
         */
        'hr.claim.self',
        'hr.claim.view',

        /*
         * ⭐ নিজের ছুটি চাওয়া আর নিজের বেতনের স্লিপ দেখা — ফোনে (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬: বাকি মডিউল অ্যাপে)।
         * ⓘ `hr.leave.self` — কেবল নিজের নামে আবেদন আর নিজের তালিকা; মঞ্জুরি আগের মতোই `hr.leave.approve`-এ। ⛔ `hr.leave.manage`
         * নয় — ওটা যেকোনো কর্মীর নামে আবেদন বসায়। `hr.payslip.self` — কেবল নিজের স্লিপ, খসড়া রান ছাড়া; ⛔ `hr.payroll.view` নয়
         * — ওটা সবার বেতন খোলে।
         */
        'hr.leave.self',
        'hr.payslip.self',

        /*
         * ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল — দেখা আর জাবেদার "সই দিন" আলাদা (a6, ২১ অক্টোবর ২০২৬; [[WppfService]])।
         * ⓘ কোনো রোলে ডিফল্টে নেই — মালিক (super admin) সব চাবি পান, কাকে দেবেন তিনিই ঠিক করেন।
         */
        'hr.wppf.view',
        'hr.wppf.manage',

        /*
         * ⭐ চূড়ান্ত পাওনা (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlements]]) — কাগজ বানানো `manage` (সাথে বেতনের চাবি), অনুমোদন `approve`।
         * ⛔ `approve` কোনো রোলে ডিফল্টে নেই — তাই অনুমোদন দেন কেবল মালিক, যতক্ষণ না তিনি নিজে কাউকে দেন।
         */
        'hr.final_settlement.manage',
        'hr.final_settlement.approve',
    ],

    /*
     * নতুন ইনস্টলে HR-এর অনুমতি, আর মাঠকর্মীর নিজের হাজিরা (§৫)।
     *
     * HR: কর্মী · হাজিরা · ছুটি। ⛔ `hr.payroll.view` ডিফল্টে নয় — ওটা বেতনের
     * অঙ্ক ও ব্যাংক-ফাইল খোলে; ক্রেতা চাইলে সচেতনভাবে যোগ করবেন। ⛔ salary.manage নয়।
     * Field Sales/Warehouse: কেবল নিজের হাজিরা (মোবাইলে sync)।
     */
    'role_templates' => [
        /*
         * কাউন্টার — প্রতিটা কোম্পানিতে ডিফল্টে। মালিক, ২৭ সেপ্টেম্বর ২০২৬: *"bosiye daw"*।
         * ⓘ কাউন্টারের কর্মী সব ডিলার দেখেন (মালিক, ২৬ সেপ্টেম্বর) — নাহলে বিল করবেন কীভাবে।
         */
        'Counter' => [
            'hr.attendance.self', 'hr.claim.self', 'hr.leave.self', 'hr.payslip.self',
        ],
        /*
         * ASM — বিক্রয়কর্মীর উপরের স্তর, প্রতিটা কোম্পানিতে ডিফল্টে (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।
         * ⚠️ SR→ASM→RSM→DSM-এর বাঁধন এখনো কোডে নেই; আপাতত কেবল চাবির তালিকা।
         */
        'ASM' => [
            'hr.attendance.self', 'hr.claim.self', 'hr.leave.self', 'hr.payslip.self',
        ],
        /*
         * RSM — ASM-এর উপরের স্তর, প্রতিটা কোম্পানিতে ডিফল্টে (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।
         * ⚠️ স্তরের বাঁধন এখনো কোডে নেই; আপাতত কেবল চাবির তালিকা।
         */
        'RSM' => [
            'hr.attendance.self', 'hr.claim.self', 'hr.leave.self', 'hr.payslip.self',
        ],
        /*
         * DSM — বিক্রয়ের সবচেয়ে উপরের স্তর, প্রতিটা কোম্পানিতে ডিফল্টে (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।
         * ⚠️ স্তরের বাঁধন এখনো কোডে নেই; আপাতত কেবল চাবির তালিকা।
         */
        'DSM' => [
            'hr.attendance.self', 'hr.claim.self', 'hr.leave.self', 'hr.payslip.self',
        ],
        /*
         * ⭐ হিসাবরক্ষক — প্রতিটা কোম্পানিতে ডিফল্টে থাকে। মালিকের নির্দেশ, ২৭
         * সেপ্টেম্বর ২০২৬: *"Accountant role by defolt erp te create thakbe"*।
         * ⓘ জমার দাবির মঞ্জুরি কেবল তাঁর (মালিক, ২৬ সেপ্টেম্বর)।
         */
        'Accountant' => [
            'hr.attendance.self', 'hr.claim.self', 'hr.leave.self', 'hr.payslip.self',
        ],
        'HR' => [
            'hr.employee.view', 'hr.employee.manage',
            'hr.attendance.view', 'hr.attendance.manage',
            'hr.leave.view', 'hr.leave.manage', 'hr.leave.approve',
            'hr.claim.self', 'hr.claim.view',
            'hr.leave.self', 'hr.payslip.self',
        ],
        'Field Sales' => ['hr.attendance.self', 'hr.claim.self', 'hr.leave.self', 'hr.payslip.self'],
        'Warehouse' => ['hr.attendance.self', 'hr.claim.self', 'hr.leave.self', 'hr.payslip.self'],
    ],

    /*
     * অনুমোদন লাগতে পারে এমন কাজ।
     *
     * বেতনের রান নিশ্চিত করা — এক চাপে গোটা মাসের বেতন খতিয়ানে বসে যায়,
     * আর ভুল হলে বাতিল করতে বিপরীত এন্ট্রি লাগে। ⓘ সই চাওয়ার জন্য এর
     * চেয়ে ভালো মুহূর্ত এই মডিউলে নেই।
     *
     * ⚠️ **ছুটির মঞ্জুরি এখানে নেই, আর সেটা ইচ্ছাকৃত।** `LeaveService`-এর
     * নিজের `approve()`/`reject()` আছে, নিজের অবস্থা আছে, আর মঞ্জুরির
     * সাথে হাজিরা বসানো বাঁধা। ওটাকে এই ইঞ্জিনে আনা মানে **চলন্ত একটা
     * ব্যবস্থা বদলানো**, জোড়া দেওয়া নয় — তাই সিদ্ধান্তটা মালিকের কাছে
     * তোলা হয়েছে, আর ততক্ষণ ওটা যেমন আছে তেমনই থাকবে।
     */
    'approvals' => [
        'payroll' => 'hr::approval.payroll',
        // ⭐ কর্মীর খরচের দাবি আর অগ্রিম — আলাদা কাজ, যাতে দুইটার আলাদা সীমা বসানো যায় (মালিকের আদেশ, ৭ অক্টোবর ২০২৬)
        'expense_claim' => 'hr::approval.expense_claim',
        'cash_advance' => 'hr::approval.cash_advance',
        /*
         * ⭐ বেতনের কাঠামো বদল — §৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬ ([[SalaryStructureService::change()]])। ⓘ ছক কেউ বসান না, কোম্পানি
         * চাইলে চালু করে (SOX/ITGC maker-checker); ⛔ বসানো হয় না। ⭐ ছক না থাকলেও দ্বিতীয় সই — নিচের `second_hand` (মালিক, ২১ অক্টোবর ২০২৬)।
         */
        'salary_change' => 'hr::approval.salary_change',
        /*
         * ⭐ কর্মীর বেতনের ঠিকানা (ব্যাংক/MFS) বদল — cb-র অপেক্ষার বদল ([[PaymentChanges]], ee99f3eaa), সই এখন ইঞ্জিনে; ছক না থাকলেও
         * দ্বিতীয় সই (নিচের `second_hand`; মালিক, a6, ২১ অক্টোবর ২০২৬)।
         */
        'payment_change' => 'hr::approval.payment_change',
        // ⓘ WPPF-এর জাবেদা — কোম্পানি চাইলে ছক বসায়; না থাকলে "সই দিন" চাপা মানুষটিই সই ([[WppfService::provision()]])
        'wppf_provision' => 'hr::approval.wppf_provision',
        /*
         * ⭐ "ব্যাংক এই লাইন দেয়নি" — বাতিল রানের ব্যাংক ফাইলে যাওয়া বকেয়া মজুরি আবার দেওয়ার তালিকায়, কেবল দ্বিতীয় সইয়ে (fe-র সিদ্ধান্ত ২,
         * ২১ অক্টোবর ২০২৬; [[HeldWages::askBankUnpaid()]]); ছক না থাকলেও দ্বিতীয় সই (নিচের `second_hand`)।
         */
        'bank_unpaid' => 'hr::approval.bank_unpaid',
        // ⭐ চূড়ান্ত পাওনা — সবসময় মালিকের অনুমোদন (নিচের `second_hand`; মালিক, ২১ অক্টোবর ২০২৬)
        'final_settlement' => 'hr::approval.final_settlement',
    ],

    /*
     * ⭐ বেতন-বদলের সইয়ের নিয়ম — fe-র উত্তর ২ ও ৪, a6, ২১ অক্টোবর ২০২৬ ([[ApprovalRules]])।
     *  · `signer_key` — ছকের ধাপে বেতনের চাবি ছাড়া কাউকে (মানুষ বা রোল) বসানো যায় না; নাহলে অঙ্ক না দেখে সই ([[ApprovalFlowService]])।
     *  · `summary` — ইনবক্স আর একসাথে সইয়ের তালিকায় প্রতিটা সারিতে আগে → পরের অঙ্ক, চাবি না থাকলে ঢাকা ([[SalaryChangeSummary]])।
     */
    /*
     * ⛔ `second_hand` — মালিকের একমাত্র ব্যতিক্রম, ২১ অক্টোবর ২০২৬ (a6; [[ApprovalRules::secondHand()]]): "সই বন্ধ মানে বন্ধ", কেবল বেতনের
     * রান নিশ্চিত, বেতনের কাঠামো বদল আর কর্মীর বেতনের ঠিকানা (ব্যাংক/MFS) বদল ছাড়া। সব ছক বন্ধ থাকা কোম্পানিতেও (UB) এগুলোয়
     * আরেকজনের "সই দিন" লাগে — যন্ত্রের নিজের এক ধাপ "দ্বিতীয় সই", চাবি থাকা যেকোনো একজন, যিনি চাইলেন তিনি নন — মালিকও নন
     * (fe, ২১ অক্টোবর ২০২৬; [[ApprovalEngine::twoHands()]]); কোম্পানির ছকের সীমা-শর্ত নিয়মটা নামাতে পারে না। ⓘ ছক বসানো হয় না ([[MoneyFlowDefaults]] আগের মতোই);
     * কোম্পানির নিজের চালু ছক থাকলে সেটাই খাটে।
     */
    'approval_rules' => [
        'salary_change' => [
            'signer_key' => 'hr.salary.view',
            'second_hand' => ['hr.salary.manage'],
            'summary' => \App\Modules\Hr\Services\SalaryChangeSummary::class,
        ],
        /*
         * ⛔ বেতনের রানের সই — নিট কেবল `hr.salary.view`-এ (fe, ২১ অক্টোবর ২০২৬, a6)।
         *  · `amount_key` — অনুরোধের নিট `approvals.amount`-এ খোলা বসে না, এনক্রিপ্ট করা থাকে; ইনবক্স, সইয়ের পাতা, ফোন, খবর,
         *    রিপোর্ট, খোঁজা — সবখানে খালি ([[ApprovalRules::amountKey()]])।
         *  · `summary` — মাস, জন, নিট; নিট চাবি ছাড়া ঢাকা ([[PayrollRunSummary]])।
         *  · `signer_key` — চাবি ছাড়া কেউ সইকারী নন, ছকের পর্দায় আর নিজে-বসানো ছকেও ([[ApprovalFlowService]]); নিজে-বসানো ছকের
         *    সইকারী মালিক বা চাবি থাকা রোল (HR-এর প্রধান), হিসাবরক্ষক কখনো নন ([[MoneyFlowDefaults::keyHolders()]]; fe, ২১ অক্টোবর ২০২৬)।
         */
        'payroll' => [
            'signer_key' => 'hr.salary.view',
            'second_hand' => ['hr.payroll.manage'],
            'amount_key' => 'hr.salary.view',
            'summary' => \App\Modules\Hr\Services\PayrollRunSummary::class,
        ],
        /*
         * ⛔ বেতনের ঠিকানার বদল — সই দেন বদলটা লেখার চাবি থাকা আরেকজন (`hr.employee.manage` + `hr.identity.view`), যিনি চাইলেন তিনি নন।
         * ⓘ পুরো নম্বর তাঁর চোখে খোলে (পরিচয়ের চাবি) — মিলিয়ে দেখে সই; চাবি ছাড়া কেউ ধাপে বসেন না (`signer_key`)।
         */
        'payment_change' => [
            'signer_key' => 'hr.identity.view',
            'second_hand' => ['hr.employee.manage'],
            'summary' => \App\Modules\Hr\Services\PaymentChangeSummary::class,
        ],
        /*
         * ⛔ "ব্যাংক এই লাইন দেয়নি" — মজুরি আবার দেওয়ার তালিকায় ফেরে, তাই বেতনের রানের অবিকল নিয়ম: `hr.payroll.manage` + `hr.salary.view`
         * থাকা আরেকজন, যিনি চাইলেন তিনি নন, মালিক ছাড়া (মালিক, ২০ নভেম্বর ২০২৬; fe-র সিদ্ধান্ত ২, ২১ অক্টোবর ২০২৬; [[HeldWages::finishBankUnpaid()]])।
         *
         * ⓘ কেন `moves_money`-তে নেই: সেই তালিকার প্রতিটা কাজে [[MoneyFlowDefaults]] প্রতিটা কোম্পানিতে একটা সইয়ের ছক বসায় — সব সই বন্ধ
         * রাখা কোম্পানিতেও (UB) ছক জন্মাত, মালিকের "সই বন্ধ মানে বন্ধ" নিয়ম ভাঙত। দুই হাত এমনিতেই নিশ্চিত — উপরের `second_hand`, ছক
         * ছাড়াই সবসময়।
         * ⛔ কেন `no_bulk`: এটা টাকা আবার বেরোনোর পথ — প্রতিটা লাইনের কারণ আর ব্যাংকের প্রমাণ নিজের পাতায় দেখে, নিজের সইয়ে; একসাথে সই
         * মানে না দেখে সই (fe, ২১ অক্টোবর ২০২৬; [[BulkApproval::oneByOne()]], [[ACancelledRunsBankLineIsNotReleasedByItselfTest::test_a_bank_line_is_never_signed_in_bulk_but_its_own_page_signs_it]])।
         */
        'bank_unpaid' => [
            'signer_key' => 'hr.salary.view',
            'second_hand' => ['hr.payroll.manage'],
            'no_bulk' => true,
        ],
        /*
         * ⛔ চূড়ান্ত পাওনা — "এক কাগজ, মালিকের অনুমোদনে" (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlements]])।
         *  · `second_hand` — চাবিটা কোনো রোলে নেই, তাই ছক না থাকলে বা সব ছক বন্ধ থাকলেও (UB) অনুমোদন দেন কেবল মালিক; যিনি বানালেন তিনি নন।
         *  · `amount_key` — নিট `approvals.amount`-এ খোলা বসে না (বেতনের রানের একই নিয়ম)।
         *  · `no_bulk` — টাকা বেরোনোর কাগজ, প্রতিটা নিজের পাতায় দেখে।
         */
        'final_settlement' => [
            'signer_key' => 'hr.salary.view',
            'second_hand' => ['hr.final_settlement.approve'],
            'amount_key' => 'hr.salary.view',
            'no_bulk' => true,
            'summary' => \App\Modules\Hr\Services\FinalSettlementSummary::class,
        ],
    ],

    /*
     * ⛔ যে কাজগুলোতে **টাকা নড়ে** — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ⭐ মালিকের সিদ্ধান্ত: এগুলোতে **একসাথে সই দেওয়া যায় না**
     * ([[BulkApproval]]) — একটা একটা করে দেখে দিতে হবে।
     *
     * ⓘ বেতনের রান — টাকা সত্যিই বেরিয়ে যায়।
     *
     * ⚠️ নামগুলো `approvals`-এ থাকতেই হবে — [[ModuleDefinition]]
     * মিলিয়ে দেখে। ⛔ একটা টাইপো নীরবে কাগজটাকে bulk-এ ঢুকিয়ে দিত।
     */
    /*
     * ⛔ `salary_change` এখানে নেই, ইচ্ছে করে — a6, ২১ অক্টোবর ২০২৬ (§৮ ⚠️৫)। এই তালিকার প্রতিটা কাজে [[MoneyFlowDefaults]]
     * প্রতিটা কোম্পানিতে একটা ছক **বসিয়ে দেয়** (হিসাবরক্ষকের সই); বেতন-বদলের সই কোম্পানির নিজের সিদ্ধান্ত, কোডের নয়।
     * ⓘ ফলে ইনবক্সে একসাথে সই দেওয়া যায় — মালিক চাইলে আলাদা প্রশ্ন।
     */
    'moves_money' => ['payroll', 'expense_claim', 'cash_advance'],

    'doc_types' => [
        /*
         * বেতনের কিস্তি — PRL, PAY নয়।
         *
         * আগে এটা 'PAY' ছিল, আর হিসাবের পরিশোধ ভাউচারও (PV) কাগজে
         * PAY উপসর্গ ছাপে। ফলে একটা বেতনের কিস্তি আর একটা পরিশোধ
         * ভাউচার দুইটাই PAY-2026-2027-0001 হতে পারত — আলাদা দুইটা
         * কাগজ, একই নম্বর। মাস শেষে কাগজ মেলাতে গিয়ে কেউ বুঝত না
         * কোনটার কথা হচ্ছে।
         */
        'PRL' => 'hr::doc.payroll',

        // ⭐ খরচের দাবি আর অগ্রিম অনুরোধ — একই সিরিজ (মালিকের আদেশ, ৭ অক্টোবর ২০২৬)
        'EXC' => 'hr::doc.expense_claim',

        /*
         * পরিচয়ের কোডগুলোও সিরিজ থেকে — মালিকের নির্দেশ (২০২৬-০৮-০৭)।
         *
         * কর্মীর কোড, বেতনের খাত, ছুটির ধরন — তিনটাই কেবল পরিচয়, আর
         * পরিচয়ের কোড মানুষ ঠিক করলে দুইজন একই কোড বসানোর চেষ্টা করেন,
         * আর ফর্মটা ফেরত আসে।
         *
         * হিসাবের ছকের কোড (১১২০ = মজুদ) এই তালিকায় নেই, আর থাকবেও না:
         * ওখানে সংখ্যাটাই অর্থ বহন করে।
         */
        'EMP' => 'hr::doc.employee_code',
        'SLH' => 'hr::doc.salary_head_code',
        'LVT' => 'hr::doc.leave_type_code',
    ],

    'drill_sources' => [
        'employee' => Employee::class,
        'payroll_run' => PayrollRun::class,
        // ⭐ খরচের দাবি — ভাউচার থেকে দাবিতে ফেরা, রসিদের ছবি, আর ক্যাশিয়ারের ভাউচার পাকা হলে "টাকা দেওয়া হয়েছে"
        'expense_claim' => ExpenseClaim::class,
    ],

    /*
     * কর্মী একটা পক্ষ — খতিয়ানের সারি তাঁর নাম ধরে রাখতে পারে।
     *
     * ── কেন এটা লাগল, ১৪ সেপ্টেম্বর ২০২৬ ───────────────────────────
     * মালিক আদায় ভাউচারে "Received From Type" চেয়েছেন, আর তালিকায়
     * **Employee** আছে। ঘোষণাটা ছাড়া কর্মী ভাউচারের পক্ষ-তালিকায় উঠত
     * না ([[App\Core\Module\ModuleDefinition::validateParties()]]
     * পক্ষকে drill_source-এর সাথে মিলিয়ে দেখে)।
     *
     * ⛔ বাস্তবে এটা রোজকার ঘটনা: কর্মীকে সফরের অগ্রিম দেওয়া হলো,
     * তিনি ফিরে এসে বাকি টাকা ফেরত দিলেন। ফেরতটা একটা আদায়, আর
     * ⚠️ পক্ষ ছাড়া সেটা খতিয়ানে বসত **কার কাছ থেকে না বলে** — তখন
     * "কার কাছে কত অগ্রিম পড়ে আছে" প্রশ্নের উত্তর মিলত না।
     *
     * ⓘ কর্মী [[App\Modules\MasterData\Models\Person]] নন, আর
     * সেই সীমানাটা Person-এর ডকেই লেখা: কর্মীর বেতন-হাজিরা-ছুটির
     * নিজের জীবনচক্র আছে, তাই তালিকা দুইটা আলাদা।
     */
    'parties' => [
        'employee' => 'hr::menu.employees',
    ],

    // ⭐ কোন ব্যবহারকারী কোন কর্মী — সম্পদের দায়িত্বে থাকা মানুষ নিজের সম্পদ দেখেন (স্থায়ী সম্পদ ধাপ ৪)
    'bindings' => [
        KnowsAUsersEmployee::class => UserEmployee::class,
    ],

    /*
     * ⭐ ব্যবহারকারীর পদবি — HR-এর কথা, কোরের রেকর্ড সম্পর্কে।
     *
     * ⓘ মালিক ফুটারে *"Al-Amin Shuvo (CEO)"* চেয়েছেন। নামটা
     * ব্যবহারকারীর নিজের, কিন্তু **পদবি কেবল HR-এ আছে** — আর শেল কোরের
     * অংশ, যে কোনো মডিউলের নাম চেনে না (মেপে দেখা: `app/Core`,
     * `app/Models`, `app/Providers` আর শেলের ভিউ — চারটার একটাতেও
     * `App\Modules\` নেই)।
     *
     * ⚠️ তাই উত্তরটা উল্টো দিক থেকে যায়, ঠিক `SalesFacts`-এর মতো।
     */
    'facts' => [
        EmployeeFacts::class,
    ],

    /*
     * ⭐ নতুন কোম্পানিতে প্রমিত ছুটির ধরন — মাতৃত্বকালীন সহ (শ্রম আইন; [[LeaveTypeDefaults]]; a6, ২১ অক্টোবর ২০২৬)।
     * ⓘ চলতি কোম্পানিতে `abos:hr-install-leave-types` বা ছুটির ধরনের পর্দার বোতাম; বারবার চালানো নিরাপদ।
     */
    'provisions' => [
        \App\Modules\Hr\Services\LeaveTypeDefaults::class,
    ],

    'dashboard' => HrDashboard::class,

    // ⭐ রিপোর্ট ইঞ্জিন boot-এ নিবন্ধন করে — হাজিরা, বেতনশিট, কর্মী সংখ্যা, ছুটির জের ([[HrReports]])
    'reports' => [
        HrReports::class,
    ],

    'widgets' => [
        HrWidgets::class,
    ],

    'settings' => [
        /*
         * ⭐ কোন কাগজে ছাপা হবে — মালিকের সিদ্ধান্ত, যন্ত্রের নয়।
         *
         * ⓘ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: *"কি কাগজে প্রিন্ট করবো এটা
         * নিজে নির্ধারণ করে দিব"*। ⚠️ আগে ঠিকানায় মাপ না থাকলে প্রতিটা
         * কন্ট্রোলার নিজে থেকে A4 ধরে নিত, আর বদলানোর কোনো পথ ছিল না।
         *
         * ⓘ প্রতিটা কাগজের নিজের ঘর, কারণ একই দোকানে বিল যায় রোলে আর
         * ভাউচার যায় A4-তে। ⛔ একটা মাত্র ডিফল্ট দিলে তার একটাকে বাঁচাতে
         * গিয়ে অন্যটা প্রতিবার হাতে বদলাতে হত।
         */
        [
            'key' => 'hr.print.paper.payslip',
            'label' => 'hr::settings.paper_payslip',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        /*
         * ছেড়ে যাওয়া কর্মীরা তালিকায় দেখাবে কি না।
         *
         * ডিফল্টে না: বেশিরভাগ দিন কাজ চলতি কর্মীদের নিয়ে, আর দশ বছরের
         * পুরনো নাম প্রতিটা তালিকায় থাকলে খোঁজা কঠিন হয়। কিন্তু মোছা
         * হয় না — পুরনো বেতনশিটে নামটা থাকতেই হবে।
         */
        /*
         * অনুপস্থিতিতে বেতন কাটবে কি না।
         *
         * ডিফল্টে বন্ধ, আর কারণটা গুরুতর: চালু থাকলে যে প্রতিষ্ঠান রোজ
         * হাজিরা লেখে না তার খাতা খালি থাকত। খালি খাতা মানে সবাই
         * অনুপস্থিত — প্রথম মাসেই সবার বেতন কাটা যেত।
         *
         * (সেবা স্তরেও দ্বিতীয় পাহারা আছে: যে দিনের সারি লেখা নেই সেটা
         * গোনাই হয় না। কিন্তু বেতনের মতো জায়গায় একটা পাহারা যথেষ্ট নয়।)
         */
        /*
         * ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল (WPPF) — শ্রম আইন ২০০৬, অধ্যায় ১৫ (a6, ২১ অক্টোবর ২০২৬; [[WppfService]])।
         * ⛔ ডিফল্টে বন্ধ — আইনটা সব প্রতিষ্ঠানে খাটে না (ধারা ২৩২: পরিশোধিত মূলধন ≥ ১ কোটি বা স্থায়ী সম্পদ ≥ ২ কোটি), আর যন্ত্র
         * সেটা আন্দাজ করে না। বন্ধ থাকলে কোথাও কিছু নেই।
         */
        [
            'key' => 'hr.wppf.enabled',
            'label' => 'hr::settings.wppf_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            'key' => 'hr.attendance_affects_salary',
            'label' => 'hr::settings.attendance_affects_salary',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        /*
         * ⭐ সাপ্তাহিক ছুটি — ডিফল্ট শুক্রবার (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬ ধারা ১০৩; [[WorkCalendar]];
         * a6, ২১ অক্টোবর ২০২৬)।
         * ⓘ ছুটির আবেদনের দিন গোনায় এই দিনগুলো বাদ, আর এই দিনে লেখা "অনুপস্থিত" বেতন থেকে কাটে না। সরকারি ছুটি আর উৎসব আলাদা
         * তালিকায় ([[HolidayController]])। ⛔ সপ্তাহের দিন সারি হয়ে বসে না — ৫২টা সারি ভুলে গেলে গোটা বছর কাজের দিন হত।
         */
        [
            'key' => 'hr.weekly_off',
            'label' => 'hr::settings.weekly_off',
            'type' => 'choice',
            'options' => array_keys(\App\Modules\Hr\Support\WorkCalendar::WEEKLY_OFF),
            'option_label' => 'hr::settings.weekly_off_',
            'default' => \App\Modules\Hr\Support\WorkCalendar::DEFAULT_WEEKLY_OFF,
            'group' => 'entry',
            // ⭐ শাখা নিজের দিন বাছতে পারে — দোকান আর কারখানা আলাদা (ধারা ১০৩, ১১৪; fe, ২১ অক্টোবর ২০২৬); বদলটা ছুটির দিনের পর্দায় ([[HolidayController::storeWeeklyOff()]])
            'per_branch' => true,
            // ⭐ এক নিয়ম, এক জায়গা — কোম্পানির ডিফল্টও ছুটির দিনের পর্দার কার্ডে; সেটিংসের পাতা আর কন্ট্রোল প্যানেল দেখায় না (fe-র উত্তর ৪)
            'owned_by' => 'hr.holiday.index',
            'owned_where' => 'hr::settings.weekly_off_owned',
        ],
        /*
         * ⭐ অর্জিত ছুটির জের — বছর শেষে বড়জোর কত দিন পরের বছরে যায়, ডিফল্ট ৪০ (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা
         * ১১৭; [[EarnedLeave::closingOf()]]; a6, ২১ অক্টোবর ২০২৬)। ⓘ অর্জন নিজে আইনের হার — প্রতি ১৮ দিন কাজে ১ দিন — সেটিং নয়।
         */
        [
            'key' => 'hr.earned_leave_carry_cap',
            'label' => 'hr::settings.earned_leave_carry_cap',
            'type' => 'integer',
            'default' => \App\Modules\Hr\Services\EarnedLeave::DEFAULT_CARRY_CAP,
            'group' => 'entry',
        ],
        [
            'key' => 'hr.show_left_employees',
            'label' => 'hr::settings.show_left_employees',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],

        /*
         * ⭐ ভবিষ্য তহবিল — BLA ২০০৬ ধারা ২৬৪, শ্রম বিধিমালা ২০১৫ বিধি ২৬২ (মালিকের অনুমোদিত আইনি বেতনের পরিকল্পনা, a6, ২১ অক্টোবর
         * ২০২৬; [[ProvidentFund]])।
         * ⓘ সুইচ ডিফল্টে বন্ধ: চালু হলে প্রতিষ্ঠানের সমান ভাগ বেতনের স্লিপে আলাদা সারি হয়ে বসে আর খাতায় খরচ (৫২২৫) ও তহবিলের দায়
         * (২১৩১)। কর্মীর ভাগের হার কর্মীর বেতন-কাঠামোয়; ⛔ এখানকার ৮% পুরনো কাঠামো নীরবে বদলায় না — প্রস্তাবিত বদলের পাতা থেকে একটা
         * তারিখ ধরে হাতে বসাতে হয়। ⛔ কেবল সুপার অ্যাডমিন — বেতন থেকে টাকা কাটার নিয়ম।
         */
        [
            'key' => 'hr.pf.enabled',
            'label' => 'hr::settings.pf_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        [
            'key' => 'hr.pf.employee_percent',
            'label' => 'hr::settings.pf_employee_percent',
            'type' => 'number',
            'default' => 8,
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        /*
         * ⭐ প্রতিষ্ঠানের ভাগ কতটা কর্মীর প্রাপ্য — চাকরির পূর্ণ বছর ধরে (BLA ধারা ২৬৭; fe, ২১ অক্টোবর ২০২৬; [[ProvidentFund::vest()]])।
         * ⛔ ডিফল্ট খালি = "বসানো নেই" (১০০% নয়) — চূড়ান্ত নিষ্পত্তি তখন হিসাব করবে না। লেখার ধরন: "3:50, 5:100"।
         */
        /*
         * ⭐ গ্র্যাচুইটির মাসিক সঞ্চিতি — চালু থাকলে মাসের বেতন নিশ্চিত হওয়ার সাথে সেই মাসের দায় পর্যন্ত খাতায় (BLA ধারা 2(10), IAS 19;
         * fe, ২১ অক্টোবর ২০২৬, দফা ৮; [[Gratuity]])। ⓘ ডিফল্টে বন্ধ — রিপোর্ট আর হাতের বোতাম সবসময় চলে।
         */
        [
            'key' => 'hr.gratuity.enabled',
            'label' => 'hr::settings.gratuity_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        [
            'key' => 'hr.pf.vesting_steps',
            'label' => 'hr::settings.pf_vesting_steps',
            'type' => 'string',
            'default' => '',
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        [
            'key' => 'hr.pf.employer_percent',
            'label' => 'hr::settings.pf_employer_percent',
            'type' => 'number',
            'default' => 8,
            'group' => 'entry',
            'super_admin_only' => true,
        ],

        /*
         * ⭐ অধিকাল — BLA ২০০৬ ধারা ১০৮: মূল ÷ ২০৮ × ২ × বাড়তি ঘণ্টা (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])।
         * ⓘ সুইচ ডিফল্টে বন্ধ। দিনের কাজের ঘণ্টা ডিফল্ট ৮; কারা পান — নিয়োগের ধরনের কোড, কমা দিয়ে (ডিফল্ট `WORKER` = শ্রমিক)।
         * ⛔ ব্যবস্থাপকের সই হওয়া ঘণ্টাই কেবল বেতনে।
         */
        [
            'key' => 'hr.ot.enabled',
            'label' => 'hr::settings.ot_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        [
            'key' => 'hr.ot.daily_hours',
            'label' => 'hr::settings.ot_daily_hours',
            'type' => 'number',
            'default' => 8,
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        /*
         * ⭐ বেতন থেকে উৎসে কর — আয়কর আইন ২০২৩ ধারা ৮৬ (a6, ২১ অক্টোবর ২০২৬; সূত্র [[SalaryTax]])।
         * ⓘ ধাপ ডিফল্টে খালি = কিছুই কাটা হয় না, আর রানের পাতায় "করের ধাপ বসানো নেই"। লেখার ধরন: "350000:0, 100000:5, …, *:30"।
         * ছাড়ের সীমা — বেতনের এক-তৃতীয়াংশ বা এই অঙ্ক, যেটা কম (ডিফল্ট 450000)।
         */
        /*
         * ⭐ উৎসব ভাতা — মূলের শতাংশ (শ্রম বিধিমালা ২০১৫ বিধি ১১১: এক মাসের মূল পর্যন্ত; a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])।
         * ⓘ ডিফল্ট 100 — কমানো যায়, 100-এর বেশি ধরা হয় না। বছরে বড়জোর দুইটা রান, এক বছরের চাকরি — কোডের নিয়ম, সেটিং নয়।
         */
        [
            'key' => 'hr.bonus.percent_of_basic',
            'label' => 'hr::settings.bonus_percent_of_basic',
            'type' => 'number',
            'default' => 100,
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        [
            'key' => 'hr.tax.slabs',
            'label' => 'hr::settings.tax_slabs',
            'type' => 'string',
            'default' => '',
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        [
            'key' => 'hr.tax.exemption_cap',
            'label' => 'hr::settings.tax_exemption_cap',
            'type' => 'number',
            'default' => 450000,
            'group' => 'entry',
            'super_admin_only' => true,
        ],
        [
            'key' => 'hr.ot.eligible_types',
            'label' => 'hr::settings.ot_eligible_types',
            'type' => 'string',
            'default' => 'WORKER',
            'group' => 'entry',
            'super_admin_only' => true,
        ],

        /*
         * ⭐ কর্মী-বিভাগ গোটা গ্রুপের, না প্রতিটা কোম্পানির আলাদা —
         * মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬: *"eta chailew keu korte
         * parbe tajonno control panel e bebosta rako"*।
         *
         * ── ⓘ কেন সুইচটা দরকার ─────────────────────────────────────
         * একই মালিকের তিনটা কোম্পানি, আর কর্মীরা প্রায়ই সবগুলোর জন্যই
         * কাজ করেন। ⚠️ আজ প্রতিটা কোম্পানিতে আলাদা কর্মী-তালিকা, তাই
         * একজন মানুষকে তিনবার বসাতে হয় — আর তিনটা বেতনশিট, তিনটা
         * ছুটির খাতা, যা মেলানো যায় না।
         *
         * ── ⛔ কেন এটা `scope => product`, কোম্পানির নয় ──────────────
         * সেটিং সাধারণত কোম্পানি ধরে বসে। ⚠️ এটা ওভাবে বসালে TCL
         * সুইচটা চালু করে **DEM-এর কর্মী দেখত, আর DEM দেখত না TCL-এর**
         * — অথচ DEM কখনো রাজি হয়নি।
         *
         * ⓘ এক পাশ থেকে খোলা দেয়াল সুবিধা নয়, **ফাঁস** — আর সবচেয়ে
         * খারাপ ধরনের, কারণ যাঁকে দেখা যাচ্ছে তিনি জানেনই না। ⭐ তাই
         * উত্তরটা একটাই, সবার জন্য এক।
         *
         * ── ⚠️ ডিফল্টে বন্ধ, আর সেটা আলোচনার বাইরে ──────────────────
         * ⛔ চালু ডিফল্ট মানে আপগ্রেডের দিন প্রতিটা প্রতিষ্ঠানের কর্মী ও
         * বেতন নীরবে সব কোম্পানিতে খুলে যেত। ⓘ টেন্যান্ট-বিচ্ছিন্নতা
         * এখানে সুবিধা নয়, বাধ্যবাধকতা — তাই দেয়ালটা কেবল **হাতে**
         * খোলা যায়, আর জেনেশুনে।
         */
        [
            'key' => 'hr.shared_across_companies',
            'label' => 'hr::settings.shared_across_companies',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
            'scope' => 'product',
            /*
             * ⛔ কেবল সুপার অ্যাডমিন — পূর্ণ নিরীক্ষা §১.৬, ২৭ সেপ্টেম্বর ২০২৬।
             * ⚠️ আগে Settings Keeper-ও এটা চালু করতে পারতেন, আর চালু হলে ৯টা HR
             * টেবিলের কোম্পানির দেয়াল উঠে যেত — এক কোম্পানির কেরানি সব কোম্পানির
             * বেতন আর NID খুলে দিতেন। ⓘ পর্দার সতর্ক-লেখা পাহারা নয়।
             */
            'super_admin_only' => true,
        ],
    ],
    /*
     * যে ঘরগুলো সবাই দেখবে না।
     *
     * ── কী ভাঙা ছিল (২ সেপ্টেম্বর ২০২৬) ─────────────────────────────
     * কর্মীর পর্দায় বেতনের অংশটা ঠিকই `@can('hr.salary.view')`-এ ঢাকা
     * ছিল। কিন্তু ঠিক তার উপরেই **জাতীয় পরিচয়পত্র, ব্যাংক হিসাব নম্বর
     * আর MFS নম্বর খোলা ছাপা হত** — যিনি কর্মীর তালিকা দেখতে পান,
     * তিনিই সব।
     *
     * অর্থাৎ পাহারাটা ছিল টাকার অঙ্কে, পরিচয়ে নয় — অথচ পরিচয়ের
     * তথ্য দিয়ে আরও দূর যাওয়া যায়।
     *
     * ── বেতনের অঙ্কগুলো এখানে নেই, আর কেন ───────────────────────────
     * `hr_salary_structures.amount` বা payslip-এর `gross`/`net`
     * ঘোষণা করা হয়নি ইচ্ছে করে। ওগুলোর নাম এত সাধারণ যে
     * [[NoSensitiveFieldIsPrintedInTheOpenTest]] পাঠ্য খুঁজে প্রতিটা
     * বিল-ভাউচারের পর্দাতেও ওদের "পেয়ে" যেত — আর যে পাহারা রোজ
     * মিথ্যা অভিযোগ করে, সেটা এক সপ্তাহে মুছে ফেলা হয়।
     *
     * ওগুলোর জন্য পর্দার `@can('hr.salary.view')` শর্তটাই কাজ করে, আর
     * সেটা সঠিক স্তরে — গোটা বেতনের অংশটাই ঢাকা পড়ে, একটা ঘর নয়।
     * ⚠️ কিন্তু API লেখার দিন ওগুলোকে আলাদা করে ভাবতে হবে: JSON-এ
     * `@can` বলে কিছু নেই।
     */
    /*
     * ⭐ খরচের দাবি বা অগ্রিমের শেষ সই — অনুমোদিত আর খসড়া ভাউচার; "না" হলে ফেরানো ([[FinishTheClaimOnTheLastSignature]])।
     */
    'listeners' => [
        // ⭐ বেতনের কাঠামো বদলের শেষ সই — তবে বসে (§৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬)
        ApprovalDecided::class => [
            FinishTheClaimOnTheLastSignature::class,
            FinishTheSalaryChangeOnTheLastSignature::class,
            FinishThePaymentChangeOnTheLastSignature::class,
            // ⭐ বকেয়া মজুরির খসড়া ভাউচার সইয়ে "না" — মজুরি আবার দেওয়ার অপেক্ষায় (fe, ২১ অক্টোবর ২০২৬)
            ReleaseHeldWagesWhenTheVoucherFalls::class,
            // ⭐ "ব্যাংক এই লাইন দেয়নি"-র শেষ সই — মজুরি আবার দেওয়ার তালিকায়, একবারই (fe-র সিদ্ধান্ত ২, ২১ অক্টোবর ২০২৬)
            FinishTheBankUnpaidLineOnTheLastSignature::class,
            // ⭐ চূড়ান্ত পাওনার শেষ সিদ্ধান্ত — অনুমোদনে খাতায়, "না"-তে বন্ধ (মালিক, ২১ অক্টোবর ২০২৬)
            FinishTheFinalSettlementOnTheLastSignature::class,
        ],
        // ⭐ বকেয়া মজুরির খসড়া ভাউচার বাতিল — একই কথা ([[HeldWages::releaseFor()]])
        \App\Modules\Accounts\Events\VoucherCancelled::class => [ReleaseHeldWagesWhenTheVoucherFalls::class],
        // ⭐ অনুমোদনের কেন্দ্রের "ফিরিয়ে নিন" — বেতনের ঠিকানার অপেক্ষার বদলটাও বন্ধ (fe, ২১ অক্টোবর ২০২৬)
        ApprovalWithdrawn::class => [CloseThePaymentChangeOnWithdrawal::class, FinishTheBankUnpaidLineOnTheLastSignature::class,
            FinishTheFinalSettlementOnTheLastSignature::class],
    ],

    'sensitive_fields' => [
        // ⭐ পরিচয় আর ব্যক্তিগত তথ্য — এক তালিকা ([[Employee::GUARDED]]; ঘাটতির তালিকা #১৯)
        Employee::class => array_fill_keys(Employee::GUARDED, 'hr.identity.view'),
        Payslip::class => [
            'bank_account_no' => 'hr.identity.view',
            'bank_routing_no' => 'hr.identity.view',
            'mfs_number' => 'hr.identity.view',
        ],
    ],

    /*
     * ⛔ বেতনের অঙ্ক অডিটের পর্দায় খোলা ছিল — Governance অডিট, ১৮ অক্টোবর ২০২৬।
     *
     * ⓘ উপরের টীকা ঠিকই বলেছিল "API লেখার দিন আলাদা করে ভাবতে হবে" — কিন্তু অডিটের তালিকা, একটা সারি, সময়যন্ত্র আর
     * তালিকার CSV-ও `@can` জানে না: ওরা যেকোনো মডেলের ঘর ধরে পুরনো ও নতুন মান ছাপে। নিরীক্ষকের (`governance.audit.view`)
     * হাতে `hr.salary.view` নেই, অথচ প্রতিটা বেতন-বদল "২০০০০ → ২৫০০০" হয়ে তাঁর চোখে পড়ত।
     *
     * ⓘ এখানে, `sensitive_fields`-এ নয়: নামগুলো সাধারণ বলে ব্লেডের খোঁজা ([[NoSensitiveFieldIsPrintedInTheOpenTest]]) এদের
     * দেখে না, কিন্তু [[FieldSecurity]] দেখে — অর্থাৎ HR-এর নিজের পর্দায় অংশের `@can`, সাধারণ পর্দায় ঘরের ঢাকনা।
     * ⓘ পরিচয়ের ঘরগুলো (ব্যাংক, MFS) উপরে থেকেই যায়; এখানে কেবল টাকার অঙ্ক।
     */
    'section_guarded_fields' => [
        SalaryStructure::class => [
            'amount' => 'hr.salary.view',
        ],
        Payslip::class => [
            'gross' => 'hr.salary.view',
            'deductions' => 'hr.salary.view',
            'net' => 'hr.salary.view',
        ],
        PayslipLine::class => [
            'amount' => 'hr.salary.view',
        ],
        PayrollRun::class => [
            'gross_total' => 'hr.salary.view',
            'deduction_total' => 'hr.salary.view',
            'net_total' => 'hr.salary.view',
        ],
    ],

    // ⭐ প্রোফাইলের পরিচয় — পদ, ফোন, কর্মী নম্বর, যোগদান ([[ContributesToAProfile]]; ২১ নভেম্বর ২০২৬)
    'profile' => [
        \App\Modules\Hr\Profile\EmployeeProfile::class,
    ],

    // ⭐ ফোনের হোম-স্ক্রিনে ১৯ আমার উপস্থিতি — নিজেরটাই, বেতন নয় (a6, ২০ অক্টোবর ২০২৬)
    'phone_widgets' => [
        \App\Modules\Hr\Widgets\AttendanceSummary::class,
    ],

];
