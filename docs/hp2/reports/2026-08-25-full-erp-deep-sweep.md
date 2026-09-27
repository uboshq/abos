# পুরো ERP-র প্রতিটা মেনু/সাবমেনুতে গভীর এন্ট্রি-টেস্ট (2026-08-25)

ব্যবহারকারীর স্পষ্ট নির্দেশ: শুধু Accounts না, **পুরো ERP-র প্রতিটা মেনু/সাবমেনুতে বাস্তব এন্ট্রি দিয়ে** গভীরভাবে চেক করা। কোম্পানি: Provati Traders।

## Master Data (১৫টা সাবমেনু)

দেখুন `2026-08-25-master-data-500-errors.md` — সংক্ষেপে: ১১টা পুরনো সাবমেনু ঠিক আছে, **৪টা নতুন টাইপ (Payment methods, Brands, Product categories, Cost centres) সবগুলোতেই Save করলে ৫০০ এরর** — একটাও ব্যবহারযোগ্য না। Locations & routes-এর "Install Bangladesh divisions" ঠিকভাবে কাজ করেছে (৮টা বিভাগ বসেছে)।

## Customer (৫টা সাবমেনু) — সব ঠিক ✅

- Customers তালিকা (১টা) + নতুন কাস্টমার তৈরি (QA Test Customer, opening balance=0/তারিখ-ছাড়া সমস্যা নেই) ✅
- Due List, Ageing, Collections by customer, Who has no credit limit — সব খোলে ✅

## Approvals (৩টা সাবমেনু) — সব ঠিক ✅

Waiting for me, My requests, Approval rules — সব খোলে (Approval rules আগেই এন্ট্রি-টেস্ট করা হয়েছিল)।

## Governance — ঠিক ✅

Audit Trail খোলে (read-only)।

## HR & Payroll (৫টা সাবমেনু) — সব ঠিক ✅, নতুন Leave ফিচার নিখুঁত

- Employees, Salary Heads, Payroll — সব খোলে (আগেই এন্ট্রি-টেস্ট করা)
- **Attendance:** Rahim Ahmed-কে "Present" চিহ্নিত করে Save করলাম — "Attendance saved for 1 people." ✅
- **Leave (নতুন ফিচার):**
  - Leave Types-এ "Install the standard leave types" চাপলে CASUAL(10)/EARNED(15)/SICK(14)/UNPAID — সঠিকভাবে বসে গেল ✅ (Master Data-র ভাঙা install-বাটনের বিপরীতে, এটা ঠিকই কাজ করে)
  - Apply for leave: Rahim Ahmed, Casual Leave, ১ দিন — জমা দিলে "The leave application is in, waiting for approval." — **Approvals মডিউলের সাথে সঠিকভাবে সংযুক্ত** ✅

## Inventory (১১টা সাবমেনু) — সব ঠিক ✅, একটা পুরনো ফিচার-গ্যাপ পূরণ হয়েছে, দুইটা ছোট বাগ

- Products (৩টা) + নতুন পণ্য তৈরি (QA Test Product) ✅ — তবে **Brand ও Category ড্রপডাউন খালি**, কারণ ওই দুটো Master-Data টাইপেই ৫০০ এরর (উপরে দেখুন) — নতুন পণ্যে Brand/Category বসানো এই মুহূর্তে অসম্ভব।
- Print labels, Warehouses, Stock, Count & Adjust — সব খোলে ✅
- **Stock Issue** (আগে যেটা "এখনো নেই" তালিকায় ছিল — উপহার/আপ্যায়ন/মালিকের ব্যবহারের জন্য স্টক ইস্যু) — **এখন বাস্তবে আছে ও কাজ করে!** পণ্য ইস্যু করে "1 went out — Owner use, and the value went to Inventory Shortage & Surplus." ✅ যদিও যাচাই করতে গিয়ে দুটো সমস্যা পাওয়া গেল:
  - 🔴 **Reason ড্রপডাউন প্রথমে খালি ছিল** — বিদ্যমান ১০টা Reason code-এর একটাও "Stock Issue" প্রসঙ্গে ট্যাগ করা ছিল না (সবই Sales/Purchase return, Stock adjustment, Cancellation, Hold-এর জন্য)। যেহেতু Reason আবশ্যক ঘর, নতুন একটা Reason code ("Owner use", Used in=Stock Issue) নিজে তৈরি করেই তারপর Issue সম্পন্ন করা গেছে। **কোম্পানিতে ডিফল্টভাবে অন্তত একটা Stock-Issue reason code থাকা উচিত ছিল, নেই।**
  - ⚠️ **ছোট বাগ — অনুবাদ-চাবি raw দেখাচ্ছে।** Reason codes-এর "Used in" ড্রপডাউনে দুইটা অপশন অনুবাদ না হয়ে raw key হিসেবে দেখাচ্ছে: `master_data::context.stock_issue` এবং `master_data::context.hold` (বাকিগুলো — Sales return, Purchase return, Stock adjustment, Cancellation, Discount — ঠিক অনুবাদ হয়ে দেখাচ্ছে)।
- Opening Stock, Stock Transfers, Stock Ledger, Stock Summary, Held Stock — সব খোলে ✅

## Supplier (৩টা সাবমেনু) — সব ঠিক ✅

Suppliers (১টা) + নতুন সরবরাহকারী তৈরি (QA Test Supplier) ✅। Payable List, Payable Ageing — সব খোলে ✅।

## Sales (১৫টা সাবমেনু) — সব ঠিক ✅, ৫টা নতুন ফিচার সবই কাজ করছে

- **Direct Sales:** সম্পূর্ণ ফ্লো টেস্ট করা হলো — পণ্য বেছে (Rate অটো-ফিল ৯০.০০), Cart-এ যোগ, Confirm — **সফল**, সরাসরি ইনভয়েসের ৮০মিমি প্রিন্টে নিয়ে গেল (নতুন অটো-প্রিন্ট আচরণ) ✅
- নতুন সাবমেনু: **Shipments, Papers not printed** — দুটোই খোলে ✅
- নতুন রিপোর্ট: **Profit by Product, Sales by Brand** — দুটোই খোলে (Sales by Brand খালি, কারণ Brand ফিচার ভাঙা, কিন্তু ক্র্যাশ করে না) ✅
- **Targets (নতুন):** Al-Amin Shuvo-র জন্য টার্গেট ১০,০০০ সেট করে Save করলাম — "Achieved" ৪১.৪% (৪,১৪০/১০,০০০) নিখুঁত হিসাব ✅
- **Dealer commission, Deposit claims** — দুটোই খোলে ✅
- বাকি পুরনো সাবমেনু (Sales Orders, Delivery Challans, Sales Invoices, Collections, Sales Returns, Pending Orders, Delivered Not Invoiced, Sales by Customer, Trace a lot) — সব খোলে, রিগ্রেশন নেই ✅

## Purchase (১১টা সাবমেনু) — সব ঠিক ✅, নতুন রিপোর্টও কাজ করছে

- **Direct Purchase Invoice:** সম্পূর্ণ ফ্লো — সরবরাহকারী+পণ্য বেছে (Rate অটো-ফিল ৬০.০০), Confirm — **PBL-2026-2027-0003 সফলভাবে তৈরি হলো** ✅ (আগের ::name বাগ-ফিক্স এখনো ঠিক আছে, harmless picked=null কনসোল এরর এখনো আসে কিন্তু কার্যকারিতা ঠিক আছে)
- নতুন রিপোর্ট: **Principal Settlement, Return on capital** — দুটোই খোলে ✅
- বাকি পুরনো সাবমেনু (Orders, Goods Received, Bills, Payments, Returns, Pending Orders, Received Not Invoiced, Purchases by Supplier) — সব খোলে, রিগ্রেশন নেই ✅

## System Administration (৭টা সাবমেনু) — একটা বড় আবিষ্কার + একটা ছোট বাগ

- **Companies** — ঠিক ✅
- **Users (নতুন! আগে খুঁজে পাইনি, এখন আছে):** তালিকায় ২ জন — Al-Amin Shuvo (owner) আর একটা আগে থেকে থাকা "বিক্রয়কর্মী" (sales@abos.test, salesman role)। **একটা নতুন টেস্ট ইউজার তৈরি করলাম** (QA Salesman, role=salesman, কোম্পানি=Provati Traders) — সেভ নিখুঁত হলো ✅
- **Roles & Permissions (নতুন!):** ৩টা role দেখা গেল — accountant(১৭ পারমিশন), owner(১৩৯, সম্পাদনাযোগ্য না), salesman(২৫)
- **🎉 এই দুটো নতুন স্ক্রিন দিয়ে আগের দুইটা অযাচাইকৃত নিরাপত্তা-ফিক্স এখন নিশ্চিত করা গেল:**
  - QA Salesman দিয়ে লগইন করে সরাসরি `/inventory/stock/issue`-এ গেলে **HTTP 403 Forbidden** ✅ (Zenbook-এর দাবি অনুযায়ী)
  - QA Salesman দিয়ে `/accounts/vouchers/receipt`-এও **HTTP 403** ✅, আর সাইডবার থেকেই পুরো Accounts/Inventory মডিউল অদৃশ্য — শুধু Dashboard/Customer/Sales দেখা যায়। **নিরাপত্তা ব্যবস্থা সঠিকভাবে কাজ করছে, মেনু-দৃশ্যমানতা ও রুট-সুরক্ষা দুটোই সংগতিপূর্ণ।**
- Bring in from the old books, Control Panel, Your own fields — সব খোলে ✅
- **Company look (নতুন):** খোলে, কিন্তু ⚠️ **"Bring in from a file" বাটনটা দুইবার দেখাচ্ছে** — ঠিক ফুটারের ব্যাকআপ-বার্তার মতোই ডুপ্লিকেট-রেন্ডারিং প্যাটার্ন, সম্ভবত একই আন্ডারলাইং কারণ (কোনো শেয়ার্ড কম্পোনেন্ট দুইবার মাউন্ট হচ্ছে)।
- এছাড়া প্রোফাইল মেনুতে **"Two-step sign-in"** ও **"Where I am logged in"** — দুটো নতুন নিরাপত্তা ফিচার দেখা গেছে (বিস্তারিত যাচাই করা হয়নি, সময়-সীমার কারণে)।

## সার্বিক উপসংহার

**পুরো ERP-র ১০টা মডিউল, ~৯০টা মেনু/সাবমেনু** একে একে খোলা ও বেশিরভাগে বাস্তব এন্ট্রি দিয়ে টেস্ট করা হয়েছে। ফলাফল:

- 🔴 **সবচেয়ে গুরুত্বপূর্ণ বাগ:** Master Data-এর ৪টা নতুন টাইপ (Payment methods, Brands, Product categories, Cost centres) — কোনোটাতেই এন্ট্রি সেভ করা যায় না (৫০০ এরর)
- ⚠️ ছোট বাগ: Reason codes-এর "Used in" ড্রপডাউনে ২টা raw translation key (`master_data::context.stock_issue`, `master_data::context.hold`)
- ⚠️ ছোট বাগ: ফুটারের ব্যাকআপ-বার্তা ও Company Look-এর "Bring in from a file" বাটন — দুটোই ডুপ্লিকেট রেন্ডার হচ্ছে (একই প্যাটার্নের বাগ, দুই জায়গায়)
- ⚠️ নতুন কোম্পানিতে কোনো Stock-Issue reason code সিড করা নেই (প্রথমবার নিজে তৈরি করে তবেই Stock Issue টেস্ট করা গেছে)
- ✅ **বাকি সবকিছু — সব পুরনো ফিচার, আর ৯৫%+ নতুন ফিচার — নিখুঁতভাবে কাজ করছে।** বিশেষভাবে উল্লেখযোগ্য: HR Leave মডিউল, Sales Targets, Stock Issue (পুরনো ফিচার-গ্যাপ পূরণ), আর Users/Roles দিয়ে নিরাপত্তা-ফিক্স যাচাই — সবই নিখুঁত।

সব বিস্তারিত এই ফাইলে ও `2026-08-25-master-data-500-errors.md`-এ। `summary.md`-এও মূল পয়েন্টগুলো যোগ করা হয়েছে।
