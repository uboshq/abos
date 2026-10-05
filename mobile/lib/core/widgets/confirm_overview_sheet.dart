import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';

/// নিশ্চিতের আগে সারাংশ — সার্ভারের কেন্দ্রীয় ইঞ্জিনের ([[ConfirmOverview]]) একই আকার, নিচ থেকে ওঠা পাতায়
/// (মালিক, ৪ অক্টোবর ২০২৬: *"নিশ্চিত করুন botam caple ekta overvew dekhabe … sob kichutei"*)।
///
/// <p>ⓘ যেকোনো কাগজ — সরাসরি বিক্রয় আজ, বাকিগুলো পরে — এই একটা পাতাই দেখায়; কাগজ কেবল সারাংশ পাঠায়।
/// এক লাইনে এক জিনিস (মালিকের নিয়ম)। ⛔ পাতা কেবল দেখায় — আসল পাহারা সার্ভারে, নিশ্চিতের সময়।
class ConfirmOverviewData {
  const ConfirmOverviewData({
    required this.title,
    required this.head,
    required this.lines,
    required this.totals,
    required this.money,
    required this.notes,
    required this.blocks,
  });

  final String title;
  final List<(String, String)> head;
  final List<({String title, List<String> details, String? amount})> lines;
  final List<({String label, String amount, bool strong})> totals;
  final List<({String label, String amount, String tone})> money;
  final List<({String text, String tone})> notes;

  /// সার্ভার বলছে নিশ্চিত হবে না (যেমন সীমা পার) — "নিশ্চিত" বন্ধ, খসড়া খোলা
  final bool blocks;

  factory ConfirmOverviewData.fromJson(Map<String, dynamic> json) {
    List<Map<String, dynamic>> rows(String key) => [
          for (final r in (json[key] as List?) ?? const [])
            if (r is Map) Map<String, dynamic>.from(r),
        ];
    return ConfirmOverviewData(
      title: json['title']?.toString() ?? '',
      head: [for (final r in rows('head')) (r['label'].toString(), r['value'].toString())],
      lines: [
        for (final r in rows('lines'))
          (
            title: r['title'].toString(),
            details: [for (final d in (r['details'] as List?) ?? const []) d.toString()],
            amount: r['amount']?.toString(),
          ),
      ],
      totals: [
        for (final r in rows('totals')) (label: r['label'].toString(), amount: r['amount'].toString(), strong: r['strong'] == true),
      ],
      money: [
        for (final r in rows('money')) (label: r['label'].toString(), amount: r['amount'].toString(), tone: r['tone']?.toString() ?? 'plain'),
      ],
      notes: [for (final r in rows('notes')) (text: r['text'].toString(), tone: r['tone']?.toString() ?? 'info')],
      blocks: json['blocks'] == true,
    );
  }
}

/// পাতার তিন উত্তর
enum OverviewChoice { confirm, draft, back }

Future<OverviewChoice?> showConfirmOverview(BuildContext context, ConfirmOverviewData data, {bool allowDraft = true}) =>
    showModalBottomSheet<OverviewChoice>(
      context: context,
      isScrollControlled: true,
      builder: (_) => ConfirmOverviewSheet(data: data, allowDraft: allowDraft),
    );

class ConfirmOverviewSheet extends StatelessWidget {
  const ConfirmOverviewSheet({super.key, required this.data, this.allowDraft = true});

  final ConfirmOverviewData data;
  final bool allowDraft;

  Color _tone(String tone) => switch (tone) {
        'bad' || 'stop' => AppColors.danger,
        'good' => AppColors.success,
        'warn' => AppColors.warning,
        _ => Colors.black87,
      };

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.9),
        child: ListView(
          key: const ValueKey('overview-sheet'),
          shrinkWrap: true,
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Text(data.title, style: text.titleLarge),
            const SizedBox(height: AppSpacing.sm),
            for (final (label, value) in data.head) Text('$label: $value'),
            const Divider(),
            for (final line in data.lines) ...[
              Text(line.title, style: const TextStyle(fontWeight: FontWeight.w600)),
              for (final d in line.details) Text(d),
              if (line.amount != null) Text('৳ ${line.amount}'),
              const SizedBox(height: AppSpacing.sm),
            ],
            const Divider(),
            for (final t in data.totals)
              Text('${t.label}: ৳ ${t.amount}', style: t.strong ? text.titleMedium : null),
            const Divider(),
            for (final m in data.money) Text('${m.label}: ৳ ${m.amount}', style: TextStyle(color: _tone(m.tone))),
            for (final n in data.notes)
              Card(
                color: n.tone == 'stop' ? AppColors.dangerSurface : null,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Text(n.text, style: TextStyle(color: _tone(n.tone))),
                ),
              ),
            const SizedBox(height: AppSpacing.md),
            FilledButton(
              key: const ValueKey('overview-confirm'),
              onPressed: data.blocks ? null : () => Navigator.pop(context, OverviewChoice.confirm),
              child: const Text('নিশ্চিত করুন'),
            ),
            if (allowDraft) ...[
              const SizedBox(height: AppSpacing.xs),
              // ⓘ ধূসর — মালিক, ২৭ সেপ্টেম্বর ২০২৬
              FilledButton(
                key: const ValueKey('overview-draft'),
                style: FilledButton.styleFrom(backgroundColor: Colors.grey.shade400, foregroundColor: Colors.black87),
                onPressed: () => Navigator.pop(context, OverviewChoice.draft),
                child: const Text('খসড়া রাখুন'),
              ),
            ],
            const SizedBox(height: AppSpacing.xs),
            OutlinedButton(
              key: const ValueKey('overview-back'),
              onPressed: () => Navigator.pop(context, OverviewChoice.back),
              child: const Text('ফিরে যান'),
            ),
          ],
        ),
      ),
    );
  }
}
