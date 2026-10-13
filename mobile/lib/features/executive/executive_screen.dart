import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';

/// ⭐ মালিকের কেন্দ্র, ফোনে — মালিক, ১৩ অক্টোবর ২০২৬: *"app e baki module & menu"*।
///
/// <p>ওয়েবের "আজ" পাতা: আটটা প্রধান সংখ্যা, বাকির চার সংখ্যা, কোম্পানি × শাখার ছক, সতর্কতা আর সইয়ের অপেক্ষা। কেবল পড়া।
/// সংখ্যা সার্ভারের ([[ExecutiveApiController]]) — ওয়েবের পাতার একই সেবা, তাই দুই পর্দা কখনো দুই কথা বলে না।
/// ঢাকা ঘর (চাবি নেই) "••••", শাখায় ভাগ হয় না এমন ঘর "—"।
class ExecutiveScreen extends StatefulWidget {
  const ExecutiveScreen({super.key, this.api = const ServerExecutiveApi()});

  final ExecutiveApi api;

  @override
  State<ExecutiveScreen> createState() => _ExecutiveScreenState();
}

class _ExecutiveScreenState extends State<ExecutiveScreen> {
  String _period = 'today';
  ExecutiveToday? _data;
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
      final data = await widget.api.today(_period);
      if (!mounted) return;
      setState(() => _data = data);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'মালিকের কেন্দ্রের সংখ্যা আনা গেল না।',
            whenAbsent: 'মালিকের কেন্দ্র এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _pick(String period) {
    if (period == _period) return;
    setState(() => _period = period);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    final children = <Widget>[
      Wrap(
        spacing: AppSpacing.xs,
        children: [
          for (final p in ExecutiveToday.periods.entries)
            ChoiceChip(
              key: ValueKey('period-${p.key}'),
              label: Text(p.value),
              selected: _period == p.key,
              onSelected: (_) => _pick(p.key),
            ),
        ],
      ),
      if (_busy && data == null) const LinearProgressIndicator(),
      if (_error != null) BooksError(_error!),
    ];

    if (data != null) {
      if (data.companies.isEmpty) {
        children.add(const EmptyState(
          icon: Icons.business_outlined,
          title: 'দেখার মতো কোনো কোম্পানি নেই',
          message: 'কোনো কোম্পানিতে আপনার মালিকের কেন্দ্র দেখার অনুমতি নেই।',
        ));
      } else {
        children
          ..add(_FigureGrid(figures: data.figures))
          ..addAll([
            if (data.credit != null) _CreditCard(credit: data.credit!),
            const _Heading('কোম্পানি ও শাখা'),
            for (final c in data.companies) _CompanyCard(company: c),
            const _Heading('সতর্কতা'),
            for (final a in data.alerts) _AlertTile(alert: a),
            const _Heading('সইয়ের অপেক্ষায়'),
            if (data.waiting.isEmpty)
              const Padding(
                padding: EdgeInsets.all(AppSpacing.md),
                child: Text('এখন কোনো কাগজ সইয়ের অপেক্ষায় নেই।'),
              ),
            for (final w in data.waiting)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  leading: const Icon(Icons.draw_outlined),
                  title: Text(w.label, maxLines: 2, overflow: TextOverflow.ellipsis),
                  subtitle: Text([w.company, dayOf(w.requestedAt)].join(' · ')),
                  trailing: w.amount == null ? null : Text(Money.taka(w.amount)),
                ),
              ),
          ]);
      }
    }

    return Scaffold(
      appBar: AppBar(title: const Text('মালিকের কেন্দ্র')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: children,
        ),
      ),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
        child: Text(text,
            style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.onSurfaceMuted)),
      );
}

/// আটটা সংখ্যা, দুই কলামে
class _FigureGrid extends StatelessWidget {
  const _FigureGrid({required this.figures});

  final List<ExecutiveFigure> figures;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
        builder: (context, box) {
          final width = (box.maxWidth - AppSpacing.sm) / 2;
          return Padding(
            padding: const EdgeInsets.only(top: AppSpacing.sm),
            child: Wrap(
              spacing: AppSpacing.sm,
              runSpacing: AppSpacing.sm,
              children: [
                for (final f in figures)
                  SizedBox(
                    width: width,
                    child: Card(
                      key: ValueKey('figure-${f.key}'),
                      margin: EdgeInsets.zero,
                      child: Padding(
                        padding: const EdgeInsets.all(AppSpacing.sm),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(f.label,
                                style: const TextStyle(fontSize: 12.5, color: AppColors.onSurfaceMuted)),
                            const SizedBox(height: 2),
                            Text(cellText(f.value, money: f.money),
                                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                            if (f.partial)
                              const Text('কিছু কোম্পানি বাদ — অনুমতি নেই',
                                  style: TextStyle(fontSize: 11, color: AppColors.warning)),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          );
        },
      );
}

class _CreditCard extends StatelessWidget {
  const _CreditCard({required this.credit});

  final ExecutiveCredit credit;

  @override
  Widget build(BuildContext context) => Card(
        key: const ValueKey('executive-credit'),
        margin: const EdgeInsets.only(top: AppSpacing.sm),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('বাকি', style: TextStyle(fontWeight: FontWeight.w700)),
              _pair('ব্যবহৃত বাকি', Money.taka(credit.exposure)),
              _pair('মেয়াদ পেরোনো পাওনা', Money.taka(credit.overdue)),
              _pair('বাকি বন্ধ গ্রাহক', '${credit.blocked} জন'),
              _pair('সীমা বাড়ানোর অনুরোধ', '${credit.pendingRequests}টা'),
              if (credit.partial)
                const Text('কিছু কোম্পানি বাদ — অনুমতি নেই',
                    style: TextStyle(fontSize: 11, color: AppColors.warning)),
            ],
          ),
        ),
      );
}

Widget _pair(String label, String value) => Padding(
      padding: const EdgeInsets.only(top: 2),
      child: Row(
        children: [
          Expanded(child: Text(label, style: const TextStyle(color: AppColors.onSurfaceMuted))),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
        ],
      ),
    );

/// এক কোম্পানি — খুললে সব সংখ্যা আর শাখার সারি
class _CompanyCard extends StatelessWidget {
  const _CompanyCard({required this.company});

  final ExecutiveCompany company;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: ExpansionTile(
          key: ValueKey('company-${company.id}'),
          title: Text(company.name, style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text(
              'বিক্রি ${cellText(company.values['sales'], money: true)} · আদায় ${cellText(company.values['collections'], money: true)}'),
          childrenPadding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.sm),
          children: [
            _Values(values: company.values),
            for (final row in company.rows) ...[
              const Divider(),
              Align(
                alignment: Alignment.centerLeft,
                child: Text(row.name, style: const TextStyle(fontWeight: FontWeight.w600)),
              ),
              _Values(values: row.values),
            ],
            if (company.unsplit != null) ...[
              const Divider(),
              const Align(
                alignment: Alignment.centerLeft,
                child: Text('শাখায় ভাগ হয়নি', style: TextStyle(fontWeight: FontWeight.w600)),
              ),
              _Values(values: company.unsplit!),
            ],
          ],
        ),
      );
}

class _Values extends StatelessWidget {
  const _Values({required this.values});

  final Map<String, String?> values;

  @override
  Widget build(BuildContext context) => Column(
        children: [
          for (final key in ExecutiveToday.keys)
            _pair(ExecutiveToday.labels[key] ?? key, cellText(values[key], money: key != 'signatures')),
        ],
      );
}

class _AlertTile extends StatelessWidget {
  const _AlertTile({required this.alert});

  final ExecutiveAlert alert;

  @override
  Widget build(BuildContext context) {
    final hot = alert.total > 0;
    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: ListTile(
        key: ValueKey('alert-${alert.kind}'),
        leading: Icon(hot ? Icons.warning_amber_rounded : Icons.check_circle_outline,
            color: hot ? AppColors.danger : AppColors.success),
        title: Text(ExecutiveToday.alertLabels[alert.kind] ?? alert.label),
        subtitle: alert.companies.length > 1
            ? Text(alert.companies
                .map((c) => '${c.name}: ${c.count ?? 'অনুমতি নেই'}')
                .join(' · '))
            : null,
        trailing: Text(hot ? '${alert.total}' : 'কিছু নেই',
            style: TextStyle(fontWeight: FontWeight.w700, color: hot ? AppColors.danger : AppColors.onSurfaceMuted)),
      ),
    );
  }
}

/// ঘরের লেখা — ঢাকা "••••", ভাগ হয় না "—", টাকা ৳, সই গোনা
String cellText(String? value, {required bool money}) {
  if (value == null) return '—';
  if (value == ExecutiveToday.hidden) return '••••';
  if (!money) return Money.plain(value);
  return Money.taka(value);
}

class ExecutiveFigure {
  const ExecutiveFigure({required this.key, required this.label, this.value, this.money = true, this.partial = false});

  final String key;
  final String label;
  final String? value;
  final bool money;
  final bool partial;
}

class ExecutiveBranch {
  const ExecutiveBranch({required this.name, required this.values});

  final String name;
  final Map<String, String?> values;
}

class ExecutiveCompany {
  const ExecutiveCompany({
    required this.id,
    required this.name,
    required this.values,
    this.rows = const [],
    this.unsplit,
  });

  final String id;
  final String name;
  final Map<String, String?> values;
  final List<ExecutiveBranch> rows;
  final Map<String, String?>? unsplit;
}

class ExecutiveAlertPart {
  const ExecutiveAlertPart({required this.name, this.count});

  final String name;
  final int? count;
}

class ExecutiveAlert {
  const ExecutiveAlert({required this.kind, required this.label, required this.total, this.companies = const []});

  final String kind;
  final String label;
  final int total;
  final List<ExecutiveAlertPart> companies;
}

class ExecutiveWaiting {
  const ExecutiveWaiting({required this.company, required this.label, this.amount, this.requestedAt});

  final String company;
  final String label;
  final String? amount;
  final String? requestedAt;
}

class ExecutiveCredit {
  const ExecutiveCredit({
    required this.exposure,
    required this.overdue,
    required this.blocked,
    required this.pendingRequests,
    this.partial = false,
  });

  final String exposure;
  final String overdue;
  final int blocked;
  final int pendingRequests;
  final bool partial;
}

class ExecutiveToday {
  const ExecutiveToday({
    required this.period,
    required this.figures,
    required this.companies,
    this.alerts = const [],
    this.waiting = const [],
    this.credit,
  });

  /// সার্ভারের ঢাকা ঘরের চিহ্ন
  static const String hidden = 'hidden';

  static const Map<String, String> periods = {'today': 'আজ', 'week': 'এই সপ্তাহ', 'month': 'এই মাস'};

  static const List<String> keys = [
    'sales', 'collections', 'receivable', 'payable', 'fund', 'stock', 'profit', 'signatures',
  ];

  /// ওয়েবের `executive::figure` — বাংলায়, ফোনের নিজের
  static const Map<String, String> labels = {
    'sales': 'বিক্রি',
    'collections': 'আদায়',
    'receivable': 'পাওনা (বাকি)',
    'payable': 'সরবরাহকারীর দেনা',
    'fund': 'নগদ + ব্যাংক',
    'stock': 'মজুদের দাম',
    'profit': 'লাভ',
    'signatures': 'সইয়ের অপেক্ষায়',
  };

  static const Map<String, String> alertLabels = {
    'over_limit': 'বাকির সীমা ছাড়িয়েছে',
    'overdue': 'মেয়াদ পেরোনো বাকি',
    'stock_low': 'মজুদ পুনঃঅর্ডারের নিচে',
    'stock_negative': 'মজুদ শূন্যের নিচে',
    'stock_dead': 'অচল মাল',
    'late_delivery': 'দেরির চালান (৭ দিন)',
    'failed_login': 'ব্যর্থ লগইন (৭ দিন)',
    'backdated': 'পিছনের তারিখের কাগজ (এ মাসে)',
  };

  final String period;
  final List<ExecutiveFigure> figures;
  final List<ExecutiveCompany> companies;
  final List<ExecutiveAlert> alerts;
  final List<ExecutiveWaiting> waiting;
  final ExecutiveCredit? credit;

  static Map<String, String?> _cells(Object? raw) => {
        for (final key in keys) key: raw is Map ? raw[key]?.toString() : null,
      };

  static List<Map> _maps(Object? raw) => raw is List ? raw.whereType<Map>().toList() : const [];

  factory ExecutiveToday.fromJson(Map<String, dynamic> body) {
    final credit = body['credit'];
    final totals = credit is Map ? credit['totals'] : null;

    return ExecutiveToday(
      period: body['period']?.toString() ?? 'today',
      figures: [
        for (final f in _maps(body['figures']))
          ExecutiveFigure(
            key: f['key']?.toString() ?? '',
            label: labels[f['key']?.toString()] ?? f['label']?.toString() ?? '',
            value: f['value']?.toString(),
            money: f['money'] != false,
            partial: f['partial'] == true,
          ),
      ],
      companies: [
        for (final c in _maps(body['companies']))
          ExecutiveCompany(
            id: c['id']?.toString() ?? '',
            name: c['name']?.toString() ?? '',
            values: _cells(c['values']),
            rows: [
              for (final r in _maps(c['rows']))
                ExecutiveBranch(name: r['name']?.toString() ?? '', values: _cells(r['values'])),
            ],
            unsplit: c['unsplit'] is Map ? _cells(c['unsplit']) : null,
          ),
      ],
      alerts: [
        for (final a in _maps(body['alerts']))
          ExecutiveAlert(
            kind: a['kind']?.toString() ?? '',
            label: a['label']?.toString() ?? '',
            total: (a['total'] as num?)?.toInt() ?? 0,
            companies: [
              for (final p in _maps(a['companies']))
                ExecutiveAlertPart(name: p['name']?.toString() ?? '', count: (p['count'] as num?)?.toInt()),
            ],
          ),
      ],
      waiting: [
        for (final w in _maps(body['waiting']))
          ExecutiveWaiting(
            company: w['company']?.toString() ?? '',
            label: w['label']?.toString() ?? '',
            amount: w['amount']?.toString(),
            requestedAt: w['requested_at']?.toString(),
          ),
      ],
      credit: totals is Map
          ? ExecutiveCredit(
              exposure: totals['exposure']?.toString() ?? '0',
              overdue: totals['overdue']?.toString() ?? '0',
              blocked: Money.valueOrZero(totals['blocked']).toInt(),
              pendingRequests: Money.valueOrZero(totals['pending_requests']).toInt(),
              partial: (credit as Map)['partial'] == true,
            )
          : null,
    );
  }
}

abstract class ExecutiveApi {
  Future<ExecutiveToday> today(String period);
}

class ServerExecutiveApi implements ExecutiveApi {
  const ServerExecutiveApi();

  @override
  Future<ExecutiveToday> today(String period) async {
    final response = await ApiClient.dio
        .get<Map<String, dynamic>>('/executive/today', queryParameters: {'period': period});
    return ExecutiveToday.fromJson(response.data ?? const <String, dynamic>{});
  }
}
