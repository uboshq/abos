import '../sync_engine/reference_cache.dart';
import 'money.dart';

/// One product, as `ProductSync` actually sends it.
///
/// <p>Field list copied from `app/Modules/Inventory/Sync/ProductSync.php`'s
/// `payload:` array; see [CustomerRecord]'s doc comment for why a typed
/// reader exists at all and what reading the wrong keys cost before it did.
/// On this entity it cost more than a name: the screen read `salesPrice` and
/// `price`, the server sends `salePrice`, so **the selling price did not
/// appear on the catalogue screen at all** — and the same wrong key put a
/// null rate on every order the phone queued.
class ProductRecord {
  const ProductRecord(this.payload);

  final Map<String, dynamic> payload;

  static const String entityType = 'Product';

  String get id => (payload['id'] ?? '').toString();

  String? get code => _text('code');

  String? get barcode => _text('barcode');

  /// Bengali first — see [CustomerRecord.name] for why.
  String get name => _text('nameBn') ?? _text('nameEn') ?? code ?? 'নাম নেই';

  /// The unit as a person says it ("পিস", "কার্টন"), falling back to the code
  /// the office files it under.
  String? get unit => _text('unitNameBn') ?? _text('unitCode');

  /// The characters the server sent, for the rate on a queued order line.
  /// Never the rendered form — see [Money] for why that distinction is not
  /// fussiness.
  String? get salePriceRaw => Money.raw(payload['salePrice']);

  double? get salePrice => Money.value(payload['salePrice']);

  /// <b>Absent, not null and not zero.</b> The server omits the key entirely
  /// for anyone without `inventory.cost.view` (docs/Contract §৩ rule ঙ), so
  /// the test is for the key, never for the value.
  bool get hasPurchasePrice => payload.containsKey('purchasePrice');

  double? get purchasePrice => Money.value(payload['purchasePrice']);

  bool get isActive => payload['isActive'] != false;

  /// Name in either language, plus the two things a rep reads off a carton:
  /// the code and the barcode.
  bool matches(String query) {
    final needle = query.trim().toLowerCase();
    if (needle.isEmpty) return true;
    return [
      _text('nameBn'),
      _text('nameEn'),
      code,
      barcode,
    ].any((field) => field != null && field.toLowerCase().contains(needle));
  }

  String? _text(String key) {
    final value = payload[key];
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }

  static List<ProductRecord> all() => ReferenceCache.instance
      .allOf(entityType)
      .map(ProductRecord.new)
      .toList();

  static ProductRecord? byId(String id) {
    if (id.isEmpty) return null;
    final payload = ReferenceCache.instance.get(entityType, id);
    return payload == null ? null : ProductRecord(payload);
  }
}
