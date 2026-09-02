<?php

declare(strict_types=1);

use App\Modules\Hr\Dashboard\HrDashboard;
use App\Modules\Hr\Dashboard\HrWidgets;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;

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
            ['label' => 'hr::dashboard.title', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'hr'], 'permission' => 'hr.employee.view'],
        ],

        'master' => [
            ['label' => 'hr::menu.employees', 'route' => 'hr.employee.index', 'permission' => 'hr.employee.view'],
            ['label' => 'hr::menu.salary_heads', 'route' => 'hr.salary_head.index', 'permission' => 'hr.salary.manage'],
        ],

        'transactions' => [
            ['label' => 'hr::menu.payroll', 'route' => 'hr.payroll.index', 'permission' => 'hr.payroll.view'],
            ['label' => 'hr::menu.attendance', 'route' => 'hr.attendance.index', 'permission' => 'hr.attendance.view'],
            ['label' => 'hr::menu.leave', 'route' => 'hr.leave.index', 'permission' => 'hr.leave.view'],
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
         * ছুটি চাওয়া আর ছুটি মঞ্জুর করা আলাদা।
         *
         * এক অনুমতিতে রাখলে যে কেউ নিজের ছুটি নিজেই মঞ্জুর করতে পারত,
         * আর অনুমোদন বলে কিছু থাকত না।
         */
        'hr.leave.view',
        'hr.leave.manage',
        'hr.leave.approve',
    ],

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
    ],

    'dashboard' => HrDashboard::class,

    'widgets' => [
        HrWidgets::class,
    ],

    'settings' => [
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
        [
            'key' => 'hr.attendance_affects_salary',
            'label' => 'hr::settings.attendance_affects_salary',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            'key' => 'hr.show_left_employees',
            'label' => 'hr::settings.show_left_employees',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
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
    'sensitive_fields' => [
        Employee::class => [
            'national_id' => 'hr.identity.view',
            'bank_account_no' => 'hr.identity.view',
            'bank_routing_no' => 'hr.identity.view',
            'mfs_number' => 'hr.identity.view',
        ],
        Payslip::class => [
            'bank_account_no' => 'hr.identity.view',
            'bank_routing_no' => 'hr.identity.view',
            'mfs_number' => 'hr.identity.view',
        ],
    ],

];
