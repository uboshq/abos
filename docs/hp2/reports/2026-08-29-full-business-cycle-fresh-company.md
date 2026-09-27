# সম্পূর্ণ নতুন কোম্পানি খুলে বাস্তব ব্যবসায়ীর মতো পুরো চক্র টেস্ট (2026-08-29)

ব্যবহারকারীর নির্দেশ: একদম শূন্য থেকে একটা নতুন কোম্পানি তৈরি করে, বাস্তব একজন ব্যবসায়ীর মতো — মূলধন নেওয়া, ইনভেস্ট/লোন নেওয়া, ক্রয় করা, ভেন্ডারের সার্ভিস নেওয়া, পাইকার ও ডিলারের কাছে বিক্রয় করা, টাকা আদায় করা — এই পুরো A-টু-Z চক্রটা চালিয়ে যতগুলো মডিউল ছোঁয়া যায় সব ছুঁয়ে দেখা, এবং ফলাফল রিপোর্ট ফাইলে লেখা।

## নতুন কোম্পানি: QA Full Cycle Trading (QAFULL)

`/system/companies/create`-এ নতুন কোম্পানি তৈরি করা হলো — "branch, financial year, number series, chart of accounts and master lists are all in place" বার্তা দিয়ে সফল হলো। ✅

## ধাপে ধাপে যা করা হলো (সব সফল)

| # | ধাপ | ডকুমেন্ট | ফলাফল |
|---|---|---|---|
| ১ | ক্যাশ টিল তৈরি | Main Cash Counter | ✅ |
| ২ | মালিকের মূলধন | RCV-2026-2027-0001, ৳300,000 (3100 Owner Capital → Main Cash Counter) | ✅ |
| ৩ | লোন/ইনভেস্ট | LN-2026-2027-0001, ৳100,000 Term Loan (City Bank, 9%, ১২ মাস) | ✅ |
| ৪ | গুদাম তৈরি | Main Warehouse | ✅ |
| ৫ | পণ্য তৈরি | Basmati Rice 5kg (Bag) | ✅ |
| ৬ | সরবরাহকারী তৈরি | Sunrise Rice Mills (মালামাল) | ✅ |
| ৭ | ভেন্ডার তৈরি | Swift Transport Service (Type=Vendor) | ✅ |
| ৮ | পাইকার গ্রাহক তৈরি | Green Valley Wholesale Mart (Type=Wholesale) | ✅ |
| ৯ | ডিলার গ্রাহক তৈরি | Karim Distribution Dealer (Type=Dealer) | ✅ |
| ১০ | ক্রয় (Direct Purchase Invoice) | PBL-2026-2027-0001 — ৫০ ব্যাগ @৳৮০০, markup ২৫%→sales price ১০০০ (margin ২০%) নিখুঁত হিসাব, আংশিক পরিশোধ ৳২০,০০০ | ✅ |
| ১১ | ভেন্ডার সার্ভিস (পরিবহন) | EXP-2026-2027-0001, ৳২,০০০ (5204 Fuel & Transport) | ✅ |
| ১২ | পাইকারের কাছে বিক্রয় (বাকিতে) | INV-2026-2027-0001 — ১০ ব্যাগ @৳১০০০=৳১০,০০০ (বিক্রয়মূল্য ক্রয়ের markup থেকে ঠিক ফিরে এসেছে) | ✅ |
| ১৩ | ডিলারের কাছে বিক্রয় (নগদ) | INV/০০০২ — ৫ ব্যাগ @৳১০০০=৳৫,০০০, সম্পূর্ণ নগদে পরিশোধ | ✅ |
| ১৪ | পাইকারের কাছ থেকে আদায় | COL-2026-2027-0002, ৳৬,০০০ (INV-2026-2027-0001-এর বিপরীতে) | ✅ |

## চূড়ান্ত যাচাই

- **Trial Balance:** কোনো ফিল্টার না ছুঁয়েই **Dr = Cr = ৳৪,৯৪,০০০.০০**, নিখুঁত ব্যালেন্সড ✅
- **Books check:** ৮টা স্বয়ংক্রিয় integrity check — **সবগুলো "Clear"** ✅

**উপসংহার: সম্পূর্ণ শূন্য থেকে শুরু করা একটা নতুন কোম্পানিতেও — মূলধন, লোন, ক্রয়, ভেন্ডার-খরচ, দুই ধরনের গ্রাহকের (পাইকার/ডিলার) কাছে বিক্রয়, আদায় — পুরো ব্যবসায়িক চক্রটা কোনো একটা ধাপেও না থেমে সফলভাবে সম্পন্ন হয়েছে, আর হিসাবের খাতা শেষে নিখুঁতভাবে মিলে গেছে। কোনো নতুন বাগ পাওয়া যায়নি এই সম্পূর্ণ চক্রে।**

## 🆕 নতুন আবিষ্কার — সাইডবারে আরও দুটো সম্পূর্ণ নতুন মডিউল/ফিচার-গুচ্ছ দেখা গেছে (এখনো গভীরভাবে টেস্ট করা হয়নি)

1. **"Finance" নামে একটা সম্পূর্ণ আলাদা মডিউল** (🏦 আইকন, `/finance/plan`) — "Accounts & Finance" (📚) থেকে আলাদা। কী আছে এখনো দেখা হয়নি।
2. **Inventory-তে রেস্টুরেন্ট/রান্নাঘর-সংক্রান্ত ফিচার:** "Kitchen board", "Kitchen screen", "Cooking", "Food cost" — সম্পূর্ণ নতুন সাবমেনু, সম্ভবত রেস্টুরেন্ট/POS ব্যবসার জন্য (ট্রেডিং ব্যবসার সাথে সম্পর্কহীন, তাই এই চক্রে ছোঁয়া হয়নি)।
3. Loans ফর্মে এখন **Hand loan, Fixed deposit (FD), DPS** — নতুন loan kind যোগ হয়েছে (আগে শুধু Term loan ও CC ছিল)।
4. নতুন Chart of Accounts খাত: Cash in Transit, Cheques in Hand, Commission Claimable, Deposits & Investments, Cheques Issued Not Presented, Interest & Profit Income, Gifts & Donations, Commission Written Off।

এগুলো পরবর্তী রাউন্ডে আলাদাভাবে গভীর টেস্ট করা দরকার (বিশেষ করে "Finance" মডিউল, আর নতুন Loan kind গুলো — Hand loan/FD/DPS)।

## পুনর্ব্যবহৃত টেস্ট ডেটা (পরিষ্কার রাখার জন্য নোট)

এই টেস্টের জন্য একটা সম্পূর্ণ নতুন কোম্পানি (QAFULL) তৈরি করা হয়েছে, যা পুরনো Provati Traders/Trade Depot ডেটার সাথে মেশেনি — তাই বিদ্যমান রিপোর্টের কোনো তথ্য প্রভাবিত হয়নি।

---

## সংযোজন (একই দিনে): Cash/Bank/MFS account — ব্যবহারকারীর প্রশ্নের ভিত্তিতে অতিরিক্ত টেস্ট

ব্যবহারকারী জিজ্ঞেস করলেন: "cash ac, bank ac, mfs ac খুলেছো?" — পরীক্ষা করে দেখা গেল উপরের চক্রে শুধু একটা জেনেরিক Cash Till (Main Cash Counter) তৈরি হয়েছিল, আলাদা Bank বা MFS account তৈরি/টেস্ট করা হয়নি। এরপর গভীরভাবে খুঁজে বের করা হলো:

### গ্যাপ resolved: Bank/MFS account তৈরির আসল জায়গা
- **Cash Tills** (`/accounts/cash-tills/create`) দিয়ে শুধু জেনেরিক Cash counter তৈরি করা যায় — কোনো "Kind" selector নেই, তাই Bank/MFS বানানো যায় না।
- আসল জায়গা হলো **Chart of Accounts → New account** (`/accounts/chart-of-accounts/create`)। এখানে "Parent account" হিসেবে **1102 — Bank & Mobile Money** বেছে নিলে "Bank or MFS account" নামে একটা checkbox আসে, সাথে Bank name/Branch name/Account number ফিল্ড।
- এই ফর্ম দিয়ে সফলভাবে দুটো account তৈরি করা হলো, সব ফিল্ড ভরে:
  - **City Bank Current Account** (কোড 1102-01, ID 283) — Bank name: City Bank PLC, Branch: Gulshan Branch, A/C: 1234567890123, Opening balance ৳50,000 (29-08-2026)
  - **bKash Merchant Account** (কোড 1102-02, ID 284) — Bank name: bKash, Branch: Mobile Financial Service, A/C: 01712345678, Opening balance ৳15,000 (29-08-2026)
- দুটোই "Money & custody" স্ক্রিনে সঠিকভাবে "Bank / MFS" kind হিসেবে দেখা যাচ্ছে।
- এরপর **Money Transfer** (`/accounts/money-transfers/create`) দিয়ে Main Cash Counter থেকে দুটো account-এ বাস্তব টাকা routing করা হলো (প্রতিটাতে "Hand over" → "I received it" দুই ধাপ লাগে):
  - TRF-2026-2027-0001: ৳30,000 → City Bank Current Account ✅ received
  - TRF-2026-2027-0002: ৳10,000 → bKash Merchant Account ✅ received
- Money & custody-তে ফলাফল: Main Cash Counter ৳343,000 · City Bank ৳80,000 · bKash ৳25,000 — সব ঠিক হিসাব মিলছে ✅

### 🐛 বাগ পাওয়া গেছে (কনফার্মড, reproducible): Chart of Accounts-এর "Opening balance" আসল ledger-এ পোস্ট হয় না

**যা ঘটছে:** Chart of Accounts → New account ফর্মে "Opening balance" ফিল্ডে টাকা বসিয়ে account তৈরি করলে —
- Account-এর নিজের পেজে "Balance" হেডারে ঠিক টাকাটা দেখায় (যেমন City Bank: ৳80,000 = ৫০,০০০ opening + ৩০,০০০ transfer)।
- Money & custody স্ক্রিনেও এই সঠিক (পূর্ণ) ব্যালেন্স দেখায়।
- কিন্তু account-এর নিজের **"Transactions" তালিকায় Opening balance-এর কোনো লাইন নেই** — শুধু পরের transfer-টা (৩০,০০০) দেখায়, আর "running balance" কলামে সরাসরি ৮০,০০০-এ লাফ দেয় (মানে opening balance-টা কোনো real journal entry হিসেবে posted হয়নি, স্রেফ একটা raw starting-value হিসেবে যোগ হয়ে গেছে)।
- **Trial Balance** রিপোর্টে City Bank Current Account শুধু ৩০,০০০ Debit দেখায় (৮০,০০০ না), bKash শুধু ১০,০০০ দেখায় (২৫,০০০ না) — মানে দুটো account মিলিয়ে ৳৬৫,০০০ (৫০,০০০+১৫,০০০) কম দেখাচ্ছে।
- **Balance Sheet** রিপোর্টেও একই ভুল — City Bank ৩০,০০০, bKash ১০,০০০ (আসল উচিত ৮০,০০০ ও ২৫,০০০)।
- **Books check**-এর ৮টা integrity check তবুও "সব Clear" দেখায় — কারণ এই check গুলো শুধু ledger টেবিলের ভেতরের সামঞ্জস্য (debit=credit) যাচাই করে, আর opening balance যেহেতু ledger-এই ঢোকেনি, তাই কোনো অসামঞ্জস্য ধরাই পড়ছে না। এই বাগটা app নিজে থেকে ধরতে পারছে না।

**প্রভাব:** এই পদ্ধতিতে (Chart of Accounts-এর সরাসরি "New account" ফর্মে opening balance দিয়ে) কোনো account খুললে, সেই account-এর Balance/Money & custody-তে যা দেখায় তার সাথে Trial Balance ও Balance Sheet-এর সংখ্যা মিলবে না — কোম্পানির আর্থিক প্রতিবেদন ভুল দেখাবে, অথচ কোনো automated check এটা ধরবে না। এটা আর্থিক-হিসাবের সঠিকতার দিক থেকে গুরুত্বপূর্ণ বাগ।

**তুলনা:** Cash Till (Main Cash Counter)-এর ক্ষেত্রে এই সমস্যা নেই — Trial Balance-এ তার Debit/Credit যোগফল (৪০৫,০০০/৬২,০০০) ঠিকই Money & custody-এর ব্যালেন্স (৩৪৩,০০০)-এর সাথে মিলে যায়, মানে Cash Till তৈরির সময় opening balance ঠিকভাবে ledger-এ পোস্ট হয় — সমস্যাটা শুধু Chart of Accounts-এর "New account" ফর্মের opening balance-এ।

**পুনরুৎপাদনের ধাপ:**
1. `/accounts/chart-of-accounts/create`-এ যান, যেকোনো Parent (Assets-এর নিচে) বেছে একটা নতুন account বানান, Opening balance-এ কোনো non-zero সংখ্যা দিন, Save করুন।
2. Account পেজে "Balance" ঠিক opening balance-টা দেখাবে, কিন্তু "Transactions" তালিকা খালি থাকবে ("No transactions on this account yet")।
3. `/accounts/reports/trial-balance` বা `/accounts/reports/balance-sheet`-এ গিয়ে দেখুন — ওই account-এর Debit/Credit/Balance কলামে opening balance-টা অনুপস্থিত।

---

## 🐛🐛 নতুন Loan Kind (Hand loan/FD/DPS) টেস্ট করতে গিয়ে আরও দুইটা বাগ পাওয়া গেছে

### বাগ ১ (কনফার্মড): "Hand loan" সেভ করলে "Cash credit (CC)" হিসেবে সেভ/প্রদর্শিত হয়

**পুনরুৎপাদনের ধাপ:**
1. `/accounts/loans/create`-এ যান, Kind-এ **"Hand loan"** রেডিও বাটন ক্লিক করুন (UI-তে সঠিকভাবে "checked" দেখায়)।
2. সব ফিল্ড ভরুন (Lender: "Abdul Karim (personal loan)", Sanctioned ৳20,000, Interest rate 5%, Promised back by 29-02-2027, Security, Liability account = 2220 Other Loan, Interest account = 5310 Interest Expense, Money goes into = Main Cash Counter, Note) এবং Save করুন।
3. **ফলাফল:** লোনটা সফলভাবে তৈরি হয় (LN-2026-2027-0002), কিন্তু detail পেজে উপরে এবং Loans লিস্ট পেজের "Kind" কলামে **"Cash credit (CC)"** দেখায় — "Hand loan" না।

এটা একটা ডেটা-সততার বাগ: ব্যবহারকারী যেই Kind সিলেক্ট করছেন, তা ভুল Kind হিসেবে সেভ/প্রদর্শিত হচ্ছে (সম্ভবত ফর্মের radio value / backend enum mapping-এ off-by-one জাতীয় ভুল)।

### বাগ ২ (কনফার্মড, blocking): Fixed deposit (FD) ও DPS — কোনোটাই UI দিয়ে তৈরি করা যায় না

**পুনরুৎপাদনের ধাপ:**
1. `/accounts/loans/create`-এ Kind = **"Fixed deposit (FD)"** বেছে নিন — ফর্ম বদলে যায় (Sanctioned, Interest rate, Starts, **Matures on**, **Pledged against** ফিল্ড দেখায়), কিন্তু **"Money goes into" ফিল্ডটাই কোথাও নেই**।
2. সব দৃশ্যমান ফিল্ড ভরে (Lender, Sanctioned ৳50,000, Interest rate 7%, Matures on 29-08-2027, Security, Liability/Interest account, Note) Save করলে এই এরর আসে: **"The into account id field is required."**
3. যেহেতু ওই ফিল্ডটা ফর্মেই নেই, এটা ভরার কোনো উপায় নেই — **FD loan কখনোই সেভ করা যায় না।**
4. Kind = **"DPS"**-তেও একদম একই জিনিস ঘটে — ফর্মে "Money goes into" নেই, একই এরর মেসেজ থেকে যায়, DPS-ও সেভ করা যায় না।

**প্রভাব:** নতুন যোগ করা তিনটা Loan kind-এর (Hand loan, FD, DPS) মধ্যে Hand loan ভুল লেবেলে সেভ হয়, আর FD ও DPS সম্পূর্ণভাবে ব্লক করা — এই ফিচারগুলো এখনো ব্যবহারযোগ্য অবস্থায় নেই। (নোট: Loans-এ এই ব্যর্থ চেষ্টার পর কোনো Draft লোনও তৈরি হয়নি — যাচাই করে দেখা গেছে Loans তালিকায় শুধু আগের ২টা লোনই আছে, তাই এখানে কোনো recovery path নেই — নিচের Journal Voucher-এর মতো "draft থেকে recover করা" সম্ভব না।)

---

## ✏️ সংশোধনী: Journal Voucher-এর "Bank/MFS txn number missing" আসলে সম্পূর্ণ ব্লকিং না — একটা recovery path আছে (তবে UX বিভ্রান্তিকর)

উপরে Journal Voucher নিয়ে যা লেখা হয়েছিল তার একটা গুরুত্বপূর্ণ সংশোধনী: create ফর্মে সত্যিই "Bank/MFS transaction number" ফিল্ড নেই এবং "Save and post" চাপলে "The into account id..." এর মতো একটা এরর দেখায় ("Money moving through ... needs its bank or MFS transaction number")। **কিন্তু** ফর্ম আসলে নিঃশব্দে একটা **Draft ভাউচার সেভ করে ফেলে** (এই টেস্টে JRN-2026-2027-0001)! Create পেজে থেকে গেলে ব্যবহারকারী মনে করবে কিছুই সেভ হয়নি — অথচ Journal Voucher তালিকায় গিয়ে দেখা যায় draft-টা তৈরি হয়ে গেছে। সেই draft-এর detail পেজে গেলে সেখানে ঠিকই "Bank / MFS transaction no. *" নামে একটা ফিল্ড ও "Post now" বাটন থাকে — সেটা ভরে "Post now" চাপলে সঠিকভাবে ledger-এ পোস্ট হয়ে যায় ("posted to the ledger")।

**প্রভাব (সংশোধিত):** এটা ব্লকিং বাগ না, কিন্তু একটা confusing UX gap — ব্যবহারকারী ভাববে তার এন্ট্রি সেভই হয়নি এবং হয়তো বারবার "Save and post" চাপার চেষ্টা করে একাধিক অসম্পূর্ণ draft তৈরি করে ফেলবে, কারণ এরর মেসেজ কোথাও বলছে না যে "এটা draft হিসেবে সেভ হয়ে গেছে, তালিকায় গিয়ে txn number যোগ করুন"। FD/DPS Loan-এর ক্ষেত্রে এই ধরনের কোনো recovery draft তৈরি হয় না (Loans তালিকা যাচাই করে নিশ্চিত হওয়া গেছে) — তাই ওটা সত্যিকারের সম্পূর্ণ ব্লক, আর এই Journal Voucher-এরটা আংশিক/recoverable ব্লক।

---

## বাকি Accounts মেনু আইটেমগুলোর টেস্ট ফলাফল (সব ফিল্ড ভরে)

সব ক'টা ফর্মে প্রতিটা ফিল্ড ভরে বাস্তব ডেটা দিয়ে টেস্ট করা হয়েছে:

| মেনু | ফলাফল |
|---|---|
| **Payment Voucher** | PAY-2026-2027-0001 — City Bank থেকে ৳5,000 Rent-এ পরিশোধ (Bank transfer mode), সঠিকভাবে posted ✅ |
| **Receipt Voucher** | RCV-2026-2027-0002 — bKash-এ ৳2,500 Other Income গ্রহণ (Mobile banking mode), সঠিকভাবে posted ✅ |
| **Journal Voucher** | JRN-2026-2027-0001/0002 — উপরে বিস্তারিত (draft/recovery আচরণ) ✅ পোস্ট হয়েছে, তবে UX gap পাওয়া গেছে |
| **Contra Voucher** | CON-2026-2027-0001 — bKash থেকে Main Cash Counter-এ ৳5,000 সরানো, সঠিকভাবে posted ✅ |
| **Cash Count** | CNT-2026-2027-0001 — নোট-ভিত্তিক গণনা ফিচার সঠিকভাবে কাজ করছে (Counted vs Per books vs Difference); ইচ্ছাকৃতভাবে "Approve" করা হয়নি যেন ভুয়া variance বইয়ে না ঢোকে ✅ |
| **Fixed assets** | সরাসরি `/accounts/assets/create` URL **404** দেয় — আসল create ফর্মটা `/accounts/assets` লিস্ট পেজের ভেতরেই ইনলাইন আছে। "Delivery Motorcycle" (Vehicles, cost ৳150,000, salvage ৳15,000, ৬০ মাস, straight-line) যোগ করা হয়েছে, সফল ✅ |
| **Cheque register** | CHQ-1001 — Green Valley Wholesale Mart থেকে ৳8,000 চেক গ্রহণ, City Bank-এ deposit-এর জন্য নথিভুক্ত, সফল ✅ |
| **Bank reconciliation** | City Bank-এর জন্য reconciliation শুরু করা হলো (statement closing balance ৳80,000) — নিচে একটা অস্পষ্ট পরিসংখ্যান পাওয়া গেছে ⚠️ |

### ⚠️ তদন্তাধীন (কনফার্মড বাগ না, কিন্তু অস্বাভাবিক): Bank reconciliation-এ ব্যাখ্যাহীন "Cheques written, not yet cashed" অঙ্ক

City Bank Current Account-এর জন্য reconciliation শুরু করার পর স্ক্রিনে দেখাচ্ছে:
- The statement says: ৳80,000.00
- Paid in, not yet on the statement: ৳0.00
- **Cheques written, not yet cashed: ৳5,150.00** ← এই অঙ্কটা কোথা থেকে এলো বোঝা যায়নি
- Our books say: ৳44,850.00
- Still unexplained: -৳40,300.00

এই কোম্পানিতে City Bank থেকে কোনো cheque **"Issued" (জারি করা)** হয়নি এই সেশনে — শুধু একটা "Received" cheque (CHQ-1001, ৳8,000, City Bank-এ deposit-এর জন্য) নথিভুক্ত করা হয়েছিল। তাহলে "Cheques written, not yet cashed" ৳5,150 কীভাবে এলো, এবং "Our books say ৳44,850" সংখ্যাটা কীভাবে গণনা হলো (এটা account পেজের ৳80,000 ব্যালেন্সের সাথেও মেলে না, আবার শুধু transfer-based ৳30,000-এর সাথেও মেলে না) — এটা স্পষ্ট নয়। সম্ভবত এটা উপরে বর্ণিত "Opening balance ledger-এ পোস্ট হয় না" বাগের সাথে সম্পর্কিত (যেহেতু এই স্ক্রিনও সম্ভবত সরাসরি ledger থেকে হিসাব করছে, cached balance থেকে না), তবে ৫,১৫০ সংখ্যাটার উৎস নিশ্চিত করা যায়নি। **এটা একটা নিশ্চিত বাগ হিসেবে দাবি না করে "আরও গভীর তদন্ত দরকার" হিসেবে চিহ্নিত করা হলো** — পরের রাউন্ডে এই স্ক্রিনের হিসাব-পদ্ধতি আরও ভালোভাবে বোঝা দরকার।

## সারসংক্ষেপ: এই সেশনে মোট যা যা পাওয়া গেছে

1. 🐛 **কনফার্মড:** Chart of Accounts-এর "Opening balance" ledger-এ পোস্ট হয় না — Trial Balance ও Balance Sheet ভুল (কম) দেখায়, Books check এটা ধরতে পারে না।
2. 🐛 **কনফার্মড:** Loan Kind "Hand loan" সিলেক্ট করে সেভ করলে "Cash credit (CC)" হিসেবে সেভ/প্রদর্শিত হয়।
3. 🐛 **কনফার্মড, ব্লকিং:** Loan Kind "Fixed deposit (FD)" ও "DPS" — কোনোটাই UI দিয়ে সেভ করা যায় না ("Money goes into" ফিল্ড ফর্মে নেই, কিন্তু ব্যাকএন্ড এটা required বলে)।
4. ⚠️ **UX gap (ব্লকিং না):** Journal Voucher-এ Bank/MFS account ছুঁলে txn-number ছাড়া "Save and post" ব্যর্থ হয়, কিন্তু নিঃশব্দে Draft সেভ হয়ে যায় — এরর মেসেজ এটা জানায় না।
5. ⚠️ **তদন্তাধীন:** Bank reconciliation-এ City Bank-এর জন্য ব্যাখ্যাহীন "Cheques written, not yet cashed ৳5,150" ও "Our books say ৳44,850" অঙ্ক — উৎস অস্পষ্ট।
6. ✅ **গ্যাপ resolved:** Bank/MFS account তৈরির আসল জায়গা হলো Chart of Accounts → New account (Cash Tills দিয়ে না)।
7. ✅ Payment/Receipt/Contra Voucher, Cash Count, Fixed assets, Cheque register — সব সঠিকভাবে কাজ করছে।
