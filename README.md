# ABOS — All Business Operating System

*Built Around Your Business.*
Developed by Al-Amin Shuvo · Powered by UNIVER BANGLADESH · www.aboserp.com

একটা হালকা ERP — Laravel 13, PHP 8.3, MySQL 8.4। পরিকল্পনা:
`E:\ABOS\ABOS_Lightweight_ERP_Plan_v3.1.docx`

---

## চালু করা

```powershell
# MySQL চালু (Laragon বন্ধ থাকলে)
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe --datadir="C:\laragon\bin\mysql\mysql-8.4.3-winx64\data" --console

# ডাটাবেজ ও ডেমো ডাটা
php artisan migrate:fresh --seeder=DemoSeeder --seed

# টেস্ট
php artisan test

# সার্ভার
php artisan serve
```

ডেমো লগইন — সবার পাসওয়ার্ড `password`:

| ব্যবহারকারী | ইমেইল | রোল | কোম্পানি |
|---|---|---|---|
| Al-Amin Shuvo | owner@abos.test | owner | ALPHA + BETA |
| হিসাবরক্ষক | accounts@abos.test | accountant | ALPHA |
| বিক্রয়কর্মী | sales@abos.test | salesman | ALPHA |

মালিক দুই কোম্পানিতেই আছেন — সুইচ করে দেখলে বোঝা যায় এক কোম্পানির ডাটা
অন্যটায় নেই।

---

## অলঙ্ঘনীয় শর্ত

1. **নেস্টেড ফোল্ডার কাঠামো** — প্রতিটা মডিউল নিজের ফোল্ডারে, ভেতরে
   Controllers, Models, Services, Requests, views, lang, Migrations।
2. **১০০% এন্টারপ্রাইজ-গ্রেড কোডিং** — কোনো স্টাব নয়, কোনো "আপাতত চলবে" নয়।
3. **১০০% এন্টারপ্রাইজ-গ্রেড ডিজাইন** — এক টোকেন, এক টুলবার, এক ফর্ম স্ট্যান্ডার্ড।
4. **১০০% এন্টারপ্রাইজ-গ্রেড সিকিউরিটি** — প্রতিটা কোয়েরিতে `company_id`,
   প্রতিটা রুটে permission, প্রতিটা লেনদেনে অডিট, প্রতিটা ইনপুটে ভ্যালিডেশন।

**গুণগত মানে কোনো ছাড় নেই।** সময় কম পড়লে স্কোপ কমবে, মান নয়।

---

## কাঠামো

```
app/
├── Core/                      সব মডিউলের সাধারণ ভিত্তি
│   ├── Concerns/                BelongsToCompany · HasDocumentStatus
│   ├── Contracts/               Drillable
│   ├── Engines/                 আটটা engine
│   ├── Module/                  ModuleDefinition · ModuleRegistry
│   ├── Services/                SettingsService
│   └── Support/                 CompanyContext · DocumentStatus
├── Modules/                   প্রতিটা মডিউল স্বয়ংসম্পূর্ণ
│   ├── Accounts/  module.php
│   ├── Customer/  module.php
│   └── …
└── Providers/ModuleServiceProvider.php   ← Modules/ স্ক্যান করে সব রেজিস্টার করে
```

নতুন মডিউল যোগ করতে কোর কোড ছুঁতে হয় না — `app/Modules/<Name>/` ফোল্ডার আর
তার ভেতরে `module.php` রাখলেই কোর সেটা খুঁজে নেয়, রুট-মেনু-permission-
মাইগ্রেশন সব নিজে থেকে বসায়। বিস্তারিত প্ল্যানের সেকশন ১৯-এ।

---

## আটটা Engine

| Engine | কাজ | অবস্থা |
|---|---|---|
| **Posting** | হিসাবের খাতায় লেখার একমাত্র পথ; ডেবিট=ক্রেডিট বাধ্যতামূলক | ✅ |
| **Number Series** | row lock দিয়ে ডকুমেন্ট নম্বর, কখনো দুইবার এক নম্বর নয় | ✅ |
| **Approval** | polymorphic, বহু-স্তর, সীমা-সাপেক্ষ | ✅ |
| **Drill-down** | যেকোনো সংখ্যা থেকে তার উৎস ডকুমেন্টে | ✅ |
| **Attachment** | ফাইল ডিস্কে (base64 নয়), সংস্করণ সহ | ✅ |
| **Module Registry** | module.php পড়ে সব নিবন্ধন | ✅ |
| **Print / Template** | ৬ ডকুমেন্ট × ৩ কাগজ × ২ ভাষা | ⏳ |
| **Report** | কোয়েরি + কলাম কনফিগে | ⏳ |

---

## যা প্রমাণিত হয়েছে (Phase 0)

- **বাংলা PDF** — mPDF + Hind Siliguri-তে ১২টা কঠিন যুক্তাক্ষর A4/৮০mm/৫৮mm
  তিন কাগজেই অক্ষত। `php tests/phase0/bangla_pdf_test.php`
- **১ লাখ রো-তে রিপোর্ট** — Trial Balance ১৪০ms, Ledger ১২ms, Day Book ২.৪ms।
  `php tests/phase0/big_data_report_test.php`
- **একটা ফাঁদ** — tabular ফন্টে (DejaVu Sans) বাংলা অঙ্ক ফাঁকা বাক্স হয়ে যায়।
  তাই টাকার অঙ্ক সবসময় ইংরেজিতে। প্রমাণ টেস্ট PDF-এর "সংখ্যার নিয়ম" টেবিলে।

---

## টেস্ট

```
php artisan test
```

MySQL-এ চলে, sqlite-এ নয় — sqlite-এ row lock নেই, তাই নম্বর সিরিজের
concurrency পাহারাটা ওখানে পাশ করত আর বাগটা প্রোডাকশনে যেত।
