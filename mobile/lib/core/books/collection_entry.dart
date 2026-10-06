import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client/api_client.dart';
import '../sync_engine/reference_cache.dart';
import '../sync_engine/sync_engine.dart';

/// ⭐ ফোনে টাকা আদায় — কেবল অফিসের লোক (মালিক, ৭ অক্টোবর ২০২৬: "এটা কেবল অফিসের লোকদের জন্য থাকবে, আর ফিল্ডের
/// জন্য থাকবে payment request")। সার্ভার `/me`-তে বলে (`mayCollect`) — আদায়ের চাবি আর খাতায় টাকা তোলার চাবি।
///
/// <p>লেখা যায় সিঙ্কের অপেক্ষার সারি দিয়ে ([[SyncEngine.enqueue]]) — নেট না থাকলে ফোনে জমা থাকে, পরে যায়, আর একই
/// আদায় দুবার পৌঁছালেও সার্ভারে একটাই বসে (changeId)। নিয়ম সব সার্ভারের — ওয়েবের আদায়-ফর্মের একই।
final mayCollectProvider = StateProvider<bool>((ref) => false);

/// একটা টাকার খাত — নগদ, ব্যাংক বা MFS (ওয়েবের আদায়-ফর্মের একই তালিকা)
class CollectionAccount {
  const CollectionAccount(
      {required this.id,
      required this.name,
      required this.kind,
      this.code = ''});

  final String id;
  final String name;
  final String code;

  /// cash · bank · mfs
  final String kind;

  String get kindLabel => switch (kind) {
        'bank' => 'ব্যাংক',
        'mfs' => 'মোবাইল ব্যাংকিং',
        _ => 'নগদ',
      };

  factory CollectionAccount.fromJson(Map<String, dynamic> j) =>
      CollectionAccount(
        id: j['id']?.toString() ?? '',
        name: j['name']?.toString() ?? '',
        code: j['code']?.toString() ?? '',
        kind: j['kind']?.toString() ?? 'cash',
      );

  Map<String, dynamic> toJson() =>
      {'id': id, 'name': name, 'code': code, 'kind': kind};
}

/// যা পাঠানো হবে — সার্ভারের [CollectionSync] এই ঘরগুলোই পড়ে
class CollectionDraft {
  const CollectionDraft({
    required this.customerId,
    required this.amount,
    required this.date,
    this.accountId,
    this.instrument,
    this.instrumentNo,
    this.narration,
    this.confirm = true,
  });

  final String customerId;

  /// দুই দশমিক পর্যন্ত, লেখা যেমন — সার্ভার কঠোরভাবে মাপে
  final String amount;
  final DateTime date;
  final String? accountId;
  final String? instrument;
  final String? instrumentNo;
  final String? narration;

  /// "এখনই নিশ্চিত" — ওয়েবের দুই চাপ এক চাপে; অনুমোদনে আটকালে খসড়া থাকে
  final bool confirm;

  Map<String, dynamic> toPayload() => {
        'customerId': customerId,
        'amount': amount,
        'trxDate':
            '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}',
        if (accountId != null) 'accountId': accountId,
        if ((instrument ?? '').trim().isNotEmpty)
          'instrument': instrument!.trim(),
        if ((instrumentNo ?? '').trim().isNotEmpty)
          'instrumentNo': instrumentNo!.trim(),
        if ((narration ?? '').trim().isNotEmpty) 'narration': narration!.trim(),
        'confirm': confirm,
      };
}

abstract class CollectionEntryApi {
  /// সার্ভারের তালিকা; নেট না থাকলে শেষবার পাওয়া তালিকা
  Future<List<CollectionAccount>> accounts();

  /// অপেক্ষার সারিতে — ফেরত changeId; বসে গেলে সার্ভারের আইডি [landedId]-এ
  Future<String> send(CollectionDraft draft);

  String? landedId(String changeId);
}

class ServerCollectionEntryApi implements CollectionEntryApi {
  const ServerCollectionEntryApi();

  static const _cacheType = 'CollectionSetup';
  static const _cacheId = 'accounts';

  @override
  Future<List<CollectionAccount>> accounts() async {
    await ReferenceCache.instance.init();
    try {
      final response = await ApiClient.dio
          .get<Map<String, dynamic>>('/sales/collections/setup');
      final list = [
        for (final a in (response.data?['accounts'] as List?) ?? const [])
          if (a is Map)
            CollectionAccount.fromJson(Map<String, dynamic>.from(a)),
      ];
      await ReferenceCache.instance.put(
        entityType: _cacheType,
        entityId: _cacheId,
        payload: {
          'accounts': [for (final a in list) a.toJson()]
        },
        updatedAt: DateTime.now(),
      );
      return list;
    } catch (_) {
      final cached = ReferenceCache.instance.get(_cacheType, _cacheId);
      if (cached == null) rethrow;
      return [
        for (final a in (cached['accounts'] as List?) ?? const [])
          if (a is Map)
            CollectionAccount.fromJson(Map<String, dynamic>.from(a)),
      ];
    }
  }

  @override
  Future<String> send(CollectionDraft draft) => SyncEngine.instance.enqueue(
        module: 'sales',
        entityType: 'Collection',
        operation: 'CREATE',
        payload: draft.toPayload(),
      );

  @override
  String? landedId(String changeId) =>
      SyncEngine.instance.appliedEntityId(changeId);
}
