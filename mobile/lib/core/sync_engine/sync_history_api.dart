import '../api_client/api_client.dart';
import '../auth/token_storage.dart';
import 'sync_capabilities_api.dart';

/// What the phone can tell a person about its own syncing: when each module
/// last came down, and what the server could not reconcile on its own.
///
/// Both are read-only views over doors the sync API already opens — nothing
/// here needs a screen to exist before it is useful, and a "সিঙ্ক ইতিহাস"
/// screen is the obvious first consumer.
class SyncHistoryApi {
  const SyncHistoryApi._();

  /// The conflicts a person has to settle, because the server would not guess.
  ///
  /// Behind an audit-level permission on the server side, and it must stay
  /// that way: a conflict row carries **both** the device's version of a
  /// record and the server's, which together are more than either side alone
  /// was allowed to see.
  static Future<List<SyncConflict>> conflicts() async {
    final response = await ApiClient.dio.get<List<dynamic>>('/sync/conflicts');
    return (response.data ?? const [])
        .map((e) => SyncConflict.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> resolveConflict(String id, {String? note}) async {
    await ApiClient.dio.post<Map<String, dynamic>>(
      '/sync/conflicts/$id/resolve',
      data: {if (note != null && note.isNotEmpty) 'note': note},
    );
  }

  /// When this device last pulled each module — one call per module, since
  /// `/sync/{module}/last-sync` names the module in the path rather than
  /// accepting a list. [SyncCapabilitiesApi.list] can return more than one
  /// entityType per module (several record kinds pulled together), so this
  /// dedupes to the module before asking.
  static Future<List<ModuleSyncStatus>> lastSyncByModule() async {
    final deviceId = await TokenStorage.instance.deviceId();
    final capabilities = await SyncCapabilitiesApi.list();
    final modules = capabilities.map((c) => c.module).toSet();

    final results = await Future.wait(modules.map((module) async {
      try {
        final response = await ApiClient.dio.get<dynamic>(
          '/sync/$module/last-sync',
          queryParameters: {'deviceId': deviceId},
        );
        return ModuleSyncStatus(
          module: module,
          // .toLocal(), like every other instant the server sends. Skipping it
          // is the kind of bug nobody reports: the screen reads "হাজিরা 4/8
          // 02:40" for a sync that happened at twenty to nine that morning,
          // and it looks like a plausible time rather than a wrong one.
          lastSyncedAt: _parseInstant(response.data),
        );
      } catch (_) {
        // "Never synced from this device" and "could not ask" read the same
        // to a person, and neither is a reason to blank the other modules'
        // own answers.
        return ModuleSyncStatus(module: module, lastSyncedAt: null);
      }
    }));

    results.sort((a, b) => a.module.compareTo(b.module));
    return results;
  }

  /// The contract says this endpoint answers `{"lastSyncedAt": "..."|null}`.
  /// A bare quoted string is accepted too, because that is the shape a server
  /// most easily returns by accident when the field is a single value — and a
  /// timestamp that silently fails to parse is exactly the failure this whole
  /// screen exists to make visible.
  static DateTime? _parseInstant(dynamic data) {
    String? raw;
    if (data is Map) {
      raw = data['lastSyncedAt'] as String?;
    } else if (data is String) {
      raw = data;
    }
    if (raw == null || raw.isEmpty) return null;
    return DateTime.tryParse(raw.replaceAll('"', ''))?.toLocal();
  }
}

class ModuleSyncStatus {
  const ModuleSyncStatus({required this.module, required this.lastSyncedAt});

  final String module;
  final DateTime? lastSyncedAt;
}

class SyncConflict {
  const SyncConflict({
    required this.id,
    required this.module,
    required this.entityType,
    required this.entityId,
    required this.reason,
    required this.status,
    required this.detectedAt,
  });

  final String id;
  final String module;
  final String entityType;
  final String entityId;
  final String? reason;

  /// AUTO_RESOLVED_LAST_WRITE_WINS / PENDING_MANUAL_RESOLUTION / RESOLVED —
  /// kept raw, so a status the server adds later shows up as its own name
  /// instead of a parsing failure.
  final String status;
  final DateTime? detectedAt;

  bool get needsResolution => status == 'PENDING_MANUAL_RESOLUTION';

  factory SyncConflict.fromJson(Map<String, dynamic> json) => SyncConflict(
        id: json['id']?.toString() ?? '',
        module: json['module'] as String? ?? '',
        entityType: json['entityType'] as String? ?? '',
        entityId: json['entityId']?.toString() ?? '',
        reason: json['reason'] as String?,
        status: json['status'] as String? ?? '',
        detectedAt: json['detectedAt'] != null
            ? DateTime.tryParse(json['detectedAt'] as String)?.toLocal()
            : null,
      );
}
