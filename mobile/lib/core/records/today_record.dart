import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../sync_engine/reference_cache.dart';
import 'money.dart';

/// How the day is going — docs/Contract §৮.
///
/// <p>Shape agreed in writing before either half was built, the ordering this
/// app keeps since the six payload bugs of 12 September.
class TodayRecord {
  const TodayRecord(this.payload);

  final Map<String, dynamic> payload;

  /// The business day **the server** says it is. Not the phone's: a phone's
  /// clock can be changed, and a business day need not end at midnight.
  String? get date => _text('date');

  /// ⚠️ Shown on screen, always. Somebody can belong to more than one company
  /// and this app has no way to switch — figures from the wrong company,
  /// unlabelled, are figures somebody acts on.
  String? get company => _text('company');

  String? get branch => _text('branch');

  /// When the server produced these numbers. A stale figure looks exactly
  /// like a fresh one, so the screen says which it is.
  DateTime? get asOf {
    final raw = _text('asOf');
    return raw == null ? null : DateTime.tryParse(raw)?.toLocal();
  }

  TodayFigure? get sales => _tile('sales');
  TodayFigure? get collections => _tile('collections');
  TodayFigure? get cashInHand => _tile('cashInHand');
  TodayFigure? get dues => _tile('dues');

  int? get pendingApprovals {
    final block = payload['approvals'];
    if (block is! Map) return null;
    return (block['pending'] as num?)?.toInt();
  }

  /// <p><b>Absent is not zero.</b> A person without `accounts.till.view` gets
  /// no `cashInHand` key at all — not null, not "0" — exactly as
  /// `purchasePrice` is omitted from the product payload. Zero is a number
  /// somebody acts on: "no cash today" and "you may not see the cash" are
  /// different sentences, and drawing the first for the second is a lie the
  /// screen tells confidently.
  TodayFigure? _tile(String key) {
    final block = payload[key];
    if (block is! Map) return null;
    return TodayFigure(
      amount: Money.value(block['amount']),
      count: (block['count'] as num?)?.toInt(),
      shops: (block['shops'] as num?)?.toInt(),
    );
  }

  String? _text(String key) {
    final value = payload[key];
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }
}

/// One number on the page, and whatever comes with it — a count of documents
/// for sales and collections, a count of shops for dues, nothing for cash.
class TodayFigure {
  const TodayFigure({this.amount, this.count, this.shops});

  final double? amount;
  final int? count;
  final int? shops;
}

/// `GET /dashboard/today`, with the last answer kept on the phone.
///
/// <p>⚠️ **The endpoint does not exist yet** — requested, with the shape
/// written down first rather than guessed.
///
/// <p>Not part of sync: no queue, no watermark, and nothing here is ever
/// pushed. But the last answer is cached, because on a phone with no signal
/// "this is how the morning looked" beats an empty screen — provided it says
/// *morning*, which is what `asOf` is for.
class TodayApi {
  const TodayApi._();

  static const String _cacheType = 'TodaySummary';
  static const String _cacheId = 'latest';

  static Future<TodayRecord> fetch() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/dashboard/today',
      options: Options(extra: const {'abosAllowStale': true}),
    );
    final body = response.data ?? const <String, dynamic>{};

    await ReferenceCache.instance.put(
      entityType: _cacheType,
      entityId: _cacheId,
      payload: body,
      updatedAt: DateTime.now(),
    );

    return TodayRecord(body);
  }

  /// What the phone last heard, or null if it has never heard anything.
  static TodayRecord? lastKnown() {
    final payload = ReferenceCache.instance.get(_cacheType, _cacheId);
    return payload == null ? null : TodayRecord(payload);
  }
}
