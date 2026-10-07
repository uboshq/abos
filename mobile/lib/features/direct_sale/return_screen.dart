import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/orders/sales_return_api.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/confirm_overview_sheet.dart';

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
  bool _busy = false;
  String? _error;

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
    setState(() => _busy = true);
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
            _error = errorMessageFor(e, fallback: 'ফেরতের তালিকা আনা গেল না।'));
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
    final lines = {
      for (final e in _qty.entries)
        e.key: double.tryParse(e.value.text.trim()) ?? 0
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
      final id = await _once.send(() => widget.api.draft(
          invoiceId: bill.id, reasonId: reason, lines: lines, note: _note.text));
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

  @override
  Widget build(BuildContext context) {
    final setup = _setup;
    final bill = _bill;

    return Scaffold(
      appBar: AppBar(title: const Text('বিক্রি ফেরত')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_error != null)
            Card(
              color: AppColors.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_error!,
                    key: const ValueKey('return-error'),
                    style: const TextStyle(color: AppColors.danger)),
              ),
            ),
          if (setup != null && bill == null) ...[
            Text('কোন বিলের ফেরত',
                style: Theme.of(context).textTheme.titleMedium),
            if (setup.invoices.isEmpty)
              const Text('ফেরত দেওয়ার মতো কোনো নিশ্চিত বিল নেই।'),
            for (final i in setup.invoices)
              Card(
                child: ListTile(
                  key: ValueKey('return-bill-${i.id}'),
                  title: Text(i.no),
                  subtitle:
                      Text('${i.customer}\n${i.date ?? ''} · ৳ ${i.total}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: _busy ? null : () => _openBill(i),
                ),
              ),
          ],
          if (setup != null && bill != null) ...[
            Text('বিল ${bill.no} · ${bill.customerName}',
                style: Theme.of(context).textTheme.titleMedium),
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
                      Text('বেচা: ${l.qty} × ৳ ${l.rate.toStringAsFixed(2)}'),
                      TextField(
                        key: ValueKey('return-qty-${l.id}'),
                        controller: _qty[l.id],
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
