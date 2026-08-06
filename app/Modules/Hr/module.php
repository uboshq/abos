<?php

declare(strict_types=1);

use App\Modules\Hr\Dashboard\HrWidgets;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;

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
     * হিসাব লাগে — বেতন শেষমেশ খরচ ও দায় হয়ে বইয়ে বসে।
     * মাস্টার ডাটা লাগে — বিভাগ, পদবি ও নিয়োগের ধরন ওখানকার তালিকা।
     */
    'depends_on' => ['accounts', 'master_data'],

    'menu' => [
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
    ],

    'drill_sources' => [
        'employee' => Employee::class,
        'payroll_run' => PayrollRun::class,
    ],

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
];
