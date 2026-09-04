import 'dart:convert';

import '../../core/sync_engine/reference_cache.dart';
import '../../core/sync_engine/sync_engine.dart';

/// What "নতুন করে লিখুন" on a rejected order hands to [NewOrderScreen].
///
/// <p><b>Why re-entry, not resend</b> — the coordinating session's own
/// framing: the server only refuses a `SalesOrder` for a business reason
/// (price, stock, credit limit, permission — docs/Contract §০/§১), checked
/// at the moment of sync. Resending the identical payload asks the same
/// question the server already answered no to; nothing about it has
/// changed. What has to change is the order itself — a different quantity,
/// a different price agreed with the shop — and only a person standing in
/// front of the shop can decide that. This class carries the *starting
/// point* for that decision (who, what was in it) so the rep is not
/// retyping a whole order from memory, not a payload this screen will ever
/// send unmodified.
class OrderPrefill {
  const OrderPrefill({
    required this.customerId,
    required this.items,
    required this.supersedesRejectedKey,
  });

  final String customerId;

  /// (productId, quantity) pairs — see `new_order_screen.dart`'s own reading
  /// of a queued `SalesOrder` payload for where these come from.
  final List<(String, int)> items;

  /// The Hive key of the rejected row this retry replaces, passed straight
  /// through to [SyncEngine.dismissRejected] once the new order is actually
  /// queued — not before: a rep who opens this screen and backs out without
  /// submitting must still see the original rejection, unresolved.
  final dynamic supersedesRejectedKey;
}

/// What a rejected `SalesOrder` looks like once named — see
/// [RejectedChange.payloadJson]'s own doc comment for why decoding it lives
/// here (a screen that knows what a sales order is) rather than inside
/// `sync_engine.dart` (which deliberately does not).
///
/// <p><b>Only what a rep needs to act, never the raw payload</b> — see the
/// coordinating session's own instruction: "কোন দোকান · কোন অর্ডার · কবে ·
/// কেন" on screen, not the JSON underneath it.
class RejectedOrderSummary {
  const RejectedOrderSummary({
    required this.customerName,
    required this.itemCount,
    required this.enqueuedAt,
    required this.reason,
    required this.prefill,
  });

  final String customerName;
  final int itemCount;
  final DateTime enqueuedAt;
  final String reason;
  final OrderPrefill prefill;

  /// Null if [item] is not a `SalesOrder`, or its payload cannot be read —
  /// a screen falls back to the bare reason string it already had, rather
  /// than crash on a row this decoding was never guaranteed to understand
  /// (the contract explicitly allows the payload shape to gain fields no
  /// current build recognises; see docs/Contract §৩ rule খ).
  static RejectedOrderSummary? fromRejected(RejectedChange item) {
    if (item.entityType != 'SalesOrder') return null;
    try {
      final decoded = jsonDecode(item.payloadJson) as Map<String, dynamic>;
      final customerId = decoded['customerId']?.toString() ?? '';
      final itemRows = (decoded['items'] as List?) ?? const [];
      final items = itemRows
          .whereType<Map>()
          .map((row) => (
                row['productId']?.toString() ?? '',
                ((row['quantity'] as num?) ?? 1).toInt(),
              ))
          .toList();

      final customer = ReferenceCache.instance.get('Customer', customerId);
      final customerName = customer?['name']?.toString() ?? 'অজানা গ্রাহক';

      return RejectedOrderSummary(
        customerName: customerName,
        itemCount: items.length,
        enqueuedAt: item.enqueuedAt,
        reason: item.reason,
        prefill: OrderPrefill(
          customerId: customerId,
          items: items,
          supersedesRejectedKey: item.key,
        ),
      );
    } catch (_) {
      return null;
    }
  }
}
