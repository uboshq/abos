# চেকলিস্ট — অডিট ২৭ সেপ্টেম্বর ২০২৬

দুই রিপোর্টের প্রতিটা কাজ একটা ঘর: **ক** = `audit report 27.9.26` (§ নম্বর), **খ** = `ABOS — কী আছে কী নেই` (A/B/C/D নম্বর)। ভাগের কারণ আর ক্রম: `docs/কাজ-ভাগের খাতা.md` §১ক।

**টিক দেওয়ার নিয়ম:** ঘরে `[x]` দেওয়া যাবে কেবল তখন, যখন লাইনের শেষে কমিট হ্যাশ আর প্রমাণের টেস্টের নাম লেখা হয়। প্রমাণ মানে: আগে লাল → সারাই → সবুজ → মিউটেন্টে লাল। লাইভে দেখা হলে পাশে `লাইভ✓`। টিক দেন কাজের মালিক; সমন্বয়কারী deploy-এর পরে `লাইভ✓` বসান।

চিহ্ন: `[ ]` বাকি · `[~]` চলছে · `[x]` শেষ · `[⏸]` মালিকের কথায় পরে

---

## ✅ ইতিমধ্যে শেষ ও লাইভে

- [x] ক §১.১০ — HTTP থেকে HTTPS-এ ৩০১ (cPanel) · লাইভ✓
- [x] ক §৯ — X-Powered-By সরানো · 17eba681 · লাইভ✓
- [x] রিপোর্টের চাবি একাই সব রিপোর্ট খুলত · e171b421 · TheScheduleKeyOpenedEveryReportTest · লাইভ✓
- [x] ক §২ — চেক খাতায় ওঠে জমা হওয়ার পরে · b41daf35 · লাইভ✓
- [x] সীমা টুকরো টুকরো করে বাড়ানো যেত · 653f65c8 · লাইভ✓
- [x] ক §১০ (অংশ) — ফোন কেবল আকার ও আঙুলের ছাপ মেলা ফাইল নেয় (সার্ভারের দিক) · 63a86cac · লাইভ✓

---

## abos-db (সমন্বয়কারী)

- [x] ক §১.১ — কাউন্টারের জমা কেবল টাকার খাতায় (`MoneyAccountRule`) · 2acb3803 (কাউন্টারের জমা আর `counterVoucher` দুই জায়গায় `MoneyAccountRule`) · EveryCounterSaleMustMatchTheBooksFiveWaysTest::test_the_counter_deposit_goes_only_to_a_money_account, OnlyAMoneyAccountTakesMoneyTest · লাইভ✓
- [x] ক §১.২ — সীমা বাড়ানোর অনুমোদন অঙ্কের সাথে বাঁধা; গ্রাহক বানানো আর আমদানিতেও; প্রবাহ না থাকলে বন্ধ; `zero_limit_blocks` ডিফল্টে চালু · 8d52bb11 (অঙ্ক অনুমোদনের ভিতরে, বানানো/আমদানিতে সীমা নয়, প্রবাহ না থাকলে বন্ধ) · TheSignatureWasForOneLakhAndFiftyWereSetTest · শূন্য সীমা মানে বাকি নয়, সুইচ ছাড়াই: 28cb42c0 · NoLimitMeansNoCreditForAnyoneTest · লাইভ✓
- [~] ক §১.৪ — জমার দাবি দুইবার গ্রহণ হয় না (সারিতে তালা + অদ্বিতীয় সূচক) · দাবির সারিতে তালা + আবার পড়া: 7cc0111c · AClaimAcceptedTwiceTookTheMoneyTwiceTest · লাইভ✓ · বাকি: আদায়ের `deposit_claim_id`-এ অদ্বিতীয় সূচক
- [x] ক §১.৫ — নিষ্ক্রিয় করলে সাথে সাথে বাইরে (প্রতিটা অনুরোধ, টোকেন নবায়ন, সেশন আর টোকেন মোছা) · 5f7bce09 · ADismissedHandKeptTheKeysTest · লাইভ✓
- [~] ক §১.৬ — "এইচআর সব কোম্পানিতে এক" কেবল সুপার অ্যাডমিন · ⓘ ৪ অক্টোবর: `super_admin_only` কোডে বসেছে, কিন্তু কমিট হয়নি · বাকি: কমিট আর প্রমাণের টেস্ট
- [x] ক §১.৮ — আবার বন্ধ করা বছরে নম্বর পিছোয় না (রিসেট হওয়া সিরিজসহ) · 07f7825f · ReopeningAYearPulledTheNumbersBackTest · লাইভ✓
- [x] ক §২ — `CreditExposure`-এ গ্রাহকের সারিতে তালা (দুই কাউন্টার একসাথে) · d499d7bf · TwoCountersSoldPastTheLimitTest · লাইভ✓
- [x] ক §২ — ক্রয় ফেরতের নম্বর-চাবি `PRT`, রিকুইজিশন থেকে আলাদা · ca722bdf (রিকুইজিশন `PRQ`-তে) · TheRequisitionTookTheReturnsNumberTest · লাইভ✓
- [~] ক §১১ — ডিফল্ট ভূমিকা নিজের কাজ পারে: হিসাবরক্ষক মাস বন্ধ ও বেতন পোস্ট, গুদাম মাল গ্রহণ, বিক্রয় ব্যবস্থাপক অর্ডার নিশ্চিত + পাহারা · ব্যবস্থাপক অর্ডার নিশ্চিত, গুদাম ট্রিপ: 4b6d6e41 · TheRolesCouldNotDoTheirOwnJobTest · ক্রয়, মাল গ্রহণ, বিল: a1bbc8a6 · EachRoleCanDoItsOwnWorkTest · লাইভ✓ · বাকি: হিসাবরক্ষকের মাস বন্ধ (`accounts.period.close`) আর বেতন পোস্ট
- [ ] ক §১১ — পুরনো আদায়ের রুট বন্ধ বা শুধু দেখা
- [ ] ক §১১ — মূল্যতালিকা বিক্রয়ে ব্যবহার
- [x] ক §২ — কাউন্টারের `finishHeld`-এ চেক-যাচাই (abos-9b-এর ১১০৪ পাহারার সাথে) · 68407e01 · AHeldSaleFinishedWithAChequeInItTest · লাইভ✓
- [x] সরাসরি বিক্রয়ের পর্দা (খসড়া, চালান নম্বর, Pending, ব্যাংকের ঘর) — কমিট ও deploy · 2acb3803 (খসড়া, Pending, ব্যাংকের ঘর) + 9945470b (নিজের নম্বর; পরে এক বিক্রয় এক নম্বর 1e402eb1) · TheParkedBillWaitsAtTheSameCounterTest, direct-sale.test.js · লাইভ✓
- [~] NEXUS Sales-এর ৮ কাজ — জোড়া, পরীক্ষা, কমিট · ⓘ কমিট হয়েছে: P5 9c58dcfe + 587363e6, §8 কোটেশন c2971671, §7 CRM 9aa5e993, §27 রুট 74715a64, §28 চ্যানেল ac45ba52, §4 সারাংশ 64847dca · বাকি: আটটার তালিকার সাথে মেলানো
- [ ] লাইভ ব্যবসা-পরীক্ষা গ (ক্রয়), ঘ (বিক্রয়), ঙ (রিপোর্ট ও বাকি)
- [ ] হালনাগাদ অডিট রিপোর্ট `E:\ABOS\Audite Report`-এ

## abos-9b (ফর্ম ও ডিজাইন — রিপোর্ট খ-এর মালিক)

**ক রিপোর্ট থেকে**
- [x] ক §২ — চেক-পাহারা শব্দ খুঁজে নয়, খাতা ১১০৪ চিনে; হাতে লেখা জার্নালেও · সারিতে: CashOnHand-এর ডাক আর পরিশোধের চার্জের পরে (তিনটাই VoucherService) · 060726c5 (VoucherService::assertNoChequeInHandByHand) · NoChequeReachesTheBooksByHandTest · লাইভ✓
- [x] ক §২ — চেক জমা কেবল ব্যাংক খাতায়, চেকের তারিখের আগে নয় · 52bd5d5e · AChequeWaitsForItsDateAndItsBankTest · লাইভ✓
- [x] ক §৮ — ছয় জায়গায় `@js(old(...))` (ডিলার পোর্টালসহ) · 48c4dd09 · TypedTextReachesAlpineOnlyThroughJsTest · লাইভ✓
- [x] ক §৮ — লাভ-ক্ষতি আর নগদ-প্রবাহের মেনু রুটের চাবির সাথে এক · cec88dd3 · TheMenuOfferedFinalAccountsTheDoorRefusedTest · লাইভ✓
- [ ] ক §১৬ — ফন্ট: অব্যবহৃত Montserrat বাদ, শিরোনামে একটাই ফন্ট
- [ ] ক §১৬ — মেনুর কঠিন শব্দ সহজ বাংলায় (কন্ট্রা, ক্যাশ টিল, পোস্টিং মনিটর)
- [ ] ক §১৬ — রেস্তোরাঁর ১৬টা "পরিকল্পিত" মেনু-সারি লুকানো

**খ ধাপ ১ — ভাঙা পর্দা (P0)**
- [x] B-02 ভুলের পরে লেখা থাকে (মাধ্যম, চার্জ, নোট, চেকের তারিখ) · a4534762 · TheVoucherFormSendsWhatItShowsTest, money.test.js · লাইভ✓
- [x] B-04 পরিশোধ ভাউচার পোস্ট হয় · a4534762 · TheVoucherFormSendsWhatItShowsTest (পরিশোধ ফর্ম যা পাঠায় তাই দিয়ে পোস্ট, দেনা ২১১১-এ) · লাইভ✓
- [x] B-05 লুকানো বা বন্ধ ঘরের কারণ পাতা খুলতেই · a4534762 · TheVoucherFormSendsWhatItShowsTest (মালিকের "নিজের বাক্সে নগদ" নিয়ম অক্ষত; held_by অফিস-নগদ নিজের) · লাইভ✓
- [x] B-06 মাধ্যম আর খাতের মিল · ভাউচার ফর্ম a4534762 · TheVoucherFormSendsWhatItShowsTest · কাউন্টার fddc7155 · EveryCounterSaleMustMatchTheBooksFiveWaysTest::test_the_deposit_method_must_fit_its_account · POS আর সেটিংস 9dcad276 · APaymentMethodFitsItsMoneyAccountAfterThePatchTest · লাইভ✓
- [x] B-07 ব্যাংক বাছলে লেনদেন-নম্বরের ঘর · a4534762 + 78555c49 · money.test.js (হাতধার, আমানত, মূলধন, উত্তোলন, ভাড়ার পর্দাসহ) · লাইভ✓
- [x] লাইভের RCV-0001: সইয়ের পরে দ্বিতীয় সই, নিঃশব্দ "পোস্ট" বোতাম, "0" নম্বর · 52bd5d5e · TheSecondPressOnAHeldVoucherSaidNothingTest · লাইভ✓
- [x] বাতিলের কারণ ফাঁকা রাখলে বোতাম চিরকাল বন্ধ · 1b0c9739 · one-submit.test.js · লাইভ✓
- [x] বাতিলের কারণ ফাঁকা হলে বার্তা · 986b1ab1 · reason-prompt.test.js, ABlankCancelReasonSaysWhyTest · লাইভ✓

**খ ধাপ ২ — দ্রুত খোঁজা আর শর্টকাট**
- [x] A-06 Ctrl+K সত্যিই কাজ করে (P0) · 1fd4806e · components/shell.test.js · লাইভ✓
- [x] A-07 খোঁজায় তীর আর Enter · 1fd4806e · components/shell.test.js · লাইভ✓
- [~] A-08 খোঁজায় কাজ ("নতুন বিক্রয়"), অনুমতি মেনে · খালি বাক্সে অনুমতি মেনে "নতুন …" কাজ: dc20fecb · AnEmptySearchBoxShowedNothingTest · লাইভ✓ · বাকি: লিখে খুঁজলে কাজ আসে না, কেবল কাগজ
- [x] A-09 সাম্প্রতিক খোলা কাগজ · dc20fecb · AnEmptySearchBoxShowedNothingTest, shell.test.js · লাইভ✓
- [x] C-04 লেখার সাথে সাথে খোঁজা · c6a13a58 · search-as-you-type.test.js, EveryListSearchesAsYouTypeTest · লাইভ✓
- [x] C-10 তালিকার শর্টকাট সব রূপে · 1fd4806e · list-keys.test.js · লাইভ✓
- [ ] D-01 কাউন্টারের JS কেবল কাউন্টারের পাতায়

**খ ধাপ ৩ — কাউন্টার (abos-db-এর হাতবদলের পরে)**
- [ ] D-03 পাতা লোড ছাড়া বিক্রয়
- [ ] D-04 একই পর্দায় রসিদ ছাপা (P0; মালিকের সিদ্ধান্ত গ)
- [ ] D-05 সবচেয়ে বেশি বিক্রির পণ্যের দ্রুত বোতাম
- [ ] D-06 "এই বিলের কপি"

**খ ধাপ ৪ — ফর্ম**
- [ ] B-08 পণ্যের সারিতে খোঁজা
- [ ] B-09 পণ্য বাছলে দাম নিজে বসে
- [ ] B-10 Enter চাপলে নতুন সারি
- [ ] B-11 সারির ত্রুটি সারির পাশে
- [ ] B-12 প্রথম ঘরে কার্সার
- [ ] B-01 উপরের ত্রুটির তালিকা এক কম্পোনেন্টে
- [ ] B-03 মাস্টার ফর্মে দরকারি ঘর উপরে, বাকি "আরো"-তে

**খ ধাপ ৫ — তালিকা ও ড্যাশবোর্ড**
- [ ] C-01 ড্যাশবোর্ডের টালি ঠিক ছাঁকনিতে যায়
- [ ] C-02 সারির মেনু সব কাগজের তালিকায়
- [ ] C-03 ডিফল্টে ৭টার বেশি কলাম নয়
- [ ] C-05 প্রিয় ভিউ নিজে খোলে
- [ ] C-06 খালি তালিকায় "নতুন বানান"
- [ ] C-07 একসাথে বহু সারিতে কাজ
- [~] C-08 "এখন কী দেখা দরকার" ড্যাশবোর্ড · হোমে ব্যতিক্রম কেন্দ্র (আটকে থাকা প্রতিটার কার্ড ও দরজা): 18dd12d3 · TheMoneyPositionSitsAtTheHeadTest · লাইভ✓ · বাকি: মেয়াদ পেরোনো বকেয়া, সীমায় পৌঁছানো গ্রাহক, পুনঃক্রয়ের পণ্য — আজকের মোটের আগে
- [ ] C-09 ড্যাশবোর্ড ক্যাশ

**খ ধাপ ৬ — চেহারা**
- [ ] A-01 কাঁচা বোতাম → `x-ui.button`
- [ ] A-02 হাতে লেখা svg → `x-ui.icon`
- [ ] A-03 কাঁচা টেবিল → `x-ui.table`
- [ ] A-04 ১১px লেখা আর সরাসরি style কমানো
- [ ] A-05 ঘনত্ব ব্যবহারকারী বাছেন (মালিকের সিদ্ধান্ত খ)
- [ ] A-10 একটাই "সংরক্ষিত হয়েছে" টোস্ট
- [ ] A-11 লোড হওয়ার ইঙ্গিত
- [ ] A-12 একটাই রূপ (মালিকের সিদ্ধান্ত ক)
- [ ] A-13 কম পট্টি
- [ ] D-02 বাংলা ফন্ট preload
- [ ] D-08 মেনুর অনুমতি-যাচাই ক্যাশ

## abos-87 (টাকার হিসাব, তথ্য, গতি)

**§২ টাকার হিসাব**
- [x] দল-রিপোর্ট `ledger_entries` থেকে (বিক্রয়, ক্রয়, বেতন আর দেখায় শূন্য নয়) · 05bb5c7e · `TheGroupReportCountedOnlyTheVouchersTest` (৪/৪; মিউট্যান্টে ৩টা মরা, ভারসাম্যের দাবি সবুজ) · ⓘ উৎস voucher_lines → ledger_entries; গ্রুপের সংখ্যা = কোম্পানির নিজের খতিয়ান · Accounts+Posting ২৫৩/২৫৩ · লাইভ✓
- [~] বছর বন্ধের পরে চার পর্দায় `closingSources()` + পাহারা · ছয় পর্দায় বসেছে: 4978c2fe · ClosingTheYearEmptiedSixScreensTest · লাইভ✓ · বাকি: পাহারা (লাভ গোনে এমন প্রতিটা কোয়েরি ফিল্টারটা নেয় কিনা)
- [x] লাভ ঘোষণা: সঞ্চিত মুনাফার বেশি নয়, দুইবার নয়, অনুমোদন বাধ্যতামূলক · 25d9fec0 · TheProfitDeclaredWasMoreThanWasEverEarnedTest · সই: 11e65c95 · AProfitWasSharedWithNobodyToSignTest · ঘোষণার বেশি দেওয়া নয়: 772969de · TheProfitWasPaidBeyondWhatWasDeclaredTest · লাইভ✓ · ⓘ প্রবাহ বন্ধ রাখলে সাথে সাথে পোস্ট — কোম্পানির নিজের সুইচ
- [x] সম্পদ বিক্রির লাভ/ক্ষতির আলাদা খাতা · 4978c2fe (৪৩৫০ লাভ, ৫৩২০ ক্ষতি) · TheAssetSaleGainHidInTheDepreciationTest · লাইভ✓
- [~] "দুই বছর খোলা" মোড · আগের খোলা বছরের লাভ ব্যালান্স শিটে ধরা, বছর বন্ধ শাখা ধরে: bcc00bd7 · YearEndTest · লাইভ✓ · বাকি: মোডটা নিজে
- [x] `CostLayerService` — ভগ্নাংশে স্তর বড় হয় না · 18d5bb00 · `AFractionOfAKiloMadeALayerBiggerThanTheGoodsTest` (৪/৪, দুই অর্ধেক আলাদা মিউট্যান্টে মরা) · ⓘ সাথে ফেরতের তিন বাগ: `OneBillsReturnAteAnotherBillsHeadroomTest` (৫/৫, ৪টা মিউট্যান্টে মরা) · লাইভ✓
- [x] বেতনের ব্যাংক-ফাইলের গোলকরণ খাতায় মেলে · cc8a56f8 · TheBankFileAndTheBooksPaidTheSameSalaryTest · লাইভ✓

**§৪ তথ্যের গাঁথুনি**
- [ ] `ledger_entries.account_id`-এ বাঁধন (আগে লাইভে অনাথ সারি গোনা)
- [ ] হিসাবের টেবিলে CASCADE → RESTRICT + পাহারা
- [x] খতিয়ানের সিলে আলাদা চাবি `LEDGER_SEAL_KEY`, সংস্করণসহ · aadfa516 · TheLedgerSealSharedTheKeyThatOpensTheCookiesTest · মাথার নিজের সিল: 5583a23c · TheBooksCouldBeEditedAndNobodyWouldKnowTest · লাইভ✓ · ⓘ বিবরণ আর কাগজের নম্বর সিলে নেই (রিপোর্টের বাড়তি কথা)
- [~] ভাড়া আর বেতন-চালানোর অদ্বিতীয় সূচক + তালা · তালা আর আবার পড়া: ভাড়া 11e65c95 · TheSecondClickPostedTheMoneyAgainTest · বেতন-চালান 5765ba5c · TwoPayrollRunsForTheSameMonthTest · লাইভ✓ · বাকি: অদ্বিতীয় সূচক (বেতনে ইচ্ছে করে নেই — বাতিল চালান থাকে)
- [ ] লাইভে SHOW INDEX-এর স্ক্রিপ্ট (চালাবেন abos-db)

**§৫ গতি**
- [x] নগদ পূর্বাভাসে কেবল বকেয়া থাকা কাগজ · 4978c2fe · TheForecastLoadedEveryPaidBillTest · লাইভ✓
- [~] মেনু-ব্যাজ ৫ মিনিট ক্যাশ + সূচক · ৫ মিনিট ক্যাশ: ea1fcbc0 · ANoticeThatRanAllDayAndNobodyReadItTest · লাইভ✓ · বাকি: সূচক (লাইভের স্কিমা বদল)
- [ ] ওপেনিং ব্যালেন্স SQL-এ, এক সহায়ক চার পর্দায়
- [x] শিকল-যাচাই `chunkById` · badddf67 · `OurOwnMigrationBrokeTheSealTest` (লাল ০/২ — reseal-ে ৫০১, verify-ে ১১১৫ সারি বাদ পড়ত — → সবুজ ২/২, পুরো ফাইল ৫/৫) · ⓘ reseal ও verify দুই জায়গাতেই; ক্রম বদলায় নি — আগেই orderBy(id), join নেই · লাইভ✓
- [~] `whereDate` সরানো + পাহারা · Core, Inventory, Accounts, Finance থেকে সরানো: 2a2daab5 + 46a5ce57 · TheDateFilterHidTheIndexFromMysqlTest, TheDateColumnsKeepTheirIndexTest · লাইভ✓ · বাকি: পুরো রিপোর পাহারা (এখনো ৪৫টা ফাইলে whereDate)
- [ ] DrillResolver-এর N+1
- [ ] বড় আমদানি, PDF আর এক্সপোর্ট কিউতে

**§৮ ভাষা**
- [ ] PostingException-এর বার্তা অনুবাদ হয়
- [ ] FinancePlan-এর লেখা ভাষার চাবিতে, ইংরেজিসহ

**অডিটের ঘরের বাইরে, একই দিনে (২৮ সেপ্টেম্বর)**
- [x] এক বিলের ফেরত অন্য বিলের জায়গা খেয়ে ফেলত, আর ফেরত বাতিলে স্তরে মাল পড়ে থাকত · 18d5bb00 · উপরের ঘরের সাথে একই কমিট, কারণ একই ফাইল · লাইভ✓
- [x] `12.5` লেখা সুদের হার `12.5000%` ছাপত, আর CC-র মার্জিন `30.00%` · 021a52a6 · `TheRateWasTypedAsTwelvePointFiveAndPrintedAsTwelvePointFiveThousandTest` (৬/৬, লাল ০/৪ → সবুজ) · লাইভ✓
- [x] দায়ের ঘরটা শুরুর ট্যাবে পর্দায় কখনো আসত না (Alpine গেট, `kind` শুরু `cc`) · e111fa54 · `TheDropdownOfferedTheWholeChartTest` (৫/৫, লাল ৩/৫ → সবুজ) · ⓘ পুরনো দাবিগুলো অন্ধ ছিল — Blade `template`-এর ভিতরটাও HTML-ে ছাপে · লাইভ✓
- [x] খালি তাকের দাম `'0'` আসত, স্কেল-৪ নয় · 2fc814a0 · `CostLayerTest::test_an_empty_shelf_reads_its_value_at_scale_four` (১১/১১) · লাইভ✓

## abos-26 (নিরাপত্তা, চালনা, মোবাইল)

- [x] ক §১.৭ — ব্যবহারকারীর পর্দা অন্য কোম্পানির সদস্যপদ ছোঁয় না · 1915a15a · TheUserScreenReachedAnotherCompanyTest · লাইভ✓
- [x] ক §৩ — রিপোর্টে শাখার সীমা + পাহারা · d7b6008d · AReportCrossedTheBranchWallTest, EveryReportStandsBehindTheBranchWallTest · লাইভ✓ · ⓘ রিপোর্টের চাবির সারাই শেষ: e171b421 (`EveryReportNamesTheKeyItsWebDoorAsksForTest`, ৮/৮ মিউট্যান্ট)
- [x] ক §৩ — গোপন লিংক কেবল নিজের দেয়ালের ভেতরের কাগজে · 5f8b30db · `ASharedLinkCrossedTheBranchWallTest` (লাল ৮/৮ → সবুজ, ৭/৭ মিউট্যান্ট) · ⓘ সাথে ফাঁকা-PDF বাগ · লাইভ✓ 004add63
- [x] ক §৩ — ব্যবহারকারীর ফর্মে কেবল নিজের কোম্পানি · 1915a15a (ফর্মে কেবল নাগালের কোম্পানি) · TheUserScreenReachedAnotherCompanyTest · লাইভ✓
- [x] ক §৩ — পোর্টাল লগইনে তালা, চেষ্টার খাতা, সমান সময় · 9597c26b · `ThePortalDoorHadNoLockTest` (৪টা মিউট্যান্ট মরা) · লাইভ✓
- [~] ক §৩ — অ্যাডমিনে MFA বাধ্যতামূলক, একই TOTP দুইবার নয় · একই TOTP দুইবার নয়: 9597c26b · `TheSameCodeOpenedTheDoorTwiceTest` (৪টা মিউট্যান্ট) · ⏸ বাধ্যতামূলক MFA মালিকের সিদ্ধান্তে (লাইভে মালিক আটকে যেতে পারেন)
- [x] ক §৩ — পাসওয়ার্ড ১২ অক্ষর + ফাঁস হওয়া তালিকায় যাচাই · 9597c26b · `AnEightLetterPasswordWasEnoughTest` (ছয় দরজা), `TheSuiteNeverAsksTheRealLeakListTest` · লাইভ✓
- [x] ক §৩ — কোম্পানিহীন লগ কেবল সুপার অ্যাডমিন দেখেন · e7ab9b90 · ALogWithNoCompanyWasEveryonesTest · লাইভ✓
- [x] ক §৯ — /health (ডাটাবেস, ডিস্ক, ব্যাকআপের বয়স), হেডার, `.env.production.example` · bb08ab72 · `TheHealthCheckOnlyAskedIfPhpWasAliveTest`, `TheCspSwitchVanishedUnderConfigCacheTest` (১১/১১ মিউট্যান্ট) · লাইভ✓ 004add63
- [x] ক §৯ — নিরাপত্তা-হেডার অজানা URL-এও (গ্লোবাল) · bb08ab72 · `TheBrowserWasNeverToldWhatNotToShareTest::test_a_url_that_matches_no_route_still_carries_every_header` · লাইভ✓ (৪০৪-এ হেডার)
- [ ] ক §১.১০ — বাইরের ব্যাকআপ রিপোতে, নিজস্ব client_id, এক ফোল্ডার, এক মেয়াদ, ৩৬ ঘণ্টায় সতর্ক
- [ ] ক §১.১০ — critical লগ মেইল বা ওয়েবহুকে, আপটাইম মনিটর
- [ ] ক §১.১০ — cPanel-এর `deploy-live.sh` (CI সবুজ না হলে থামে, ব্যর্থ হলে ফেরত)
- [ ] ক §৯ — RPO/RTO লেখা, মাসিক পুনরুদ্ধার-মহড়া
- [ ] ক §৬ — CI-তে MariaDB, PHPStan বেসলাইন বাড়লে লাল
- [~] ক §১০ — ফোনের নিজে-আপডেটের কোড, নয়তো চুক্তি ফেরত · সার্ভারের দিক: 271d754d + 63a86cac (sha256, আকার, https) · লাইভ✓ · বাকি: অ্যাপের কোড
- [~] ক §১০ — §১০-এর তিন রুট, flutter CI · তিন রুট: 8520617b · `ThePhoneCouldNotPrintWhatItSawTest` (২৭/২৭, ১৮টা মিউট্যান্ট) · বাকি: flutter CI

## abos-ca (অনুমোদন ও প্রোমোশন)

- [x] প্রোমোশন কমিট (১৩৩ পথ) · 869396c5 · বাকি: কুপন, কম্বো আর লয়্যালটির ১৪টা পর্দা ডিস্কে, জোড়া লাগানো হয়নি
- [x] ক §১.৩ ধাপ ১ — নতুন ফাইল (চুক্তি, ব্যতিক্রম, ডিফল্ট প্রবাহ, কমান্ড) · 54563671 · EveryMoneyActionGetsASignerTest · লাইভ✓
- [~] ক §১.৩ ধাপ ২ — প্রবাহ না থাকলে টাকার কাগজ থামে; POS নগদ বাদে সব টাকার কাজে সই; year_end আর journal-এ আরেকজন; maker ≠ checker · সিদ্ধান্ত ১ (শেষ সইয়ে নিজে পোস্ট) কাউন্টার-বিক্রিতে: 31e0738d, `TheSaleWaitedForAButtonAfterItsLastSignatureTest` ৯/৯ · বাকি: অন্য টাকার কাগজ, বাধ্যতামূলক সই, maker ≠ checker (প্যাকেজ scratchpad-এ) · নতুন কোম্পানিতে প্রবাহ নিজে আসে: 66a36a5c · ANewCompanyTookMoneyWithNobodyToSignTest · প্রতিনিধিও নিজের কাগজে সই দেন না: 82d50612 · AStandInCouldSignTheirOwnPaperOrSignTwiceTest · লাইভ✓ · বাকি (৪ অক্টোবর): প্রবাহ না থাকলে থামা — `NoApprovalFlow` কেউ ছোঁড়ে না, AMoneyPaperPostedWithNobodyToSignTest-এর দাবিগুলো incomplete
- [x] ক §৩ — `ApprovalFlowStep`-এ IsAudited · 5d0aac7a · `AStepChangeLeavesATrailTest` ৫/৫ (প্যাচের আগে ০/৫), ৪ মিউট্যান্ট মরেছে · লাইভ✓
- [x] ক §১১ — মজুদ সমন্বয় অনুমোদনের তালিকায় · fdba4441 · `AnAdjustmentWaitsForItsSignatureTest` ৩/৩ (ছক থাকলে সই চায়, তাক ও খাতা নড়ে না), ৩ মিউট্যান্ট মরেছে · লাইভ✓
- [~] ক §১১ — প্রোমোশন বিক্রয়ে জোড়া (চালান আর কাউন্টারে ছাড়), মেয়াদ-শেষের কাজ শিডিউলে · মেয়াদ-শেষ শিডিউলে: 869396c5 (`promotion:expire`) · বাকি: চালান আর কাউন্টারে জোড়া · ⓘ ৪ অক্টোবর: উপহার খাতায় খরচে 9b8f0d21; চালানে জোড়ার কাজ চলছে (ChallanOffers, কমিট হয়নি)
- [x] ক §৬ — অডিট-ট্রেইলের পাহারা `extends Authenticatable` দেখে · 70c7f3c9 · ৪/৪; User থেকে IsAudited তুললে লাল · লাইভ✓
- [x] ক §৬ — অবস্থার পাহারায় "কিছু পেয়েছি" গণনা + ইচ্ছাকৃত ভুল নমুনা · 86dc8e83 · ৬/৬; সত্যিকারের ফাইলে ভুল নমুনা বসালে লাল · লাইভ✓

## abos-32 (বিক্রয়কর্মী ও পরিচ্ছন্নতা)

- [~] ক §১.৯ — বিক্রয়কর্মী কেবল নিজের ডিলার; SR→ASM→RSM→DSM; মোবাইল-সিঙ্কে স্কোপ; Field Sales থেকে বাড়তি চাবি সরানো — নতুন ফাইল stage-এ, sandbox-এ সবুজ; জোড়া ব্যবসা চালুর পরে (চেকলিস্ট §৫)
  - ⓘ পাওয়া গেল, abos-d8, ২৮ সেপ্টেম্বর — `RouteAccountController::show()` রুটের **প্রতিটা** Customer দেখায়, তাদের বিক্রয় ও বাকিসহ — ডিলার-স্তরের কোনো দেয়াল নেই। ⓘ আজ নিয়মটা টিকে আছে **কেবল `sales.route.view` চাবিটা বিক্রয়কর্মীকে না দেওয়ায়** (DemoSeeder-এ বাদ-তালিকা; পাহারা `ARouteHadDealersButNoBooksTest::test_the_salesman_role_does_not_hold_the_route_key`, লাল-তারপর-সবুজ প্রমাণিত)। ⚠️ সংযোগটা এলে এই কোয়েরিটাও ছাঁকতে হবে, [[Lead::scopeVisibleTo()]]-এর মতো।
- [ ] ক §৮ — প্রতিটা মডিউলে ঘরের বাংলা/ইংরেজি নাম (`attributes`) + পাহারা — প্রস্তুত: ৬৫৪-র ৫৭৯ ঘর, ModuleAwareValidator, stage\attributes — ব্যবসা চালুর পরে
- [ ] ক §৮ — ব্যাকআপ মডিউলের কাঁচা চাবি আর শুধু-বাংলা বার্তা — প্রস্তুত: ৫টা কাঁচা চাবি ধরা পড়েছে, একই কাজে
- [ ] ক §৭ — ৫টা মৃত পর্দা মোছা, দুই অদৃশ্য পর্দা মেনুতে
- [~] ক §৬ — ক্রয়ের পুরো চক্র আর বেতন-চালানোর শুরু-থেকে-শেষ পরীক্ষা — ক্রয়-চক্র a493609a; বেতন (৭৯ দাবি) abos-d8-এর গোলকরণের সাথে

---

## ⏸ মালিকের কথায় পরে (আগে ডিপো চালু)

- [⏸] এইচআর — অগ্রিম, ঋণ, পিএফ, কর, চূড়ান্ত নিষ্পত্তি
- [⏸] রেস্তোরাঁর বাকি অংশ
- [⏸] ভ্যাট/মূসক, ল্যান্ডেড কস্ট
- [⏸] D-07 ওয়েবে অফলাইন (মালিকের সিদ্ধান্ত ঘ)
- [⏸] গিট ইতিহাস থেকে পুরনো ডাম্প মোছা (সবার ক্লোন বদলায় — মালিকের অনুমতি লাগবে)

## ❓ মালিকের সিদ্ধান্ত লাগবে

- [ ] ক — দশটা রূপ কমিয়ে একটা (নেভি)?
- [ ] খ — তালিকার ঘনত্ব ব্যবহারকারী নিজে বাছবেন?
- [ ] গ — কাউন্টারে বিক্রয় শেষে রসিদ নিজে থেকে ছাপা হবে?
- [ ] ঘ — ইন্টারনেট চলে গেলে কাউন্টার কী করবে?
