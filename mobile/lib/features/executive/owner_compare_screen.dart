import 'package:flutter/material.dart';

import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import 'owner_page_frame.dart';
import 'owner_pages_api.dart';

/// ⭐ মালিকের কেন্দ্র — তুলনা, ফোনের নিজের পর্দায় (ওয়েবের `/owner/compare`; মালিক, ১৭ অক্টোবর ২০২৬)।
///
/// <p>কোম্পানি বনাম কোম্পানি বা এক কোম্পানির শাখা বনাম শাখা; প্রতিটা সারিতে ছয় সংখ্যার এখন, তখন আর বদল। নিচে একটা সংখ্যার ধারা,
/// পাশে গত বছরের একই খোপ। ⛔ সংখ্যা সার্ভারের ([[Comparison]]) — ওয়েবের পাতার একই সেবা; ফোন কিছু কষে না। কেবল পড়া।
class OwnerCompareScreen extends StatefulWidget {
  const OwnerCompareScreen({super.key, this.api = const ServerOwnerPagesApi()});

  final OwnerPagesApi api;

  @override
  State<OwnerCompareScreen> createState() => _OwnerCompareScreenState();
}

class _OwnerCompareScreenState extends State<OwnerCompareScreen> {
  String _mode = OwnerCompare.companiesMode;
  String? _company;
  String _against = 'previous_month';
  String _grain = 'monthly';
  String _figure = 'sales';

  /// শেষ উত্তরের কোম্পানির তালিকা আর সার্ভারের বাছা কোম্পানি — চিপ এখান থেকে আঁকে, কিন্তু নতুন করে আনার চাবিতে নেই
  /// (ⓘ চাবিতে কেবল মানুষের বাছাই; নইলে সার্ভারের "প্রথমটা" ফিরে এসে আরেকবার আনাত)
  List<OwnerCompany> _companies = const [];
  String? _shownCompany;

  static const Map<String, String> _modes = {
    OwnerCompare.companiesMode: 'কোম্পানি বনাম কোম্পানি',
    OwnerCompare.branchesMode: 'শাখা বনাম শাখা',
  };

  static const Map<String, String> _againsts = {
    'previous_month': 'আগের মাসের একই দিনগুলো',
    'last_year': 'গত বছরের একই সময়',
  };

  static const Map<String, String> _grains = {
    'daily': 'দিন ধরে',
    'weekly': 'সপ্তাহ ধরে',
    'monthly': 'মাস ধরে',
    'quarterly': 'তিন মাস ধরে',
    'yearly': 'বছর ধরে',
  };

  /// ওয়েবের `executive::figure` — বাংলায়, ফোনের নিজের
  static const Map<String, String> labels = {
    'sales': 'বিক্রি',
    'collections': 'আদায়',
    'receivable': 'পাওনা (বাকি)',
    'profit': 'লাভ',
    'expenses': 'খরচ',
    'cash_flow': 'নগদ প্রবাহ (নিট)',
  };

  Future<OwnerCompare> _load() async {
    final data = await widget.api.compare(mode: _mode, company: _company, against: _against, grain: _grain, figure: _figure);
    // ⓘ সার্ভার যা বেছেছে সেটাই সত্য — যেমন শাখা বনাম শাখায় কোম্পানি না বাছলে প্রথমটা
    if (mounted) {
      setState(() {
        _companies = data.companies;
        _shownCompany = data.company;
      });
    }
    return data;
  }

  @override
  Widget build(BuildContext context) {
    final companyChoices = {
      if (_mode == OwnerCompare.companiesMode) '': 'সব কোম্পানি',
      for (final c in _companies) c.id: c.name,
    };

    return OwnerPageFrame<OwnerCompare>(
      title: 'তুলনা',
      failed: 'তুলনার সংখ্যা আনা গেল না।',
      reloadKey: (_mode, _company, _against, _grain, _figure),
      load: _load,
      controls: [
        OwnerChips(
          prefix: 'mode',
          choices: _modes,
          selected: _mode,
          onSelected: (m) => setState(() {
            _mode = m;
            // ⓘ কোম্পানি বনাম কোম্পানিতে "সব"-এ ফেরা; শাখায় সার্ভার প্রথমটা বাছে
            if (m == OwnerCompare.companiesMode) _company = null;
          }),
        ),
        if (companyChoices.length > 1)
          OwnerChips(
            prefix: 'company',
            choices: companyChoices,
            selected: _shownCompany ?? '',
            onSelected: (id) => setState(() => _company = id.isEmpty ? null : id),
          ),
        OwnerChips(prefix: 'against', choices: _againsts, selected: _against, onSelected: (a) => setState(() => _against = a)),
      ],
      body: (context, data) => [
        OwnerNote('এখন: ${ownerRange(data.nowFrom, data.nowTo)} · তখন: ${ownerRange(data.wasFrom, data.wasTo)}'),
        if (data.rows.isEmpty)
          const EmptyState(
            icon: Icons.business_outlined,
            title: 'দেখার মতো কিছু নেই',
            message: 'কোনো কোম্পানিতে আপনার এই পর্দা দেখার অনুমতি নেই।',
          ),
        for (final row in data.rows) _RowCard(row: row, columns: data.columns),
        const OwnerNote('বাকি একটা জের: "তখন" মানে ঐ দিনের রাতে রাখা হিসাব। রাতের হিসাব না থাকলে "—"।'),
        const SizedBox(height: AppSpacing.sm),
        Text('${labels[data.figure] ?? data.figure} — সময়ের সাথে', style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: AppSpacing.xs),
        OwnerChips(
          prefix: 'figure',
          choices: {
            for (final c in data.columns)
              if (c.trend) c.key: labels[c.key] ?? c.label,
          },
          selected: data.figure,
          onSelected: (f) => setState(() => _figure = f),
        ),
        OwnerChips(prefix: 'grain', choices: _grains, selected: data.grain, onSelected: (g) => setState(() => _grain = g)),
        if (data.trend.isEmpty)
          const OwnerNote('দেখার অনুমতি নেই')
        else
          Card(
            key: const ValueKey('compare-trend'),
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: OwnerBars(
                firstLabel: labels[data.figure] ?? data.figure,
                secondLabel: 'গত বছর',
                rows: [for (final b in data.trend) (label: b.label, first: b.value, second: b.lastYear)],
              ),
            ),
          ),
      ],
    );
  }
}

/// এক সারি — কোম্পানি বা শাখা; ছয় সংখ্যা, প্রতিটায় এখন, তখন আর বদল
class _RowCard extends StatelessWidget {
  const _RowCard({required this.row, required this.columns});

  final CompareRow row;
  final List<CompareColumn> columns;

  @override
  Widget build(BuildContext context) => Card(
        key: ValueKey('compare-row-${row.id}'),
        margin: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(row.name, style: const TextStyle(fontWeight: FontWeight.w700)),
              for (final c in columns) _Figure(label: _OwnerCompareScreenState.labels[c.key] ?? c.label, row: row, column: c.key),
            ],
          ),
        ),
      );
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.row, required this.column});

  final String label;
  final CompareRow row;
  final String column;

  @override
  Widget build(BuildContext context) {
    final now = row.now[column];
    final change = ownerChange(row.change[column]);
    final up = change != null && change.startsWith('▲');
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: Text(label, style: TextStyle(color: context.palette.onSurfaceMuted))),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(ownerCell(now), style: TextStyle(fontWeight: FontWeight.w700, color: ownerTone(context, now))),
              if (now != ownerHidden) ...[
                Text('তখন ${ownerCell(row.was[column])}', style: TextStyle(fontSize: 11.5, color: context.palette.onSurfaceMuted)),
                if (change != null)
                  Text(change, style: TextStyle(fontSize: 11.5, color: up ? context.palette.success : context.palette.danger)),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
