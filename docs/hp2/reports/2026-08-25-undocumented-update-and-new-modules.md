# বড় আপডেট ধরা পড়ল যা Zenbook-এর WHATS-NEW.md-এ নেই (2026-08-25)

আজ লগইন করার পরপরই বোঝা গেল অ্যাপ অনেকটা এগিয়ে গেছে, কিন্তু `C:\ABOS\abos\WHATS-NEW.md` এখনো ২০২৬-০৮-১২ (কমিট `2228731`) তারিখেই আটকে আছে — **এই আপডেটটা কোথাও ডকুমেন্ট করা হয়নি।**

## যা নতুন দেখা গেল (ডকুমেন্ট-না-করা)

- **রি-ব্র্যান্ডিং:** লোগো/নাম "ABOS" থেকে **"ADI | ABOS"** হয়ে গেছে, ট্যাগলাইন "Simple to Run. Powerful to Grow"। সাইডবারে নতুন রঙিন আইকন-ভিত্তিক মডিউল-রেইল যোগ হয়েছে।
- **Dashboard সম্পূর্ণ নতুন ডিজাইন:** এখন Today/This month/This year — তিনটা পিরিয়ড-ট্যাব, "Needs doing" (যা করা দরকার) আর "Just happened" (সাম্প্রতিক অ্যাক্টিভিটি ফিড, ঠিক সময়সহ) সেকশন।
- **হিসাব ও অর্থ মডিউলে ৬টা সম্পূর্ণ নতুন মেনু:**
  1. Money & custody (`/accounts/money-custody`)
  2. Books check (`/accounts/books-check`)
  3. Cheque register (`/accounts/cheques`)
  4. Bank reconciliation (`/accounts/reconciliations`)
  5. Fixed assets (`/accounts/assets`)
  6. Close a Month (`/accounts/periods`)

## প্রতিটা নতুন মেনু আলাদাভাবে টেস্ট করা হলো (ব্যবহারকারীর নির্দেশে "একটা একটা করে")

| মেনু | পরীক্ষা | ফলাফল |
|---|---|---|
| **Money & custody** | সব ক্যাশ টিলের ব্যালেন্স+কাস্টডি একসাথে দেখায়, সংখ্যা Trial Balance-এর সাথে মেলে | ✅ |
| **Books check** | ৮টা স্বয়ংক্রিয় integrity-check (ডকুমেন্ট ব্যালেন্স, ভুতুড়ে অ্যাকাউন্ট, Trial Balance, permission, bill/invoice total-mismatch) — **সবগুলো "Clear"** | ✅ চমৎকার নতুন ফিচার |
| **Cheque register** | ফাঁকা তালিকা (কোনো চেক নেই), নতুন এন্ট্রি ফর্ম ঠিক আছে (Direction/Cheque no/Bank/Date/Amount/Party) | ✅ |
| **Bank reconciliation** | ফর্ম খোলে, "Bank account" ড্রপডাউন খালি (এই কোম্পানিতে কোনো bank-type অ্যাকাউন্ট তৈরি না হওয়ায় প্রত্যাশিত, বাগ না) | ✅ |
| **Fixed assets** | ফর্ম খোলে, Furniture/Vehicles/Equipment/Computer ক্যাটাগরি, depreciation posting | ✅ |
| **Close a Month** | August/July 2026 দুটোই "Open" দেখাচ্ছে — **বাস্তবে মাস বন্ধ করিনি** (কাজটা অপরিবর্তনীয় বলে শুধু স্ক্রিন খোলে কিনা যাচাই করা হয়েছে) | ✅ |
| **Dashboard পিরিয়ড ট্যাব** | Today/This month/This year — তিনটাই সঠিক তারিখ-পরিসর ও সংখ্যা দেখায় (This month-এ Margin=1,350.00/50% on cost, আগে যাচাই করা Gross Profit-এর সাথে হুবহু মিলে) | ✅ |

## 🔴 নতুন বাগ পাওয়া গেছে — ফুটারে ব্যাকআপ সতর্কবার্তা দুইবার রেন্ডার হয়ে ওভারল্যাপ করছে

**প্রতিটা পাতার ফুটারেই** (Books check, Bank reconciliation, Close a Month, Dashboard — সবগুলোতে যাচাই করা হয়েছে) নিচের বার্তাটা দুইবার দেখা যায়:

> "Backups and the books sit on one disk — lose it and you lose both. Set a second destination."

স্ক্রিনশট নিয়ে নিশ্চিত করা হয়েছে — এটা শুধু accessibility-tree-এর ডুপ্লিকেট না, **চোখেও দুইটা কপি একটার উপর আরেকটা ওভারল্যাপ হয়ে জগাখিচুড়ি/কাটা-কাটা টেক্সট দেখায়** (স্ক্রিনশটে "...: — lose it and you lose both. Set a second destination." এর ঠিক নিচে/উপরে আবার পুরো বাক্যটা কাটাকাটাভাবে বসে গেছে)। ছোট কিন্তু দৃশ্যমান UI বাগ, প্রতিটা পাতায় ধরা পড়বে।

## ⚠️ পুরনো, ইতিমধ্যে-জানা সমস্যা (নতুন না)

কনসোলে ২টা এরর প্রতি পাতাতেই আসছে:
- `Failed to load resource: 404 — /storage/logos/Trade Depot.png`
- `Failed to load resource: 404 — /storage/logos/FamilyMart.png`

এগুলো আগেই রিপোর্ট করা "A4 প্রিন্টে লোগো ভাঙা" সমস্যার সাথেই সম্পর্কিত (Trade Depot-এর জন্য আগে জানানো হয়েছিল, এখন FamilyMart-এও একই সমস্যা)। নতুন বাগ না, তবে console-এ প্রতি পাতায় এরর দেখানো ভালো অভিজ্ঞতা না।

## সারসংক্ষেপ

Zenbook-কে জানানো দরকার: **WHATS-NEW.md আপডেট করা হয়নি যদিও অ্যাপে অনেক নতুন কাজ ডিপ্লয় হয়ে গেছে** (রি-ব্র্যান্ডিং, ড্যাশবোর্ড রিডিজাইন, ৬টা নতুন Accounts মডিউল)। ভাগ্যক্রমে সবকিছু ভালোভাবে কাজ করছে — শুধু ফুটারের ডুপ্লিকেট ব্যাকআপ-সতর্কবার্তাটা বাগ হিসেবে ঠিক করা দরকার।
