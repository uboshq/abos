import 'package:intl/intl.dart';

import '../sync_engine/reference_cache.dart';
import 'customer_record.dart';
import 'money.dart';
import 'product_record.dart';

/// An order the server has confirmed back to this phone, as
/// `SalesOrderSync::pull()` sends it.
///
/// <p>`SalesOrder` is the one entity in the catalogue that travels both ways
/// (docs/Contract §২), so its two shapes are both here: this class reads what
/// comes down, [SalesOrderDraft] writes what goes up.
///
/// <p>The order screen used to read `orderNumber` and `number`; the server
/// sends `documentNo`. So every order that came back — the ones that had made
/// it, the ones whose number is the whole point of the trip — displayed
/// "নম্বর নেই". That is the owner's second decision (docs/Contract §০) failing
/// in exactly the place it was meant to land: the rep cannot say the number
/// offline, and then could not say it after sync either.
class SalesOrderRecord {
  const SalesOrderRecord(this.payload);

  final Map<String, dynamic> payload;

  static const String entityType = 'SalesOrder';

  String get id => (payload['id'] ?? '').toString();

  /// The real number, assigned by the server at sync — null only for a row
  /// that somehow arrived without one.
  String? get documentNo => _text('documentNo');

  String get customerId => (payload['customerId'] ?? '').toString();

  /// The name if this phone has the shop cached, the bare id if not: an order
  /// can outlive a customer dropping out of the local catalogue.
  String get customerName =>
      CustomerRecord.byId(customerId)?.name ?? 'অজানা গ্রাহক';

  DateTime? get trxDate => _date('trxDate');

  DateTime? get deliverOn => _date('deliverOn');

  String? get status => _text('status');

  double? get total => Money.value(payload['total']);

  String? get narration => _text('narration');

  /// `DocumentStatus`'s four constants, in the language the rest of this app
  /// speaks. An unknown value is shown as it came rather than hidden — a
  /// status this build has not heard of is information, not an error.
  String get statusLabel => switch (status) {
        'draft' => 'খসড়া',
        'confirmed' => 'নিশ্চিত',
        'cancelled' => 'বাতিল',
        'closed' => 'বন্ধ',
        _ => status ?? '—',
      };

  String? _text(String key) {
    final value = payload[key];
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }

  DateTime? _date(String key) {
    final text = _text(key);
    return text == null ? null : DateTime.tryParse(text);
  }

  static List<SalesOrderRecord> all() => ReferenceCache.instance
      .allOf(entityType)
      .map(SalesOrderRecord.new)
      .toList();
}

/// One line of an order being written on the phone.
class SalesOrderDraftLine {
  SalesOrderDraftLine({required this.product, this.quantity = 1});

  final ProductRecord product;
  int quantity;

  String get productId => product.id;

  /// The price the rep was shown, passed back in the characters it arrived
  /// in — see [Money]. The server measures this against today's price with
  /// its own tolerance rule and may refuse the order for it; that refusal is
  /// only meaningful if the figure it measures is the figure on the screen.
  String? get rate => product.salePriceRaw;

  double get lineTotal => (product.salePrice ?? 0) * quantity;
}

/// An order on its way up, in the shape `SalesOrderSync::apply()` reads.
///
/// <p><b>This is the bug this file was written for.</b> The screen was
/// queueing `{customerId, items: [{productId, quantity}], note}`. The server
/// reads `$payload['lines']`, each with `qty` and `rate`, and `narration` —
/// and having found no `lines`, refuses:
///
/// ```php
/// if ($lines === []) {
///     throw new SyncRejection(__('sales::sync.order_has_no_lines'));
/// }
/// ```
///
/// So **every order this app has ever queued would come back REJECTED**, with
/// a reason about empty lines that names nothing the rep did wrong and gives
/// them nothing to change. The screen's own doc comment was honest that its
/// payload was "this screen's own best reading of the contract, not a field
/// list confirmed against a live response" — it was read from the handler
/// this time, and the keys are pinned by `test/payload_contract_test.dart`.
///
/// <p>Only `customerId` and `lines` are required; the rest the server defaults.
class SalesOrderDraft {
  SalesOrderDraft({
    required this.customerId,
    required this.lines,
    this.narration,
    this.trxDate,
  });

  final String customerId;
  final List<SalesOrderDraftLine> lines;
  final String? narration;

  /// The day the order was actually taken, not the day it managed to sync.
  ///
  /// <p>The server falls back to `now()` when this is absent — which for an
  /// order written on Friday in a village with no signal and drained on
  /// Monday morning would date it Monday. The whole point of the offline
  /// queue is that the gap between those two days can be long, so the phone
  /// sends the day it knows.
  final DateTime? trxDate;

  static final DateFormat _wireDate = DateFormat('yyyy-MM-dd');

  Map<String, dynamic> toPayload() => {
        'customerId': customerId,
        if (trxDate != null) 'trxDate': _wireDate.format(trxDate!),
        'lines': [
          for (final line in lines)
            {
              'productId': line.productId,
              'qty': line.quantity,
              if (line.rate != null) 'rate': line.rate,
            },
        ],
        if (narration != null && narration!.trim().isNotEmpty)
          'narration': narration!.trim(),
      };

  /// Reads back an order already sitting in the queue — what a rejected row
  /// is re-opened from.
  ///
  /// <p>The `items`/`quantity` fallback is for a row queued by a build from
  /// before this file: those rows are on the handsets that ran the September
  /// emulator round, they are precisely the rows that were rejected, and a
  /// rep re-opening one should see their order rather than an empty form.
  /// New rows are never written in that shape.
  static ({String customerId, List<(String, int)> lines})? fromPayload(
      Map<String, dynamic> payload) {
    final customerId = payload['customerId']?.toString() ?? '';
    final rows = (payload['lines'] as List?) ?? (payload['items'] as List?);
    if (rows == null) return null;

    final lines = rows
        .whereType<Map>()
        .map((row) => (
              row['productId']?.toString() ?? '',
              ((row['qty'] ?? row['quantity']) as num?)?.toInt() ?? 1,
            ))
        .where((line) => line.$1.isNotEmpty)
        .toList();

    return (customerId: customerId, lines: lines);
  }
}
