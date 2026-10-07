import '../sync_engine/reference_cache.dart';
import 'money.dart';
import 'product_record.dart';

/// What is on the shelf, as `StockOnHandSync` actually sends it.
///
/// <p>Field list copied from
/// `app/Modules/Inventory/Sync/StockOnHandSync.php`'s `payload:` array. The
/// screen used to read `productName`, `warehouseName`, `quantity` and `unit`
/// — four keys, none of which exist in that array. Every row read "নাম নেই"
/// and every quantity read 0, which on a stock screen is not a cosmetic
/// failure: zero is a number a person acts on.
///
/// <p><b>The row carries no product name, and that is correct.</b> The
/// `entityId` is the product's own `public_id`, so the name comes from the
/// cached `Product` — the server does not send the same name twice, once per
/// stock row. [product] is that lookup.
///
/// <p><b>Why five figures and not one.</b> The handler's own comment says it:
/// a rep who sees 100 on the shelf but 40 sellable has a right to know where
/// the other 60 went — held on someone else's order, or blocked — because
/// sending only the sellable figure sends them back to the shop saying "স্টক
/// নাই". So the screen shows the breakdown, not just [available].
class StockRecord {
  const StockRecord(this.payload);

  final Map<String, dynamic> payload;

  static const String entityType = 'StockOnHand';

  /// The product's `public_id` — this record's own id as well.
  String get productId => (payload['productId'] ?? '').toString();

  /// Everything physically on the shelf, committed or not.
  double get floor => Money.valueOrZero(payload['floor']);

  /// Spoken for by a confirmed order.
  double get reserved => Money.valueOrZero(payload['reserved']);

  /// Blocked — damaged, quarantined, or awaiting a decision.
  double get hold => Money.valueOrZero(payload['hold']);

  /// What can actually be sold: floor less reserved less hold. Computed on
  /// the server with bcmath and sent, not recomputed here from three doubles.
  double get available => Money.valueOrZero(payload['available']);

  /// The free-issue half of [available] — stock that came in as a gift with a
  /// purchase rather than bought.
  double get freeAvailable => Money.valueOrZero(payload['freeAvailable']);

  /// True when the breakdown says something [available] alone does not.
  bool get hasCommitments => reserved != 0 || hold != 0;

  ProductRecord? get product => ProductRecord.byId(productId);

  static List<StockRecord> all() => ReferenceCache.instance
      .allOf(entityType)
      .map(StockRecord.new)
      .toList();

  static StockRecord? forProduct(String productId) {
    if (productId.isEmpty) return null;
    final payload = ReferenceCache.instance.get(entityType, productId);
    return payload == null ? null : StockRecord(payload);
  }
}
