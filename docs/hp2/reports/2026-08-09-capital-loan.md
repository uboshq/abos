# ধাপ ৫ — মূলধন, বিনিয়োগ, ঋণ (2026-08-09)

## প্রথমে যা লাগল

Receipt Voucher দিয়ে টাকা ঢোকাতে গেলে "Received into" ঘরে কোনো ক্যাশ কাউন্টার/ব্যাংক অ্যাকাউন্ট ছিল না (তালিকা খালি) — কারণ কোনো Cash Till তৈরি করা ছিল না। "Main Cash Counter" নামে একটা ক্যাশ কাউন্টার বানাতে হলো (Branch: Main Mymensingh, Main counter ✓)।

## ছোট একটা অসংগতি — Cash Till তৈরির ফর্মে

- **কোন পর্দা:** `/accounts/cash-tills/create`
- **কী হল:** Opening balance ঘরে ডিফল্ট মান "0" থাকা সত্ত্বেও Save করলে বার্তা এল: "The opening date field is required when opening balance is present." — অথচ আমি কোনো opening balance দিইনি (ডিফল্ট ০ ছিল)। মানে "present" হিসেবে ০-কেও ধরা হচ্ছে। Opening date বসিয়ে দিলে সমস্যা মিটে যায়, কাজ চালিয়ে যাওয়া গেছে — কিন্তু যে ব্যবহারকারী শুধু নাম আর শাখা দিয়ে একটা কাউন্টার বানাতে চান (opening balance নিয়ে ভাবেনইনি), তিনি এই এররে আটকে যাবেন।

## ঠিক আছে যা যাচাই হয়েছে (এবং এটাই ভালো খবর)

- **মালিকের মূলধন:** Receipt Voucher — Received from "3100 — Owner Capital", Received into "Main Cash Counter", ৳৫,০০,০০০ — সফলভাবে পোস্ট হয়েছে (RCV-2026-2027-0001)।
- **ঋণ:** Receipt Voucher — Received from "2210 — Bank Loan", Received into "Main Cash Counter", ৳৩,০০,০০০ — সফলভাবে পোস্ট হয়েছে (RCV-2026-2027-0002)।
- **Trial Balance যাচাই:** ডেবিট = ক্রেডিট = ৮,০৬,০০০.০০ — **পুরোপুরি মিলেছে।**
  - Main Cash Counter: ৮,০০,০০০ (ডেবিট) = ৫,০০,০০০ (মূলধন) + ৩,০০,০০০ (ঋণ) ✓
  - Bank Loan: ৩,০০,০০০ (ক্রেডিট) ✓
  - Owner Capital: ৫,০০,০০০ (ক্রেডিট) ✓
- **উপসংহার:** ভাউচারের মাধ্যমে ঢোকানো লেনদেন ঠিকমতোই হিসাবের খাতায় পোস্ট হচ্ছে এবং ক্যাশে দেখাচ্ছে। এটা প্রমাণ করে যে ধাপ ৪-এ পাওয়া বড় গরমিলটা (Opening Stock বনাম Trial Balance) সাধারণ হিসাব-ইঞ্জিনের সমস্যা না — নির্দিষ্টভাবে ডেমো ওপেনিং-স্টক ডেটার সাথে সম্পর্কিত (সম্ভবত সেটা সরাসরি ডাটাবেজে বসানো হয়েছিল, স্বাভাবিক ফর্ম দিয়ে না)।

## পুনঃযাচাই — সম্পূর্ণ নতুন কোম্পানি "Provati Traders"-এ (2026-08-09, ~14:15)

কোম্পানি তৈরির UI আসার পর (দেখুন `2026-08-09-company-branch.md`) নতুন, ডেমো-ডেটা-মুক্ত কোম্পানি "Provati Traders"-এ এই একই ধাপ আবার করলাম:

- **Cash Till তৈরির একই বাগ আবার পাওয়া গেছে:** নতুন কোম্পানিতেও `/accounts/cash-tills/create`-এ Opening balance ডিফল্ট "0" থাকা সত্ত্বেও Save করলে "The opening date field is required when opening balance is present." বার্তা আসে। **এটা প্রমাণ করে বাগটা ডেমো-ডেটা-নির্দিষ্ট না, বরং ফর্মের ভ্যালিডেশন লজিকেই আছে (protected/general বাগ)** — Zenbook-কে জানানো দরকার।
- **মালিকের মূলধন:** Receipt Voucher — Received from "3100 — Owner Capital", Received into "Main Cash Counter" (নতুন তৈরি), ৳১,০০,০০০ — সফলভাবে পোস্ট হয়েছে (RCV-2026-2027-**0001**, নতুন কোম্পানির জন্য ১ থেকে শুরু হওয়া নাম্বার সিরিজ নিশ্চিত হলো)।
- **ঋণ:** Receipt Voucher — Received from "2210 — Bank Loan", Received into "Main Cash Counter", ৳৫০,০০০ — সফলভাবে পোস্ট হয়েছে (RCV-2026-2027-0002)।
- **Trial Balance যাচাই:** ডেবিট = ক্রেডিট = ১,৫৫,০০০.০০ — **পুরোপুরি মিলেছে।**
  - Main Cash Counter: ১,৫০,০০০ (ডেবিট) = ১,০০,০০০ (মূলধন) + ৫০,০০০ (ঋণ) ✓
  - Bank Loan: ৫০,০০০ (ক্রেডিট) ✓
  - Owner Capital: ১,০০,০০০ (ক্রেডিট) ✓
  - (Inventory ৫,০০০ আগের ওপেনিং স্টক টেস্ট থেকে, ওটাও এখানে ঠিকই যোগ হয়েছে Retained Earnings-এর বিপরীতে)
- **উপসংহার:** ধাপ ৫ নতুন কোম্পানিতেও সম্পূর্ণ নির্ভুলভাবে কাজ করছে। শুধু Cash Till-এর opening-balance/opening-date ভ্যালিডেশন বাগটা এখনো আছে (ছোট, ব্লকিং না — opening date বসিয়ে দিলেই এগোনো যায়)।
