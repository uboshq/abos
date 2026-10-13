import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/hr/self_service_api.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/paged_list.dart';
import '../books/books_widgets.dart';
import '../finance/finance_screens.dart' show monthWords;

/// ⭐ নিজের ছুটি আর নিজের বেতনের স্লিপ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস। আবেদন পাঠানো মানে ছুটি পাওয়া নয় — মঞ্জুরি অফিসের।

Color leaveColour(String status) => switch (status) {
      'approved' => AppColors.success,
      'rejected' => AppColors.danger,
      'cancelled' => AppColors.onSurfaceMuted,
      _ => AppColors.pending,
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
            fallback: 'ছুটির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ফোনের ছুটি এখনো আসেনি — অফিসে জানান।'));
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
      appBar: AppBar(title: const Text('আমার ছুটি')),
      floatingActionButton: canApply
          ? FloatingActionButton.extended(
              key: const ValueKey('leave-new'),
              onPressed: () async {
                final sent = await Navigator.of(context).push<LeaveRequest>(
                    MaterialPageRoute(builder: (_) => NewLeaveScreen(api: widget.api, types: book.types)));
                if (sent != null && context.mounted) {
                  ScaffoldMessenger.of(context)
                      .showSnackBar(const SnackBar(content: Text('ছুটির আবেদন গেছে — অনুমোদনের অপেক্ষায়।')));
                  await _load();
                }
              },
              icon: const Icon(Icons.add),
              label: const Text('ছুটির আবেদন'),
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
              const EmptyState(
                icon: Icons.badge_outlined,
                title: 'আপনার নামে কর্মীর খাতা নেই',
                message: 'ছুটির আবেদন করতে HR-কে বলুন আপনার লগইন কর্মীর খাতার সাথে জুড়ে দিতে।',
              ),
            if (book != null && book.employee) ...[
              const SectionTitle('এ বছর কত বাকি'),
              if (book.types.isEmpty)
                const Text('এখনো কোনো ছুটির ধরন নেই — HR ঠিক করে দেবেন।', style: TextStyle(color: AppColors.onSurfaceMuted)),
              for (final t in book.types)
                FactRow(t.name, t.left == null ? 'সীমা নেই · নেওয়া ${t.taken} দিন' : '${t.left} দিন বাকি · মোট ${t.allowed}',
                    key: ValueKey('leave-type-${t.id}')),
              const SectionTitle('আমার আবেদন'),
              if (book.applications.isEmpty)
                const EmptyState(icon: Icons.event_available_outlined, title: 'এখনো কোনো ছুটির আবেদন নেই'),
              for (final a in book.applications)
                Card(
                  key: ValueKey('leave-${a.id}'),
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    title: Text('${a.type} · ${a.days} দিন', style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(a.fromDate == a.toDate ? dayOf(a.fromDate) : '${dayOf(a.fromDate)} – ${dayOf(a.toDate)}'),
                        if (a.reason.isNotEmpty) Text(a.reason, maxLines: 1, overflow: TextOverflow.ellipsis),
                        if (a.remarks.isNotEmpty) Text('অফিসের কথা: ${a.remarks}', maxLines: 2, overflow: TextOverflow.ellipsis),
                      ],
                    ),
                    trailing: Text(a.statusWords,
                        style: TextStyle(color: leaveColour(a.status), fontWeight: FontWeight.w700)),
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
  }

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
        ? 'কোন ছুটি, বাছুন।'
        : !_daysShape.hasMatch(days) || (double.tryParse(days) ?? 0) <= 0
            ? 'দিনের সংখ্যা ঠিকভাবে লিখুন — যেমন ২ বা ১.৫।'
            : (double.tryParse(days) ?? 0) > _span
                ? 'দিনের সংখ্যা তারিখের ঘরের চেয়ে বেশি — $_span দিনের বেশি নয়।'
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
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'আবেদন পাঠানো গেল না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('ছুটির আবেদন')),
        body: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            const Text('আবেদন যায় অফিসে; মঞ্জুর হলে দিনগুলো হাজিরায় "ছুটি" হয়ে বসে।',
                style: TextStyle(color: AppColors.onSurfaceMuted)),
            const SizedBox(height: AppSpacing.sm),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              Card(
                key: const ValueKey('leave-error'),
                color: AppColors.dangerSurface,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Text(_error!, style: const TextStyle(color: AppColors.danger)),
                ),
              ),
            DropdownButtonFormField<String>(
              key: const ValueKey('leave-type'),
              initialValue: _type,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'কোন ছুটি'),
              items: [
                for (final t in widget.types)
                  DropdownMenuItem(
                    value: t.id,
                    child: Text(t.left == null ? t.name : '${t.name} (${t.left} দিন বাকি)', overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) => setState(() => _type = v),
            ),
            ListTile(
              key: const ValueKey('leave-from'),
              contentPadding: EdgeInsets.zero,
              title: const Text('কবে থেকে'),
              subtitle: Text('${_from.day}/${_from.month}/${_from.year}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: () => _pick(from: true),
            ),
            ListTile(
              key: const ValueKey('leave-to'),
              contentPadding: EdgeInsets.zero,
              title: const Text('কবে পর্যন্ত'),
              subtitle: Text('${_to.day}/${_to.month}/${_to.year}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: () => _pick(from: false),
            ),
            TextField(
              key: const ValueKey('leave-days'),
              controller: _days,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'কত দিন (আধা দিন হলে .৫)'),
            ),
            TextField(
              key: const ValueKey('leave-reason'),
              controller: _reason,
              maxLength: 500,
              maxLines: 3,
              minLines: 1,
              decoration: const InputDecoration(labelText: 'কারণ'),
            ),
            const SizedBox(height: AppSpacing.md),
            FilledButton.icon(
              key: const ValueKey('leave-send'),
              onPressed: _busy ? null : _send,
              icon: const Icon(Icons.send_outlined),
              label: const Text('পাঠান'),
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
        appBar: AppBar(title: const Text('আমার বেতনের স্লিপ')),
        body: PagedList<Payslip>(
          load: (page) async {
            final p = await widget.api.payslips(page: page);
            if (mounted && p.employee != _employee) setState(() => _employee = p.employee);
            return (p.rows, p.nextPage);
          },
          failed: 'স্লিপের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
          whenAbsent: 'সার্ভারে ফোনের বেতনের স্লিপ এখনো আসেনি — অফিসে জানান।',
          emptyIcon: Icons.receipt_long_outlined,
          emptyTitle: _employee ? 'এখনো কোনো পাকা বেতনের স্লিপ নেই' : 'আপনার নামে কর্মীর খাতা নেই',
          emptyMessage: _employee ? 'বেতনের রান পাকা হলে স্লিপ এখানে আসে।' : 'HR-কে বলুন আপনার লগইন কর্মীর খাতার সাথে জুড়ে দিতে।',
          item: (context, s) => Card(
            margin: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: ListTile(
              key: ValueKey('payslip-${s.id}'),
              title: Text(monthWords(s.month), style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text('আয় ${Money.taka(s.gross)} · কর্তন ${Money.taka(s.deductions)}'),
              trailing: Text(Money.taka(s.net), style: const TextStyle(fontWeight: FontWeight.w800)),
              onTap: () => Navigator.of(context)
                  .push(MaterialPageRoute<void>(builder: (_) => PayslipScreen(id: s.id, api: widget.api))),
            ),
          ),
        ),
      );
}

String _paidBy(String method) => switch (method) {
      'cash' => 'নগদ',
      'bank' => 'ব্যাংক',
      'mfs' => 'মোবাইল ব্যাংকিং',
      _ => method,
    };

class PayslipScreen extends StatelessWidget {
  const PayslipScreen({super.key, required this.id, this.api = const ServerSelfServiceApi()});

  final String id;
  final SelfServiceApi api;

  @override
  Widget build(BuildContext context) => PaperPage<Payslip>(
        title: 'বেতনের স্লিপ',
        titleOf: (s) => monthWords(s.month),
        load: () => api.payslip(id),
        failed: 'স্লিপটা আনা গেল না।',
        onLoaded: (s) => RecentPapers.note(kind: 'payslip', id: id, number: monthWords(s.month)),
        body: (context, s) => [
          TotalStrip(label: 'নিট বেতন', value: Money.taka(s.net)),
          if (s.name.isNotEmpty) FactRow('নাম', s.code.isEmpty ? s.name : '${s.name} (${s.code})'),
          FactRow('মাস', monthWords(s.month)),
          if (s.paymentMethod.isNotEmpty) FactRow('কীভাবে', _paidBy(s.paymentMethod)),
          const SectionTitle('আয়'),
          for (final l in s.lines.where((l) => l.earning)) FactRow(l.name, Money.taka(l.amount)),
          FactRow('মোট আয়', Money.taka(s.gross), colour: AppColors.success),
          const SectionTitle('কর্তন'),
          for (final l in s.lines.where((l) => !l.earning)) FactRow(l.name, Money.taka(l.amount)),
          FactRow('মোট কর্তন', Money.taka(s.deductions), colour: AppColors.danger),
        ],
      );
}
