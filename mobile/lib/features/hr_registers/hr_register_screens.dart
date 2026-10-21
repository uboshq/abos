import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/hr/hr_register_api.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';
import '../../core/locale/l10n.dart';

/// ⭐ হাজিরার রেজিস্টার — একটা দিন বেছে নাগালের সব কর্মী, প্রত্যেকের অবস্থা, উপরে অবস্থা ধরে গোনা আর মোট (মালিক, ১৭ অক্টোবর ২০২৬)।
/// ⓘ হাজিরা বসানো (বেতনের ভিত্তি) ওয়েবে — এখানে কেবল দেখা।
class AttendanceRegisterScreen extends StatefulWidget {
  const AttendanceRegisterScreen({super.key, this.api = const ServerHrRegisterApi(), this.today});

  final HrRegisterApi api;
  final DateTime? today;

  @override
  State<AttendanceRegisterScreen> createState() => _AttendanceRegisterScreenState();
}

class _AttendanceRegisterScreenState extends State<AttendanceRegisterScreen> {
  late DateTime _day;
  DeskRow? _page;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _day = DateTime(now.year, now.month, now.day);
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.attendance(isoOf(_day));
      if (mounted) setState(() => _page = page);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.hrRegisterAttendanceLoadFailed,
            whenAbsent: context.l10n.hrRegisterAttendanceServerMissing));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pick() async {
    final last = widget.today ?? DateTime.now();
    final picked = await showDatePicker(context: context, initialDate: _day, firstDate: DateTime(2024), lastDate: last);
    if (picked == null) return;
    setState(() => _day = picked);
    await _load();
  }

  Color _tone(String? status) => switch (status) {
        'present' => context.palette.success,
        'absent' => context.palette.danger,
        'leave' || 'holiday' => context.palette.warning,
        _ => context.palette.onSurfaceMuted,
      };

  @override
  Widget build(BuildContext context) {
    final p = _page;

    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.hrRegisterAttendanceTitle),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            OutlinedButton.icon(
              key: const ValueKey('attendance-day'),
              icon: const Icon(Icons.event, size: 18),
              label: Text(context.l10n.hrRegisterAttendanceDay(dayOf(isoOf(_day)))),
              onPressed: _pick,
            ),
            const SizedBox(height: AppSpacing.sm),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: context.l10n.hrRegisterLoadFailedTitle, message: _error)
            else if (p != null && p.rows('rows').isEmpty && !_busy)
              EmptyState(icon: Icons.people_outline, title: context.l10n.hrRegisterAttendanceNobody)
            else if (p != null) ...[
              Card(
                key: const ValueKey('attendance-counts'),
                color: context.palette.successSurface,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.l10n.hrRegisterAttendanceTotal(p.number('count')), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                    const SizedBox(height: AppSpacing.xs),
                    Wrap(spacing: AppSpacing.md, runSpacing: AppSpacing.xs, children: [
                      for (final c in p.rows('counts'))
                        Text(context.l10n.hrRegisterAttendanceCount(c.text('label'), c.number('count')),
                            style: TextStyle(color: _tone(c.text('status')), fontWeight: FontWeight.w600)),
                    ]),
                  ]),
                ),
              ),
              const SizedBox(height: AppSpacing.xs),
              for (final e in p.rows('rows'))
                Card(
                  key: ValueKey('attendance-${e.text('id')}'),
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    dense: true,
                    title: Text('${e.text('code')} · ${e.text('name')}', style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text([
                      if (e.text('designation').isNotEmpty) e.text('designation'),
                      if (e.text('department').isNotEmpty) e.text('department'),
                      if (e.text('remarks').isNotEmpty) e.text('remarks'),
                    ].join(' · ')),
                    trailing: Text(e.flag('late') ? context.l10n.hrRegisterAttendanceLate(e.text('status_label')) : e.text('status_label'),
                        style: TextStyle(color: _tone(e.payload['status'] as String?), fontWeight: FontWeight.w700)),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

/// ⭐ ছুটির রেজিস্টার — নাগালের কর্মীদের আবেদন, "অপেক্ষায়" ছাঁকনি, আর চাবি থাকলে মঞ্জুর/ফেরত (ফেরতে কারণ লাগে)।
/// ⛔ নিজের আবেদনে বোতাম নেই, আর দরজাও তা মানে না।
class LeaveRegisterScreen extends StatefulWidget {
  const LeaveRegisterScreen({super.key, this.api = const ServerHrRegisterApi()});

  final HrRegisterApi api;

  @override
  State<LeaveRegisterScreen> createState() => _LeaveRegisterScreenState();
}

class _LeaveRegisterScreenState extends State<LeaveRegisterScreen> {
  bool _pending = true;
  final List<DeskRow> _rows = [];
  DeskRow? _page;
  String? _error;
  bool _busy = false;
  final _reason = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.leaves(pending: _pending, page: more ? (_page?.nextPage ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(page.rows('rows'));
        _page = page;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.hrRegisterLeaveLoadFailed,
            whenAbsent: context.l10n.hrRegisterLeaveServerMissing));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _decide(DeskRow row, bool approve) async {
    String? text;
    if (!approve) {
      _reason.clear();
      text = await showDialog<String>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(context.l10n.hrRegisterLeaveRejectTitle),
          content: TextField(key: const ValueKey('leave-reason'), controller: _reason, autofocus: true, maxLength: 500),
          actions: [
            TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.l10n.hrRegisterLeaveRejectDismiss)),
            FilledButton(
                key: const ValueKey('leave-reason-send'),
                onPressed: () => Navigator.of(context).pop(_reason.text.trim()),
                child: Text(context.l10n.hrRegisterLeaveRejectConfirm)),
          ],
        ),
      );
      if (text == null || !mounted) return;
      if (text.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.l10n.hrRegisterLeaveRejectReasonNeeded)));
        return;
      }
    }
    try {
      approve ? await widget.api.approve(row.text('id')) : await widget.api.reject(row.text('id'), remarks: text!);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(approve ? context.l10n.hrRegisterLeaveSigned : context.l10n.hrRegisterLeaveRejected)));
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(errorMessageFor(e, fallback: approve ? context.l10n.hrRegisterLeaveSignFailed : context.l10n.hrRegisterLeaveRejectFailed))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.hrRegisterLeaveTitle),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Wrap(spacing: AppSpacing.xs, children: [
              ChoiceChip(
                key: const ValueKey('leave-pending'),
                label: Text(context.l10n.hrRegisterLeavePending),
                selected: _pending,
                onSelected: (_) {
                  setState(() => _pending = true);
                  _load();
                },
              ),
              ChoiceChip(
                key: const ValueKey('leave-all'),
                label: Text(context.l10n.hrRegisterLeaveAll),
                selected: !_pending,
                onSelected: (_) {
                  setState(() => _pending = false);
                  _load();
                },
              ),
            ]),
            if (_page != null && _rows.isNotEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
                child: Text(context.l10n.hrRegisterLeaveTotal(_page!.number('count')),
                    key: const ValueKey('leave-count'), style: const TextStyle(fontWeight: FontWeight.w600)),
              ),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: context.l10n.hrRegisterLoadFailedTitle, message: _error)
            else if (_page != null && _rows.isEmpty && !_busy)
              EmptyState(icon: Icons.beach_access_outlined, title: _pending ? context.l10n.hrRegisterLeaveNonePending : context.l10n.hrRegisterLeaveNone),
            for (final a in _rows)
              Card(
                key: ValueKey('leave-${a.text('id')}'),
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Expanded(child: Text(a.text('employee'), style: const TextStyle(fontWeight: FontWeight.w600))),
                      Text(a.text('status_label'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    ]),
                    Text(context.l10n.hrRegisterLeaveLine(a.text('type'), a.text('from'), a.text('to'), num.tryParse(a.text('days')) ?? 0)),
                    if (a.text('reason').isNotEmpty)
                      Text(a.text('reason'), style: TextStyle(fontSize: 13, color: context.palette.onSurfaceMuted)),
                    if (a.text('decider').isNotEmpty)
                      Text(context.l10n.hrRegisterLeaveDecider(a.text('decider')), style: TextStyle(fontSize: 13, color: context.palette.onSurfaceMuted)),
                    if (a.flag('can_decide'))
                      Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                        TextButton(
                            key: ValueKey('leave-reject-${a.text('id')}'),
                            onPressed: () => _decide(a, false),
                            child: Text(context.l10n.hrRegisterLeaveReject)),
                        const SizedBox(width: AppSpacing.xs),
                        FilledButton(
                            key: ValueKey('leave-approve-${a.text('id')}'),
                            onPressed: () => _decide(a, true),
                            child: Text(context.l10n.hrRegisterLeaveSign)),
                      ]),
                  ]),
                ),
              ),
            if (_page?.nextPage != null)
              OutlinedButton(onPressed: _busy ? null : () => _load(more: true), child: Text(context.l10n.hrRegisterLeaveMore)),
          ],
        ),
      ),
    );
  }
}
