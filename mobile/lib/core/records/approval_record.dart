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

  /// What is being asked for — `confirm`, `cancel`, and so on.
  String? get action => _text('action');

  /// A decimal string (`decimal:4` on the server), never a number. Read as a
  /// number and a wrong key yields null, and null draws nothing — see [Money].
  double? get amount => Money.value(payload['amount']);

  int get currentLevel => (payload['currentLevel'] as num?)?.toInt() ?? 0;

  DateTime? get requestedAt {
    final raw = _text('requestedAt');
    return raw == null ? null : DateTime.tryParse(raw)?.toLocal();
  }

  String? get requesterName => _text('requesterName');

  String? get summary => _text('summary');

  /// The document kind in the language the rest of this app speaks. An
  /// unfamiliar type is shown as it came rather than hidden — a document this
  /// build has not heard of is still a document somebody is waiting on.
  String get documentTypeLabel => switch (documentType) {
        'PurchaseOrder' => 'ক্রয় আদেশ',
        'PurchaseBill' => 'ক্রয় বিল',
        'PurchaseReturn' => 'ক্রয় ফেরত',
        'Payment' => 'পরিশোধ',
        'StockTransfer' => 'মজুদ স্থানান্তর',
        'Payroll' => 'বেতন',
        _ => documentType.isEmpty ? 'নথি' : documentType,
      };

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
