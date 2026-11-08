import '../locale/app_locale.dart';
import 'money.dart';

/// One document waiting for a decision, as `GET /approvals/pending` sends it.
///
/// <p>Field list from docs/Contract §৫, agreed in writing with the server side
/// on 13 September 2026 **before either half was built** — which is the whole
/// point. The six payload bugs found the day before all came from the same
/// place: screens written from a guess at a shape nobody had agreed, passing
/// every test for a month while drawing nothing.
///
/// <p>Pinned by `test/payload_contract_test.dart` the day the endpoint lands;
/// until then there is no PHP file to read, and a pin that matches nothing
/// would pass while proving nothing.
class ApprovalRecord {
  const ApprovalRecord(this.payload);

  final Map<String, dynamic> payload;

  /// The `public_id` — docs/Contract §৩ rule ক: a sequential id can be
  /// counted, so it never travels.
  String get id => (payload['id'] ?? '').toString();

  /// `PurchaseBill`, `PurchaseOrder`, `PurchaseReturn`, `Payment`,
  /// `StockTransfer` — the model name, in English, as the server files it.
  String get documentType => _text('documentType') ?? '';

  String? get documentNo => _text('documentNo');

  /// The document's own `public_id` — what `GET /documents/{type}/{id}/pdf` takes (docs/Contract §১০). Absent for a
  /// request whose paper is gone; then there is nothing to open.
  String? get documentId => _text('documentId');

  /// What is being asked for — `confirm`, `cancel`, and so on.
  String? get action => _text('action');

  /// A decimal string (`decimal:4` on the server), never a number. Read as a
  /// number and a wrong key yields null, and null draws nothing — see [Money].
  double? get amount => Money.value(payload['amount']);

  /// ⛔ অঙ্কটা কী মাপে — ছাড়ের সইয়ে অঙ্কটা ছাড়, বিলের মোট নয় (৪৮,০০০-এর বিলে ৫০০ ছাড় কার্ডে "৳৫০০" পড়ত, যেন ৫০০-র বিল;
  /// ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)। ওয়েবের ইনবক্সে কাজের ঘর আছে, ফোনে ছিল না।
  String? get amountText {
    if (amount == null) return null;
    final taka = Money.taka(amount);
    return action == 'discount' ? AppLocale.l10n.approvalCardDiscount(taka) : taka;
  }

  /// কার্ডে যে অঙ্ক দেখানো — হুবহু সার্ভারের লেখা; সইয়ের সাথে ফেরত যায় ([[ApprovalsApi.approve]])
  String? get rawAmount => Money.raw(payload['amount']);

  int get currentLevel => (payload['currentLevel'] as num?)?.toInt() ?? 0;

  DateTime? get requestedAt {
    final raw = _text('requestedAt');
    return raw == null ? null : DateTime.tryParse(raw)?.toLocal();
  }

  String? get requesterName => _text('requesterName');

  String? get summary => _text('summary');

  /// ⭐ কার কাগজ — গ্রাহক হলে "নাম · পয়েন্ট", নয়তো সরবরাহকারী/কর্মী/ব্যক্তি; নম্বরের লাইনে বসে (মালিক, ১৯ অক্টোবর ২০২৬)।
  /// ⚠️ পুরনো সার্ভার পাঠায় না — তখন null, আর কার্ড আগের মতো সারাংশেই পক্ষ দেখায়।
  String? get party => _text('party');

  // ── ⭐ ইনবক্সের ঘর — `GET /approvals/inbox` (অনুমোদন ভাগ ৩, দফা ২১; পরিকল্পনার পাতা ৬)। লেখা আর রং সার্ভারের — ওয়েবের
  // ইনবক্স যেখান থেকে পড়ে ([[ApprovalInbox]]); ফোন নিজে কিছু হিসাব করে না। পুরনো সার্ভারের `pending` সারিতে এগুলো নেই — তখন null/false,
  // আর কার্ড আগের মতো আঁকে।

  /// `pending` · `approved` · `rejected` · `returned` …; পুরনো সার্ভারে null
  String? get status => _text('status');

  /// অবস্থার নাম, সার্ভারের ভাষায় ("অনুমোদিত")
  String? get statusLabel => _text('statusLabel');

  /// শেষ সময়ের লেখা — "শেষ সময় পেরিয়েছে ৩ ঘণ্টা", "আজ ৫:০০"; ঘড়ি নেই বা অপেক্ষায় নয় ⇒ null
  String? get deadlineText => _part('deadline', 'text');

  /// `danger` (দেরি) · `warning` (কাছে) · `success` (সময় আছে)
  String? get deadlineTone => _part('deadline', 'tone');

  /// ধাপ "২ / ৩" — কত নম্বর ধাপ আর মোট কয়টা; না জানা ⇒ null
  int? get stepAt => (_map('step')?['at'] as num?)?.toInt();
  int? get stepOf => (_map('step')?['of'] as num?)?.toInt();
  String? get stepName => _part('step', 'name');

  /// দায়িত্ব হস্তান্তরে কার হয়ে
  String? get standInFor => _text('standInFor');

  /// ⛔ একসাথে অনুমোদনে বাছা যায় কি না — সার্ভার বলে (টাকা নড়ার কাজে কখনো নয়); না বললে না
  bool get bulkable => payload['bulkable'] == true;

  // ── ⭐ নতুন ইনবক্সের ঘর — মালিকের নমুনা "অনুমোদনের নতুন ইনবক্স" v4 ("ok", ৮ নভেম্বর ২০২৬); সার্ভার 6c75e093c থেকে পাঠায়।
  // ⚠️ পুরনো সার্ভারে নেই — তখন শূন্য/null, আর কার্ডে চিহ্নগুলো আসে না।

  /// কাগজে কয়টা সংযুক্তি — কার্ডে "📎 3"
  int get attachmentCount => (payload['attachments'] as num?)?.toInt() ?? 0;

  /// চাওয়ার পর কাগজের অঙ্ক বদলেছে — কার্ডে হলুদ সতর্কতা
  bool get amountChanged => payload['amountChanged'] == true;

  /// যিনি চেয়েছেন তার লেখা কারণ
  String? get reason => _text('reason');

  /// "দিয়েছি" ট্যাবে সিদ্ধান্তের সময়
  DateTime? get decidedAt {
    final raw = _text('decidedAt');
    return raw == null ? null : DateTime.tryParse(raw)?.toLocal();
  }

  /// "ফেরত পাঠান" — কেবল সার্ভার যেখানে বলে (নমুনার দফা ১০); পুরনো সার্ভার ঘরটা পাঠায় না ⇒ আগের মতো সবখানে
  bool get canSendBack => payload['canSendBack'] != false;

  /// ⭐ কার্ডের রং আর চিহ্নের বিভাগ — সার্ভারের `module` (কাজের চিপের একই চাবি); পুরনো সার্ভারে কাগজের ধরন থেকে
  String get moduleKey => _text('module') ?? switch (documentType) {
        'Voucher' || 'Payment' || 'MoneyTransfer' || 'Note' => 'accounts',
        'SalesOrder' || 'SalesInvoice' || 'DeliveryOrder' || 'DeliveryChallan' || 'SalesQuotation' || 'SalesReturn' || 'Collection' => 'sales',
        'PurchaseOrder' || 'PurchaseBill' || 'PurchaseReturn' => 'purchase',
        'ExpenseClaim' || 'Payroll' => 'hr',
        'StockTransfer' => 'inventory',
        'Promotion' => 'promotion',
        'CustomerCreditRequest' => 'credit_control',
        _ => '',
      };

  Map<String, dynamic>? _map(String key) => switch (payload[key]) {
        final Map<dynamic, dynamic> m => m.cast<String, dynamic>(),
        _ => null,
      };

  String? _part(String key, String inner) {
    final text = _map(key)?[inner]?.toString().trim();
    return text == null || text.isEmpty ? null : text;
  }

  /// The document kind in the language the rest of this app speaks. An
  /// unfamiliar type is shown as it came rather than hidden — a document this
  /// build has not heard of is still a document somebody is waiting on.
  String get documentTypeLabel {
    final w = AppLocale.l10n;
    return switch (documentType) {
        'PurchaseOrder' => w.approvalDocPurchaseOrder,
        'PurchaseBill' => w.approvalDocPurchaseBill,
        'PurchaseReturn' => w.approvalDocPurchaseReturn,
        'Payment' => w.approvalDocPayment,
        'StockTransfer' => w.approvalDocStockTransfer,
        'Payroll' => w.approvalDocPayroll,
        // ⓘ বাকিগুলোও বাংলায় — মালিক কেবল বাংলা পড়েন; আগে "Voucher" ইংরেজিতে দেখাত
        'Voucher' => w.approvalDocVoucher,
        'DeliveryOrder' => w.approvalDocDeliveryOrder,
        'SalesOrder' => w.approvalDocSalesOrder,
        'SalesInvoice' => w.approvalDocSalesInvoice,
        'DeliveryChallan' => w.approvalDocDeliveryChallan,
        'SalesQuotation' => w.approvalDocSalesQuotation,
        'SalesReturn' => w.approvalDocSalesReturn,
        'Collection' => w.approvalDocCollection,
        'ExpenseClaim' => w.approvalDocExpenseClaim,
        'Note' => w.approvalDocNote,
        'MoneyTransfer' => w.approvalDocMoneyTransfer,
        'Promotion' => w.approvalDocPromotion,
        // ⭐ বাকির সীমা বাড়ানোর অনুরোধ — মালিকের সইয়ে (বাকি নিয়ন্ত্রণ, ১৩ অক্টোবর ২০২৬)
        'CustomerCreditRequest' => w.approvalDocCustomerCreditRequest,
        _ => documentType.isEmpty ? w.approvalDocUnknown : documentType,
      };
  }

  /// <b>Salary never reaches a phone.</b> The owner's decision of 13
  /// September 2026, and docs/Contract §৪ before it: payroll is approved at
  /// the desk.
  ///
  /// <p>The server filters these out in the query, and that is the real gate —
  /// this is the second lock, the same shape as [SyncEngine]'s offline-write
  /// guard. It exists because the leak here would be quiet and expensive: the
  /// endpoint is called "approvals", not "payroll", so a salary amount
  /// arriving through it would look like an ordinary row to every screen that
  /// drew it, and to every person reviewing the code.
  ///
  /// <p>⚠️ It follows that this filter must never be the thing that *detects*
  /// a server-side mistake — a silent drop teaches nobody. The server has its
  /// own test for that, asserting both halves: a payroll approval that does
  /// not appear, and a non-payroll one that does.
  bool get isPayroll => documentType == 'Payroll';

  String? _text(String key) {
    final value = payload[key];
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }
}
