# নকশা — DO-র হিসাবের অনুমোদন ও মজুদ আটকানো (গ + ঘ)

> বিক্রয়ের কাজের ধারা — ২ অক্টোবর ২০২৬, ধাপ গ আর ঘ। সেশন abos-86। ⚠️ টেবিল বানানোর আগে সমন্বয়কের (abos-63) "হ্যাঁ" চাই।
> নির্ভর: abos-2c-এর `sal_delivery_orders` / `sal_delivery_order_lines`, `DeliveryOrderStatus`, `DeliveryOrderLine::finalQty()`,
> আর দুই event `DeliveryOrderSupervisorApproved` ও `DeliveryOrderCancelled` (reason: rejected | cancelled)।

## ১. কে কোন অবস্থা লেখে (তিন সেশনের এক তালিকা)
draft → submitted → supervisor_pending → supervisor_approved → **accounts_held | accounts_approved** → depot_check → invoiced; পাশে rejected, cancelled।
- abos-2c: draft…supervisor_approved, rejected, cancelled · **abos-86: accounts_held, accounts_approved** · abos-bb: depot_check, invoiced।

## ২. গ — হিসাবের অনুমোদন (সফটওয়্যার নিজে)
**কখন চলে:** `DeliveryOrderSupervisorApproved` (afterCommit) → listener `CheckTheDeliveryOrderAccounts`। আবার: ঐ গ্রাহকের রসিদ পোস্ট হলে, বা তাঁর চেক ক্লিয়ার হলে — তাঁর সব `accounts_held` DO, পুরনোটা আগে।

**সূত্র — বাকির দেয়ালের হুবহু ([[CreditExposure]]), নতুন হিসাব নয়:**
`খাতার বকেয়া + আটকে থাকা (বিল না হওয়া চালান + খসড়া বিল + হিসাবে অনুমোদিত অথচ বিল না হওয়া DO) + ক্লিয়ার না হওয়া চেক + এই DO-র total ≤ সীমা`
- সীমা ০ হলেও অগ্রিম থাকলে চলে: বকেয়া ঋণাত্মক (জমা) হলে `−জমা + DO ≤ 0` — অর্থাৎ জমা টাকায় কুলোলে, সীমা ছাড়াই।
- ⛔ চেক কেবল ক্লিয়ার হলে (২৬ সেপ্টেম্বরের নিয়ম): যে পুরনো পথে চেক হাতে নেওয়ার দিনই গ্রাহকের খাতায় জমা বসে ([[ChequeService::receivedIntoTheBooks()]]), ক্লিয়ার না হওয়া সেই চেকের টাকা হিসাবে ফেরত যোগ হয়।
- নতুন: `CreditExposure::pending()` এখন হিসাবে অনুমোদিত DO-ও গোনে — নইলে দুইটা DO আলাদা করে সীমার ভিতরে, মিলে বাইরে।

**ফল:**
- কুলোলে → `accounts_approved`, মজুদ শক্ত আটকানো (ঘ)।
- না কুলোলে → `accounts_held`, আর DO-তে লেখা থাকে কত কম (`accounts_short`), কবে থেকে আটকে (`accounts_held_at`)।
- সতর্কবার্তা — §৬-এর তিন কারণে।

**তালা:** গ্রাহকের সারিতে `FOR UPDATE` আগে ([[CreditExposure::lockCustomer()]]), তারপর DO-র সারি — দুই রসিদ একসাথে এলে একই DO দুইবার অনুমোদন নয়, আর দুই DO একসাথে একই জায়গা খায় না।

## ৩. ঘ — মজুদ
> ⭐ মালিকের নির্দেশ (abos-63-এর মাধ্যমে, ৩ অক্টোবর ২০২৬) — আগের "নরম/শক্ত" বদলে তিন জানালা: *"সুপারভাইজার অনুমোদন দিলে মাল সংরক্ষিত হবে — 24h er jonno korakori atkabe, baki 2din dekhabe but bikroy cholbe"*।

| কখন (সুপারভাইজারের অনুমোদন থেকে) | কী | কোথায় |
|---|---|---|
| ০–২৪ ঘণ্টা | **কড়া আটকানো** — অন্য কেউ বেচতে পারে না (available = floor − reserved − hold) | `sal_do_stock_holds` (kind = firm) + `StockService::move(reserved: +qty)`, উৎস `delivery_order` |
| ২৪–৭২ ঘণ্টা | **দেখানো সংরক্ষণ** — কোন DO-র জন্য কত, দেখায়; বিক্রি থামায় না | কড়াটা নেমে আসে: `reserved: −qty`, হোল্ড kind = soft |
| ৭২ ঘণ্টা, টাকা আসেনি | নিজে ছাড় | হোল্ড বন্ধ (release_reason = expired) |
| হিসাবে অনুমোদিত (যেকোনো সময়) | আবার **কড়া**, বিল না হওয়া পর্যন্ত — আর কোনো ঘড়ি নেই | kind = firm, `reserved: +qty` (আগে কড়া থাকলে কিছু বদলায় না); `expires_at` = null |
| ছাড়ের পরে টাকা এল | আবার যাচাই; কুলোলে মজুদ যতটা আছে ততটা আবার কড়া; না থাকলে সতর্ক (`stock_short`) | — |
| বাতিল / ফেরত | ছাড় | হোল্ড বন্ধ, কড়া হলে `reserved: −qty` |
| চালান নিশ্চিত (abos-bb) | যা বেরোল ততটা ওঠে, বাকিটা ছাড় | `consume($do, $challan)` |
| ডিপো পরিমাণ কমাল (abos-bb) | আটকানোও কমে | `resize($do, [line => qty])` — কেবল কমানো |

- ঘড়ি দুইটা সেটিং থেকে: `sales.do_hard_hold_hours` (ডিফল্ট ২৪) আর `sales.do_hold_days` (ডিফল্ট ৩ = ৭২ ঘণ্টা)।
- কাজ `abos:do-holds-expire` — **প্রতি ঘণ্টায়** (২৪ ঘণ্টার কিনারা দিনে একবারে ধরা পড়ে না); দুই ধাপই করে: কড়া → দেখানো, দেখানো → ছাড়।
- হোল্ডে দুই সময়: `firm_until` (কড়া কবে নামবে) আর `expires_at` (কবে ছাড়) — হিসাবে অনুমোদিত হলে দুইটাই null।
- পরীক্ষা: সময় সরিয়ে (travel) তিন জানালাই — ২৩ ঘণ্টায় অন্য বিক্রি আটকায়, ২৫ ঘণ্টায় চলে অথচ সংরক্ষণ দেখায়, ৭৩ ঘণ্টায় ছাড়; হিসাবে অনুমোদিত DO ৭৩ ঘণ্টায়ও কড়া।

সেবা: `App\Modules\Sales\Services\DeliveryOrderStock` — `soft()`, `firm()`, `release()`, `consume()`, `resize()`, `expireOld()`। প্রতিটা নিজের লেনদেনে, DO-র সারিতে তালা, দুইবার ডাকলে কিছু হয় না।

## ৪. নতুন টেবিল ও কলাম (অনুমতি চাই)
**`sal_do_stock_holds`**: id, company_id, branch_id, delivery_order_id, delivery_order_line_id, product_id, warehouse_id, batch_id (nullable), qty decimal(18,4), kind (soft | firm), held_at, firm_until (nullable), expires_at (nullable), released_at (nullable), release_reason (nullable: cancelled | rejected | expired | consumed | resized), consumed_qty decimal(18,4) default 0, timestamps।
সূচক: (company_id, delivery_order_id), (company_id, product_id, warehouse_id, released_at) — নাম ৬৪ অক্ষরের নিচে।

**`sal_delivery_orders`-এ তিন কলাম** (abos-2c-এর টেবিল — ওর মাইগ্রেশনে বা আমার আলাদা মাইগ্রেশনে, ওর পছন্দে): `accounts_short` decimal(18,4) nullable, `accounts_held_at` timestamp nullable, `accounts_checked_at` timestamp nullable, `accounts_warnings` json nullable (§৬-এর পাঁচ ধরন)।

## ৫. নতুন event (রিচেকের জন্য)
- `App\Modules\Accounts\Events\ChequeCleared` — `ChequeService::clear()`-এ afterCommit; payload {cheque_id, party_type, party_id, amount}। হিসাব মডিউল কারও ওপর নির্ভর করে না — কেবল event ঘোষণা করে।
- কাউন্টারের/মাঠের আদায়: `CollectionService::confirm()` এখন কোনো event দেয় না → `App\Modules\Sales\Events\CollectionConfirmed` {collection_id, customer_id, amount}।
- রসিদ ভাউচার: বিদ্যমান `VoucherPosted` (party_type = customer হলে)।

## ৬. সমন্বয়কের উত্তর (abos-63, ২ অক্টোবর ২০২৬) — নকশায় "হ্যাঁ"
১. **সতর্কবার্তা — মালিকের চূড়ান্ত তালিকা (৩ অক্টোবর ২০২৬, abos-63-এর তিন কারণের ওপর মালিকের দুই যোগ আর এক বাছাই)।** DO থাকে `accounts_approved`, সতর্কগুলো `accounts_warnings` (json, প্রতিটা {kind, line, …}):
   - `stock_short` — মজুদ কম: **"অর্ডার কমান"**, কত আছে তা সহ (মালিক)।
   - `rate_vs_lot` — অর্ডারের দর লটের দামের (লটের `mrp`, বসানো থাকলে) সাথে মেলে না (মালিক: *"dam zedame order asche tar sathe stock loter partoko hole sotorko korbe"*)।
   - `qty_changed` — সুপারভাইজার পরিমাণ বদলেছেন।
   - `limit_near` — সীমার ৯০%-এর বেশি ব্যবহৃত।
   - `lot_expiring` — আটকানো লটের মেয়াদ শিগগির শেষ (`inventory.expiry_alert_days`, ডিফল্ট ৩০) — মালিক বেছেছেন।
   - ✕ বাছাই হয়নি: মেয়াদ পেরোনো পুরনো বকেয়া, কেনা দামের নিচে বিক্রি, সম্প্রতি ফেরত চেক।
২. **মজুদ কম হলে** যতটা আছে ততটা শক্ত আটকানো (মালিক: *"za ache ta atkabe"*), বাকিটার সতর্কবার্তা; ডিপোর যাচাইয়ে কম পরিমাণ দেখায়।
৩. **৩ দিনের ছাড়ের পরে টাকা এলে** (মালিক: *"tumar moto koro"*) আবার যাচাই; কুলোলে আবার আটকানো; মজুদ না থাকলে সতর্কবার্তা।
- শর্ত: `ChequeCleared` Accounts-এ, Accounts কারও ওপর নির্ভর করে না; সূচক/FK নাম ছোট; DO টেবিলের কলাম একটাই মাইগ্রেশনে (abos-2c-এর সাথে ঠিক করা); `abos:do-holds-expire` নিয়মিত (মালিকের তিন জানালার পরে প্রতি ঘণ্টায়, §৩); এই নথি কোডের কমিটের সাথে।

## ৭. পরীক্ষা (প্রতিটা: লাল → সবুজ → mutant)
সীমায় কুলোয় → অনুমোদিত + শক্ত; কুলোয় না → আটকে + কত কম; সীমা ০ + অগ্রিমে কুলোয় → অনুমোদিত; ক্লিয়ার না হওয়া চেক গোনা হয় না; রসিদ/চেক ক্লিয়ারে আটকে থাকা DO নিজে অনুমোদিত (পুরনোটা আগে, জায়গা ফুরোলে থামে); দুই DO একসাথে → একটাই জায়গা পায় (দুই সংযোগ); ৩ দিন → নরম/শক্ত ছাড়, সেটিং ৫ দিলে ৫; বাতিল → ছাড়; consume/resize; শাখার দেয়াল।
