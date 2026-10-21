import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';
import 'executive_screen.dart';
import 'owner_page_frame.dart';
import 'owner_pages_api.dart';

/// ⭐ মালিকের কেন্দ্র — আগের দিনের হিসাব (ওয়েবের `/owner/history`; মালিক, ১৭ অক্টোবর ২০২৬): "গত মাসের এই দিনে বাকি কত ছিল?"
///
/// <p>বাছা দিনের রাতে লেখা আটটা সংখ্যা, প্রতিটা শাখা আর গোটা কোম্পানির (মোটের পট্টি); নিচে একটা সংখ্যার গত ৯০ দিন, পাশে গত
/// মাসের একই দিন। ⛔ সব রাতের হিসাব থেকে ([[Snapshots]]) — আজকের খাতা থেকে নতুন করে গোনা নয়; রাতের হিসাব না থাকলে ফাঁক, ৳০ নয়।
class OwnerHistoryScreen extends StatefulWidget {
  const OwnerHistoryScreen({super.key, this.api = const ServerOwnerPagesApi(), this.today});

  final OwnerPagesApi api;

  /// পরীক্ষার জন্য — না দিলে আজ
  final DateTime? today;

  @override
  State<OwnerHistoryScreen> createState() => _OwnerHistoryScreenState();
}

class _OwnerHistoryScreenState extends State<OwnerHistoryScreen> {
  /// null = সার্ভারের নিজের "গত মাসের আজকের দিন"
  String? _date;
  String _figure = 'receivable';
  String? _shownDate;

  Future<OwnerHistory> _load() async {
    final data = await widget.api.history(date: _date, figure: _figure);
    if (mounted) setState(() => _shownDate = data.date);
    return data;
  }

  Future<void> _pickDay() async {
    final now = widget.today ?? DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final shown = DateTime.tryParse(_shownDate ?? '') ?? today;
    final picked = await showDatePicker(
      context: context,
      firstDate: DateTime(today.year - 3),
      lastDate: today,
      initialDate: shown.isAfter(today) ? today : shown,
    );
    if (picked != null) setState(() => _date = isoOf(picked));
  }

  @override
  Widget build(BuildContext context) => OwnerPageFrame<OwnerHistory>(
        title: context.l10n.ownerHistoryTitle,
        failed: context.l10n.ownerHistoryFailed,
        reloadKey: (_date, _figure),
        load: _load,
        controls: [
          Wrap(children: [
            ActionChip(
              key: const ValueKey('history-day'),
              avatar: const Icon(Icons.event, size: 18),
              label: Text(context.l10n.ownerHistoryDay(dayOf(_shownDate ?? _date))),
              onPressed: _pickDay,
            ),
          ]),
          OwnerChips(
            prefix: 'figure',
            choices: ExecutiveToday.labelsIn(context.l10n),
            selected: _figure,
            onSelected: (f) => setState(() => _figure = f),
          ),
        ],
        body: (context, data) {
          final money = {for (final f in data.figures) f.key: f.money};
          final w = context.l10n;
          final label = ExecutiveToday.labelsIn(w)[data.figure] ?? data.figure;
          return [
            OwnerNote(w.ownerHistoryAsKept(dayOf(data.date), '23:55')),
            if (data.rows.isEmpty)
              EmptyState(
                icon: Icons.history_toggle_off,
                title: w.ownerHistoryNoneTitle,
                message: w.ownerHistoryNoneMessage,
              ),
            for (final row in data.rows) _RowCard(row: row, figure: data.figure, money: money),
            if (data.series.isNotEmpty)
              Card(
                key: const ValueKey('history-trend'),
                margin: const EdgeInsets.only(top: AppSpacing.sm),
                child: ExpansionTile(
                  title: Text(w.ownerHistoryTrend(label, 90), style: const TextStyle(fontWeight: FontWeight.w700)),
                  childrenPadding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.sm),
                  children: [
                    // ⓘ নতুন দিন আগে — ফোনে নিচে নামলে পুরনো
                    OwnerBars(
                      firstLabel: label,
                      secondLabel: w.ownerHistoryMonthBefore,
                      money: money[data.figure] ?? true,
                      rows: [
                        for (final p in data.series.reversed) (label: dayOf(p.day), first: p.value, second: p.monthBefore),
                      ],
                    ),
                  ],
                ),
              ),
          ];
        },
      );
}

/// এক সারি — শাখা, বা গোটা কোম্পানি (মোটের পট্টির রঙে); খুললে আটটা সংখ্যা
class _RowCard extends StatelessWidget {
  const _RowCard({required this.row, required this.figure, required this.money});

  final HistoryRow row;
  final String figure;
  final Map<String, bool> money;

  @override
  Widget build(BuildContext context) {
    final labels = ExecutiveToday.labelsIn(context.l10n);
    return Card(
        key: ValueKey('history-row-${row.company}-${row.branch ?? 'whole'}'),
        color: row.whole ? context.palette.successSurface : null,
        margin: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: ExpansionTile(
          title: Text(row.name, style: TextStyle(fontWeight: row.whole ? FontWeight.w800 : FontWeight.w600)),
          subtitle: row.whole ? null : Text(row.companyName),
          trailing: Text(ownerCell(row.values[figure], money: money[figure] ?? true),
              style: const TextStyle(fontWeight: FontWeight.w800)),
          childrenPadding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.sm),
          children: [
            for (final key in ExecutiveToday.keys)
              FactRow(labels[key] ?? key, ownerCell(row.values[key], money: money[key] ?? key != 'signatures')),
          ],
        ),
      );
  }
}
