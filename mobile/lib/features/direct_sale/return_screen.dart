import 'package:flutter/material.dart';

import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/status/paper_state.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/orders/sales_return_api.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/theme/app_palette.dart';
import '../../core/widgets/confirm_overview_sheet.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/state_views.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../books/books_widgets.dart' show dayOf;
import '../../core/widgets/design_list_row.dart';

/// ⭐ বিক্রি ফেরত, ফোনে — কাউন্টারের "ফেরত" বোতাম (মালিক, ৪ অক্টোবর ২০২৬)।
///
/// <p>ধাপ ওয়েবের মতোই: বিল বাছা → কোন মাল কত ফেরত আর কেন → খসড়া ফেরত → নিশ্চিতের আগে সারাংশ → নিশ্চিত।
/// ⛔ ফোন কেবল বিলের সারি থেকে ফেরত নেয় — বিলের বাইরের মাল, অন্য দর বা অন্য লট পাঠানোর পথই নেই।
class ReturnScreen extends StatefulWidget {
  const ReturnScreen(
      {super.key, this.customerId, this.api = const ServerSalesReturnApi()});

  final String? customerId;
  final SalesReturnApi api;

  @override
  State<ReturnScreen> createState() => _ReturnScreenState();
}

class _ReturnScreenState extends State<ReturnScreen> {
  ReturnSetup? _setup;
  ReturnBill? _bill;
  String? _reasonId;
  final Map<String, TextEditingController> _qty = {};
  final _note = TextEditingController();
  /// ⭐ এই কাজের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]], অডিট ফোন ⚠️১২)
  final _once = OnceKey();
  String? _draftId;
  bool _busy = false;
  String? _error;

  /// তালিকা আনার ভুল — আলাদা, যাতে "আবার চেষ্টা করুন" কেবল আনাটাই আবার চালায় (নকশা ৩, পাতা ৮)
  String? _setupError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [..._qty.values, _note]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _setupError = null;
    });
    try {
      final setup = await widget.api.setup(customerId: widget.customerId);
      if (mounted) {
        setState(() {
          _setup = setup;
          _reasonId = setup.reasons.firstOrNull?.id;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() =>
            _setupError = errorMessageFor(e, fallback: 'ফেরতের তালিকা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _openBill(ReturnInvoice invoice) async {
    setState(() => _busy = true);
    try {
      final bill = await widget.api.bill(invoice.id);
      if (!mounted) return;
      setState(() {
        _bill = bill;
        for (final c in _qty.values) {
          c.dispose();
        }
        _qty
          ..clear()
          ..addAll({for (final l in bill.lines) l.id: TextEditingController()});
      });
    } catch (e) {
      if (mounted) {
        await _popup('বিল খোলা গেল না',
            errorMessageFor(e, fallback: 'আবার চেষ্টা করুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _go() async {
    final bill = _bill;
    final reason = _reasonId;
    // ⛔ লেখা আছে অথচ পড়া যায় না (অক্ষর, দুই দশমিক) — বাংলা অঙ্ক আর হাজারের কমা পড়া যায়; আগে লাইনটা ফেরত থেকে চুপচাপ বাদ পড়ত (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
    if (_qty.values.any((c) => Money.typed(c.text) == null)) {
      setState(() => _error = 'ফেরতের পরিমাণ পড়া গেল না — কেবল সংখ্যা লিখুন।');
      return;
    }
    final lines = {
      for (final e in _qty.entries)
        e.key: Money.typed(e.value.text) ?? 0
    }..removeWhere((_, v) => v <= 0);
    if (bill == null || reason == null || lines.isEmpty) {
      setState(() => _error =
          reason == null ? 'কারণ বাছুন।' : 'অন্তত একটা মালের ফেরত পরিমাণ দিন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      // ⛔ খসড়া একবারই — পরের ধাপ (সারাংশ) নেটে ব্যর্থ হলে আবার চাপায় আগের খসড়াই চলে; আগে চাবি মুছে যেত আর দ্বিতীয়
      // খসড়া বসত, প্রথমটা পড়ে থাকত (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
      final String id = _draftId ?? await _once.send(() => widget.api.draft(
          invoiceId: bill.id, reasonId: reason, lines: lines, note: _note.text));
      _draftId = id;
      final overview = await widget.api.overview(id);
      if (!mounted) return;
      setState(() => _busy = false);
      final choice = await showConfirmOverview(context, overview);
      if (!mounted) return;
      if (choice != OverviewChoice.confirm) {
        await _popup('খসড়া ফেরত রাখা হলো',
            'নিশ্চিত হয়নি — ওয়েবের ফেরতের তালিকায় খসড়া হয়ে আছে।');
        if (mounted) Navigator.of(context).pop();
        return;
      }
      setState(() => _busy = true);
      final (status, notice) = await widget.api.confirm(id);
      if (!mounted) return;
      setState(() => _busy = false);
      await _popup(status == 'done' ? 'ফেরত হলো' : 'সইয়ের অপেক্ষায়', notice);
      if (mounted) Navigator.of(context).pop();
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        await _popup(
            'ফেরত হলো না', errorMessageFor(e, fallback: 'আবার চেষ্টা করুন।'));
      }
    }
  }

  Future<void> _popup(String title, String text) => showDialog<void>(
        context: context,
        builder: (dialog) => AlertDialog(
          key: const ValueKey('return-popup'),
          title: Text(title),
          content: Text(text),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(dialog),
                child: const Text('ঠিক আছে'))
          ],
        ),
      );

  // ⭐ বিলে ফেরতের পরিমাণ বা বিবরণ লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা ফেরত এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _bill != null && !_busy && (_qty.values.any((c) => c.text.trim().isNotEmpty) || _note.text.trim().isNotEmpty), child: _screen(context));

  Widget _screen(BuildContext context) {
    final setup = _setup;
    final bill = _bill;

    return Scaffold(
      appBar: const AbosScreenBar(title: 'বিক্রি ফেরত', module: 'বিক্রয়'),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): প্রথম আনায় খসড়া সারি, পরে উপরে সরু দাগ; আনার ভুলে "আবার চেষ্টা করুন"
          if (_busy && setup == null) const LoadingSkeleton(rows: 4),
          if (_busy && setup != null) const LinearProgressIndicator(),
          if (_setupError != null && setup == null)
            ErrorState(key: const ValueKey('return-load-error'), message: _setupError!, onRetry: _busy ? null : _load),
          // ⭐ ভুলের পট আর লেখা জোড়া রং — লাল পটে নিজের লাল লেখা, দুই মোডেই পড়া যায় (নকশার নিয়ম ০.৪.৩৮ §১, a4, ১৯ অক্টোবর ২০২৬)
          if (_error != null)
            Card(
              color: context.palette.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_error!,
                    key: const ValueKey('return-error'),
                    style: TextStyle(
                        color: context.palette.danger)),
              ),
            ),
          if (setup != null && bill == null) ...[
            Text('কোন বিলের ফেরত',
                style: Theme.of(context).textTheme.titleMedium),
            if (setup.invoices.isEmpty)
              EmptyState(
                key: const ValueKey('return-empty'),
                icon: Icons.receipt_long_outlined,
                title: 'ফেরত দেওয়ার মতো কোনো নিশ্চিত বিল নেই।',
                message: 'বিল পাকা হলে এখানে আসবে।',
                action: OutlinedButton.icon(
                    key: const ValueKey('return-empty-refresh'),
                    style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                    onPressed: _busy ? null : _load,
                    icon: const Icon(Icons.refresh),
                    label: const Text('নতুন করে দেখুন')),
              ),
            // ⭐ নকশা ৩-এর সারি: ক্রেতা "নাম · পয়েন্ট · শাখা", ডানে বিলের টাকা; দ্বিতীয় লাইনে বিলের নম্বর · তারিখ।
            // ⓘ টাকা Money.taka — ইংরেজি অঙ্ক, লাখের কমা (⛔ আগে সার্ভারের কাঁচা লেখা "৳ 12500.0000" সোজা বসত)
            for (final i in setup.invoices)
              DesignListRow(
                key: ValueKey('return-bill-${i.id}'),
                icon: Icons.receipt_long_outlined,
                name: i.customer.isEmpty ? '—' : i.customer,
                second: '${i.no} · ${dayOf(i.date)}',
                amount: Money.valueOrZero(i.total),
                // ⭐ ফেরত নেওয়া যায় কেবল পাকা বিলের — অ্যাপের এক চিপ (নকশা ৩, অংশ ৫)
                pill: const AppStatusChip.compact(state: PaperState.posted),
                onTap: _busy ? null : () => _openBill(i),
              ),
          ],
          if (setup != null && bill != null) ...[
            // ⭐ ক্রেতা "নাম · পয়েন্ট · শাখা" এক লাইনে, বিলের নম্বর দ্বিতীয় লাইনে (মালিক, ১৯ অক্টোবর ২০২৬)
            CustomerLine(bill.customerName,
                key: const ValueKey('return-bill-customer'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600)),
            Text('বিল ${bill.no}',
                key: const ValueKey('return-bill-no'),
                style: TextStyle(fontSize: 13, color: Theme.of(context).colorScheme.onSurfaceVariant)),
            for (final l in bill.lines)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(l.name,
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      if (l.lotNo != null) Text('লট: ${l.lotNo}'),
                      Text('বেচা: ${l.qty} × ${Money.taka(l.rate)}'),
                      TextField(
                        key: ValueKey('return-qty-${l.id}'),
                        controller: _qty[l.id],
                        onChanged: (_) => setState(() {}),
                        keyboardType: const TextInputType.numberWithOptions(
                            decimal: true),
                        decoration: const InputDecoration(labelText: 'কত ফেরত'),
                      ),
                    ],
                  ),
                ),
              ),
            if (setup.reasons.isNotEmpty)
              DropdownButtonFormField<String>(
                key: const ValueKey('return-reason'),
                initialValue: _reasonId,
                decoration: const InputDecoration(labelText: 'কেন ফেরত'),
                items: [
                  for (final r in setup.reasons)
                    DropdownMenuItem(value: r.id, child: Text(r.label))
                ],
                onChanged: (v) => setState(() => _reasonId = v),
              ),
            TextField(
                controller: _note,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(labelText: 'বিবরণ (ঐচ্ছিক)')),
            const SizedBox(height: AppSpacing.md),
            FilledButton(
              key: const ValueKey('return-go'),
              onPressed: _busy ? null : _go,
              child: const Text('নিশ্চিত করুন'),
            ),
            TextButton(
                onPressed: _busy ? null : () => setState(() => _bill = null),
                child: const Text('অন্য বিল বাছুন')),
          ],
        ],
      ),
    );
  }
}
