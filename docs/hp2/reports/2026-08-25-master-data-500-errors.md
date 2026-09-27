# 🔴 জরুরি বাগ — Master Data-এর ৪টা নতুন টাইপ কোনোটাতেই নতুন এন্ট্রি সেভ করা যায় না (৫০০ এরর)

ব্যবহারকারীর নির্দেশে "পুরো ERP-র প্রতিটা মেনু/সাবমেনুতে এন্ট্রি দিয়ে গভীরভাবে চেক" করার সময় Master Data মডিউলে ৪টা সম্পূর্ণ নতুন ক্যাটাগরি পাওয়া গেল (আগে ছিল না, WHATS-NEW.md-এও নেই): **Payment methods, Brands, Product categories, Cost centres**।

## যা করলাম, কী পেলাম

চারটাতেই একই পরীক্ষা: `/master-data/{type}/create` খুলে শুধু **Name (English)** ঘরে একটা সাধারণ নাম লিখে "Save" চাপা হলো (অন্য কোনো ঘর ছোঁয়া হয়নি)।

| # | টাইপ | যা লিখলাম | ফলাফল |
|---|---|---|---|
| ১ | Payment methods | "bKash" (+ Money goes to = Main Cash Counter) | 🔴 **HTTP 500 Server Error** |
| ২ | Payment methods (দ্বিতীয়বার, শুধু Name) | "Nagad" | 🔴 **HTTP 500 Server Error** (পুনরায় নিশ্চিত) |
| ৩ | Brands | "Radhuni" | 🔴 **HTTP 500 Server Error** |
| ৪ | Product categories | "Groceries" | 🔴 **HTTP 500 Server Error** |
| ৫ | Cost centres | "Head Office Overheads" | 🔴 **HTTP 500 Server Error** |

**চারটা আলাদা মাস্টার-ডেটা টাইপ, প্রতিটাতে Save চাপলেই ৫০০ — একটাও কাজ করছে না।** যেহেতু চারটাতেই একই রকম সাধারণ ফর্ম (Code/Name English/Name Bangla + কিছু টাইপ-নির্দিষ্ট ঘর) আর একই রকম ব্যর্থতা, সন্দেহ হচ্ছে এই চারটা নতুন টাইপের পেছনে একটাই ভাগাভাগি-করা কোড/কন্ট্রোলার আছে যেখানে সমস্যাটা — হয়তো Loans মডিউলে আগে যেমন হয়েছিল (নতুন ডকুমেন্ট-টাইপ পুরনো কোম্পানিতে নম্বর-সিরিজ পায়নি), এখানেও অনুরূপ কিছু (নতুন মাস্টার-ডেটা টাইপ পুরনো কোম্পানিতে দরকারি কনফিগারেশন/সিরিজ পায়নি) হতে পারে — কিন্তু এটা শুধু অনুমান, আসল কারণ কোডেই দেখা দরকার।

## ⚠️ সংশ্লিষ্ট ছোট সমস্যা — "Install standard lists" বাটন কিছুই ইনস্টল করে না, আর ভুল/অপ্রাসঙ্গিক বার্তা দেখায়

Payment methods ও Brands-এর খালি-তালিকা পাতায় **"These lists are empty."** ব্যানার আর **"Install standard lists"** বাটন দেখা যায়, বার্তায় লেখা: *"Without units, taxes, terms and reason codes the first invoice cannot be written."* — এটা units/taxes/terms/reason-codes সম্পর্কে কথা বলছে, Payment methods বা Brands সম্পর্কে না। বাটনে চাপলে "Standard lists installed." বলে সফলতার বার্তা দেখায়, কিন্তু **পাতা রিফ্রেশ করলে তালিকা তখনো খালিই থাকে** ("Nothing here yet.")। অর্থাৎ:
- এই ব্যানার/বাটনটা সম্ভবত একটা জেনেরিক/শেয়ার্ড কম্পোনেন্ট যা সব খালি Master Data তালিকাতেই দেখানো হয়, প্রাসঙ্গিক কিনা না ভেবেই — তাই Payment methods/Brands-এর পাতাতেও Units/Tax-সংক্রান্ত ভুল বার্তা দেখায়।
- বাটন "সফল" বলা সত্ত্বেও Payment methods-এর জন্য বাস্তবে কিছুই তৈরি করে না।

## ✅ তুলনায় যা ঠিক আছে (রিগ্রেশন-চেক)

- **Locations & routes** (আরেকটা নতুন-প্রথমবার-খালি স্ক্রিন): "Install Bangladesh divisions" বাটন **ঠিকভাবে কাজ করেছে** — ৮টা বিভাগ + দেশ সঠিক Country›Division›Area›Territory›Point›Route কাঠামোয় বসেছে। তাই "install ও bootstrap প্যাটার্ন" নিজে থেকে ভাঙা না — শুধু Master Data-এর এই ৪টা নতুন টাইপেই সমস্যা।
- **Units** (৬টা, আগে থেকেই ছিল) ও **Tax & VAT** — দুটোই স্বাভাবিকভাবে খোলে, পুরনো ডেটা ঠিক দেখায়।

## প্রভাব

Sales/Purchase-এর ফর্মে এখন সম্ভবত Brand, Product category, Cost centre, Payment method বেছে নেওয়ার ঘর থাকতে পারে (নতুন ফিচার হিসেবে) — কিন্তু যেহেতু এই চারটার একটাতেও নতুন রেকর্ড তৈরিই করা যাচ্ছে না, ব্যবহারকারী এই ফিচারগুলোর কোনোটাই আসলে ব্যবহার করতে পারবেন না যতক্ষণ না এই ৫০০ এরর ঠিক হয়।
