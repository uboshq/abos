import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/hr/self_service_api.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/paged_list.dart';
import '../books/books_widgets.dart';
import '../finance/finance_screens.dart' show monthWords;
import '../../core/widgets/unsaved_guard.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

/// ⭐ নিজের ছুটি আর নিজের বেতনের স্লিপ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস। আবেদন পাঠানো মানে ছুটি পাওয়া নয় — মঞ্জুরি অফিসের।

Color leaveColour(BuildContext context, String status) => switch (status) {
      'approved' => context.palette.success,
      'rejected' => context.palette.danger,
      'cancelled' => context.palette.onSurfaceMuted,
      _ => context.palette.pending,
    };

class LeaveScreen extends StatefulWidget {
  const LeaveScreen({super.key, this.api = const ServerSelfServiceApi()});

  final SelfServiceApi api;

  @override
  State<LeaveScreen> createState() => _LeaveScreenState();
}

class _LeaveScreenState extends State<LeaveScreen> {
  LeaveBook? _book;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final book = await widget.api.leave();
      if (mounted) setState(() => _book = book);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.hrLeaveLoadFailed,
            whenAbsent: context.l10n.hrLeaveServerMissing));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final book = _book;
    final canApply = book != null && book.employee && book.types.isNotEmpty;
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.hrLeaveTitle),
      floatingActionButton: canApply
          ? FloatingActionButton.extended(
              key: const ValueKey('leave-new'),
              onPressed: () async {
                final sent = await Navigator.of(context).push<LeaveRequest>(
                    MaterialPageRoute(builder: (_) => NewLeaveScreen(api: widget.api, types: book.types)));
                if (sent != null && context.mounted) {
                  ScaffoldMessenger.of(context)
                      .showSnackBar(SnackBar(content: Text(context.l10n.hrLeaveSent)));
                  await _load();
                }
              },
              icon: const Icon(Icons.add),
              label: Text(context.l10n.hrLeaveApply),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (book != null && !book.employee)
              EmptyState(
                icon: Icons.badge_outlined,
                title: context.l10n.hrNoEmployeeRecord,
                message: context.l10n.hrLeaveNoEmployeeMessage,
              ),
            if (book != null && book.employee) ...[
              SectionTitle(context.l10n.hrLeaveLeftThisYear),
              if (book.types.isEmpty)
                Text(context.l10n.hrLeaveNoTypes, style: TextStyle(color: context.palette.onSurfaceMuted)),
              for (final t in book.types)
                FactRow(t.name, t.left == null ? context.l10n.hrLeaveNoLimit(t.taken) : context.l10n.hrLeaveBalance(t.left!, t.allowed),
                    key: ValueKey('leave-type-${t.id}')),
              SectionTitle(context.l10n.hrLeaveMyApplications),
              if (book.applications.isEmpty)
                EmptyState(icon: Icons.event_available_outlined, title: context.l10n.hrLeaveNoApplications),
              for (final a in book.applications)
                Card(
                  key: ValueKey('leave-${a.id}'),
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    title: Text(context.l10n.hrLeaveCardTitle(a.type, a.days), style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(a.fromDate == a.toDate ? dayOf(a.fromDate) : '${dayOf(a.fromDate)} – ${dayOf(a.toDate)}'),
                        if (a.reason.isNotEmpty) Text(a.reason, maxLines: 1, overflow: TextOverflow.ellipsis),
                        if (a.remarks.isNotEmpty) Text(context.l10n.hrLeaveOfficeNote(a.remarks), maxLines: 2, overflow: TextOverflow.ellipsis),
                      ],
                    ),
                    trailing: Text(a.statusWords,
                        style: TextStyle(color: leaveColour(context, a.status), fontWeight: FontWeight.w700)),
                  ),
                ),
            ],
            const SizedBox(height: 80),
          ],
        ),
      ),
    );
  }
}

class NewLeaveScreen extends StatefulWidget {
  const NewLeaveScreen({super.key, required this.api, required this.types, this.today});

  final SelfServiceApi api;
  final List<LeaveKind> types;
  final DateTime? today;

  @override
  State<NewLeaveScreen> createState() => _NewLeaveScreenState();
}

class _NewLeaveScreenState extends State<NewLeaveScreen> {
  static final _daysShape = RegExp(r'^\d{1,3}(\.5)?$');

  final _reason = TextEditingController();
  final _days = TextEditingController();
  /// ⭐ এই আবেদনের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]])
  final _once = OnceKey();

  String? _type;
  late DateTime _from;
  late DateTime _to;
  bool _busy = false;
  String? _error;

  DateTime get _today {
    final now = widget.today ?? DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _from = _today;
    _to = _today;
    _type = widget.types.isEmpty ? null : widget.types.first.id;
    _days.text = '1';
    _reason.addListener(_typed);
    _days.addListener(_typed);
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  /// লেখা আছে: কারণ, বদলানো তারিখ, দিন বা ছুটির ধরন — খোলার সময়ের বসানো মান নয়
  bool get _dirty =>
      !_busy &&
      (_reason.text.trim().isNotEmpty ||
          _from != _today ||
          _to != _today ||
          _days.text.trim() != '1' ||
          _type != (widget.types.isEmpty ? null : widget.types.first.id));

  @override
  void dispose() {
    _reason.dispose();
    _days.dispose();
    super.dispose();
  }

  int get _span => _to.difference(_from).inDays + 1;

  Future<void> _pick({required bool from}) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: from ? _from : _to,
      firstDate: _today.subtract(const Duration(days: 60)),
      lastDate: _today.add(const Duration(days: 365)),
    );
    if (picked == null) return;
    setState(() {
      if (from) {
        _from = picked;
        if (_to.isBefore(_from)) _to = _from;
      } else {
        _to = picked.isBefore(_from) ? _from : picked;
      }
      // ⓘ দিনের সংখ্যা নিজে বসে — আধা দিন চাইলে বদলে নিন
      _days.text = '$_span';
    });
  }

  Future<void> _send() async {
    final days = _days.text.trim();
    final problem = _type == null
        ? context.l10n.hrLeaveChooseType
        : !_daysShape.hasMatch(days) || (double.tryParse(days) ?? 0) <= 0
            ? context.l10n.hrLeaveDaysShape
            : (double.tryParse(days) ?? 0) > _span
                ? context.l10n.hrLeaveDaysTooMany(_span)
                : null;
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final sent = await _once.send(() => widget.api.applyLeave(
          LeaveDraft(typeId: _type!, from: _from, to: _to, days: days, reason: _reason.text)));
      if (mounted) Navigator.of(context).pop(sent);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.hrLeaveSendFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ কারণ, তারিখ বা দিন বদলালে "পিছনে" জিজ্ঞেস করে — লেখা ছুটির আবেদন এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _dirty, child: _screen(context));

  Widget _screen(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.hrLeaveApply),
        body: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Text(context.l10n.hrLeaveHowItWorks,
                style: TextStyle(color: context.palette.onSurfaceMuted)),
            const SizedBox(height: AppSpacing.sm),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              Card(
                key: const ValueKey('leave-error'),
                color: context.palette.dangerSurface,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Text(_error!, style: TextStyle(color: context.palette.danger)),
                ),
              ),
            DropdownButtonFormField<String>(
              key: const ValueKey('leave-type'),
              initialValue: _type,
              isExpanded: true,
              decoration: InputDecoration(labelText: context.l10n.hrLeaveTypeField),
              items: [
                for (final t in widget.types)
                  DropdownMenuItem(
                    value: t.id,
                    child: Text(t.left == null ? t.name : context.l10n.hrLeaveTypeWithLeft(t.name, t.left!), overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) => setState(() => _type = v),
            ),
            ListTile(
              key: const ValueKey('leave-from'),
              contentPadding: EdgeInsets.zero,
              title: Text(context.l10n.hrLeaveFrom),
              subtitle: Text('${_from.day}/${_from.month}/${_from.year}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: () => _pick(from: true),
            ),
            ListTile(
              key: const ValueKey('leave-to'),
              contentPadding: EdgeInsets.zero,
              title: Text(context.l10n.hrLeaveUntil),
              subtitle: Text('${_to.day}/${_to.month}/${_to.year}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: () => _pick(from: false),
            ),
            TextField(
              key: const ValueKey('leave-days'),
              controller: _days,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(labelText: context.l10n.hrLeaveDaysField),
            ),
            TextField(
              key: const ValueKey('leave-reason'),
              controller: _reason,
              maxLength: 500,
              maxLines: 3,
              minLines: 1,
              decoration: InputDecoration(labelText: context.l10n.hrLeaveReasonField),
            ),
            const SizedBox(height: AppSpacing.md),
            FilledButton.icon(
              key: const ValueKey('leave-send'),
              onPressed: _busy ? null : _send,
              icon: const Icon(Icons.send_outlined),
              label: Text(context.l10n.hrLeaveSend),
            ),
          ],
        ),
      );
}

// ───────────────────────────── বেতনের স্লিপ ─────────────────────────────

class PayslipListScreen extends StatefulWidget {
  const PayslipListScreen({super.key, this.api = const ServerSelfServiceApi()});

  final SelfServiceApi api;

  @override
  State<PayslipListScreen> createState() => _PayslipListScreenState();
}

class _PayslipListScreenState extends State<PayslipListScreen> {
  bool _employee = true;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.hrPayslipListTitle),
        body: PagedList<Payslip>(
          load: (page) async {
            final p = await widget.api.payslips(page: page);
            if (mounted && p.employee != _employee) setState(() => _employee = p.employee);
            return (p.rows, p.nextPage);
          },
          failed: context.l10n.hrPayslipListFailed,
          whenAbsent: context.l10n.hrPayslipServerMissing,
          emptyIcon: Icons.receipt_long_outlined,
          emptyTitle: _employee ? context.l10n.hrPayslipEmptyTitle : context.l10n.hrNoEmployeeRecord,
          emptyMessage: _employee ? context.l10n.hrPayslipEmptyMessage : context.l10n.hrPayslipNoEmployeeMessage,
          item: (context, s) => Card(
            margin: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: ListTile(
              key: ValueKey('payslip-${s.id}'),
              title: Text(monthWords(s.month), style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                // ⭐ উৎসব ভাতার স্লিপ চেনা যায় — কেবল run_type "bonus"-এ; বেতনের বা পুরনো সার্ভারের স্লিপ আগের মতোই
                if (s.isFestivalBonus) _FestivalBonusLabel(key: ValueKey('payslip-bonus-${s.id}')),
                Text(context.l10n.hrPayslipRowLine(Money.taka(s.gross), Money.taka(s.deductions))),
              ]),
              trailing: Text(Money.taka(s.net), style: const TextStyle(fontWeight: FontWeight.w800)),
              onTap: () => Navigator.of(context)
                  .push(MaterialPageRoute<void>(builder: (_) => PayslipScreen(id: s.id, api: widget.api))),
            ),
          ),
        ),
      );
}

String _paidBy(BuildContext context, String method) => context.l10n.hrPayslipPaidBy(method);

class PayslipScreen extends StatelessWidget {
  const PayslipScreen({super.key, required this.id, this.api = const ServerSelfServiceApi()});

  final String id;
  final SelfServiceApi api;

  @override
  Widget build(BuildContext context) => PaperPage<Payslip>(
        title: context.l10n.hrPayslipTitle,
        titleOf: (s) => monthWords(s.month),
        load: () => api.payslip(id),
        failed: context.l10n.hrPayslipFailed,
        onLoaded: (s) => RecentPapers.note(kind: 'payslip', id: id, number: monthWords(s.month)),
        body: (context, s) => [
          if (s.isFestivalBonus) const _FestivalBonusLabel(key: ValueKey('payslip-festival-bonus')),
          TotalStrip(label: context.l10n.hrPayslipNet, value: Money.taka(s.net)),
          if (s.name.isNotEmpty) FactRow(context.l10n.hrPayslipName, s.code.isEmpty ? s.name : '${s.name} (${s.code})'),
          FactRow(context.l10n.hrPayslipMonth, monthWords(s.month)),
          if (s.paymentMethod.isNotEmpty) FactRow(context.l10n.hrPayslipHow, _paidBy(context, s.paymentMethod)),
          SectionTitle(context.l10n.hrPayslipEarnings),
          for (final l in s.lines.where((l) => l.earning)) FactRow(l.name, Money.taka(l.amount)),
          FactRow(context.l10n.hrPayslipGross, Money.taka(s.gross), colour: context.palette.success),
          SectionTitle(context.l10n.hrPayslipDeductions),
          for (final l in s.lines.where((l) => !l.earning)) FactRow(l.name, Money.taka(l.amount)),
          FactRow(context.l10n.hrPayslipTotalDeductions, Money.taka(s.deductions), colour: context.palette.danger),
        ],
      );
}

/// "উৎসব ভাতা" — বোনাসের রানের স্লিপের চিহ্ন (তালিকার সারি আর স্লিপের পাতা)
class _FestivalBonusLabel extends StatelessWidget {
  const _FestivalBonusLabel({super.key});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 2),
        child: Text(context.l10n.hrPayslipFestivalBonus,
            style: TextStyle(color: context.palette.success, fontWeight: FontWeight.w700)),
      );
}
