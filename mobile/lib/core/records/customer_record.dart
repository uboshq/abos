import '../sync_engine/reference_cache.dart';
import 'money.dart';

/// One shop, as `CustomerSync` actually sends it.
///
/// <p><b>Why this file exists at all</b> — see `reference_cache.dart`'s own
/// doc comment, which says the cache stores payloads untyped on purpose and
/// that reading their fields "belongs to a typed reader built once those
/// entity types are live and their real shape can be read from an actual
/// response rather than guessed from a plan". Until now no such reader was
/// built and each screen guessed instead: they read `name`, `mobile` and
/// `address`, none of which the server has ever sent. Every customer row on
/// the phone therefore read "নাম নেই", and the search box matched nothing.
///
/// <p>The field list below is copied from
/// `app/Modules/Customer/Sync/CustomerSync.php`'s `payload:` array. No
/// compiler guards this boundary — docs/Contract says so in its first
/// paragraph — so this class and that array are the two halves that must be
/// changed together, and `test/payload_contract_test.dart` holds a copy of
/// that array to make a drift fail a test rather than a rep's afternoon.
class CustomerRecord {
  const CustomerRecord(this.payload);

  final Map<String, dynamic> payload;

  static const String entityType = 'Customer';

  /// The `public_id` — never the sequential id, which the contract forbids on
  /// the wire (§৩ rule ক) and which therefore never reaches this app at all.
  String get id => (payload['id'] ?? '').toString();

  String? get code => _text('code');

  /// Bengali first: this app has no language switch and every label around
  /// this name is Bengali. English is the fallback rather than the other way
  /// round, and the code the fallback of last resort — a row with no name at
  /// all is still recognisable by the code the shop is billed under.
  String get name =>
      _text('nameBn') ?? _text('nameEn') ?? code ?? 'নাম নেই';

  String? get ownerName => _text('ownerName');

  String? get phone => _text('phone');

  String? get address => _text('addressBn') ?? _text('addressEn');

  String? get customerType => _text('customerType');

  bool get isActive => payload['isActive'] != false;

  /// What the list search box and the order screen's picker both match on.
  ///
  /// Both names, always — a rep who knows a shop as "Rahim Store" must find
  /// it even when the Bengali name is the one on screen, and the phone number
  /// is how a shop is identified over a call.
  bool matches(String query) {
    final needle = query.trim().toLowerCase();
    if (needle.isEmpty) return true;
    return [
      _text('nameBn'),
      _text('nameEn'),
      code,
      ownerName,
      phone,
    ].any((field) => field != null && field.toLowerCase().contains(needle));
  }

  String? _text(String key) {
    final value = payload[key];
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }

  static List<CustomerRecord> all() => ReferenceCache.instance
      .allOf(entityType)
      .map(CustomerRecord.new)
      .toList();

  static CustomerRecord? byId(String id) {
    final payload = ReferenceCache.instance.get(entityType, id);
    return payload == null ? null : CustomerRecord(payload);
  }
}

/// What a shop owes, as `CustomerDueSync` sends it.
///
/// <p><b>This is the one figure the contract says a rep must see before
/// promising goods</b> — outstanding credit is one of the four things
/// docs/Contract §০ lists as unknowable offline, and the server goes to real
/// trouble to keep it fresh: `CustomerDueSync`'s watermark is taken from the
/// ledger's last movement rather than the customer row, precisely so that a
/// phone showing "৫,০০০ বাকি" cannot go on showing it for three months while
/// the real figure walks to ৮০,০০০.
///
/// <p>That whole mechanism has been arriving on the handset and being thrown
/// away: `CustomerDue` was pulled into the cache and nothing in the app ever
/// read it.
///
/// <p><b>Kept separate from [CustomerRecord] on purpose</b>, matching the
/// server: it is its own `entityType` with its own watermark, so a shop can
/// have a due record and no customer record cached yet, or the reverse. They
/// share an `entityId` — the customer's `public_id` — which is what makes
/// [forCustomer] a lookup rather than a scan.
class CustomerDueRecord {
  const CustomerDueRecord(this.payload);

  final Map<String, dynamic> payload;

  static const String entityType = 'CustomerDue';

  String get customerId => (payload['customerId'] ?? '').toString();

  /// Positive means the shop owes us; negative means we owe them, which is
  /// what an advance deposit looks like. The sign is not interpreted on the
  /// server side either — the web screen reads it by this same rule.
  double get outstanding => Money.valueOrZero(payload['outstanding']);

  double get creditLimit => Money.valueOrZero(payload['creditLimit']);

  int get creditDays => (payload['creditDays'] as num?)?.toInt() ?? 0;

  bool get isActive => payload['isActive'] != false;

  /// <b>Zero is not "no credit allowed".</b> `CustomerDueSync` says it
  /// plainly: a zero limit means cash or advance, and whether that blocks a
  /// sale at all is a company switch (`customer.zero_limit_blocks`) the phone
  /// is deliberately not told about. So the raw number travels, no screen
  /// draws a limit that is not there, and no screen decides what one means.
  bool get hasCreditLimit => creditLimit > 0;

  String get outstandingLabel {
    if (outstanding == 0) return 'বকেয়া নেই';
    if (outstanding < 0) return 'অগ্রিম ${Money.taka(-outstanding)}';
    return 'বকেয়া ${Money.taka(outstanding)}';
  }

  static CustomerDueRecord? forCustomer(String customerId) {
    if (customerId.isEmpty) return null;
    final payload = ReferenceCache.instance.get(entityType, customerId);
    return payload == null ? null : CustomerDueRecord(payload);
  }

  /// When this figure was last known to have moved on the server — a rep
  /// looking at a due that is four days old should be able to tell.
  static DateTime? syncedAt(String customerId) =>
      ReferenceCache.instance.updatedAt(entityType, customerId);
}
