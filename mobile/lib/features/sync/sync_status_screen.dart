import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/sync_engine/sync_capabilities_api.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/sync_engine/sync_history_api.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/app_status_pill.dart';
import '../../core/widgets/empty_state.dart';
import '../orders/rejected_order_card.dart';

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
    // Only the modules whose last attempt actually failed; empty when the
    // queue is simply waiting for a connection that has not been tried yet.
    final failures = SyncEngine.instance.lastFailures;

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
                  // ⛔ সারির বাক্স খোলেনি — চুপ নয়, মানুষকে বলা ([[QueueOpening]], মোবাইল অ্যাপ নিরীক্ষা, ১৮ অক্টোবর ২০২৬)
                  ValueListenableBuilder<QueueTrouble?>(
                    valueListenable: SyncEngine.instance.trouble,
                    builder: (context, trouble, _) => trouble == null
                        ? const SizedBox.shrink()
                        : Card(
                            key: const Key('queue-trouble'),
                            child: ListTile(
                              leading: AppStatusPill(
                                label: '!',
                                kind: trouble == QueueTrouble.busy ? AppStatusKind.pending : AppStatusKind.danger,
                              ),
                              title: Text(queueTroubleText(trouble)),
                            ),
                          ),
                  ),
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
                      // ⚠️ Why the last attempt did not go through, when
                      // there was one. "Waiting" and "refused outright" leave
                      // a queue looking identical, and only one of them is
                      // fixed by walking to a window — see
                      // SyncEngine.lastFailureFor's own doc comment for the
                      // afternoon that cost.
                      subtitle: pendingCount == 0 || failures.isEmpty
                          ? null
                          : Text(
                              failures.first.sentence,
                              style: TextStyle(
                                fontSize: 12.5,
                                color: failures.first.isServerRefusal
                                    ? AppColors.danger
                                    : context.palette.onSurfaceMuted,
                              ),
                            ),
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
                    ...rejected.map((item) => RejectedOrderCard(
                          item: item,
                          onDismissed: () => setState(() {}),
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
                              style: TextStyle(
                                  color: context.palette.onSurfaceMuted, fontSize: 12),
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

/// সারির বাক্সের সমস্যা, মানুষের ভাষায় — প্রতিটায় বলা "কিছুই মোছা হয়নি", কারণ ঠিক সেটাই আগে হত।
String queueTroubleText(QueueTrouble trouble) => switch (trouble) {
      QueueTrouble.busy => 'পেছনের সিঙ্ক এখন সারিটা পাঠাচ্ছে — একটু পরে এখানেও খুলবে। কিছুই মোছা হয়নি।',
      QueueTrouble.keyUnavailable => 'ফোনের নিরাপদ ভাণ্ডার এখন খোলেনি — অপেক্ষমাণ অর্ডার ফোনেই রাখা আছে। অ্যাপ বন্ধ করে আবার খুলুন।',
      QueueTrouble.quarantined => 'অপেক্ষমাণ সারির ফাইল খোলেনি — ফাইলটা আলাদা করে রাখা আছে, মোছা হয়নি। নতুন অর্ডার যাচ্ছে; আগেরগুলোর জন্য অফিসকে জানান।',
      QueueTrouble.broken => 'অপেক্ষমাণ সারির ফাইল খোলেনি — ফাইল মোছা হয়নি, সিঙ্ক বন্ধ। অফিসকে জানান।',
    };
