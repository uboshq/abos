import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';
import '../printing/document_actions_sheet.dart';

/// ⭐ বিক্রয় বিল আর চালান — তালিকা ও পাতা (মালিক, ১৩ অক্টোবর ২০২৬: *"Mobile App e kora invoice, challan, voucher — kono
/// kichuri list show korche na"*)।
///
/// <p>কাউন্টারে বিক্রি করে বিলটা ফোনে আর কোথাও দেখা যেত না। এখন বিক্রি → লেনদেন-এ দুই তালিকা: নতুন আগে, তারিখের পরিসর
/// (না বাছলে এই মাস), নম্বর বা দোকানের নাম দিয়ে খোঁজা। পাতায় সারি, মোট, আদায়, বাকি আর জোড়া কাগজ (বিলের চালান, চালানের বিল);
/// ছাপা/পাঠানো সেই একই শিট দিয়ে। সার্ভার ([[SalesPaperApiController]]) ওয়েবের তালিকার একই কোয়েরি আর দেয়াল মানে।

/// কোন কাগজ — বিল না চালান
enum SalesPaperKind { invoice, challan }

extension on SalesPaperKind {
  String get title => this == SalesPaperKind.invoice ? 'বিক্রয় বিল' : 'চালান';
  String get path => this == SalesPaperKind.invoice ? 'invoices' : 'challans';
  String get recentKind => this == SalesPaperKind.invoice ? 'invoice' : 'challan';
  String get printType => this == SalesPaperKind.invoice ? 'SalesInvoice' : 'DeliveryChallan';
}

class SalesPaperListScreen extends StatefulWidget {
  const SalesPaperListScreen({super.key, required this.kind, this.api = const ServerSalesPaperApi(), this.today});

  final SalesPaperKind kind;
  final SalesPaperApi api;

  /// পরীক্ষার জন্য — না দিলে আজ
  final DateTime? today;

  @override
  State<SalesPaperListScreen> createState() => _SalesPaperListScreenState();
}

class _SalesPaperListScreenState extends State<SalesPaperListScreen> {
  late DateTime _from;
  late DateTime _to;
  final _search = TextEditingController();
  final List<SalesPaperRow> _rows = [];
  SalesPaperPage? _page;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _to = DateTime(now.year, now.month, now.day);
    _from = DateTime(now.year, now.month);
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.list(widget.kind,
          from: isoOf(_from), to: isoOf(_to), q: _search.text.trim(), page: more ? (_page?.nextPage ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(page.rows);
        _page = page;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: '${widget.kind.title}ের তালিকা আনা গেল না।',
            whenAbsent: 'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    final invoice = widget.kind == SalesPaperKind.invoice;

    return Scaffold(
      appBar: AppBar(title: Text(widget.kind.title)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            DateRangeBar(
              from: _from,
              to: _to,
              today: widget.today,
              onChanged: (from, to) {
                setState(() {
                  _from = from;
                  _to = to;
                });
                _load();
              },
            ),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: TextField(
                key: const ValueKey('papers-search'),
                controller: _search,
                textInputAction: TextInputAction.search,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'নম্বর বা দোকানের নাম',
                  isDense: true,
                ),
                onSubmitted: (_) => _load(),
              ),
            ),
            if (_busy && page == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (page != null && _rows.isNotEmpty)
              TotalStrip(label: 'মোট ${widget.kind.title}', value: Money.taka(page.sum), count: page.count),
            if (page != null && _rows.isEmpty)
              EmptyState(
                icon: Icons.receipt_long_outlined,
                title: 'এই সময়ে কোনো ${widget.kind.title} নেই',
                message: 'তারিখ বদলে বা নিচে টেনে আবার দেখুন।',
              ),
            for (final r in _rows)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  key: ValueKey('paper-${r.id}'),
                  title: Text([r.no, if (r.saleNo != null && r.saleNo!.isNotEmpty) r.saleNo!].join(' · '),
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(
                    [
                      [r.customer, if (r.point != null && r.point!.isNotEmpty) r.point!].join(' · '),
                      [dayOf(r.date), r.statusLabel].where((s) => s.isNotEmpty).join(' · '),
                    ].join('\n'),
                  ),
                  isThreeLine: true,
                  trailing: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(Money.taka(r.total), style: const TextStyle(fontWeight: FontWeight.w700)),
                      if (invoice && r.due != null)
                        Text(Money.valueOrZero(r.due) > 0.004 ? 'বাকি ${Money.taka(r.due)}' : 'শোধ',
                            style: TextStyle(
                                fontSize: 12,
                                color: Money.valueOrZero(r.due) > 0.004 ? AppColors.danger : AppColors.success)),
                    ],
                  ),
                  onTap: () => context.push('/home/${RecentPapers.path(widget.kind.recentKind, r.id)}'),
                ),
              ),
            if (page?.nextPage != null)
              Center(
                child: TextButton.icon(
                  key: const ValueKey('papers-more'),
                  onPressed: _busy ? null : () => _load(more: true),
                  icon: const Icon(Icons.expand_more),
                  label: const Text('আরও'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// একটা বিল বা চালান — সারি, মোট, আদায়/বাকি, জোড়া কাগজ, ছাপা
class SalesPaperScreen extends StatefulWidget {
  const SalesPaperScreen({super.key, required this.kind, required this.id, this.api = const ServerSalesPaperApi()});

  final SalesPaperKind kind;
  final String id;
  final SalesPaperApi api;

  @override
  State<SalesPaperScreen> createState() => _SalesPaperScreenState();
}

class _SalesPaperScreenState extends State<SalesPaperScreen> {
  SalesPaperDetail? _paper;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final paper = await widget.api.one(widget.kind, widget.id);
      if (!mounted) return;
      setState(() {
        _paper = paper;
        _error = null;
      });
      // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ
      RecentPapers.note(kind: widget.kind.recentKind, id: widget.id, number: paper.no);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: '${widget.kind.title}টা আনা গেল না।'));
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _paper;
    final invoice = widget.kind == SalesPaperKind.invoice;

    return Scaffold(
      appBar: AppBar(
        title: Text(p == null ? widget.kind.title : '${widget.kind.title} · ${p.no}'),
        actions: [
          if (p != null)
            IconButton(
              key: const ValueKey('paper-print'),
              tooltip: 'ছাপা / পাঠান',
              icon: const Icon(Icons.print_outlined),
              onPressed: () => DocumentActionsSheet.show(context,
                  type: widget.kind.printType, id: p.id, title: '${widget.kind.title} · ${p.no}', fileStem: p.no),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (p == null && _error == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (p != null) ...[
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(p.customer, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                      if (p.point != null && p.point!.isNotEmpty)
                        Text(p.point!, style: const TextStyle(color: AppColors.onSurfaceMuted)),
                      const SizedBox(height: AppSpacing.xs),
                      _pair('তারিখ', dayOf(p.date)),
                      if (p.saleNo != null && p.saleNo!.isNotEmpty) _pair('বিক্রির নম্বর', p.saleNo!),
                      _pair('অবস্থা', p.statusLabel),
                      if (p.dueOn != null) _pair('মেয়াদ', dayOf(p.dueOn)),
                      if (p.warehouse != null && p.warehouse!.isNotEmpty) _pair('গুদাম', p.warehouse!),
                      if (p.vehicleNo != null && p.vehicleNo!.isNotEmpty) _pair('গাড়ি', p.vehicleNo!),
                      if (p.createdBy != null && p.createdBy!.isNotEmpty) _pair('লিখেছেন', p.createdBy!),
                    ],
                  ),
                ),
              ),
              const Padding(
                padding: EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
                child: Text('পণ্য', style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.onSurfaceMuted)),
              ),
              for (final l in p.lines)
                Card(
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    title: Text(l.name),
                    subtitle: Text([
                      '${Money.plain(l.qty)}${l.unit == null ? '' : ' ${l.unit}'} × ${Money.taka(l.rate)}',
                      if (Money.valueOrZero(l.freeQty) > 0) 'ফ্রি ${Money.plain(l.freeQty)}',
                      if (Money.valueOrZero(l.discount) > 0) 'ছাড় ${Money.taka(l.discount)}',
                    ].join(' · ')),
                    trailing: Text(Money.taka(l.amount), style: const TextStyle(fontWeight: FontWeight.w600)),
                  ),
                ),
              Card(
                key: const ValueKey('paper-totals'),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    children: [
                      if (Money.valueOrZero(p.discount) > 0) _pair('ছাড়', Money.taka(p.discount)),
                      if (Money.valueOrZero(p.tax) > 0) _pair('কর', Money.taka(p.tax)),
                      _pair('মোট', Money.taka(p.total), bold: true),
                      if (invoice && p.collected != null) _pair('আদায়', Money.taka(p.collected)),
                      if (invoice && p.due != null)
                        _pair('বাকি', Money.taka(p.due), bold: true,
                            colour: Money.valueOrZero(p.due) > 0.004 ? AppColors.danger : AppColors.success),
                    ],
                  ),
                ),
              ),
              if (p.links.isNotEmpty) ...[
                Padding(
                  padding: const EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
                  child: Text(invoice ? 'এই বিলের চালান' : 'এই চালানের বিল',
                      style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.onSurfaceMuted)),
                ),
                for (final link in p.links)
                  Card(
                    margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                    child: ListTile(
                      key: ValueKey('paper-link-${link.id}'),
                      leading: Icon(invoice ? Icons.local_shipping_outlined : Icons.receipt_long_outlined),
                      title: Text(link.no),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => context.push('/home/${RecentPapers.path(invoice ? 'challan' : 'invoice', link.id)}'),
                    ),
                  ),
              ],
              if (p.narration.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Text(p.narration, style: const TextStyle(color: AppColors.onSurfaceMuted)),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

Widget _pair(String label, String value, {bool bold = false, Color? colour}) => Padding(
      padding: const EdgeInsets.only(top: 3),
      child: Row(
        children: [
          Expanded(child: Text(label, style: const TextStyle(color: AppColors.onSurfaceMuted))),
          Text(value,
              style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w600, color: colour ?? AppColors.onSurface)),
        ],
      ),
    );

class SalesPaperRow {
  const SalesPaperRow({
    required this.id,
    required this.no,
    this.saleNo,
    this.date,
    this.customer = '',
    this.point,
    this.status = '',
    this.statusLabel = '',
    required this.total,
    this.due,
  });

  final String id;
  final String no;
  final String? saleNo;
  final String? date;
  final String customer;
  final String? point;
  final String status;
  final String statusLabel;
  final String total;

  /// কেবল বিলে — চালানে null
  final String? due;

  factory SalesPaperRow.fromJson(Map<dynamic, dynamic> j) => SalesPaperRow(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        saleNo: j['sale_no']?.toString(),
        date: j['date']?.toString(),
        customer: j['customer']?.toString() ?? '',
        point: j['point']?.toString(),
        status: j['status']?.toString() ?? '',
        statusLabel: _status(j['status']?.toString(), j['status_label']?.toString()),
        total: j['total']?.toString() ?? '0',
        due: j['due']?.toString(),
      );
}

/// অবস্থা বাংলায় — সার্ভারের লেখা বাংলা না হলে ফোনের নিজের
String _status(String? status, String? label) {
  const words = {
    'draft': 'খসড়া',
    'confirmed': 'নিশ্চিত',
    'cancelled': 'বাতিল',
    'closed': 'সম্পন্ন',
    'awaiting_approval': 'অনুমোদনের অপেক্ষায়',
  };
  return words[status] ?? label ?? '';
}

class SalesPaperPage {
  const SalesPaperPage({required this.rows, this.count = 0, this.sum = '0', this.nextPage});

  final List<SalesPaperRow> rows;
  final int count;
  final String sum;
  final int? nextPage;
}

class SalesPaperLine {
  const SalesPaperLine({
    required this.name,
    required this.qty,
    this.unit,
    required this.rate,
    this.discount = '0',
    this.freeQty = '0',
    required this.amount,
  });

  final String name;
  final String qty;
  final String? unit;
  final String rate;
  final String discount;
  final String freeQty;
  final String amount;
}

class SalesPaperLink {
  const SalesPaperLink(this.id, this.no);

  final String id;
  final String no;
}

class SalesPaperDetail {
  const SalesPaperDetail({
    required this.id,
    required this.no,
    this.saleNo,
    this.date,
    this.dueOn,
    this.statusLabel = '',
    this.customer = '',
    this.point,
    this.warehouse,
    this.vehicleNo,
    this.createdBy,
    this.narration = '',
    this.lines = const [],
    this.discount = '0',
    this.tax = '0',
    required this.total,
    this.collected,
    this.due,
    this.links = const [],
  });

  final String id;
  final String no;
  final String? saleNo;
  final String? date;
  final String? dueOn;
  final String statusLabel;
  final String customer;
  final String? point;
  final String? warehouse;
  final String? vehicleNo;
  final String? createdBy;
  final String narration;
  final List<SalesPaperLine> lines;
  final String discount;
  final String tax;
  final String total;
  final String? collected;
  final String? due;

  /// বিলের চালান, বা চালানের বিল
  final List<SalesPaperLink> links;

  factory SalesPaperDetail.fromJson(Map<dynamic, dynamic> j) {
    List<Map> maps(Object? raw) => raw is List ? raw.whereType<Map>().toList() : const [];
    return SalesPaperDetail(
      id: j['id']?.toString() ?? '',
      no: j['no']?.toString() ?? '',
      saleNo: j['sale_no']?.toString(),
      date: j['date']?.toString(),
      dueOn: j['due_on']?.toString(),
      statusLabel: _status(j['status']?.toString(), j['status_label']?.toString()),
      customer: j['customer']?.toString() ?? '',
      point: j['point']?.toString(),
      warehouse: j['warehouse']?.toString(),
      vehicleNo: j['vehicle_no']?.toString(),
      createdBy: j['created_by']?.toString(),
      narration: j['narration']?.toString() ?? '',
      lines: [
        for (final l in maps(j['lines']))
          SalesPaperLine(
            name: l['name']?.toString() ?? '',
            qty: l['qty']?.toString() ?? '0',
            unit: l['unit']?.toString(),
            rate: l['rate']?.toString() ?? '0',
            discount: l['discount']?.toString() ?? '0',
            freeQty: l['free_qty']?.toString() ?? '0',
            amount: l['amount']?.toString() ?? '0',
          ),
      ],
      discount: j['discount']?.toString() ?? '0',
      tax: j['tax']?.toString() ?? '0',
      total: j['total']?.toString() ?? '0',
      collected: j['collected']?.toString(),
      due: j['due']?.toString(),
      links: [
        for (final l in [...maps(j['challans']), ...maps(j['bills'])])
          SalesPaperLink(l['id']?.toString() ?? '', l['no']?.toString() ?? ''),
      ],
    );
  }
}

abstract class SalesPaperApi {
  Future<SalesPaperPage> list(SalesPaperKind kind,
      {required String from, required String to, String q = '', int page = 1});

  Future<SalesPaperDetail> one(SalesPaperKind kind, String id);
}

class ServerSalesPaperApi implements SalesPaperApi {
  const ServerSalesPaperApi();

  @override
  Future<SalesPaperPage> list(SalesPaperKind kind,
      {required String from, required String to, String q = '', int page = 1}) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/sales/${kind.path}',
        queryParameters: {'from': from, 'to': to, if (q.isNotEmpty) 'q': q, 'page': page});
    final body = r.data ?? const <String, dynamic>{};
    return SalesPaperPage(
      rows: [
        for (final row in (body['rows'] is List ? body['rows'] as List : const []).whereType<Map>())
          SalesPaperRow.fromJson(row),
      ],
      count: (body['count'] as num?)?.toInt() ?? 0,
      sum: body['sum']?.toString() ?? '0',
      nextPage: (body['next_page'] as num?)?.toInt(),
    );
  }

  @override
  Future<SalesPaperDetail> one(SalesPaperKind kind, String id) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/sales/${kind.path}/$id');
    return SalesPaperDetail.fromJson(r.data ?? const <String, dynamic>{});
  }
}
