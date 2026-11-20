import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/records/money.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/theme/expressive.dart';
import '../../core/theme/glass.dart';
import '../../core/theme/look_widgets.dart';
import '../../core/widgets/totals_bar.dart';
import '../../core/locale/l10n.dart';

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
          label: Text(context.l10n.booksRangeToday),
          selected: isToday,
          onSelected: (_) => onChanged(day, day),
        ),
        ChoiceChip(
          key: const ValueKey('range-month'),
          label: Text(context.l10n.booksRangeMonth),
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
      {super.key, required this.label, required this.value, this.count, this.period});

  final String label;
  final String value;
  final int? count;

  /// ⭐ মোটের সময়কাল — নামের নিচে ([TotalsPeriodLabel], নিচের মোটের পট্টির একই লেখা); null কেবল তারিখহীন মোটে
  final TotalsPeriod? period;

  @override
  Widget build(BuildContext context) {
    // ⭐ রূপ ধরে (০.৪.৬০): ABOS-এ সবুজ পট্টি (আগের মতো); Liquid Glass-এ কাচের প্যানেল ([GlassPanel], ঝাপসা দাগ থেকে);
    // Material 3-এ বেগুনি সুরের মূল কার্ড (নমুনার উল্টো-কোনার আকার) আর মোটা চওড়া অঙ্ক
    final look = context.look;
    final body = _body(context, look);
    return switch (look) {
      AppLook.abos => Card(key: const ValueKey('books-total'), color: context.palette.successSurface, child: body),
      AppLook.glass => KeyedSubtree(key: const ValueKey('books-total'), child: GlassPanel(radius: 22, child: body)),
      AppLook.expressive => Card(
          key: const ValueKey('books-total'),
          color: Expressive.secondaryBox,
          shape: const RoundedRectangleBorder(borderRadius: Expressive.heroShape),
          child: body),
    };
  }

  Widget _body(BuildContext context, AppLook look) => Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          // ⓘ জায়গা না হলে অঙ্ক নামের নিচে নামে — বড় লেখায় বা কোটি টাকায় কেটে যায় না, ঢেকেও যায় না
          // (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)। ⛔ আগে এক সারিতে বাঁধা ছিল, ১৩ dp ওপারে চলে যেত।
          child: Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: AppSpacing.sm,
            children: [
              if (period case final p?)
                // ⓘ নামের নিচে, অঙ্কের পাশে — একই লাইনে বসালে ৪১২dp-তেই অঙ্ক নিচের লাইনে নামত (পট্টি আরও উঁচু হত); এই পট্টি
                // তালিকার মাথায়, তালিকার সাথে সরে, নিচের স্থির পট্টির মতো "＋ নতুন" বোতামের নিচে বসে না
                Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
                  TotalsPeriodLabel(p),
                ])
              else
                Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
              Wrap(
                crossAxisAlignment: WrapCrossAlignment.center,
                spacing: AppSpacing.sm,
                children: [
                  Text(value,
                      semanticsLabel: Money.speakable(value),
                      style: look == AppLook.expressive
                          ? Expressive.bigNumber.copyWith(fontSize: 24, color: Expressive.ink)
                          : const TextStyle(
                              fontSize: 18, fontWeight: FontWeight.w800)),
                  if (count != null)
                    Text(context.l10n.booksTotalCount(count!),
                        style: TextStyle(color: context.palette.onSurfaceMuted)),
                ],
              ),
            ],
          ),
        );
}

/// মোটের পট্টির নিচের এক লাইন — বাঁয়ে নাম, ডানে অঙ্ক; জায়গা না হলে অঙ্ক নিচের লাইনে ডানে নামে, ⛔ অঙ্ক মাঝখানে ভাঙে না
/// (যেমন গ্রাহকের তালিকার "মোট বকেয়া" আর "মোট অগ্রিম", ০.৪.৪৮)
class TotalLine extends StatelessWidget {
  const TotalLine(this.label, this.value, {super.key});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm, vertical: 2),
        child: OverflowBar(
          alignment: MainAxisAlignment.spaceBetween,
          overflowAlignment: OverflowBarAlignment.end,
          children: [
            Text(label, style: TextStyle(color: context.palette.onSurfaceMuted)),
            Text(value, semanticsLabel: Money.speakable(value), style: const TextStyle(fontWeight: FontWeight.w600)),
          ],
        ),
      );
}

class BooksError extends StatelessWidget {
  const BooksError(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Card(
        key: const ValueKey('books-error'),
        color: context.palette.dangerSurface,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Text(text, style: TextStyle(color: context.palette.danger)),
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
                    style: TextStyle(color: context.palette.onSurfaceMuted))),
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
          label: Text(context.l10n.booksMore),
        ),
      );
}
