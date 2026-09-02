import 'dart:convert';

import 'package:hive/hive.dart';

import '../storage/hive_encryption.dart';

/// Local storage for the download half of sync — reference data (a product's
/// price, a customer's address, a shop's outstanding balance) pulled from
/// `GET /sync/{module}/pull` and kept so the pickers that need them work with
/// no signal, the way the offline queue already lets a rep record a sale with
/// none.
///
/// Deliberately untyped: this file stores whatever `payloadJson` each record
/// carries, decoded once, keyed by `"$entityType:$entityId"`. It does not know
/// or care what fields a Customer or a Product record carries — that belongs
/// to a typed reader built once those entity types are live and their real
/// shape can be read from an actual response rather than guessed from a plan.
/// Storing generically here means the pull/cache machinery does not have to
/// change the day a fifth entityType is added to the catalogue.
class ReferenceCache {
  ReferenceCache._();

  static final ReferenceCache instance = ReferenceCache._();

  static const String _boxName = 'abos_reference_cache';

  Box<Map>? _box;

  Future<void> init() async {
    _box ??= await HiveEncryptionKey.openBox<Map>(_boxName);
  }

  /// Empties the cache — called on sign-out, same reasoning as
  /// TokenStorage.clear(): the next person to sign in on this device may be at
  /// a different company, and a stale product/customer catalogue from the last
  /// session must not leak into theirs. In a multi-company product that is not
  /// tidiness, it is the tenant boundary.
  Future<void> clearAll() async {
    await _box?.clear();
  }

  Box<Map> get _requireBox {
    final box = _box;
    if (box == null) {
      throw StateError('ReferenceCache.init() must be awaited before use');
    }
    return box;
  }

  static String _key(String entityType, String entityId) =>
      '$entityType:$entityId';

  /// Stores one record's decoded payload, and the [updatedAt] it arrived with
  /// — kept alongside the data so a reader can tell how stale a cached figure
  /// (a price, a credit limit) is without a second field added to every
  /// payload shape.
  Future<void> put({
    required String entityType,
    required String entityId,
    required Map<String, dynamic> payload,
    required DateTime updatedAt,
  }) async {
    await _requireBox.put(_key(entityType, entityId), <String, dynamic>{
      'payload': jsonEncode(payload),
      'updatedAt': updatedAt.toIso8601String(),
    });
  }

  /// One record by id, decoded back into a Map — null if never synced.
  Map<String, dynamic>? get(String entityType, String entityId) {
    final row = _box?.get(_key(entityType, entityId));
    if (row == null) return null;
    return jsonDecode(row['payload'] as String) as Map<String, dynamic>;
  }

  /// When one record was last known to have changed on the server — for a
  /// screen that has to say "দাম ৩ দিন আগের" rather than showing a stale
  /// figure as if it were today's.
  DateTime? updatedAt(String entityType, String entityId) {
    final row = _box?.get(_key(entityType, entityId));
    final raw = row?['updatedAt'];
    if (raw is! String) return null;
    return DateTime.tryParse(raw)?.toLocal();
  }

  /// Every cached record of one entityType, decoded — for a picker that lists
  /// everything of one kind (every product, every customer) rather than
  /// looking one up by id.
  List<Map<String, dynamic>> allOf(String entityType) {
    final box = _box;
    if (box == null) return const [];
    final prefix = '$entityType:';
    return box.keys
        .where((key) => key is String && key.startsWith(prefix))
        .map((key) {
          final row = box.get(key);
          if (row == null) return null;
          return jsonDecode(row['payload'] as String) as Map<String, dynamic>;
        })
        .whereType<Map<String, dynamic>>()
        .toList();
  }

  /// How many records of one entityType are cached — a settings screen's
  /// "X products, Y customers stored offline" line, without decoding every
  /// payload just to count them.
  int countOf(String entityType) {
    final box = _box;
    if (box == null) return 0;
    final prefix = '$entityType:';
    return box.keys
        .where((key) => key is String && key.startsWith(prefix))
        .length;
  }
}
