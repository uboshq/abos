import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/sync_engine/sync_capabilities_api.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/sync_engine/sync_history_api.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/app_status_pill.dart';
import '../../core/widgets/empty_state.dart';

/// The screen the 4 September briefing calls the most important one this
/// app has: "যা যায়নি" (what did not go up) is invisible everywhere else,
/// and its absence is exactly how a rep ends up believing an order went
/// through that the server actually refused — see docs, §৪(গ).
///
/// Three things, every role can open all of them:
///  - what is still queued, waiting for a connection;
///  - what the server refused outright, with its reason, kept until
///    dismissed by a person who has dealt with it;
///  - when each module last pulled successfully, so a stale catalogue is a
///    fact on screen rather than something only discovered by its absence.
///
/// Conflicts sit behind `governance.audit.view` on the server (contract, §২)
/// — most roles get a 403 for that one call, and this screen simply omits
/// the section rather than showing an error for a door that was never meant
/// to open for them.
class SyncStatusScreen extends StatefulWidget {
  const SyncStatusScreen({super.key});

  @override
  State<SyncStatusScreen> createState() => _SyncStatusScreenState();
}

class _SyncStatusScreenState extends State<SyncStatusScreen> {
  bool _loading = true;
  List<ModuleSyncStatus> _lastSync = const [];
  List<SyncConflict> _conflicts = const [];
  bool _conflictsAllowed = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    List<ModuleSyncStatus> lastSync = const [];
    List<SyncConflict> conflicts = const [];
    var conflictsAllowed = true;
    try {
      lastSync = await SyncHistoryApi.lastSyncByModule();
    } catch (_) {
      // Left empty — the module rows below fall back to "never synced from
      // this device" for every module rather than blanking the whole
      // screen over one failed call.
    }
    try {
      conflicts = await SyncHistoryApi.conflicts();
    } on DioException catch (error) {
      if (error.response?.statusCode == 403) {
        conflictsAllowed = false;
      }
      // Any other failure (offline, 5xx): leave the section showing
      // whatever was already loaded rather than claiming "no conflicts".
    } catch (_) {
      // Non-Dio failure — same reasoning.
    }
    if (!mounted) return;
    setState(() {
      _lastSync = lastSync;
      _conflicts = conflicts;
      _conflictsAllowed = conflictsAllowed;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final rejected = SyncEngine.instance.rejectedItems;
    final pendingCount = SyncEngine.instance.pendingCount;

    return Scaffold(
      appBar: AppBar(title: const Text('সিঙ্কের অবস্থা')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  const _SectionHeader('অপেক্ষমাণ'),
                  Card(
                    child: ListTile(
                      leading: AppStatusPill(
                        label: '$pendingCount',
                        kind: pendingCount == 0
                            ? AppStatusKind.success
                            : AppStatusKind.pending,
                      ),
                      title: Text(pendingCount == 0
                          ? 'সব পাঠানো হয়ে গেছে'
                          : 'সংযোগের অপেক্ষায় — এই ফোনেই আছে'),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  const _SectionHeader('যা যায়নি — কারণসহ'),
                  if (rejected.isEmpty)
                    const Card(
                      child: ListTile(
                        leading: AppStatusPill(
                            label: '✓', kind: AppStatusKind.success),
                        title: Text('প্রত্যাখ্যাত কিছু নেই'),
                      ),
                    )
                  else
                    ...rejected.map((item) => Card(
                          child: ListTile(
                            leading: const AppStatusPill(
                                label: 'ব্যর্থ', kind: AppStatusKind.danger),
                            title: Text(syncEntityLabel(item.entityType)),
                            subtitle: Text(item.reason),
                            isThreeLine: true,
                            trailing: IconButton(
                              icon: const Icon(Icons.close),
                              tooltip: 'বুঝেছি, সরিয়ে ফেলুন',
                              onPressed: () async {
                                await SyncEngine.instance
                                    .dismissRejected(item.key);
                                if (mounted) setState(() {});
                              },
                            ),
                          ),
                        )),
                  const SizedBox(height: AppSpacing.md),
                  const _SectionHeader('শেষ সিঙ্ক'),
                  if (_lastSync.isEmpty)
                    const EmptyState(
                      icon: Icons.sync_disabled,
                      title: 'কোনো তথ্য পাওয়া যায়নি',
                    )
                  else
                    ..._lastSync.map((status) => Card(
                          child: ListTile(
                            title: Text(syncModuleLabel(status.module)),
                            trailing: Text(
                              status.lastSyncedAt == null
                                  ? 'কখনো না'
                                  : DateFormat('dd/MM/yyyy hh:mm a')
                                      .format(status.lastSyncedAt!),
                              style: const TextStyle(
                                  color: AppColors.onSurfaceMuted, fontSize: 12),
                            ),
                          ),
                        )),
                  if (_conflictsAllowed) ...[
                    const SizedBox(height: AppSpacing.md),
                    const _SectionHeader('দ্বন্দ্ব'),
                    if (_conflicts.isEmpty)
                      const Card(
                        child: ListTile(
                          leading: AppStatusPill(
                              label: '✓', kind: AppStatusKind.success),
                          title: Text('কোনো দ্বন্দ্ব নেই'),
                        ),
                      )
                    else
                      ..._conflicts.map((conflict) => Card(
                            child: ListTile(
                              leading: AppStatusPill(
                                label: conflict.needsResolution
                                    ? 'সমাধান বাকি'
                                    : conflict.status,
                                kind: conflict.needsResolution
                                    ? AppStatusKind.warning
                                    : AppStatusKind.neutral,
                              ),
                              title: Text(syncEntityLabel(conflict.entityType)),
                              subtitle: conflict.reason != null
                                  ? Text(conflict.reason!)
                                  : null,
                            ),
                          )),
                  ],
                ],
              ),
            ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader(this.label);

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: Text(label,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
    );
  }
}
