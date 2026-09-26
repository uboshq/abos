# চেকলিস্ট · DMS-এ যা আছে, ABOS-এ যা নেই

**তারিখ:** ২০২৬-০৮-১৮ · শেষ যাচাই ২০২৬-০৯-২৬ (নিচে)
**কীভাবে বানানো:** অনুমানে নয় — `e:\DMS\ubos-dms`-এর ১৬টা মডিউলের প্রতিটা
entity আর `core/`-এর ২১টা প্যাকেজ ধরে ধরে, তারপর প্রতিটার জন্য ABOS-এ
খুঁজে দেখে। "আছে" মানে কোডে সত্যিই পাওয়া গেছে; "নেই" মানে খুঁজে পাওয়া
যায়নি।

দুইটা আলাদা স্ট্যাক (DMS: Java/Spring, ABOS: Laravel), তাই কোড কপি হবে
না — **নকশাটা** নেওয়ার কথা।

---

## যাচাই · ২৮ আগস্ট ২০২৬

নিচের তালিকাটা ১৮ আগস্টের, আর **তার ছয়টা "নেই" আজ আর সত্যি নয়**। প্রতিটা
কোড **আর লাইভ ডাটাবেজের টেবিল** — দুইটাই ধরে দেখা হয়েছে, কেবল নাম মিলিয়ে
নয়।

| ১৮ আগস্টে লেখা ছিল | আজকের সত্যি |
|---|---|
| ❌ `Cheque` | ✅ `acc_cheques` |
| ❌ `BankReconciliation` | ✅ `acc_bank_reconciliations` |
| ❌ `FixedAsset`, অবচয় | ✅ `acc_fixed_assets` · `acc_depreciation_entries` |
| ❌ `CostCenter` | ✅ `mdm_cost_centers` — **নামটা `acc_` নয়, `mdm_`** |
| ❌ `UserDataScope` | ✅ `user_data_scopes` |
| ❌ `DepositClaim` | ✅ `sal_deposit_claims` |

সাথে `user_permission_overrides`, `sal_commission_claims` আর `period_locks`-ও
আছে। ABOS আজ **১২৪ টেবিল**।

> **কেন বাসি তালিকা বিপজ্জনক:** যে কাগজ বলে "নেই", সেটা দেখে একদিন কেউ
> জিনিসটা **আবার** বানাবেন — আর তখন এক ব্যবসায় দুইটা চেকের খাতা থাকবে।
> তাই তালিকাটা কাজে লাগানোর আগে প্রতিবার যাচাই করে নেওয়া, আর যাচাইয়ের
> তারিখটা লিখে রাখা।

---

## যাচাই · ২৬ সেপ্টেম্বর ২০২৬ — প্রতিটা সারি কোড থেকে আবার

⚠️ **নিচের ভাগ ১–১১-এর টেবিলগুলো ১৮ আগস্টের। যেখানে দুইটা আলাদা কথা বলে,
এই টেবিলটাই জেতে।** abos-d3 ধরেছিলেন যে `Scheme`, `CommissionRule` আর পোর্টালের
চাবি আছে, অথচ কাগজ বলছিল "নেই"। মাপতে গিয়ে দেখা গেল আরও অনেক সারি পুরনো।

⓵ **কীভাবে মাপা:** মডেলের তালিকা `git ls-files` থেকে (গোনা ফাইল, খোঁজা নয়),
আর প্রতিটা "নেই" `git grep` দিয়ে, পুরো `app/` আর সব মাইগ্রেশনে। ⚠️ মেশিন
তখন ভীষণ ব্যস্ত ছিল, আর Grep যন্ত্র একবার আংশিক ফল দিয়েছিল (যেমন
`PricingRule` মডেল আছে, অথচ মাইগ্রেশনের খোঁজে আসেনি)। তাই "নেই" কেবল
তখনই লেখা হয়েছে যখন `git grep`-ও শূন্য দিয়েছে। কমিটহীন ফাইলও দেখা হয়েছে
(`git ls-files -o`)।

### হিসাব ও অর্থ

| DMS-এ | আজ | প্রমাণ |
|---|---|---|
| `Cheque` | ✅ | `app/Modules/Accounts/Models/Cheque.php` |
| `BankAccount` | ✅ | খাতে `is_bank` (১৮টা ফাইলে পড়া হয়), আর খাতটা কোন ব্যাংকের তা `app/Modules/Finance/Models/InstitutionAccount.php`। ⓘ চেক-বইয়ের পাতার ঘর এই দফায় মাপা হয়নি |
| `BankReconciliation` | ✅ | `Accounts/Services/BankReconciliationService.php` |
| `BankStatementLine` | ✅ | `Accounts/Models/BankStatementLine.php`; ফাইল থেকে তোলা `Accounts/Imports/BankStatementImporter.php`, নিবন্ধিত `Accounts/module.php:520`; মেলানো `matched_line_id` |
| `CostCenter` | ✅ | `Accounts/Models/CostCenter.php` |
| `Budget`, `BudgetLine` | ✅ | `Finance/Models/Budget.php:29-31` — একটা সারিই একটা লাইন (মাস × খাত × খরচকেন্দ্র)। সীমার দেয়াল `Purchase/Services/PurchaseRequisitionService.php:114` |
| `Expense`, `ExpenseCategory` | ✅ | `Accounts/Models/MoneyCategory.php` আর সহজ খরচের ফর্ম `Accounts/Resources/views/voucher/simple-form.blade.php` |
| `FixedAsset`, অবচয় | ✅ | `Accounts/Models/FixedAsset.php`, `DepreciationEntry.php` |
| `CapitalEntry` | ✅ | `Finance/Models/CapitalEntry.php`, `Finance/Http/Controllers/CapitalController.php` |
| `Withdrawal`, `WithdrawalLimit` | ✅ | `Finance/Models/Withdrawal.php`, `WithdrawalLimit.php`; সীমা পড়ে `Finance/Services/WithdrawalService.php` |

### বিক্রয় ও পরিবেশন

| DMS-এ | আজ | প্রমাণ |
|---|---|---|
| `Scheme`, `CommissionRule` | ✅ নিয়ম আছে · 🟨 নিজে থেকে বসে না | `Sales/Models/Scheme.php` (মূল্য · পরিমাণ · স্ল্যাব; পণ্য · শ্রেণি · ব্র্যান্ড · এলাকা ধরে), `Sales/Models/CommissionRule.php`। ⚠️ হিসাব করে `Sales/Services/CommissionEngine.php`, আর তাকে ডাকে **কেবল** `Sales/Http/Controllers/SchemeController.php:41` — বিল নিশ্চিত হওয়ার সময় কেউ ডাকে না। ⓘ ট্রেড প্রোমোশন আলাদা মডিউলে আসছে (`app/Modules/Promotion/`, abos-39, এখনো কমিটহীন) |
| `CommissionEntry` | ✅ | `Sales/Models/CommissionClaim.php` |
| `SalesRepresentative`, `DealerAssignment` | ⛔ | `Sales/Models/SalesTarget.php:34` — কেবল `user_id · branch_id · month · amount`। ⛔ কোন কর্মী কোন ডিলার বা এলাকা দেখেন, সেটা কোথাও বাঁধা নেই। ⭐ মালিকের সিদ্ধান্ত ২৬ সেপ্টেম্বর (ক): বিক্রয়কর্মী কেবল নিজের ডিলার ও এলাকা দেখবেন — নকশা চলছে (`docs/Plan — বিক্রয়কর্মী ও ডিলারের বাঁধন.md`) |
| `PricingRule` | ✅ | `Sales/Models/PricingRule.php`, পড়া হয় `Sales/Services/SalesInvoiceService.php:714` |

### মজুদ · ক্রয় · মানুষ

| DMS-এ | আজ | প্রমাণ |
|---|---|---|
| `DamageEntry` | 🟨 আগের মতোই | আলাদা নথি নেই (`damage_entr`/`damage_note` খুঁজে শূন্য); ক্ষতি যায় সমন্বয়ের পথে কারণ-কোড ধরে |
| `PurchaseRequisition` | ✅ | `Purchase/Models/PurchaseRequisition.php` |
| `EmployeeAdvance` | 🟨 | কেবল খাত আছে — `Accounts/Services/StandardChart.php:403` (`1131`)। ⛔ অগ্রিমের নথি নেই, বেতনে কাটার পথও নেই (`app/Modules/Hr`-এ advance নামে কোনো ফাইল নেই) |
| `PayrollSettings` | 🟨 | ⓘ `payroll.*` বা `hr.*` নামে কোনো সেটিং-চাবি পাওয়া যায়নি; বেতনের নিয়ম `SalaryHead` আর `SalaryStructure`-এ |

### মাস্টার ডাটা

| DMS-এ | আজ | প্রমাণ |
|---|---|---|
| `Manufacturer` | ⛔ | `manufacturer` খুঁজে গোটা `app/`-এ শূন্য |
| `MdmBank` | ✅ | `Finance/Models/Institution.php` (ব্যাংক, MFS, বীমা — এক তালিকা) |
| `BusinessPartner` | 🟨 আগের মতোই | `Customer` আর `Supplier` আলাদা; একই পক্ষকে দুই দিকে খুঁজে বের করে `Accounts/Services/DuplicateParties.php` |
| `PartnerConductNote` | ✅ ক্রেতার জন্য | `Customer/Models/CustomerConduct.php:23`; ⓘ সরবরাহকারীর জন্য নেই |
| `PriceList` | 🟨 আগের মতোই — **মাস্টার আছে, ব্যবহার নেই** | নামটা কেবল `MasterData`-র ভিতরে (`MasterListController`, `MasterListService`, `module.php`); Sales বা Inventory-র কোনো ফাইল তালিকাটা পড়ে না |

### নিরাপত্তা ও শাসন

| DMS-এ | আজ | প্রমাণ |
|---|---|---|
| `CompanyModuleSetting` | ✅ | `app/Models/BranchModule.php` — শাখা পর্যন্ত মডিউলের সুইচ |
| `SelfApprovalRule` | ✅ | `approval.self_limit` — `Core/Engines/Approval/ApprovalEngine.php:1005`, `:1093` |
| `UserPermissionOverride` | ✅ | `app/Models/UserPermissionOverride.php` |
| `UserDataScope` | ✅ | `app/Models/UserDataScope.php`, `Core/Concerns/ScopedToUserBranch.php`, `ScopedToUserWarehouse.php` |
| `SecurityPolicy` | ⛔ | পাসওয়ার্ডের দৈর্ঘ্য-মেয়াদের নীতি নেই। ⓘ `Core/Support/Csp.php`-এর *security policy* ব্রাউজারের CSP, আলাদা জিনিস |
| `PasswordHistoryEntry` | ⛔ | `password_histor` খুঁজে শূন্য |
| `UserSession` | ⛔ | Laravel-এর `sessions` টেবিল আছে (`0001_01_01_000000_create_users_table.php`), কিন্তু *"কোন যন্ত্রে ঢুকে আছি"* পর্দা বা দূর থেকে বের করার পথ নেই (`logoutOtherDevices` খুঁজে শূন্য) |
| `BusinessCategory` | ⛔ | খুঁজে শূন্য |
| `CompanyNotice` | ✅ | Notice Center — `app/Models/Notice.php` ও সঙ্গী মডেল |

### অনুমোদন

| DMS-এ | আজ | প্রমাণ |
|---|---|---|
| `ApprovalDelegation` | ✅ | `app/Models/ApprovalDelegation.php`, `Core/Engines/Approval/DelegationService.php` |
| `ApprovalMatrixRule` | ✅ | শর্ত `app/Models/ApprovalCondition.php`, রোল ধরে টাকার সীমা `app/Models/ApprovalLimit.php` (`AuthorityService.php:29`) |

### কোরের ইঞ্জিন

| DMS-এ | আজ | প্রমাণ |
|---|---|---|
| `core/duplication` | ✅ · ⛔ ছবির perceptual hash | `Core/Engines/Duplication/DuplicationEngine.php`, `Core/Services/DuplicateGuard.php`; ছবির হ্যাশ খুঁজে শূন্য |
| `core/notification` | ✅ | `NotificationService` |
| `core/search` | ✅ | `Core/Engines/Search/SearchEngine.php` |
| `core/integration` (SMS, WhatsApp) | ⛔ | কোনো গেটওয়ে ক্লাস নেই; SMS শব্দটা কেবল মন্তব্যে (`Sales/Events/InvoiceConfirmed.php:19`)। নকশা: `docs/Plan — Integration Platform ও Auto Approval.md` |
| ডিজিটাল স্বাক্ষর ও সিল | ⛔ | ছাপায় কেবল সইয়ের ঘরের লেবেল (`Core/Engines/Print/PrintableDocument.php:34`), কোনো যাচাইযোগ্য স্বাক্ষর নয় |
| `core/sync` | ⚠️ **বদলেছে** | আগে লেখা ছিল *"ইচ্ছাকৃতভাবে নয়"*। এখন ফোনের জন্য `Core/Engines/Sync/*` আছে (`routes/api.php:155`)। ⓘ মজুদ দুই জায়গায় সত্য হয় কি না — পুরনো আপত্তিটা — এই দফায় মাপা হয়নি |
| `core/theme` | ✅ ইঞ্জিন আছে | `app/Models/LookSkin.php`, `LookSkinVersion.php`, `Core/Services/LookSkinService.php` (প্রকাশ ও আগের সংস্করণে ফেরা) |

### গ্রাহক পোর্টাল ও মোবাইল

| DMS-এ | আজ | প্রমাণ |
|---|---|---|
| `PortalAccessAccount` | ✅ গ্রাহকের সারিতে | `portal_password`, `portal_enabled`, `portal_last_login_at` — `database/migrations/2026_09_17_100000_the_dealer_could_never_see_his_own_ledger.php:46-48`; লগইনে মিনিটে পাঁচবারের তালা `Sales/Routes/web.php:309-314`। ⓘ ব্যর্থ চেষ্টায় হিসাব বন্ধ বা পাসওয়ার্ড বদলের বাধ্যবাধকতা নেই |
| `DepositClaim` | ✅ | `Sales/Models/DepositClaim.php` |
| `SupportTicket` | ⛔ | খুঁজে শূন্য |
| `MobileDeviceRegistration` | ✅ | `app/Models/SyncDevice.php` |

---

## কাজের তালিকা · এখনো সত্যিকারের ফাঁক

⚠️ ২৬ সেপ্টেম্বর ২০২৬-এ নতুন করে লেখা। আগের তালিকার ১৩টার মধ্যে ৯টা এখন
আছে (উপরের টেবিল), সেগুলো তালিকা থেকে সরে ✅-এ গেছে; বাকি ৪টা
(`EmployeeAdvance`, `SecurityPolicy`, `UserSession`, `BusinessCategory`) নিচে থেকে গেল। ক্রমটা ব্যবসার দাম ধরে।

| | ফাঁক | কেন লাগে | আকার |
|---|---|---|---|
| ⬜ | **কর্মী ↔ ডিলার/এলাকার বাঁধন** | মালিকের সিদ্ধান্ত (ক): বিক্রয়কর্মী কেবল নিজের ডিলার দেখবেন। আজ বাঁধনটাই নেই (`SalesTarget.php:34`) | বড় — প্রতিটা তালিকা ও রিপোর্টে ছাঁকনি |
| ⬜ | **কমিশন বিলের সাথে নিজে থেকে** | নিয়ম আছে, কিন্তু হিসাব কেবল স্কিমের পর্দা থেকে (`SchemeController.php:41`) | মাঝারি |
| ⬜ | **`PriceList` ব্যবহার** | মাস্টার আছে, কোনো বিল পড়ে না — পর্দায় বসানো দাম কিছুই বদলায় না | মাঝারি |
| ⬜ | **`EmployeeAdvance`** | খাত আছে, নথি আর বেতনে কাটা নেই | ছোট |
| ⬜ | **`SecurityPolicy` · `PasswordHistoryEntry`** | পাসওয়ার্ডের নীতি — দৈর্ঘ্য, মেয়াদ, পুরনোটা আবার নয় | ছোট |
| ⬜ | **`UserSession`** | কে এখন কোথা থেকে ঢুকে আছেন, আর দূর থেকে বের করে দেওয়া | ছোট |
| ⬜ | **`DamageEntry`** | *"এই মাসে কত টাকার মাল নষ্ট হলো"* এক প্রশ্নে | ছোট |
| ⬜ | **`Manufacturer`** | ব্র্যান্ড আছে, প্রস্তুতকারক নেই | ছোট |
| ⬜ | **`BusinessCategory`** | ব্যবসার ধরন ধরে মডিউলের প্রিসেট — ⓘ অনেক ব্যবসায়ীর কাছে বিক্রির জন্য কাজে লাগে | ছোট |
| ⬜ | **SMS / WhatsApp** | নকশা আছে (Integration Platform-এর প্রস্তাব) | মাঝারি |
| ⬜ | **`SupportTicket`** | ডিলারের অভিযোগ | ছোট |

### সরে যাওয়া সারি, যাতে কেউ আবার না বানান

`Scheme` · `CommissionRule` · `PricingRule` · `CapitalEntry` · `Withdrawal` ·
`MdmBank` · `Budget` · `PurchaseRequisition` · `ApprovalDelegation` ·
`CompanyNotice` · `PortalAccessAccount` — প্রমাণ উপরের টেবিলে।

### যেগুলো এই তালিকায় নেই, ইচ্ছাকৃতভাবে

**মোবাইল অ্যাপ ও অফলাইন সিঙ্ক** — DMS-এ ১৬১টা Dart ফাইল আর Hive-ভিত্তিক
সারি, ABOS-এ শূন্য। কিন্তু ওটা একটা *ফাঁক* নয়, একটা **আলাদা পণ্য**; ওটা
রেস্টুরেন্টের ধাপ ২-এর সাথে একসাথে ভাবার জিনিস।

### আর যেখানে ABOS এগিয়ে

**রেসিপি / BOM** — ২৮ আগস্ট ২০২৬-এ ABOS-এ বানানো (`inv_recipes`,
`inv_recipe_lines`)। **DMS-এ নেই।** অর্থাৎ রেস্টুরেন্টের দিকে ABOS এখন
DMS-এর চেয়ে এগিয়ে, আর এই কাগজটা কেবল এক দিকের হিসাব রাখে বলে ওটা এখানে
সহজে চোখে পড়ত না।

---

## ০ · আগে একটা জিনিস, যেটা "নেওয়া" নয়

### পেছনের তারিখের তালা আজ মিথ্যা বলছে

Control Panel-এ ঘরটা আছে — *"কত দিন পেছনের তারিখে এন্ট্রি নেওয়া যাবে"*,
ডিফল্ট ৭। সংখ্যাটা সংরক্ষিত হয়, ফিরেও আসে। **কিন্তু `app/`-এর কোথাও
`accounts.backdate_days` পড়া হয় না** — কেবল সেটিংস পর্দায় লেখা ও দেখানো
হয়।

চারটা টেস্ট আছে, আর চারটাই কেবল দেখে সংখ্যাটা জমা হয় কি না। একটাও
পুরনো তারিখের এন্ট্রি বসিয়ে দেখে না যে আটকায় কি না — কারণ আটকায় না।

এটা Global Features-এর বাধ্যতামূলক নিয়ম, আর পর্দায় দেখে মালিক ধরে
নেবেন এটা কাজ করছে। **আজ যেকোনো তারিখে ভাউচার বসে — গত মাসের রিপোর্ট
বেরিয়ে যাওয়ার পরেও।**

DMS-এ এর সঠিক রূপ দুই স্তরে:

| DMS-এ | কী করে |
|---|---|
| `PeriodLock` | মাস বা অর্থবছর বন্ধ; ভেতরের তারিখে কিছু বসে না, নির্দিষ্ট আনলক ছাড়া |
| `BranchMonthEnd` | শাখা নিজের মাস "শেষ" বলে চিহ্ন দেয়, **কিন্তু তালাটা কোম্পানি-ব্যাপী** — শাখাভিত্তিক তালা দিলে আন্তঃশাখা স্থানান্তরের দুই পা আলাদা হয়, আর ট্রায়াল ব্যালান্স মেলে না |

---

## ১ · হিসাব ও অর্থ

| DMS-এ | ABOS-এ | মন্তব্য |
|---|---|---|
| `Voucher`, `VoucherLine` | ✅ আছে | |
| `ChartOfAccount` | ✅ `Account` + `StandardChart` | |
| `FiscalYear` | ✅ আছে | |
| `CashTill`, `CashCount`, `CashCountNote` | ✅ আছে | |
| `MoneyTransfer`, `MoneyTransferCount` | ✅ আছে | |
| `Loan`, `LoanInstallment`, `LoanInterestCharge`, `LoanSecurity` | ✅ আছে | |
| **`Cheque`** | ❌ **নেই** | ABOS-এ "চেক" শুধু ভাউচারের একটা লেবেল। DMS-এ পুরো জীবনচক্র: ইস্যু/গৃহীত, জমা, পাশ, **ফেরত (bounce)**, বাতিল |
| **`BankAccount`** | 🟡 আংশিক | ABOS-এ ব্যাংক একটা `Account` সারি (`is_bank`); আলাদা ব্যাংক-মাস্টার নেই |
| **`BankReconciliation`, `BankStatementLine`** | ❌ নেই | ব্যাংকের কাগজ আর খাতা মেলানোর পর্দা নেই |
| **`CostCenter`** | ❌ নেই | রুট/ডেলিভারি ধরে খরচের একমাত্র পথ |
| **`Budget`, `BudgetLine`** | ❌ নেই | খরচের সীমা |
| **`Expense`, `ExpenseCategory`** | 🟡 ভাউচার দিয়ে হয় | হিসাব-না-জানা লোকের জন্য ভাউচার কঠিন |
| **`FixedAsset`, `AssetDepreciationEntry`** | ❌ নেই | চার্টে খাত আছে, ব্যবস্থা নেই। ট্রাক/ফ্রিজ/জেনারেটরের অবচয় ছাড়া মুনাফা বেশি দেখায় |
| **`CapitalEntry`** | ❌ নেই | মালিকের পুঁজি ঢোকা-বেরোনো |
| **`Withdrawal`, `WithdrawalLimit`** | ❌ নেই | মালিকের উত্তোলন ও তার সীমা |

## ২ · বিক্রয় ও পরিবেশন

| DMS-এ | ABOS-এ | মন্তব্য |
|---|---|---|
| `SalesOrder`, `SalesOrderLine` | ✅ আছে | |
| `SalesOrderDeposit` | ✅ আছে (`sales.field_deposit`) | |
| `SalesOrderGiftLine` | ✅ আছে | |
| `DeliveryChallan` | ✅ আছে | |
| `GatePass` | ✅ আছে | |
| `SalesReturn`, `SalesReturnLine` | ✅ আছে | |
| `Collection`, `CollectionAllocation` | ✅ আছে | |
| `BulkDoRound` | ✅ আছে (চার্ট/বাল্ক DO শিট) | |
| `InvoiceReprintRequest` | ✅ আছে (`PrintJob` + `sales.reprint.override`) | |
| **`Scheme`, `CommissionRule`, `CommissionEntry`** | ❌ **নেই** | মূল্য/পরিমাণ/স্ল্যাব ভিত্তিক ট্রেড স্কিম — পণ্য, শ্রেণি, ব্র্যান্ড, গ্রুপ, টেরিটরি বা ডিলার-টিয়ার ধরে; ভূমিকা ধরে কমিশন, স্তরসহ। **পরিবেশনের ব্যবসা এটার উপরেই চলে** |
| **`SalesRepresentative`, `DealerAssignment`** | 🟡 `SalesTarget` আছে | কোন কর্মী কোন ডিলার/এলাকা দেখেন সেটা বাঁধা নেই |
| **`PricingRule`** | ❌ নেই | মান দাম থেকে কতটা সরা যাবে, আর সরলে কী — মানা / সতর্কতা / অনুমোদন |

## ৩ · মজুদ

| DMS-এ | ABOS-এ | মন্তব্য |
|---|---|---|
| `StockBatch` | ✅ `Batch` | |
| `StockLedgerEntry` | ✅ `StockMovement` | |
| `StockAdjustment` | ✅ আছে (খতিয়ানে দাখিলাসহ) | |
| `StockReservation` | ✅ আছে (Reserved অবস্থা) | |
| `StockValuation` | ✅ আছে (`CostLayer`, FIFO) | |
| **`DamageEntry`, `DamageEntryLine`** | 🟡 আংশিক | ABOS-এ ক্ষতি যায় `issue()`/সমন্বয়ের পথে কারণ-কোড ধরে। আলাদা নথি নেই — "এই মাসে কত টাকার মাল নষ্ট হলো" এক প্রশ্নে বলা যায় না |

## ৪ · ক্রয়

| DMS-এ | ABOS-এ |
|---|---|
| `PurchaseOrder`, `PurchaseOrderLine` | ✅ আছে |
| `PurchaseInvoice` | ✅ আছে (+ চালান ও GRNI) |
| `PurchaseReturn`, `PurchaseReturnLine` | ✅ আছে |
| **`PurchaseRequisition`** | ❌ নেই — "মাল লাগবে" চাহিদাপত্র, PO-র আগের ধাপ |

## ৫ · কাউন্টার (POS)

| DMS-এ | ABOS-এ |
|---|---|
| `PosSaleTransaction`, `PosSaleLine` | ✅ আছে |
| `PosSalePayment` | ✅ আছে (উপায় ও ভাগ করা পরিশোধ) |
| `PosHeldCart` | ✅ আছে (ধরে রাখা বিল) |
| `PosShift` | ✅ আছে (`CounterShift`, Z-রিপোর্ট) |

## ৬ · মানুষ ও বেতন

| DMS-এ | ABOS-এ |
|---|---|
| `Employee`, `AttendanceRecord`, `LeaveRequest` | ✅ আছে |
| `PayrollRun`, `Payslip` | ✅ আছে (+ `SalaryHead`, `SalaryStructure`) |
| **`EmployeeAdvance`** | ❌ নেই — বেতনের আগে টাকা তোলা, রোজকার ঘটনা |
| **`PayrollSettings`** | 🟡 কিছু সেটিং আছে, একজায়গায় নয় |

## ৭ · মাস্টার ডাটা

| DMS-এ | ABOS-এ |
|---|---|
| `Product`, `ProductCategory`, `Brand`, `Vehicle`, `ReasonCode` | ✅ আছে |
| `MdmLocation` | ✅ আছে — Country→Division→Region→Area→Territory→Point→Route, পুরোটাই |
| `MdmWarehouse` | ✅ `Warehouse` |
| **`Manufacturer`** | ❌ নেই — ব্র্যান্ড আছে, প্রস্তুতকারক নেই |
| **`MdmBank`** | ❌ নেই |
| **`BusinessPartner`** | 🟡 ABOS-এ `Customer` ও `Supplier` আলাদা — এক পক্ষ যিনি দুইটাই, তাঁর দুইটা সারি |
| **`PartnerConductNote`** | ❌ নেই — পক্ষের আচরণের নোট ("এই ডিলার চেক ফেরত দেন") |
| `PriceList` | 🟡 **মাস্টার আছে, ব্যবহার নেই** — কোনো বিল এই তালিকা থেকে দাম নেয় না |

## ৮ · নিরাপত্তা ও শাসন — ফাঁক এখানেই সবচেয়ে বেশি

| DMS-এ | ABOS-এ | কেন দরকার |
|---|---|---|
| `Role`, `RolePermission`, `UserRole` | ✅ আছে (Spatie) | |
| `LoginAttempt` | ✅ আছে (`LoginJournal`) | |
| `ControlPanelFieldSetting`, `PrintOption`, `RequiredField` | ✅ আছে | |
| `CompanyModuleSetting`, `CompanyMenuSetting` | 🟡 পর্দা-ভিত্তিক সুইচ আছে | মডিউল ধরে নয় |
| **`SelfApprovalRule`** | ❌ নেই | কে নিজের কাগজ নিজে অনুমোদন করতে পারবেন, কত টাকা পর্যন্ত। DMS-এ ধরা পড়েছিল হেঁটে দেখে: অফিস নিজের খরচের দাবি নিজেই অনুমোদন করছিল, ম্যানেজার নিজের স্টক রাইট-অফ |
| **`UserPermissionOverride`** | ❌ নেই | একজনের জন্য একটা ব্যতিক্রম, রোল না বদলে |
| **`UserDataScope`** | ❌ নেই | কে কোন শাখা/গুদাম/টেরিটরির সারি দেখবেন — এটাই **ভাগ চ (RLS)** |
| **`SecurityPolicy`** | ❌ নেই | পাসওয়ার্ডের দৈর্ঘ্য, মেয়াদ, ব্যর্থ চেষ্টায় তালা |
| **`PasswordHistoryEntry`** | ❌ নেই | পুরনো পাসওয়ার্ড ফিরিয়ে দেওয়া বন্ধ |
| **`UserSession`** | ❌ নেই | "কোন কোন যন্ত্রে আমি লগইন আছি", দূর থেকে বের করে দেওয়া |
| **`BusinessCategory`** | ❌ নেই | ব্যবসার ধরন অনুযায়ী প্রিসেট |
| **`CompanyNotice`** | ❌ নেই | |

## ৯ · অনুমোদন

| DMS-এ | ABOS-এ |
|---|---|
| প্রবাহ, স্তর, সিদ্ধান্ত | ✅ আছে (`ApprovalEngine`) |
| নিজের অনুরোধ নিজে অনুমোদন নিষেধ | ✅ আছে |
| **`ApprovalDelegation`** | ❌ নেই — ম্যানেজার ছুটিতে গেলে ভার অন্যকে, তারিখ ধরে |
| **`ApprovalMatrixRule`** | 🟡 প্রবাহে টাকার সীমা আছে, পূর্ণ ছক নেই |

## ১০ · কোরের ইঞ্জিন

| DMS-এ | ABOS-এ | মন্তব্য |
|---|---|---|
| `core/audit` | ✅ আছে (পুরনো/নতুন মানসহ) | |
| `core/attachment` | ✅ আছে | |
| `core/document` (নম্বর সিরিজ, রেন্ডার) | ✅ আছে | |
| `core/metrics` | ✅ আছে | |
| `core/dashboard`, `core/analytics` | ✅ আছে (উইজেট, তুলনা, Top N) | |
| `core/tax`, `core/uom` | ✅ আছে | |
| `core/hook` | ✅ আছে (`events`, `listeners`) | |
| **`core/duplication`** | ❌ **নেই** | দুই রকম অনন্যতা এক জায়গায়: (ক) মাস্টারের নাম — সক্রিয় ভাইবোনদের মধ্যে অনন্য, নিষ্ক্রিয় করলে নাম ছাড়া পায়; (খ) চিরকালের মান, যেমন ব্যাংক স্লিপ নম্বর। সাথে **ছবির perceptual hash** |
| **`core/notification`** | ❌ নেই | অনুমোদন চাইলে মানুষ জানবেন কীভাবে? আজ পর্দায় না গেলে জানেনই না |
| **`core/search`** | ❌ নেই | এক ঘরে বিল নম্বর, গ্রাহক, পণ্য |
| **`core/integration`** (SMS, WhatsApp পোর্ট) | ❌ নেই | পেইড সেবা ছাড়াই কাঠামোটা বসানো থাকে |
| **ডিজিটাল স্বাক্ষর ও সিল** | ❌ নেই | ছাপা কাগজ পরে যাচাই করা যায় |
| `core/formula` | ⛔ **ইচ্ছাকৃতভাবে নয়** | ব্যবহারকারীর লেখা সূত্র = ব্যবহারকারীর লেখা প্রোগ্রাম |
| `core/sync` | ⛔ **ইচ্ছাকৃতভাবে নয়** | ১৬ নং — মজুদ দুই জায়গায় সত্যি ধরা |
| `core/automation` | 🟡 Laravel-এর নিজেরটা আছে | |
| **`core/theme`** | 🟡 **দশটা রূপ চলছে, ইঞ্জিন নেই** | রূপগুলো একটা CSS ফাইলে (৩৫KB, ৪৭২ টোকেন)। প্রত্যেকে আটটাই নামান, ব্যবহার করেন একটা। কোম্পানির নিজের রূপ সম্ভব নয়, নতুন রূপে ডিপ্লয় লাগে, আর ভুল টোকেন সেভের সময় ধরা পড়ে না। মালিকের সিদ্ধান্ত ২১ আগস্ট: **আলাদা ও বড় ইঞ্জিন হবে** — `docs/Plan — Theme Engine.md` |

## ১১ · গ্রাহক পোর্টাল ও মোবাইল

| DMS-এ | ABOS-এ | মন্তব্য |
|---|---|---|
| **`PortalAccessAccount`** | ❌ নেই | ডিলারের নিজের লগইন — তালা, ব্যর্থ চেষ্টা, পাসওয়ার্ড বদলের বাধ্যবাধকতাসহ |
| **`DepositClaim`** | ❌ নেই | ডিলার ব্যাংক স্লিপের ছবি দিয়ে জমার দাবি তোলেন, ডিপো মিলিয়ে নিশ্চিত করে। **আজ কাজটা ফোনে হয়** |
| **`SupportTicket`** | ❌ নেই | |
| **`MobileDeviceRegistration`** | ❌ নেই | |

---

## যে ক্রমে করা উচিত

| ক্রম | কাজ | কেন এই জায়গায় |
|---|-----|----------------|
| **০** | **পেছনের তারিখের তালা সত্যি করা** + `PeriodLock` | আজ পর্দা মিথ্যা বলছে। বাধ্যতামূলক নিয়ম, আর সবচেয়ে সস্তা |
| ১ | চেক রেজিস্টার | ডিপোর অর্ধেক আদায় PDC চেকে |
| ২ | কস্ট সেন্টার | ছোট কাঠামো, রুট-ভিত্তিক খরচের দরজা খোলে |
| ৩ | `SelfApprovalRule` + `UserPermissionOverride` | ছোট, আর অনুমোদনের কাজের সাথেই যায় |
| ৪ | ব্যাংক মিলকরণ | চেক এলে পরের স্বাভাবিক ধাপ |
| ৫ | নকল ঠেকানো | মাস্টার ডাটা নোংরা হওয়ার আগেই |
| ৬ | বিজ্ঞপ্তি | অনুমোদন ব্যবস্থা তখনই সত্যি কাজে লাগে |
| ৭ | স্কিম ও কমিশন | বড় — **মালিকের সিদ্ধান্ত লাগবে** |
| ৮ | স্থায়ী সম্পদ ও অবচয় | বছর শেষের আগে |
| ৯ | `UserDataScope` (ভাগ চ · RLS) | একাধিক এলাকার কর্মী যোগ হলে |
| ১০ | গ্রাহক পোর্টাল ও জমার দাবি | ডিলার সংখ্যা বাড়লে |
| ১১ | **থিম ইঞ্জিন — ধাপ ১** · রেজিস্ট্রি · সংকলক · স্কিমা · গাঢ় রূপ | দশটা রূপ ডাটায় সরে; পাতায় কেবল চলতিটা নামে; ভুল টোকেন সেভের সময় ফেরে। **প্রমাণ:** ইঞ্জিনের পরেও ব্রাউজারে ওই একই ৮৭টা মান মিলতে হবে, একটাও পিক্সেল না নড়ে |
| ১২ | থিম ইঞ্জিন — ধাপ ২ · উত্তরাধিকার · স্তর · কনট্রাস্ট গেট | এখান থেকে কোম্পানির নিজের রূপ সম্ভব |
| ১৩ | থিম ইঞ্জিন — ধাপ ৪ · আমদানি/রপ্তানি · পাহারার সম্প্রসারণ | রূপ একটা ফাইল হয়ে যায় — গ্রাহকের রূপ আমরাই বানিয়ে পাঠাতে পারি |
| ১৪ | থিম ইঞ্জিন — ধাপ ৩ · প্রিভিউ · সংস্করণ · সম্পাদনার পর্দা | সবার শেষে, আর কেবল গ্রাহক নিজে চাইলে। কনট্রাস্ট গেট (১২) ছাড়া এটা কখনো নয় |

---

# মালিকের দেওয়া কাজ · ১৮–১৯ আগস্ট

উপরের তালিকাটা DMS ধরে বানানো। এই অংশটা আলাদা: এগুলো এসেছে **ABC
এন্টারপ্রাইজের নিজের ব্যবসা** থেকে — সুপার ডিপো চালাতে গিয়ে যে
প্রশ্নগুলো ওঠে।

## ✅ শেষ হয়েছে

| কাজ | কী বেরিয়েছিল | টেস্ট |
|---|---|---|
| **তিন কোণা সমন্বয়** | ভাউচারের মাথায় একটামাত্র পক্ষ ছিল, তাই "ডিলার টাকাটা কোম্পানিকে দিয়েছে" লেখাই যেত না। সাথে একটা ফাঁক: অচেনা `party_type` কাঁচা ইনপুট হয়ে খতিয়ানে পৌঁছাতে পারত | ১০ |
| **Receive-এর সব পথে দাম-চতুষ্টয়** | ক্রয়দর → markup % → margin % → বিক্রয়মূল্য — চালানের পর্দায় ছিল না, অথচ মাল ওখানেই ঢোকে। আর বাক্সে বুঝে নিলে দামটা পিসে নামত না | ৬ |
| **পেছনের তারিখের তালা + মাস বন্ধ** | `accounts.backdate_days` **কোথাও পড়াই হত না** — এক বছর ধরে পর্দা মিথ্যা বলছিল। মাস বন্ধ করার উপায়ই ছিল না | ১৩ |
| **ADI \| ABOS ব্র্যান্ডিং** | নতুন লোগো তিন রূপে, সোনা কমলা-অ্যাম্বারে, Poppins ফিরল। গাঢ় থিমে সোনা ছিল সেই সমতল হলুদ যেটা হালকা থিমে বাদ দেওয়া হয়েছিল | — |
| **কোম্পানির নিষ্পত্তি রিপোর্ট + ২ উইজেট** | মাস শেষের চারটা সংখ্যা এক পাতায়। "কার মাল বিক্রি হলো" বেরোয় FIFO স্তর ধরে, অনুমানে নয় | ৭ |
| **ডিলারের কমিশন** | দাবি হিসেবে, ছাড় হিসেবে নয় — নাহলে ৪% মার্জিনে ৫% কমিশন মানে খাতা বলত লোকসানে বেচছি | ১৬ |
| **চেক রেজিস্টার** (১) | `instrument` ঘরটা জমা থাকত, **একটাও দাখিলা বদলাত না** — হাতে আসা চেক সাথে সাথেই ব্যাংকের টাকা হয়ে যেত | ১৩ |
| **পুঁজির উপর ফেরত** | ৪% বলে বিক্রির উপর কত; এটা বলে টাকা খেটে বছরে কত আনছে | ৩ |
| **ক্রেডিট লিমিটের সুইচ** | সীমাটা দেখা হত **কেবল বিক্রয় আদেশে**, আর পার করানোর অনুমতি দেখা হত **ছাড়ের চাবি** ধরে — দুইটাই ভুল | ৯ |
| **কস্ট সেন্টার** (২) | "নেত্রকোনার রুটে মাসে কত খরচ" — প্রশ্নটার কোনো উত্তরই ছিল না | ৮ |
| **নিজের সইয়ের সীমা + একজনের ব্যতিক্রম** (৩) | Spatie দিতে পারত, **কাড়তে পারত না** — একজনের একটা ক্ষমতা তুলতে আস্ত নতুন রোল লাগত | ১০ |

## 🟡 কোড তৈরি, সুইচ মালিকের

### ক্রেডিট লিমিট শূন্য মানে শূন্য

আজ ABOS-এ **শূন্য মানে সীমাহীন**, আর কোডে মন্তব্য করে কারণটাও লেখা:
*"শূন্যকে 'কিছুই বাকি রাখা যাবে না' ধরলে নতুন গ্রাহকের প্রথম বিলটাই
আটকে যেত।"* মালিকের সিদ্ধান্ত এর উল্টো — শূন্য মানে বাকি নয়, হয় আগে
অনলাইনে টাকা, নয় নগদে বিল।

দুইটা অংশে:

1. **সুইচ** — Control Panel-এ "লিমিট ছাড়া বাকি নয়"। চালু করলে
   `limit = 0` মানে বাকি শূন্য; অর্ডার HOLD-এ যাবে, আর কাউন্টারে বলবে
   "আগে টাকা নিন"।
2. **তালিকা** — কাদের লিমিট বসানো নেই। ADI-তে ১৪৮ জন, আর তাঁদের মধ্যে
   কয়েকজন বড় ডিলার নিশ্চয়ই আছেন যাঁদের সত্যিই বাকি দিতে হয়।

**সুইচটা মালিক টিপবেন, আমি নয়** — কোড তৈরি থাকবে, চালু হবে তাঁর বেছে
নেওয়া দিনে, তালিকা দেখে লিমিট বসানোর পর।

### আরও দুইটা ঘুমন্ত সুইচ

একই নিয়মে বানানো — কোড তৈরি, কিন্তু মালিক না বললে কিছুই বদলায় না।

| সুইচ | কোথায় | চালু করলে |
|---|---|---|
| **নিজের সইয়ের সীমা** (`approval.self_limit`) | Control Panel → অনুমোদন | এর নিচের অঙ্কে নিজের কাগজে নিজে সই চলে। ডিফল্ট শূন্য = কখনো নয়, অর্থাৎ আজকের কঠোর নিয়ম |
| **একজনের ব্যতিক্রম** | *পর্দা এখনো নেই* — সারি বসাতে হয় | রোল যা দিয়েছে তা একজনের কাছ থেকে কাড়া যায়, বা রোল যা দেয়নি তা দেওয়া যায় |

ব্যতিক্রমের পর্দাটা বাকি — ৪ নং-এর সাথে হবে।

## ✅ টাকা কোথায় খাটছে — তিনটাই হয়েছে

এই তিনটা **আটকে থাকা পুঁজির** অংশ, তাই পুঁজির রিপোর্টের সাথে সরাসরি
জড়িত।

| কাজ | অবস্থা |
|---|---|
| **হাতধার** — অনানুষ্ঠানিক ধার দেওয়া-নেওয়া | ✅ `LoanService` · `MoneyLentOnAWordIsStillMoneyTest` |
| **FD / DPS** | ✅ `Loan` মডেলের ধরনেই — `LoanTest` |
| **ঋণের বিপরীতে FD** | ✅ `TheFdBehindTheLoanTest` |

## পরের ক্রম — শেষ

| ক্রম | কাজ | অবস্থা |
|---|---|---|
| ৪ | ব্যাংক মিলকরণ | ✅ `BankReconciliationService` |
| ৫ | নকল ঠেকানো | ✅ `DuplicateGuard` |
| ৬ | বিজ্ঞপ্তি | ✅ `NotificationService` |
| ৭ | হাতধার · FD/DPS · ঋণের বিপরীতে FD | ✅ উপরে |
| ৮ | স্থায়ী সম্পদ ও অবচয় | ✅ `FixedAssetService` · `TheVanWoreOutAndTheBooksNeverNoticedTest` |
| ৯ | কে কোন সারি দেখবেন (RLS) | ✅ `ScopedToUserWarehouse` · `TheWallHadNoDoorTest` |
| ১০ | গ্রাহক পোর্টাল ও জমার দাবি | ✅ `CustomerPortalService` · `NobodyCouldOpenTheCustomersDoorTest` |

> **কেন এই ভাগটা আবার লেখা হলো (২৫ আগস্ট ২০২৬):** উপরের সারিগুলোয় লেখা
> ছিল *"❌ কিছুই নয়"* আর *"সার্ভিস ও পরীক্ষা বাকি"*, অথচ তিনটাই সার্ভিস
> ও পরীক্ষাসহ তৈরি। কাজগুলো হয়েছিল পরের সেশনগুলোয়, আর এই কাগজটা
> হালনাগাদ হয়নি।
>
> একটা চেকলিস্ট যা মিথ্যা বলে, সেটা চেকলিস্ট না থাকার চেয়ে খারাপ:
> কেউ ওটা পড়ে হয় দ্বিতীয়বার একই জিনিস বানাতে বসতেন, নয় ভাবতেন
> পুঁজির রিপোর্টটা অসম্পূর্ণ — অথচ ওটা পূর্ণ।

সব শেষে: পূর্ণ সুইট → লোকাল সার্ভারে ব্রাউজারে নিজে যাচাই → `APP_NAME`
বদল → একবারেই দুই সার্ভারে ডিপ্লয়।
