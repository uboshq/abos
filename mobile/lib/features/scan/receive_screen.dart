import 'package:flutter/material.dart';

import '../../core/orders/paper_scan_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ "বুঝিয়ে দিন" — পৌঁছানোর প্রমাণ, মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬: *"ডিলার বুঝে নিলেন —
/// নাম, ফোন … কম বা ভাঙা মাল → ফেরত বা দাবি"*।
///
/// <p>যিনি নিলেন তাঁর নাম আর ফোন (দুইটাই লাগে); প্রতিটা সারিতে কতটা ভালো অবস্থায় নিলেন আর কতটা ভাঙা — "কম" নিজে
/// গোনা হয়। সব পুরো আর ভাঙা নেই হলে "পৌঁছেছে", নাহলে সার্ভার "আংশিক" লেখে আর ফেরত বানায় (কম বিক্রয়যোগ্য, ভাঙা আটকে)।
/// QR পাতা আর আজকের ডেলিভারি — দুই জায়গাই এটা খোলে।
class ReceiveResult {
  const ReceiveResult(
      {required this.receiver,
      required this.phone,
      required this.taken,
      required this.damaged});

  final String receiver;
  final String phone;
  final Map<int, double> taken;
  final Map<int, double> damaged;
}

class ReceiveScreen extends StatefulWidget {
  const ReceiveScreen(
      {super.key,
      required this.documentNo,
      required this.lines,
      this.customer});

  final String documentNo;
  final String? customer;
  final List<ScannedLine> lines;

  @override
  State<ReceiveScreen> createState() => _ReceiveScreenState();
}

class _ReceiveScreenState extends State<ReceiveScreen> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  late final Map<int, TextEditingController> _taken = {
    for (final l in widget.lines)
      l.line: TextEditingController(text: _plain(l.qty)),
  };
  late final Map<int, TextEditingController> _damaged = {
    for (final l in widget.lines) l.line: TextEditingController(text: '0'),
  };
  String? _error;

  static String _plain(double v) =>
      v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toString();

  static double _num(TextEditingController c) =>
      double.tryParse(c.text.trim()) ?? -1;

  @override
  void dispose() {
    for (final c in [_name, _phone, ..._taken.values, ..._damaged.values]) {
      c.dispose();
    }
    super.dispose();
  }

  void _done() {
    final name = _name.text.trim();
    final phone = _phone.text.trim();
    String? why;
    if (name.isEmpty) {
      why = 'যিনি মাল নিলেন তাঁর নাম লিখুন।';
    } else if (!RegExp(r'^\+?[0-9][0-9\- ]{5,19}$').hasMatch(phone)) {
      why = 'যিনি নিলেন তাঁর ফোন নম্বর লিখুন — পরে জিজ্ঞেস করার পথ এটাই।';
    } else {
      for (final l in widget.lines) {
        final took = _num(_taken[l.line]!);
        final bad = _num(_damaged[l.line]!);
        if (took < 0 || bad < 0) {
          why = '${l.product}: পরিমাণ ঠিক নেই।';
          break;
        }
        if (took + bad > l.qty + 0.00001) {
          why = '${l.product}: নেওয়া আর ভাঙা মিলে চালানের চেয়ে বেশি।';
          break;
        }
      }
    }
    if (why != null) {
      setState(() => _error = why);
      return;
    }
    Navigator.pop(
      context,
      ReceiveResult(
        receiver: name,
        phone: phone,
        taken: {for (final l in widget.lines) l.line: _num(_taken[l.line]!)},
        damaged: {
          for (final l in widget.lines) l.line: _num(_damaged[l.line]!)
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text('বুঝিয়ে দিন — ${widget.documentNo}')),
        body: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (widget.customer != null && widget.customer!.isNotEmpty)
              Text(widget.customer!,
                  style: Theme.of(context).textTheme.titleMedium),
            TextField(
              key: const ValueKey('receive-name'),
              controller: _name,
              decoration: const InputDecoration(labelText: 'যিনি মাল নিলেন'),
            ),
            TextField(
              key: const ValueKey('receive-phone'),
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'তাঁর ফোন'),
            ),
            const SizedBox(height: AppSpacing.md),
            const Text(
                'প্রতিটা পণ্য — কতটা নিলেন, কতটা ভাঙা; বাকিটা কম (ফেরত আসবে)',
                style: TextStyle(color: AppColors.onSurfaceMuted)),
            for (final l in widget.lines)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${l.product} · চালানে ${Money.plain(l.qty)}',
                          style: const TextStyle(fontWeight: FontWeight.w600)),
                      Row(children: [
                        Expanded(
                          child: TextField(
                            key: ValueKey('receive-taken-${l.line}'),
                            controller: _taken[l.line],
                            keyboardType: const TextInputType.numberWithOptions(
                                decimal: true),
                            decoration:
                                const InputDecoration(labelText: 'নিলেন'),
                            onChanged: (_) => setState(() {}),
                          ),
                        ),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(
                          child: TextField(
                            key: ValueKey('receive-damaged-${l.line}'),
                            controller: _damaged[l.line],
                            keyboardType: const TextInputType.numberWithOptions(
                                decimal: true),
                            decoration:
                                const InputDecoration(labelText: 'ভাঙা'),
                            onChanged: (_) => setState(() {}),
                          ),
                        ),
                      ]),
                      Builder(builder: (_) {
                        final short = l.qty -
                            _num(_taken[l.line]!) -
                            _num(_damaged[l.line]!);
                        return short > 0.00001
                            ? Text('কম: ${Money.plain(short)}',
                                key: ValueKey('receive-short-${l.line}'),
                                style: const TextStyle(color: AppColors.danger))
                            : const SizedBox.shrink();
                      }),
                    ],
                  ),
                ),
              ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: Text(_error!,
                    key: const ValueKey('receive-error'),
                    style: const TextStyle(color: AppColors.danger)),
              ),
            FilledButton(
              key: const ValueKey('receive-done'),
              onPressed: _done,
              child: const Text('নিশ্চিত — বুঝে নিলেন'),
            ),
          ],
        ),
      );
}
