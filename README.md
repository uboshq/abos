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
| **Print / Template** | ৬ ডকুমেন্ট × ৩ কাগজ × ২ ভাষা | ✅ |
| **Report** | কোয়েরি + কলাম কনফিগে | ✅ |
| **Audit** | পুরনো ও নতুন মান সহ, ঘর ধরে ধরে | ✅ |

---

## মালের দাম — FIFO

**নতুন কেউ কোড ছোঁয়ার আগে এই অংশটা পড়বেন।** এটাই সিস্টেমের সবচেয়ে
নিঃশব্দ নিয়ম, আর এখানে ভুল হলে খাতার সংখ্যা ভুল হয়।

মাল যে দামে গুদামে ঢোকে, ঠিক সেই দামেই বেরোয় — **যেটা আগে ঢুকেছে সেটাই
আগে বেরোয়** (মালিকের সিদ্ধান্ত, ২০২৬-০৮-০৭)। প্রতিটা চালান নিজের দাম নিয়ে
একটা স্তর হয়ে থাকে (`inv_cost_layers`), আর প্রতিটা টান আলাদা সারিতে লেখা
থাকে (`inv_cost_layer_uses`) — তাই যেকোনো খরচের অঙ্ক থেকে তার চালানে
পৌঁছানো যায়।

**কোনো কোড যেন পণ্য-মাস্টারের `purchase_price` ধরে খরচ না বসায়।** ওটাই
আগের রোগ ছিল: মাল ঢুকত চালানের আসল দরে, বেরোত মাস্টারের দরে, আর দুইটা
কখনো এক হত না। ১,০০০ টাকার ১০ বস্তার ৪টা বেচে মজুদ খাত থেকে ১৩,৬০০
বেরিয়ে গিয়েছিল, আর খাতটা ঋণাত্মক হয়ে বসেছিল।

খরচের দর নিতে হবে `CostLayerService` থেকে:

| যা ঘটছে | কী ডাকতে হবে |
|---|---|
| মাল ঢুকল (চালান, receipt-হীন বিল, উদ্বৃত্ত) | `receive()` |
| মাল বেরোল (বিক্রয়, ঘাটতি) | `issue()` |
| নির্দিষ্ট চালানের মাল বেরোল (ক্রয় ফেরত) | `issueFromSource()` |
| বিক্রি হওয়া মাল ফিরল | `returnToLayers()` |
| নথি বাতিল, মাল ছোঁয়া হয়নি | `withdraw()` |

দাম জানা নেই এমন মাল বেরোতে পারে না — তখন থেমে গিয়ে বলা হয় কী করতে হবে।
একটা দর ধরে নেওয়া নিষিদ্ধ, কারণ ধরে নেওয়া দরই এই পুরো ব্যবস্থাটার শত্রু।

বিস্তারিত ও কীভাবে ধরা পড়েছিল:
`docs/Finding — Inventory is valued two different ways.md`

---

## কোড নিজে বসে

গ্রাহক, সরবরাহকারী, পণ্য, গুদাম, কর্মী, ক্যাশ টিল, বেতন খাত ও ছুটির ধরন —
কোডের ঘর ফাঁকা রাখলে নম্বর সিরিজ থেকে নিজে বসে (মালিকের নির্দেশ,
২০২৬-০৮-০৭)। ঘরটা তবু থাকে: পুরনো হিসাবের কোড ধরে রাখতে চাইলে হাতে লেখা
যায়।

**যেগুলো হাতেই থাকে, আর থাকবে:** হিসাবের ছকের কোড (`1120` = মজুদ), একক
(`PCS`), কর (`VAT15`), কারণ কোড (`HOLD-DMG`)। ওখানে সংখ্যাটা বা অক্ষরগুলোই
অর্থ বহন করে; অটো করলে খাতা পড়া যেত না।

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

---

## পরিবেশের তিনটা ফাঁদ

একদিনে তিনটাই একসাথে ঘটেছে (২০২৬-০৮-০৭), আর তিনটাই দেখতে কোডের বাগের
মতো লাগে। কোড খোঁজার আগে এগুলো দেখে নিন।

**১ · `php.ini` রিসেট হয়ে যায়।** Laragon আপডেট বা PHP পুনরায় বসালে ফাইলটা
আগের অবস্থায় ফেরে। তখন `extension=zip` চলে যায় (ব্যাকআপ প্যাকেজ ভাঙে,
composer-ও ঠিকমতো চলে না) আর OPcache বন্ধ হয়ে যায় (পাতা ১০ গুণ ধীর)।
দুইটাই দরকার:

```ini
extension=zip
zend_extension=opcache
```

**২ · CLI-তে OPcache চালু করা যাবে না।** `opcache.enable_cli=1` দিলে পুরো
টেস্ট স্যুট থেমে যায়:
`Declaration of Carbon\CarbonPeriod::getIterator(): Generator must be
compatible with DatePeriod::getIterator(): Iterator`
— Carbon তার মূল ক্লাসটা PHP সংস্করণ দেখে শর্তসাপেক্ষে বানায়, আর ক্যাশ
সেই বাঁধনটা ভুল প্রসেসে ফিরিয়ে আনে। বার্তাটা Carbon-এর দিকে আঙুল তোলে,
দোষটা ক্যাশের। `opcache.enable_cli=0` (PHP-র নিজের ডিফল্টও তাই); ওয়েবে
চালু থাকবে, লাভটা তো ওখানেই।

**৩ · থামানো টেস্ট প্রসেস আসলে মরে না।** ব্যাকগ্রাউন্ডের `artisan test`
বন্ধ করার পরেও চলতে থাকে, আর পরের রানের সাথে একই টেস্ট ডাটাবেজে লড়াই
করে — তখন `Table 'migrations' doesn't exist` আর `Table 'users' already
exists` পালা করে আসে, আর ১০০+ টেস্ট "ব্যর্থ" দেখায় যদিও কোডে কিছুই ভুল
নেই। নতুন রান শুরুর আগে দেখে নিতে হয়:

```powershell
Get-CimInstance Win32_Process -Filter "name='php.exe'" |
  Where-Object CommandLine -match 'artisan test|phpunit'
```

কিছু পেলে মেরে ফেলে `abos_test` ডাটাবেজটা নতুন করে বানাতে হয়।
