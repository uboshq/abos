# Handover: customer collection screen ("টাকা নেওয়া") — a5 → a6, 21 Oct 2026

a5 stopped on the weekly limit. fe gave this work to a6.

## Where things are
- **Branch:** `a5/collection-form`, worktree `E:\ABOS\a5-cf`.
  - It started from `cb/web-bundle-21` 2b174fa70; move it onto main once web-bundle-21 is on main.
  - Head before this note: `cfaa4c1de`.
- **Contract (read first, fully):** `C:\Users\alami\AppData\Local\Temp\claude\e--ABOS-abos\7f0af7e4-077e-431d-8092-efaea60c2531\scratchpad\collection_contract.md`. It holds:
  - the file ownership split (server and web);
  - the shared service signatures;
  - the server rules by দফা;
  - the phone API (names already taken by a4);
  - cb's `x-money.method` props;
  - **fe's answers D1–D4, which override the original proposals**.
- **Design:** `E:\ABOS\মালিকের-ফাইল\টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-২১-অক্টোবর.pdf`.
  - The collection screen sits under the page-1 title. As a rendered image it is scratchpad `design100_p2.png`.
  - The 25-point list is in `design100_p5.txt` / `design100_p6.txt`, in the same scratchpad.
- **Owner's order:** match the design 100%, label by label: every field, name and order, the three right cards and the three header figures. Also:
  - a match list (`collection_match.md`, one row per design element);
  - a 1366px side-by-side image of the design and the real page.
- **Rules that apply:**
  - a claim for every rule, with its দফা number in the test's docblock;
  - mutants;
  - the Architecture, Design and CSP vitest suites;
  - tests run on the Mac only. fe gave the Mac run name **a5cf** priority; tell fe the name you use.
- **The existing code, mapped:**
  - CollectionService: create, confirm (lines Dr account / Dr charge / Cr 1110 customer), cancel, oldestFirst, replaceLines.
  - CollectionController plus `collection/form.blade.php` (the old "লাইন / আরেকটা লাইন" table).
  - `resources/js/components/documents.js` `invoiceCollection`.
  - `SalesPaperOverview::collection` (the confirm popup).
  - `CollectionApiController` (setup, index, show).
  - `CollectionSync::apply`.
  - `SlipIsNotUsedTwice`.
  - `AttachmentEngine` (slip types, 5 MB).
  - `SalesPrintController::receipt` plus `AmountInWords`.

## Done (committed)
- `14a30fd64`: merged `cb/money-form` 2840c3a67 (cb step A). It brings:
  - `App\Core\Support\MoneyMethod`, with `rules('bank_charge')`, `onlyItsOwn($data, 'bank_charge')` and `assertReadyToPost($collection)`;
  - `<x-money.method>`, `resources/js/components/money.js` and `lang/*/money.php`;
  - the `value_date`, `transfer_mode_id`, `deposit_slip_no` and `from_bank` columns;
  - the collection MFS charge moved to 5211.
- `cfaa4c1de` (WIP, untested, `php -l` clean):
  - `StandardChart`: new accounts.
    - 1138 `VDS_RECEIVABLE` "উৎসে কাটা ভ্যাট পাওনা" (D2).
    - 4120 `COLLECTION_DISCOUNT` "আদায়ে বিক্রয় ছাড়", contra-revenue (D3).
    - 5305 `ROUNDING_DIFFERENCE` "রাউন্ডিং পার্থক্য" (D3).
    - The migration that adds them for existing companies: `app/Modules/Accounts/Database/Migrations/2027_03_06_090000_what_the_customer_withheld_had_no_account.php`.
  - `sal_collections` gets vds, ait, difference_kind, difference_amount, difference_account_id and unapplied: `app/Modules/Sales/Database/Migrations/2027_03_06_100000_a_collection_could_not_say_what_the_customer_withheld.php`, with the casts and fillable fields in `Collection.php`.
  - `sal_collection_applications` (collection_id, kind, source_id, target_invoice_id, amount): `…/2027_03_06_110000_a_credit_note_could_not_pay_a_bill.php`, model `CollectionApplication.php`.
  - `SalesInvoice`:
    - new `clearedByCredits()`;
    - `collectedAmount()` and the `withCollected` SQL now add the posted applications, so the bill page, CreditExposure and SalesPartyOpenBills agree (দফা ৩).
    - ⚠️ Check CreditExposure::openBillRows and SalesPartyOpenBills really read `collected_total`. If one sums on its own, add the applications there.
  - `CollectionInput` (DTO): `fromRequest()` and `fromPhone()` (accepts a4's `{invoice, amount}` as kind=bill), `grossToCustomer()`, `differenceAmount/Kind()`, `paperFields()`. It maps onto cb's column names (instrument, instrument_no, value_date…).
    - ⚠️ Review it against the contract before building on it; it was written just before the stop.

## Not started
- **Server:**
  - `CollectionOpenItems::for()` and `CollectionPreview::of()`.
  - `CollectionService::createFrom/updateFrom`, with ONE private line builder shared by confirm() and the preview. Its lines are:
    - Dr account (net of charge);
    - Dr charge (cb's line, leave it as is);
    - Dr 1138 VDS;
    - Dr 1135 AIT;
    - Dr 4120 discount, which needs the owner's signature through the `sales.discount` flow;
    - Dr 5305 rounding, within the setting `sales.collection.rounding_limit` (default 10), no signature;
    - Cr 1110 customer for the gross amount;
    - unapplied stays Cr on the customer, never an advance account.
  - Credit application (a clearing, no money posting).
  - Duplicate reference per money account, with a link to the earlier paper (দফা ৭).
  - Cancel: needs a reason and a second person's signature, and reverses on the cancel day (দফা ১২).
  - Closed month (১৩).
  - The `attachment_required` setting (১৫).
  - The audit (১৬).
  - SalesMetrics: net sales = 4100 − 4110 − 4120, with a claim (D3).
  - **D4 inter-branch: do NOT build.** The owner hasn't decided; keep today's refusal of another branch's money account, with a Bengali reason, and claim it.
- **Phone API** (a4 is building against these exact names):
  - `GET api/v1/sales/collections/open-bills?customer=`;
  - `POST collections/preview`;
  - the new sync fields and answer fields;
  - `POST collections/{id}/attachment`.
- **Web:**
  - the whole screen per the design and the contract's "Web screen" section;
  - the real `<x-money.method>`, already merged; no stand-in is needed now;
  - the JSON routes `open-items` and `preview`;
  - the receipt showing words, applied bills, VDS/AIT, difference and unapplied;
  - the claim `TheCollectionScreenIsDrawnLikeTheDesignTest`, which dumps the page HTML to `storage/app/screens/collection-form.html` when `ABOS_DUMP_SCREEN=1`, for the side-by-side image.
- **Claims and mutants:** none yet. The mutant files go to the scratchpad `gmut\cf_*.diff` / `cfw_*.diff`, or to a6's own folder.

## Merge watch
- `a5/net-sales-2` (cb1290b82, not yet run on the Mac) also changes SalesMetrics; merge with care.
- `a5/next-bundle` 102729da0 fixes three raw queries without a company filter (HomeFilter, Snapshots, SalesBasis).
- a4 is waiting for the server hash and the Mac result to check the app's claims against the real answers.
