# Accounts মডিউলের সব রিপোর্ট/স্ক্রিন যাচাই (2026-08-09, ~17:20-18:00)

ব্যবহারকারীর অনুরোধে Accounts & Finance মডিউলের সবগুলো রিপোর্ট/স্ক্রিন একসাথে যাচাই করা হলো: Trial Balance, Profit & Loss, Balance Sheet, General Ledger, AR Reports, AP Reports, Cash Book, Bank Book, Cash Tills, Day-End Count (Cash Count), Cash Reconciliation, Party Ledger, Day Book। কোম্পানি: Trade Depot (আসল লেনদেন-ডেটাসহ)।

## 🔴 ভুল ১ (সবচেয়ে জরুরি, নতুন) — Trial Balance-এ লুকানো তারিখ-ফিল্টার, ভুল সংখ্যা দেখায়

বিস্তারিত `2026-08-09-opening-stock.md`-এ আছে, সংক্ষেপে: Trial Balance ও Balance Sheet উভয়েরই "Filter By" প্যানেলে ডিফল্ট "From date" = চলতি মাসের ১ তারিখ থাকে (ভাঁজ করা, চোখে পড়ে না)। **Balance Sheet সঠিকভাবে এই "From date" উপেক্ষা করে cumulative ব্যালেন্স দেখায়, কিন্তু Trial Balance ভুলভাবে এটাকে ব্যালেন্স ক্যালকুলেশনেও প্রয়োগ করে** — ফলে "From date"-এর আগের সব পোস্টিং (Opening Stock ইত্যাদি) বাদ পড়ে যায়। প্রমাণ: Ledger-এ Account="Inventory", From=2020-01-01 দিয়ে দেখা গেছে ০১/০৭/২০২৬ তারিখে ৭টা পৃথক "OPENING" এন্ট্রি (মোট ৮,৪০,০০০) আছে, যা Trial Balance-এর ডিফল্ট ভিউ থেকে বাদ পড়ে যাচ্ছিল।

**এটা আগের সিদ্ধান্তকে ("ডেমো ডেটার সমস্যা, কোড-বাগ না") বাতিল করে** — এটা আসলে একটা জরুরি কোড-বাগ।

## 🔴 ভুল ২ (নতুন, সম্পূর্ণ ব্লকিং) — Cash Count (Day-End Count) কখনোই সেভ করা যায় না

- **কোন পর্দা:** `/accounts/cash-counts/create`
- **কী করলাম:** Cash Tills = "Main Cash Counter" বেছে, নোট-ব্রেকডাউন টেবিলে ১০০০-টাকার নোট ৮০১টা আর ৫০০-টাকার নোট ১টা লিখলাম (মোট ৮,০১,৫০০ হওয়ার কথা, যা বইয়ের ব্যালেন্সের সাথে মিলে)।
- **কী হল:** প্রতিটা সারির "Amount" কলাম আর নিচের "Total" সারি (Pieces ও Amount দুটোই) **সবসময় "0" / "0.00" দেখাচ্ছে** — পিস সংখ্যা লেখার পরেও, Tab চেপে ফোকাস সরানোর পরেও কোনো পরিবর্তন হয় না। এটা শুধু ডিসপ্লে-বাগ না — সরাসরি কীবোর্ড টাইপিং (fill না, আসল keystroke সিমুলেশন) দিয়েও একই ফল, তাই এটা Playwright-এর সমস্যা না, সাইটের নিজের জাভাস্ক্রিপ্ট রিঅ্যাক্টিভিটির বাগ।
- **সবচেয়ে গুরুত্বপূর্ণ:** "Save count" বাটনটা নিজেই `total <= 0` হলে disabled থাকে (বাটনের নিজের কোড: `(busy || total <= 0) && 'pointer-events-none opacity-50'`) — যেহেতু `total` কখনো ০ ছাড়িয়ে যায় না, **বাটনে ক্লিকই করা যায় না, কোনো Cash Count কখনোই সেভ করা সম্ভব না।**
- **তুলনা:** Opening Stock পর্দায় (`/inventory/stock/opening`) একই ধরনের Qty×Rate লাইভ ক্যালকুলেশন ঠিকমতো কাজ করে (Value ঘর লাইভ আপডেট হয়) — তাই এই Cash Count পর্দার calculation logic-টা আলাদাভাবে ভাঙা, সাধারণ ফর্ম-লাইব্রেরির সমস্যা না।
- **প্রভাব:** এই বাগের কারণে **"Day-End Count" ও "Cash Reconciliation" — দুটো ফিচারই সম্পূর্ণ অকার্যকর**, কারণ Cash Reconciliation-এর ভিত্তিই হলো একটা Cash Count সেভ করে বইয়ের ব্যালেন্সের সাথে তুলনা করা।
- **কনসোল এরর নেই** (`browser_console_messages` চেক করা হয়েছে) — নীরব লজিক বাগ, ক্র্যাশ না।

## ✅ ঠিক আছে যা যাচাই হয়েছে

- **Profit & Loss** (`/accounts/reports/profit-loss`) — অভ্যন্তরীণভাবে সামঞ্জস্যপূর্ণ: নিট মুনাফা ২২৫.০০ (Sales ১,৫০০ − Sales Return ৩৭৫ − COGS ৯০০), হিসাব মিলে যায়।
- **General Ledger** (`/accounts/reports/ledger`) — Account+From-date ফিল্টার দিয়ে প্রতিটা এন্ট্রি আলাদাভাবে সঠিক ও ট্রেসেবল (ভাউচার লিংকসহ)। এই রিপোর্টে নিজে কোনো বাগ নেই, তবে ডিফল্ট "From date" ফিল্টার একই রকম (চলতি মাস) — ব্যবহারকারী সচেতন না থাকলে বিভ্রান্ত হতে পারেন।
- **AR — Due List ও Ageing** (`/customers/reports/due-list`, `/customers/reports/ageing`) — উভয়ই -৩৭৫.০০ দেখায়, Trial Balance-এর Accounts Receivable-এর সাথে মেলে।
- **AP — Payable List** (`/suppliers/reports/payable-list`) — ১০,৮০০.০০ দেখায়, Trial Balance-এর Accounts Payable নিট ব্যালেন্সের সাথে ঠিক মেলে।
- **Cash Book** (`/accounts/reports/cash-book`) — শেষ ব্যালেন্স ৮,০১,৫০০.০০, Cash Tills পর্দার "in hand" সংখ্যার সাথে ঠিক মেলে।
- **Bank Book** (`/accounts/reports/bank-book`) — "No transactions in this range" — ঠিক আছে, কারণ কোনো ব্যাংক-টিল তৈরিই করা হয়নি ডেমো ডেটায় (বাগ না, ডেটার অভাব)।
- **Cash Tills** (`/accounts/cash-tills`) — "801,500.00 in hand across the company" — সঠিক, কোনো তারিখ-ফিল্টার সমস্যা নেই এখানে (বর্তমান ব্যালেন্স সবসময় cumulative)।

- **Party Ledger** (গ্রাহক/সরবরাহকারী পর্দার "Transactions" ট্যাব, যেমন `/customers/7#transactions`, `/suppliers/4#transactions`) — কোনো তারিখ-ফিল্টার নেই, সবসময় cumulative দেখায়, Outstanding/Payable-এর সাথে ঠিক মেলে (গ্রাহক -৩৭৫.০০, সরবরাহকারী ১০,৮০০.০০)। কোনো বাগ নেই।
- **Day Book** (`/accounts/reports/day-book`) — এরও ডিফল্ট "চলতি মাস" ফিল্টার আছে, কিন্তু এটা যৌক্তিক কারণ Day Book স্বভাবতই একটা নির্দিষ্ট সময়ের লেনদেন দেখানোর রিপোর্ট (Trial Balance-এর মতো cumulative ব্যালেন্স-রিপোর্ট না) — তাই এখানে এই ডিফল্ট ফিল্টার সঠিক আচরণ, বাগ না।

## সারসংক্ষেপ — কোন ধরনের রিপোর্টে ডিফল্ট তারিখ-ফিল্টার ঠিক আছে, কোথায় ভুল

| রিপোর্ট | ডিফল্ট ফিল্টার প্রয়োগ সঠিক? |
|---|---|
| Trial Balance | ❌ ভুল — cumulative হওয়ার কথা, কিন্তু ফিল্টার প্রয়োগ হচ্ছে |
| Balance Sheet | ✅ ঠিক — ফিল্টার থাকলেও ব্যালেন্স হিসাবে প্রভাব ফেলে না |
| Ledger | ⚠️ ডিফল্ট বিভ্রান্তিকর কিন্তু From date বদলালে ঠিক কাজ করে |
| Day Book | ✅ ঠিক — period-report হিসেবে এটাই প্রত্যাশিত |
| AR Due List / Ageing / AP Payable List / Party Ledger | ✅ ঠিক — cumulative ব্যালেন্স, কোনো সমস্যা পাওয়া যায়নি |
| Cash Book / Cash Tills | ✅ ঠিক |

## চূড়ান্ত তালিকা — এই দফায় পাওয়া বাগ

1. 🔴 **Trial Balance-এর তারিখ-ফিল্টার বাগ** (জরুরি) — cumulative ব্যালেন্স না দেখিয়ে ভুলভাবে "From date" প্রয়োগ করে
2. 🔴 **Cash Count সম্পূর্ণ সেভ করা যায় না** (জরুরি, ব্লকিং) — লাইভ Total ক্যালকুলেশন কাজ করে না বলে Save বাটন সবসময় disabled থাকে, ফলে Day-End Count ও Cash Reconciliation দুটো ফিচারই ব্যবহারের অযোগ্য

## পুনঃযাচাই (2026-08-09, ~19:16) — এখনো অপরিবর্তিত

চারটা প্রধান জিনিস আবার চেক করা হলো: (১) ক্রয়ের Lines টেবিল — ঠিক আছে, কোনো এরর নেই; (২) কোম্পানি তৈরির UI — এখনো আছে, Provati Traders-এর Branches ঠিক "3" দেখাচ্ছে; (৩) Trial Balance-এর তারিখ-ফিল্টার বাগ — এখনো অপরিবর্তিত (Inventory এখনো ১৮,৩০০/২,৪০০, Retained Earnings এখনো ৬,০০০); (৪) Cash Count সেভ বাগ — এখনো অপরিবর্তিত, পিস সংখ্যা লিখলেও Amount/Total এখনো "0.00"/"0"। **কোনো পরিবর্তন নেই।**
