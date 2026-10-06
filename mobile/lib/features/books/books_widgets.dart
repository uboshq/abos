import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// টাকা আদায়, প্রিন্সিপাল আর ক্রয়ের পাতার একসাথের টুকরো — তারিখের পরিসর, মোটের পট্টি, ভুলের ঘর।

/// সার্ভারের `Y-m-d` → `dd/MM/yyyy` (ওয়েবের তারিখের রূপ); খালি হলে "—"
String dayOf(String? iso) {
  final parsed = iso == null ? null : DateTime.tryParse(iso);
  return parsed == null ? '—' : DateFormat('dd/MM/yyyy').format(parsed);
}

String isoOf(DateTime day) => DateFormat('yyyy-MM-dd').format(day);

/// তারিখের পরিসর — আজ · এ মাস · নিজে বাছা
class DateRangeBar extends StatelessWidget {
  const DateRangeBar({
    super.key,
    required this.from,
    required this.to,
    required this.onChanged,
    this.today,
  });

  final DateTime from;
  final DateTime to;
  final void Function(DateTime from, DateTime to) onChanged;

  /// পরীক্ষার জন্য — না দিলে আজ
  final DateTime? today;

  @override
  Widget build(BuildContext context) {
    final now = today ?? DateTime.now();
    final day = DateTime(now.year, now.month, now.day);
    final monthStart = DateTime(now.year, now.month);
    final isToday = from == day && to == day;
    final isMonth = from == monthStart && to == day;

    return Wrap(
      spacing: AppSpacing.xs,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        ChoiceChip(
          key: const ValueKey('range-today'),
          label: const Text('আজ'),
          selected: isToday,
          onSelected: (_) => onChanged(day, day),
        ),
        ChoiceChip(
          key: const ValueKey('range-month'),
          label: const Text('এ মাস'),
          selected: isMonth,
          onSelected: (_) => onChanged(monthStart, day),
        ),
        ActionChip(
          key: const ValueKey('range-pick'),
          avatar: const Icon(Icons.date_range, size: 18),
          label: Text(
              '${DateFormat('dd/MM/yyyy').format(from)} – ${DateFormat('dd/MM/yyyy').format(to)}'),
          onPressed: () async {
            final picked = await showDateRangePicker(
              context: context,
              firstDate: DateTime(now.year - 5),
              lastDate: day,
              initialDateRange: DateTimeRange(start: from, end: to),
            );
            if (picked != null) onChanged(picked.start, picked.end);
          },
        ),
      ],
    );
  }
}

/// "মোট ৳… · n টি" — তালিকার মাথায়, সার্ভারের গোটা ছাঁকনির যোগ (কেবল এই পাতার নয়)
class TotalStrip extends StatelessWidget {
  const TotalStrip(
      {super.key, required this.label, required this.value, this.count});

  final String label;
  final String value;
  final int? count;

  @override
  Widget build(BuildContext context) => Card(
        key: const ValueKey('books-total'),
        color: AppColors.successSurface,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Row(
            children: [
              Expanded(
                  child: Text(label,
                      style: const TextStyle(fontWeight: FontWeight.w600))),
              Text(value,
                  style: const TextStyle(
                      fontSize: 18, fontWeight: FontWeight.w800)),
              if (count != null)
                Padding(
                  padding: const EdgeInsets.only(left: AppSpacing.sm),
                  child: Text('· $count টি',
                      style: const TextStyle(color: AppColors.onSurfaceMuted)),
                ),
            ],
          ),
        ),
      );
}

class BooksError extends StatelessWidget {
  const BooksError(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Card(
        key: const ValueKey('books-error'),
        color: AppColors.dangerSurface,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Text(text, style: const TextStyle(color: AppColors.danger)),
        ),
      );
}

/// বিস্তারিতের এক সারি — বাঁয়ে নাম, ডানে মান
class FactRow extends StatelessWidget {
  const FactRow(this.label, this.value, {super.key, this.colour});

  final String label;
  final String value;
  final Color? colour;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
                child: Text(label,
                    style: const TextStyle(color: AppColors.onSurfaceMuted))),
            Flexible(
              child: Text(value,
                  textAlign: TextAlign.end,
                  style: TextStyle(fontWeight: FontWeight.w600, color: colour)),
            ),
          ],
        ),
      );
}

/// "আরও দেখুন" — পরের পাতা থাকলে
class MoreButton extends StatelessWidget {
  const MoreButton({super.key, required this.busy, required this.onPressed});

  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Center(
        child: TextButton.icon(
          key: const ValueKey('books-more'),
          onPressed: busy ? null : onPressed,
          icon: const Icon(Icons.expand_more),
          label: const Text('আরও দেখুন'),
        ),
      );
}
