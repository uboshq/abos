import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/records/attendance_record.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

/// Marking your own day present — docs/Contract §৭.
///
/// <p><b>The one screen every role needs.</b> A rep, a warehouse hand, a
/// depot clerk, someone on distribution — all of them mark attendance, and
/// all of them are somewhere with no signal when they do it. So this writes
/// to the offline queue like an order does, and `AttendanceSync` has accepted
/// that push since the engine was built; nothing in this app had ever called
/// it.
///
/// <p><b>It is not reached from the menu, and that is the important part.</b>
/// The `hr.attendance.index` row needs `hr.attendance.view` — the permission
/// for seeing *the whole team's* attendance, which field staff deliberately
/// do not have. Their templates carry `hr.attendance.self` instead. A tile
/// built from the menu would therefore have been invisible to exactly the
/// people this is for, while showing up fine for whoever checked it from an
/// office. See menu_repository.dart, where the tile is synthesized from the
/// permission directly.
class AttendanceScreen extends StatefulWidget {
  const AttendanceScreen({super.key, this.now, this.onMark});

  /// Injected in tests — "today" is the one thing this screen is entirely
  /// about, so it must be possible to state it rather than wait for midnight.
  final DateTime Function()? now;

  /// Seam for the queue write.
  final Future<void> Function(AttendanceDraft draft)? onMark;

  @override
  State<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends State<AttendanceScreen> {
  bool _busy = false;

  DateTime get _today => (widget.now ?? DateTime.now)();

  /// Today counts as marked if the server has sent it back **or** it is still
  /// sitting in the queue.
  ///
  /// <p>Both halves matter. The server refuses a second row for the same day
  /// (CONFLICT), so letting someone queue today twice would send a change
  /// that comes back refused — and a refused row reads as "my attendance did
  /// not go through" when in fact it had. The queue half is what a phone with
  /// no signal has to go on, which is most of the time this screen is used.
  bool get _alreadyMarked {
    final wanted = AttendanceRecord.wireDate.format(_today);
    if (AttendanceRecord.syncedFor(_today)) return true;
    return SyncEngine.instance
        .pendingPayloadsOf(AttendanceRecord.entityType)
        .any((payload) => AttendanceDraft.dayOf(payload) == wanted);
  }

  bool get _waitingToSend {
    final wanted = AttendanceRecord.wireDate.format(_today);
    return !AttendanceRecord.syncedFor(_today) &&
        SyncEngine.instance
            .pendingPayloadsOf(AttendanceRecord.entityType)
            .any((payload) => AttendanceDraft.dayOf(payload) == wanted);
  }

  Future<void> _refresh() async {
    setState(() => _busy = true);
    try {
      await ReferenceSync.syncAll();
    } catch (_) {
      // Same as every other screen: a failed pull leaves what was cached.
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _mark() async {
    if (_alreadyMarked) return;
    setState(() => _busy = true);
    final now = _today;
    try {
      final draft = AttendanceDraft(
        day: now,
        // The phone's clock, because in a field there is no other one. The
        // server marks the gap between the day claimed and the day it arrived
        // rather than refusing it — docs/Contract §৭ rule খ.
        inTime: DateFormat('HH:mm').format(now),
      );

      await (widget.onMark ??
          (AttendanceDraft d) => SyncEngine.instance.enqueue(
                module: AttendanceRecord.module,
                entityType: AttendanceRecord.entityType,
                operation: 'CREATE',
                payload: d.toPayload(),
              ))(draft);

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(context.l10n.hrAttendanceMarkedQueued),
      ));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final history = AttendanceRecord.all()
      ..sort((a, b) => (b.workDate ?? '').compareTo(a.workDate ?? ''));

    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.hrAttendanceTitle),
      body: Column(
        children: [
          if (_busy) const LinearProgressIndicator(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  _TodayCard(
                    today: _today,
                    marked: _alreadyMarked,
                    waitingToSend: _waitingToSend,
                    onMark: _busy ? null : _mark,
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Text(context.l10n.hrAttendanceEarlierDays,
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: AppSpacing.sm),
                  if (history.isEmpty)
                    EmptyState(
                      icon: Icons.event_available_outlined,
                      title: context.l10n.hrAttendanceNoHistoryTitle,
                      message: context.l10n.hrAttendanceNoHistoryMessage,
                    )
                  else
                    ...history.map((row) => _DayTile(row: row)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _TodayCard extends StatelessWidget {
  const _TodayCard({
    required this.today,
    required this.marked,
    required this.waitingToSend,
    required this.onMark,
  });

  final DateTime today;
  final bool marked;
  final bool waitingToSend;
  final VoidCallback? onMark;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          children: [
            Text(DateFormat('dd/MM/yyyy').format(today),
                style: const TextStyle(
                    fontSize: 22, fontWeight: FontWeight.w700)),
            const SizedBox(height: AppSpacing.xs),
            Text(context.l10n.hrAttendanceToday, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: AppSpacing.lg),
            if (marked) ...[
              Icon(Icons.check_circle,
                  size: 48, color: context.palette.success),
              const SizedBox(height: AppSpacing.sm),
              Text(context.l10n.hrAttendanceTodayMarked,
                  style: const TextStyle(fontWeight: FontWeight.w700)),
              if (waitingToSend) ...[
                const SizedBox(height: AppSpacing.xs),
                // Told plainly rather than hidden behind the tick: on a phone
                // with no signal this is the honest state, and a person who
                // believes it already reached the office has no reason to
                // keep the app open until it does.
                Text(context.l10n.hrAttendanceWaitingToSend,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 12.5, color: context.palette.onSurfaceMuted)),
              ],
            ] else
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: onMark,
                  icon: const Icon(Icons.how_to_reg_outlined),
                  label: Text(context.l10n.hrAttendanceMarkToday),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _DayTile extends StatelessWidget {
  const _DayTile({required this.row});

  final AttendanceRecord row;

  @override
  Widget build(BuildContext context) {
    final date = row.date;
    final colour = switch (row.status) {
      'present' => context.palette.success,
      'absent' => context.palette.danger,
      _ => context.palette.onSurfaceMuted,
    };

    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: ListTile(
        title: Text(date == null
            ? (row.workDate ?? '—')
            : DateFormat('dd/MM/yyyy').format(date)),
        subtitle: Text([
          if (row.inTime != null) context.l10n.hrAttendanceIn(row.inTime!),
          if (row.outTime != null) context.l10n.hrAttendanceOut(row.outTime!),
          if (row.isLate) context.l10n.hrAttendanceLate,
          // The server's own late-sync note, carried through untouched — see
          // AttendanceRecord.remarks for why a screen must not swallow it.
          if (row.remarks != null) row.remarks!,
        ].join(' · ')),
        trailing: Text(
          row.statusLabel,
          style: TextStyle(color: colour, fontWeight: FontWeight.w700),
        ),
      ),
    );
  }
}
