# চুক্তি — ফোনের খবর কোথায় খোলে

**তারিখ:** ২৪ অক্টোবর ২০২৬
**দুই পাশ:** সার্ভার (`ec/notice-opens-2`) ↔ অ্যাপ 0.4.49 (a4)
**সার্ভারের ফাইল:** `app/Core/Notifications/PhoneNoticeTarget.php` (একমাত্র উত্তরদাতা),
`app/Http/Controllers/Api/NotificationApiController.php` (তালিকা),
`app/Http/Controllers/Api/NotificationManageApiController.php` (বিস্তারিত),
`app/Core/Notifications/Channels/MobilePushChannel.php` (বিজ্ঞপ্তির পুশ),
`app/Modules/Sales/Services/TrackingNotices.php` (ট্র্যাকিংয়ের পুশ)
**অ্যাপের ফাইল (a4-এর):** `mobile/lib/core/push/push_service.dart` (`destinationOf()`),
`mobile/lib/core/records/notification_record.dart`, `mobile/lib/features/notifications/notifications_screen.dart`

এই কাগজটা দুই পাশের মাঝের চুক্তি। এক পাশ বদলালে অন্য পাশ নীরবে ভাঙে —
তাই কাগজটাই পাহারা।

---

## ⛔ পুরনো অ্যাপের নিশ্চয়তা (বদলায়নি)

সার্ভার **কেবল ঘর যোগ করেছে**। কোনো পুরনো ঘরের নাম, ধরন বা মানে বদলায়নি, কোনোটা সরেনি।

| জায়গা | পুরনো ঘর — যেমন ছিল |
|---|---|
| `GET /api/v1/notifications` → `data[]` | `id`, `type`, `title`, `body`, `url`, `read`, `at`, `priority`, `category`, `module`, `archived`; `meta.unread` |
| `GET /api/v1/notifications/{id}` → `data` | আগের সব ঘর |
| বিজ্ঞপ্তির পুশ (`data`) | `open` = `"notification"`, `id` = খবরের `public_id` |
| ট্র্যাকিংয়ের পুশ (`data`) | `open` = `"tracking"`, `kind` = `"challan"` বা `"order"`, `id` = চালান/আদেশের `public_id` |

⚠️ একটাই সূক্ষ্ম বদল: তালিকার `url` এখন **`null`** হয় যখন খবরের কাগজটা আর খোলা যায় না
(মুছে গেছে, বা কাগজের শাখা আর নাগালে নেই) — বিস্তারিতের দরজা আগে থেকেই তাই করত। আজকের
অ্যাপ `url` পড়েই না (`NotificationRecord`-এ `url` নেই), তাই কিছু ভাঙে না।

---

## ১ · ঘণ্টার তালিকা আর বিস্তারিত — নতুন চার ঘর

`GET /api/v1/notifications` (প্রতিটা `data[]`) আর `GET /api/v1/notifications/{id}` (`data`) — দুইটাতেই একই চার ঘর, একই মানে।

| ঘর | ধরন | মানে |
|---|---|---|
| `may_open` | bool | এই মানুষটা খবরের কাগজটা এখন খুলতে পারেন কি। কাগজহীন খবরে `true`। |
| `paper` | object | `{ "type": string\|null, "public_id": string\|null }` — কোন কাগজের খবর |
| `paper.type` | string\|null | কাগজের ধরন — ড্রিলের উৎস-নাম (মডিউলের `module.php` → `drillSources`, যেমন `delivery_challan`, `sales_order`)। কাগজহীন খবরে `null`। |
| `paper.public_id` | string\|null | কাগজের `public_id`। ⛔ `may_open = false` হলে **সবসময় `null`** — খোলা যায় না এমন কাগজের চিহ্ন বাইরে যায় না। |
| `destination` | string | চাপলে অ্যাপ কোথায় যাবে — নিচের চার মানের একটা, কখনো `null` নয় |
| `closed` | string\|null | খোলা না গেলে কেন — `gone` (কাগজটা আর নেই, মুছে ফেলা হয়েছে) বা `reach` (কাগজ আছে, আপনার নাগালের বাইরে); খোলা গেলে `null`। ⛔ `may_open = false` ⇔ `closed ≠ null` — একই উত্তর থেকে (সার্ভারের `NotificationService::whyClosed()`), তাই কখনো দুই কথা বলে না। ⓘ কেবল চাবি, লেখা নয় — অ্যাপ নিজের ভাষার ফাইল থেকে দেখায় (নিচে, ওয়েবের হুবহু বাক্য)। অচেনা চাবি এলে `reach`-এর মতো আচরণ। (a4-এর অনুরোধ, fe, ২৪ অক্টোবর ২০২৬) |

### `destination`-এর চার মান

| মান | কখন | অ্যাপ চাপলে কী করবে |
|---|---|---|
| `tracking` | ডেলিভারির ধাপ (`sales.delivery_stage`) আর বাকির সীমায় আটকানো আদেশ (`sales.order_credit_held`), কাগজ খোলা গেলে | ট্র্যাকিং খোলো (`/home/tracking`)। কোনটা: `paper.type` `delivery_challan` → kind `challan`, `sales_order` → kind `order`; id = `paper.public_id` |
| `approval` | শ্রেণি "অনুমোদন" (`category = approval`) — কারও সইয়ের অপেক্ষা (যেমন `sales.order_awaits_you`, `supplier.bank_change_waiting`, `approval.*`), ⓘ সইয়ের ফল (`approval.approved`, `approval.rejected`) বাদ | সইয়ের তালিকা খোলো (`/home/approvals`) |
| `paper` | অন্য সব খবর যার কাগজ আছে আর খোলা যায় (সইয়ের ফলও) | `paper.type` অ্যাপের চেনা কোনো পর্দা হলে সেই কাগজ খোলো (`paper.public_id`); অচেনা ধরন হলে `list`-এর মতো আচরণ |
| `list` | কাগজ নেই, বা `may_open = false` | কোথাও যেও না — খবরটা (শিরোনাম, লেখা) দেখাও, পড়া দাগ দাও |

⛔ অ্যাপ ওয়েবের `url` ভেঙে পথ বের করবে না — `destination` আর `paper` দেখে চলবে।
⛔ অচেনা `destination` মান এলে `list`-এর মতো আচরণ (সার্ভার ভবিষ্যতে মান যোগ করতে পারে)।

### `closed`-এর বাক্য — ওয়েবের হুবহু (অ্যাপ এটাই দেখাবে)

ওয়েবের পাতা (`notifications.no-access`) ঠিক এই কথা বলে (`lang/bn/core.php`, `lang/en/core.php`, `core.notify.*`); অ্যাপ একই
শিরোনাম আর লেখা দেখাবে, যাতে ফোন আর ওয়েব একই কথা বলে:

| `closed` | শিরোনাম (বাংলা) | লেখা (বাংলা) | English (title — text) |
|---|---|---|---|
| `gone` | কাগজটা আর নেই | যে কাগজের খবর, সেটা মুছে ফেলা হয়েছে — খোলার মতো কিছু আর নেই। খবরটা পড়া হিসেবে রাখা হলো। | The paper is gone — The paper this notification is about was deleted, so there is nothing left to open. The notification is marked read. |
| `reach` | এই কাগজটা আর আপনার নাগালে নেই | যে কাগজের খবর, সেটা আছে, কিন্তু যে শাখার কাগজ সেখানে আপনার অনুমতি নেই। খবরটা পড়া হিসেবে রাখা হলো। দরকার হলে প্রশাসককে জানান। | This paper is no longer within your reach — The paper this notification is about still exists, but you have no access to its branch. The notification is marked read. Tell an administrator if you need it. |

ⓘ ওয়েবের চাবি: `core.notify.gone_title`/`gone_body`, `core.notify.no_access_title`/`no_access_body` — বদলালে এই সারণিও।

### উদাহরণ — তালিকার একটা খবর

```json
{
  "id": "01JB8T3K9V7Q2M4N6P8R0S2T4V",
  "type": "sales.delivery_stage",
  "title": "S-0007 — রওনা হয়েছে",
  "body": "রহিম স্টোর · কারওয়ান বাজার",
  "url": "https://erp.example/sales/tracking/challan/01JB8SZ…",
  "read": false,
  "at": "2026-10-24T12:00:00+06:00",
  "priority": "low",
  "category": "update",
  "module": "sales",
  "archived": false,
  "may_open": true,
  "paper": { "type": "delivery_challan", "public_id": "01JB8SZ…" },
  "destination": "tracking"
}
```

### উদাহরণ — কাগজ আর খোলা যায় না

```json
{
  "id": "01JB8T3K…",
  "type": "sales.delivery_stage",
  "url": null,
  "may_open": false,
  "paper": { "type": "delivery_challan", "public_id": null },
  "destination": "list"
}
```
(বাকি পুরনো ঘর যেমন থাকে।)

---

## ২ · পুশের `data` — নতুন ছয় ঘর

দুই ধরনের পুশেই একই ছয় ঘর যোগ হয়। ⛔ **সব মান লেখা (string)** — FCM-এর নিয়ম; না থাকলে `""`, কখনো `null` নয়।

| ঘর | মানে |
|---|---|
| `notification_id` | ঘণ্টার খবরের `public_id` — পড়া দাগ দিতে (`POST /api/v1/notifications/{id}/read`) বা বিস্তারিত আনতে। ⓘ নাম `notification` নয়: FCM-এ সেটা সংরক্ষিত শব্দ |
| `type` | খবরের ধরন (তালিকার `type`) |
| `paper_type` | তালিকার `paper.type`, না থাকলে `""` |
| `paper_id` | তালিকার `paper.public_id`, না থাকলে বা খোলা না গেলে `""` |
| `destination` | তালিকার `destination` — একই চার মান |
| `closed` | তালিকার `closed` — `gone`, `reach`, বা খোলা গেলে `""` |

### বিজ্ঞপ্তির পুশ (নিয়ম বা জরুরি খবর, `MobilePushChannel`)

```json
{
  "open": "notification",
  "id": "01JB8T3K…",
  "notification_id": "01JB8T3K…",
  "type": "approval.rejected",
  "paper_type": "delivery_challan",
  "paper_id": "01JB8SZ…",
  "destination": "paper"
}
```
ⓘ এখানে `id` আর `notification_id` একই।

### ট্র্যাকিংয়ের পুশ (`TrackingNotices`)

```json
{
  "open": "tracking",
  "kind": "challan",
  "id": "01JB8SZ…",
  "notification_id": "01JB8T3K…",
  "type": "sales.delivery_stage",
  "paper_type": "delivery_challan",
  "paper_id": "01JB8SZ…",
  "destination": "tracking"
}
```
⚠️ এখানে `id` = **চালান/আদেশের** `public_id` (পুরনো মানে), খবরের নয় — খবরেরটা `notification_id`-এ।
ⓘ সইয়ের অপেক্ষার আদেশ (`sales.order_awaits_you`): `open = tracking`, `kind = order` আগের মতো, কিন্তু `destination = approval`।

### অ্যাপ চাপলে (0.4.49)

1. `destination` থাকলে সেটা ধরে চলো — উপরের টেবিল।
2. না থাকলে (পুরনো সার্ভার) আগের নিয়ম: `open == tracking` → `/home/tracking`, অন্যথা যেখানে ছিলে।
3. `notification_id` থাকলে খবরটা পড়া দাগ দিতে পারো।

---

## ৩ · একটা খবরে একটাই পুশ

আগে: ট্র্যাকিংয়ের ধরনগুলোয় (`sales.delivery_stage`, `sales.order_credit_held`, `sales.order_awaits_you`)
`TrackingNotices` নিজে পুশ পাঠাত, আর বিজ্ঞপ্তির নিয়মে মোবাইল পুশ চালু থাকলে মাধ্যমও পাঠাত — একই মানুষ **দুইটা** পুশ পেতেন।

এখন: এই তিন ধরনের পুশ **কেবল `TrackingNotices`-এর পথে** যায় (`open: tracking` — আজকের অ্যাপ যেটা চেনে আর
ট্র্যাকিং খোলে)। মাধ্যম এগুলো আর পাঠায় না; তার সারি "পৌঁছেছে" (`provider_ref = own-push`) — ব্যর্থ-তালিকায় যায় না।
ⓘ ছুটির বদলির কপি (`on_behalf_of`) ট্র্যাকিংয়ের পুশ পায় না, তাই সেটা মাধ্যমের পথেই যায় — নিয়ম চালু থাকলে।

**অ্যাপের জন্য:** এই ধরনের খবরে `open = notification` পুশ আর আসবে না — `open = tracking` পুশেই নতুন পাঁচ ঘর আছে।

---

## ৪ · `paper.type`-এর পুরো তালিকা (a4-এর অনুরোধ, ২৪ অক্টোবর ২০২৬)

`paper.type` = ড্রিলের উৎস-নাম — প্রতিটা মডিউলের `module.php` → `drill_sources`। ⛔ তালিকাটা নিচে হুবহু; সার্ভারে একটা দাবি
(`tests/Feature/Api/TheContractNamesEveryPaperTypeTest.php`) এই সারণির নাম আর `DrillResolver::map()`-এর নাম মিলিয়ে দেখে — নতুন
কাগজের ধরন যোগ হলে (বা নাম বদলালে) এখানে না লিখলে লাল। অ্যাপ প্রতিটা নামকে হয় একটা পর্দায় পাঠায়, নয়তো স্পষ্ট বিকল্পে
(খবরের বিবরণ, `list`-এর মতো)।

- **ধরনের ভাগ আলাদা নামেই** — অন্য কোনো `variant` ঘর নেই: ক্রয়ের আদেশ `purchase_order`, মাল-প্রাপ্তি `purchase_receipt`,
  বিল `purchase_bill`, পরিশোধ `purchase_payment`, ফেরত `purchase_return`; ভাড়ায় দিক দুই নামে: `rental_contract` = আমরা ভাড়াটে,
  ভাড়া শোধ করি (অ্যাপে side `pay`); `tenancy` = আমরা মালিক, জায়গা ভাড়ায় দিই আর ভাড়া পাই (অ্যাপে side `let`)। ভাউচারের পাঁচ রকম (`receipt_voucher`, `payment_voucher`, …)
  আলাদা নাম, আর `voucher` তাদের সাধারণ নাম।
- **`paper.public_id`** = কাগজের নিজের `public_id` (UUID) — ফোনের কাগজের API ঠিক এটাই নেয়: `GET /api/v1/papers/{key}/{public_id}`
  ([[PhonePaperApiController::show()]]), আর ট্র্যাকিং `GET /api/v1/sales/tracking/{kind}/{public_id}`। ⚠️ মডিউলের নিজস্ব কোনো ফোন-পথ
  যদি সংখ্যার id নেয়, অ্যাপের মানচিত্রের দাবিতে সেটা ধরা পড়বে — সার্ভার কখনো সংখ্যার id পাঠায় না।
- **`public_id = null` হলে `destination` সবসময় `list`** — কাগজ নেই, মুছে গেছে, `public_id` নেই, বা খোলার অনুমতি নেই
  ([[PhoneNoticeTarget::destination()]]-এর শেষ শাখা)।

<!-- paper-types:start -->
| `paper.type` | মডিউল | মডেল |
|---|---|---|
| `account` | Accounts | Account |
| `approval` | Approval | Approval |
| `asset_capitalise` | Accounts | AssetCapitalisationPaper |
| `asset_disposal` | Accounts | AssetDisposalPaper |
| `asset_event` | Accounts | AssetEvent |
| `asset_transfer` | Accounts | AssetTransfer |
| `asset_verification_line` | Accounts | AssetVerificationLine |
| `bank_facility` | Finance | BankFacility |
| `capital_entry` | Finance | CapitalEntry |
| `cash_count` | Accounts | CashCount |
| `cash_till` | Accounts | CashTill |
| `cheque` | Accounts | Cheque |
| `collection` | Sales | Collection |
| `commission_claim` | Sales | CommissionClaim |
| `contra_voucher` | Accounts | Voucher |
| `credit_profile` | CreditControl | CreditProfile |
| `currency` | MasterData | Currency |
| `customer` | Customer | Customer |
| `delivery_challan` | Sales | DeliveryChallan |
| `department` | MasterData | Department |
| `deposit` | Finance | Deposit |
| `deposit_movement` | Finance | DepositMovement |
| `depreciation` | Accounts | DepreciationEntry |
| `depreciation_run` | Accounts | DepreciationRun |
| `designation` | MasterData | Designation |
| `employee` | Hr | Employee |
| `employment_type` | MasterData | EmploymentType |
| `expense_claim` | Hr | ExpenseClaim |
| `expense_voucher` | Accounts | Voucher |
| `fixed_asset` | Accounts | FixedAsset |
| `hand_loan` | Finance | HandLoanAccount |
| `hand_loan_movement` | Finance | HandLoanMovement |
| `insurance_claim` | Finance | InsuranceClaim |
| `insurance_premium` | Finance | InsurancePremium |
| `insurance_prepayment` | Finance | InsurancePrepayment |
| `journal_voucher` | Accounts | Voucher |
| `loan` | Accounts | Loan |
| `loan_instalment` | Accounts | LoanInstalment |
| `loan_movement` | Accounts | LoanMovement |
| `location` | MasterData | Location |
| `money_category` | Accounts | MoneyCategory |
| `money_transfer` | Accounts | MoneyTransfer |
| `note` | Accounts | Note |
| `party_type` | MasterData | PartyType |
| `payment_term` | MasterData | PaymentTerm |
| `payment_voucher` | Accounts | Voucher |
| `payroll_run` | Hr | PayrollRun |
| `person` | MasterData | Person |
| `price_list` | MasterData | PriceList |
| `product` | Inventory | Product |
| `purchase_bill` | Purchase | PurchaseBill |
| `purchase_order` | Purchase | PurchaseOrder |
| `purchase_payment` | Purchase | Payment |
| `purchase_receipt` | Purchase | PurchaseReceipt |
| `purchase_return` | Purchase | PurchaseReturn |
| `reason_code` | MasterData | ReasonCode |
| `receipt_voucher` | Accounts | Voucher |
| `rental_adjustment` | Finance | RentalAdjustment |
| `rental_contract` | Finance | RentalContract |
| `sales_channel` | MasterData | SalesChannel |
| `sales_invoice` | Sales | SalesInvoice |
| `sales_invoice_cancellation` | Sales | SalesInvoiceCancellation |
| `sales_order` | Sales | SalesOrder |
| `sales_quotation` | Sales | SalesQuotation |
| `sales_return` | Sales | SalesReturn |
| `shipment` | Sales | Shipment |
| `stock_transfer` | Inventory | StockTransfer |
| `supplier` | Supplier | Supplier |
| `tax` | MasterData | Tax |
| `tenancy` | Finance | Tenancy |
| `transfer_mode` | MasterData | TransferMode |
| `unit` | MasterData | Unit |
| `vehicle` | MasterData | Vehicle |
| `vehicle_type` | MasterData | VehicleType |
| `voucher` | Accounts | Voucher |
| `warehouse` | Inventory | Warehouse |
| `withdrawal` | Finance | Withdrawal |
| `year_close` | Accounts | YearClosing |
<!-- paper-types:end -->

---

## দাবি (সার্ভারের পাহারা)

| দাবি | কী ধরে |
|---|---|
| `tests/Feature/Api/ThePhoneNoticeSaysWhereItLeadsTest.php` | তালিকার পুরনো ঘর যেমন ছিল; `paper`, `destination`, `may_open`; কাগজ না থাকলে `url = null`, `may_open = false`, `public_id = null`, `destination = list`; বিস্তারিতও একই কথা বলে |
| `tests/Feature/Api/ATrackingNoticePushesOnceTest.php` | নিয়মে মোবাইল পুশ চালু থাকলেও একটা ধাপ-বদলে একটাই পুশ, `open/kind/id` যেমন ছিল, আর নতুন পাঁচ ঘর |
| `tests/Feature/Api/TheMobilePushKeepsItsOldWordsAndSaysWhereItLeadsTest.php` | বিজ্ঞপ্তির পুশে `open/id` যেমন ছিল, নতুন পাঁচ ঘর, সব মান লেখা; কাগজহীন খবরে `""` আর `list` |
| `tests/Feature/Api/TheContractNamesEveryPaperTypeTest.php` | চুক্তির `paper.type` সারণি = `DrillResolver::map()`-এর নাম, একটাও কম-বেশি নয় |
| `tests/Feature/Api/ThePhoneNoticeSaysWhereItLeadsTest.php` (`test_the_phone_is_told_why_a_notice_does_not_open`) | খোলা গেলে `closed = null`; নাগালের বাইরে `reach`; মোছা কাগজে তালিকা আর পুশ দুইটাতেই `gone` |
