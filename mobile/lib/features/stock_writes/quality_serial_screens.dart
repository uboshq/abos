import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/locale/l10n.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../home/nav_bottom_bar.dart' show ActionBottomBar, BarFab;
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/search_debouncer.dart';
import '../books/books_widgets.dart';
import 'stock_write_screens.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ মান যাচাই (QC) আর সিরিয়াল নম্বর — ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
///
/// <p>⛔ নিয়ম সব সার্ভারের ([[QualityPhoneController]], [[SerialPhoneController]]) — ওয়েবের একই সেবা আর নীতি: রায় কেবল বাকি
/// যাচাইয়ে আর রায়ের চাবিতে, বাতিল মাল সরানো কেবল বাতিল থাকলে, নকল সিরিয়াল ফেরে। কোন বোতাম দেখাবে সার্ভার বলে
/// (`can_decide`, `can_dispose`)। ⭐ প্রতিটা লেখা একবারই — [OnceKey]।

String _today() {
  final now = DateTime.now();
  return '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
}

void _tell(BuildContext context, String notice) =>
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(backgroundColor: context.palette.success, content: Text(notice.isEmpty ? context.l10n.stockWriteResultDone : notice)));

// ── সার্ভার ─────────────────────────────────────────────────────────────────────────────────────

class InspectionRow {
  const InspectionRow({required this.id, required this.no, this.dateLabel, this.product = '', this.warehouse, this.qty = '', this.status = '', this.statusLabel = ''});

  final String id;
  final String no;
  final String? dateLabel;
  final String product;
  final String? warehouse;
  final String qty;
  final String status;
  final String statusLabel;

  factory InspectionRow.fromJson(Map<dynamic, dynamic> j) => InspectionRow(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        dateLabel: j['date_label']?.toString(),
        product: j['product']?.toString() ?? '',
        warehouse: j['warehouse']?.toString(),
        qty: j['qty']?.toString() ?? '',
        status: j['status']?.toString() ?? '',
        statusLabel: j['status_label']?.toString() ?? '',
      );
}

class Inspection {
  const Inspection({
    required this.row,
    this.batch,
    this.criteria,
    this.remarks,
    this.accepted = '0',
    this.rejected = '0',
    this.disposed = '0',
    this.canDecide = false,
    this.canDispose = false,
    this.results = const [],
    this.writeOffReasons = const [],
  });

  final InspectionRow row;
  final String? batch;
  final String? criteria;
  final String? remarks;
  final String accepted;
  final String rejected;
  final String disposed;
  final bool canDecide;
  final bool canDispose;
  final List<StockChoice> results;
  final List<StockChoice> writeOffReasons;

  factory Inspection.fromJson(Map<dynamic, dynamic> j) {
    List<StockChoice> choices(Object? raw, String key) => [
          for (final c in (raw is List ? raw : const []).whereType<Map>()) StockChoice(c[key]?.toString() ?? '', c['label']?.toString() ?? c['name']?.toString() ?? ''),
        ];
    return Inspection(
      row: InspectionRow.fromJson(j),
      batch: j['batch']?.toString(),
      criteria: j['criteria']?.toString(),
      remarks: j['remarks']?.toString(),
      accepted: j['accepted_qty']?.toString() ?? '0',
      rejected: j['rejected_qty']?.toString() ?? '0',
      disposed: j['disposed_qty']?.toString() ?? '0',
      canDecide: j['can_decide'] == true,
      canDispose: j['can_dispose'] == true,
      results: choices(j['results'], 'code'),
      writeOffReasons: choices(j['write_off_reasons'], 'id'),
    );
  }
}

class SerialRow {
  const SerialRow({required this.serialNo, this.product = '', this.warehouse, this.status = '', this.statusLabel = '', this.soldTo, this.issuedOn});

  final String serialNo;
  final String product;
  final String? warehouse;
  final String status;
  final String statusLabel;
  final String? soldTo;
  final String? issuedOn;
}

abstract class QualitySerialApi {
  /// শেষেরটা সার্ভারের গোটা ছাঁকনির গোনা — পুরনো সার্ভারে `null` (পূর্ণ নিরীক্ষা §৯ ⚠️৭)
  Future<(List<InspectionRow>, List<StockChoice>, int?, int?)> inspections({String? status, int page = 1});
  Future<Inspection> inspection(String id);
  Future<String> openInspection(Map<String, Object?> body);
  Future<String> decide(String id, Map<String, Object?> body);
  Future<String> dispose(String id, Map<String, Object?> body);
  /// শেষেরটা সার্ভারের গোটা খোঁজের গোনা — পুরনো সার্ভারে `null` (পূর্ণ নিরীক্ষা §৯ ⚠️৭)
  Future<(List<SerialRow>, int?, int?)> serials({String serial = '', int page = 1});
  Future<String> receiveSerials(Map<String, Object?> body);
  Future<String> issueSerials(Map<String, Object?> body);
}

class ServerQualitySerialApi implements QualitySerialApi {
  const ServerQualitySerialApi();

  Future<String> _post(String path, Map<String, Object?> body) async =>
      (await ApiClient.dio.post<Map<String, dynamic>>('/inventory/$path', data: body)).data?['notice']?.toString() ?? '';

  @override
  Future<(List<InspectionRow>, List<StockChoice>, int?, int?)> inspections({String? status, int page = 1}) async {
    final body = (await ApiClient.dio.get<Map<String, dynamic>>('/inventory/quality',
            queryParameters: {if (status != null) 'status': status, 'page': page}))
        .data ?? const {};
    return (
      [for (final r in (body['rows'] is List ? body['rows'] as List : const []).whereType<Map>()) InspectionRow.fromJson(r)],
      [
        for (final s in (body['statuses'] is List ? body['statuses'] as List : const []).whereType<Map>())
          StockChoice(s['code']?.toString() ?? '', s['label']?.toString() ?? ''),
      ],
      (body['next_page'] as num?)?.toInt(),
      (body['count'] as num?)?.toInt(),
    );
  }

  @override
  Future<Inspection> inspection(String id) async =>
      Inspection.fromJson((await ApiClient.dio.get<Map<String, dynamic>>('/inventory/quality/${Uri.encodeComponent(id)}')).data ?? const {});

  @override
  Future<String> openInspection(Map<String, Object?> body) => _post('quality', body);

  @override
  Future<String> decide(String id, Map<String, Object?> body) => _post('quality/${Uri.encodeComponent(id)}/decide', body);

  @override
  Future<String> dispose(String id, Map<String, Object?> body) => _post('quality/${Uri.encodeComponent(id)}/dispose', body);

  @override
  Future<(List<SerialRow>, int?, int?)> serials({String serial = '', int page = 1}) async {
    final body = (await ApiClient.dio.get<Map<String, dynamic>>('/inventory/serials',
            queryParameters: {if (serial.isNotEmpty) 'serial': serial, 'page': page}))
        .data ?? const {};
    return (
      [
        for (final r in (body['rows'] is List ? body['rows'] as List : const []).whereType<Map>())
          SerialRow(
            serialNo: r['serial_no']?.toString() ?? '',
            product: r['product']?.toString() ?? '',
            warehouse: r['warehouse']?.toString(),
            status: r['status']?.toString() ?? '',
            statusLabel: r['status_label']?.toString() ?? '',
            soldTo: r['sold_to']?.toString(),
            issuedOn: r['issued_on']?.toString(),
          ),
      ],
      (body['next_page'] as num?)?.toInt(),
      (body['count'] as num?)?.toInt(),
    );
  }

  @override
  Future<String> receiveSerials(Map<String, Object?> body) => _post('serials', body);

  @override
  Future<String> issueSerials(Map<String, Object?> body) => _post('serials/issue', body);
}

// ── মান যাচাই ────────────────────────────────────────────────────────────────────────────────────

class QualityListScreen extends StatefulWidget {
  const QualityListScreen({super.key, this.api = const ServerQualitySerialApi(), this.canCreate = false, this.products});

  final QualitySerialApi api;

  /// `inventory.qc.create` — না থাকলে "নতুন যাচাই" নেই
  final bool canCreate;
  final List<ProductRecord>? products;

  @override
  State<QualityListScreen> createState() => _QualityListScreenState();
}

class _QualityListScreenState extends State<QualityListScreen> {
  final List<InspectionRow> _rows = [];
  List<StockChoice> _statuses = const [];
  String? _status;
  int? _next;
  int? _count;
  bool _loaded = false;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (rows, statuses, next, count) = await widget.api.inspections(status: _status, page: more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(rows);
        _statuses = statuses;
        _next = next;
        _count = count;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.stockQualityLoadFailed, whenAbsent: context.l10n.stockQualityNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => screen));
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: l.stockQualityTitle),
      bottomNavigationBar: ActionBottomBar.maybe(
          action: widget.canCreate
              ? BarFab(
                  key: const ValueKey('qc-new'),
                  onPressed: () => _open(NewInspectionScreen(api: widget.api, products: widget.products)),
                  label: l.stockQualityNew,
                )
              : null),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_statuses.isNotEmpty)
              Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
                ChoiceChip(
                    key: const ValueKey('qc-status-all'),
                    label: Text(l.stockQualityAll),
                    selected: _status == null,
                    onSelected: (_) {
                      setState(() => _status = null);
                      _load();
                    }),
                for (final s in _statuses)
                  ChoiceChip(
                      key: ValueKey('qc-status-${s.id}'),
                      label: Text(s.name),
                      selected: _status == s.id,
                      onSelected: (_) {
                        setState(() => _status = s.id);
                        _load();
                      }),
              ]),
            // ⭐ গোটা ছাঁকনির গোনা — সার্ভারের, সব পাতার; ⛔ পুরনো সার্ভারে পট্টি নেই (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
            if (_count != null && _rows.isNotEmpty) TotalStrip(label: l.stockQualityTotal, value: l.stockQualityCountValue(_count!)),
            if (_busy && !_loaded) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (_loaded && _rows.isEmpty)
              EmptyState(icon: Icons.verified_outlined, title: l.stockQualityEmpty, message: l.stockWritePullAgain),
            for (final r in _rows)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  key: ValueKey('qc-${r.id}'),
                  title: Text(r.no, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text([r.product, [r.dateLabel ?? '', r.qty, r.statusLabel].where((s) => s.isNotEmpty).join(' · ')].join('\n')),
                  isThreeLine: true,
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _open(InspectionScreen(id: r.id, api: widget.api)),
                ),
              ),
            if (_next != null)
              Center(child: TextButton.icon(onPressed: _busy ? null : () => _load(more: true), icon: const Icon(Icons.expand_more), label: Text(l.stockQualityMore))),
          ],
        ),
      ),
    );
  }
}

class InspectionScreen extends StatefulWidget {
  const InspectionScreen({super.key, required this.id, this.api = const ServerQualitySerialApi()});

  final String id;
  final QualitySerialApi api;

  @override
  State<InspectionScreen> createState() => _InspectionScreenState();
}

class _InspectionScreenState extends State<InspectionScreen> {
  final _decideOnce = OnceKey();
  final _disposeOnce = OnceKey();
  final _accepted = TextEditingController();
  final _rejected = TextEditingController(text: '0');
  final _remarks = TextEditingController();
  final _disposeQty = TextEditingController();
  Inspection? _paper;
  String? _result;
  String? _reason;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_accepted, _rejected, _remarks, _disposeQty]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final paper = await widget.api.inspection(widget.id);
      if (!mounted) return;
      setState(() {
        _paper = paper;
        _error = null;
        if (_accepted.text.isEmpty) _accepted.text = paper.row.qty.split(' ').first;
      });
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.stockQualityOneLoadFailed));
    }
  }

  Future<void> _act(OnceKey once, Future<String> Function() write) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final notice = await once.send(write);
      if (!mounted) return;
      _tell(context, notice);
      await _load();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.stockQualitySaveFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _paper;
    final l = context.l10n;
    const gap = SizedBox(height: AppSpacing.sm);

    return Scaffold(
      appBar: AbosScreenBar(title: p == null ? l.stockQualityTitle : l.stockQualityTitleNo(p.row.no)),
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
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(p.row.product, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                    FactRow(l.stockQualityStatus, p.row.statusLabel),
                    if (p.row.dateLabel != null) FactRow(l.stockQualityDate, p.row.dateLabel!),
                    if (p.row.warehouse != null) FactRow(l.stockWriteWarehouse, p.row.warehouse!),
                    if (p.batch != null) FactRow(l.stockQualityLot, p.batch!),
                    FactRow(l.stockQualityInspected, p.row.qty),
                    FactRow(l.stockQualityAccepted, p.accepted),
                    FactRow(l.stockQualityRejected, p.rejected),
                    if (p.disposed != '0') FactRow(l.stockQualityDisposed, p.disposed),
                    if (p.criteria != null && p.criteria!.isNotEmpty) FactRow(l.stockQualityCriteria, p.criteria!),
                    if (p.remarks != null && p.remarks!.isNotEmpty) FactRow(l.stockQualityRemarks, p.remarks!),
                  ]),
                ),
              ),
              if (p.canDecide)
                Card(
                  key: const ValueKey('qc-decide-card'),
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Text(l.stockQualityVerdict, style: const TextStyle(fontWeight: FontWeight.w700)),
                      DropdownButtonFormField<String>(
                        key: const ValueKey('qc-result'),
                        initialValue: _result,
                        decoration: InputDecoration(labelText: l.stockQualityResult, isDense: true),
                        items: [for (final r in p.results) DropdownMenuItem(value: r.id, child: Text(r.name))],
                        onChanged: (v) => setState(() => _result = v),
                      ),
                      gap,
                      TextField(key: const ValueKey('qc-accepted'), controller: _accepted, keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: InputDecoration(labelText: l.stockQualityAccepted, isDense: true)),
                      gap,
                      TextField(key: const ValueKey('qc-rejected'), controller: _rejected, keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: InputDecoration(labelText: l.stockQualityRejected, isDense: true)),
                      gap,
                      TextField(key: const ValueKey('qc-remarks'), controller: _remarks, decoration: InputDecoration(labelText: l.stockQualityRemarksOptional, isDense: true)),
                      gap,
                      FilledButton(
                        key: const ValueKey('qc-decide'),
                        onPressed: _busy || _result == null
                            ? null
                            : () => _act(_decideOnce, () => widget.api.decide(widget.id, {
                                  'result': _result,
                                  'accepted_qty': _accepted.text.trim(),
                                  'rejected_qty': _rejected.text.trim(),
                                  if (_remarks.text.trim().isNotEmpty) 'remarks': _remarks.text.trim(),
                                })),
                        child: Text(l.stockQualityGiveVerdict),
                      ),
                    ]),
                  ),
                ),
              if (p.canDispose)
                Card(
                  key: const ValueKey('qc-dispose-card'),
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Text(l.stockQualityDisposeTitle, style: const TextStyle(fontWeight: FontWeight.w700)),
                      TextField(key: const ValueKey('qc-dispose-qty'), controller: _disposeQty, keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: InputDecoration(labelText: l.stockQualityDisposeQty, isDense: true)),
                      gap,
                      DropdownButtonFormField<String>(
                        key: const ValueKey('qc-dispose-reason'),
                        initialValue: _reason,
                        decoration: InputDecoration(labelText: l.stockWriteReason, isDense: true),
                        items: [for (final r in p.writeOffReasons) DropdownMenuItem(value: r.id, child: Text(r.name))],
                        onChanged: (v) => setState(() => _reason = v),
                      ),
                      gap,
                      FilledButton(
                        key: const ValueKey('qc-dispose'),
                        onPressed: _busy || _reason == null || _disposeQty.text.trim().isEmpty
                            ? null
                            : () => _act(_disposeOnce, () => widget.api.dispose(widget.id, {'qty': _disposeQty.text.trim(), 'reason': _reason})),
                        child: Text(l.stockQualityDispose),
                      ),
                    ]),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class NewInspectionScreen extends StatefulWidget {
  const NewInspectionScreen({super.key, this.api = const ServerQualitySerialApi(), this.products});

  final QualitySerialApi api;
  final List<ProductRecord>? products;

  @override
  State<NewInspectionScreen> createState() => _NewInspectionScreenState();
}

class _NewInspectionScreenState extends State<NewInspectionScreen> {
  final _once = OnceKey();
  final _qty = TextEditingController();
  final _batch = TextEditingController();
  final _criteria = TextEditingController();
  ProductRecord? _product;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    for (final c in [_qty, _batch, _criteria]) {
      c.addListener(_typed);
    }
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  bool get _written => _product != null || [_qty, _batch, _criteria].any((c) => c.text.trim().isNotEmpty);

  @override
  void dispose() {
    for (final c in [_qty, _batch, _criteria]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pick() async {
    final all = widget.products ?? ProductRecord.all().where((p) => p.isActive).toList(growable: false);
    final picked = await showModalBottomSheet<ProductRecord>(context: context, isScrollControlled: true, builder: (_) => ProductPickSheet(products: all));
    if (picked != null && mounted) setState(() => _product = picked);
  }

  Future<void> _send() async {
    if (_product == null || _qty.text.trim().isEmpty) {
      setState(() => _error = context.l10n.stockQualityNeedFields);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final notice = await _once.send(() => widget.api.openInspection({
            'product': _product!.id,
            'inspected_on': _today(),
            'inspected_qty': _qty.text.trim(),
            if (_batch.text.trim().isNotEmpty) 'batch_no': _batch.text.trim(),
            if (_criteria.text.trim().isNotEmpty) 'criteria': _criteria.text.trim(),
          }));
      if (!mounted) return;
      _tell(context, notice);
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.stockQualityOpenFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ পণ্য বা পরিমাণ লেখা থাকলে "পিছনে" জিজ্ঞেস করে — নতুন যাচাইয়ের লেখা এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _written && !_busy, child: _screen(context));

  Widget _screen(BuildContext context) {
    const gap = SizedBox(height: AppSpacing.sm);
    final l = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: l.stockQualityNewTitle),
      body: ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
        Card(
          child: ListTile(
            key: const ValueKey('qc-product'),
            leading: const Icon(Icons.inventory_2_outlined),
            title: Text(_product?.name ?? l.stockWritePickProduct),
            trailing: const Icon(Icons.search),
            onTap: _pick,
          ),
        ),
        gap,
        TextField(key: const ValueKey('qc-qty'), controller: _qty, keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: l.stockQualityQtyToCheck, isDense: true)),
        gap,
        TextField(key: const ValueKey('qc-batch'), controller: _batch, decoration: InputDecoration(labelText: l.stockQualityLotOptional, isDense: true)),
        gap,
        TextField(key: const ValueKey('qc-criteria'), controller: _criteria, decoration: InputDecoration(labelText: l.stockQualityCriteriaOptional, isDense: true)),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
        const SizedBox(height: AppSpacing.md),
        FilledButton(key: const ValueKey('qc-send'), onPressed: _busy ? null : _send, child: Text(l.stockQualityOpen)),
      ]),
    );
  }
}

// ── সিরিয়াল নম্বর ─────────────────────────────────────────────────────────────────────────────────

class SerialScreen extends StatefulWidget {
  const SerialScreen({super.key, this.api = const ServerQualitySerialApi(), this.canManage = false, this.products});

  final QualitySerialApi api;

  /// `inventory.serial.manage` — না থাকলে কেবল খোঁজা
  final bool canManage;
  final List<ProductRecord>? products;

  @override
  State<SerialScreen> createState() => _SerialScreenState();
}

class _SerialScreenState extends State<SerialScreen> {
  final _search = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (_) => _load());
  final List<SerialRow> _rows = [];
  int? _next;
  int? _count;
  bool _loaded = false;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _typed.dispose();
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (rows, next, count) = await widget.api.serials(serial: _search.text.trim(), page: more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(rows);
        _next = next;
        _count = count;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.stockSerialLoadFailed, whenAbsent: context.l10n.stockQualityNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(bool receive) async {
    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => SerialFormScreen(receive: receive, api: widget.api, products: widget.products)));
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: l.stockSerialTitle),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (widget.canManage)
              Row(children: [
                Expanded(child: OutlinedButton.icon(key: const ValueKey('serial-receive'), onPressed: () => _open(true), icon: const Icon(Icons.login), label: Text(l.stockSerialReceive))),
                const SizedBox(width: AppSpacing.sm),
                Expanded(child: OutlinedButton.icon(key: const ValueKey('serial-issue'), onPressed: () => _open(false), icon: const Icon(Icons.logout), label: Text(l.stockSerialIssue))),
              ]),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              key: const ValueKey('serial-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(prefixIcon: const Icon(Icons.qr_code_scanner), hintText: l.stockSerialSearchHint, isDense: true),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
            const SizedBox(height: AppSpacing.sm),
            // ⭐ গোটা খোঁজের গোনা — সার্ভারের, সব পাতার; ⛔ পুরনো সার্ভারে পট্টি নেই (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
            if (_count != null && _rows.isNotEmpty) TotalStrip(label: l.stockSerialTotal, value: l.stockQualityCountValue(_count!)),
            if (_busy && !_loaded) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (_loaded && _rows.isEmpty) EmptyState(icon: Icons.qr_code_2, title: l.stockSerialEmpty, message: l.stockWritePullAgain),
            for (final s in _rows)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  key: ValueKey('serial-${s.serialNo}'),
                  title: Text(s.serialNo, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text([s.product, [s.statusLabel, s.warehouse ?? '', s.soldTo ?? '', s.issuedOn ?? ''].where((t) => t.isNotEmpty).join(' · ')].join('\n')),
                  isThreeLine: true,
                ),
              ),
            if (_next != null)
              Center(child: TextButton.icon(onPressed: _busy ? null : () => _load(more: true), icon: const Icon(Icons.expand_more), label: Text(l.stockQualityMore))),
          ],
        ),
      ),
    );
  }
}

/// পিস ঢোকানো বা বের করা — প্রতি সারিতে একটা নম্বর, স্ক্যানারের মতো
class SerialFormScreen extends StatefulWidget {
  const SerialFormScreen({super.key, required this.receive, this.api = const ServerQualitySerialApi(), this.products});

  final bool receive;
  final QualitySerialApi api;
  final List<ProductRecord>? products;

  @override
  State<SerialFormScreen> createState() => _SerialFormScreenState();
}

class _SerialFormScreenState extends State<SerialFormScreen> {
  final _once = OnceKey();
  final _serials = TextEditingController();
  final _soldTo = TextEditingController();
  final _warranty = TextEditingController();
  ProductRecord? _product;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    for (final c in [_serials, _soldTo, _warranty]) {
      c.addListener(_typed);
    }
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  bool get _written => _product != null || [_serials, _soldTo, _warranty].any((c) => c.text.trim().isNotEmpty);

  @override
  void dispose() {
    for (final c in [_serials, _soldTo, _warranty]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pick() async {
    final all = widget.products ?? ProductRecord.all().where((p) => p.isActive).toList(growable: false);
    final picked = await showModalBottomSheet<ProductRecord>(context: context, isScrollControlled: true, builder: (_) => ProductPickSheet(products: all));
    if (picked != null && mounted) setState(() => _product = picked);
  }

  Future<void> _send() async {
    if (_serials.text.trim().isEmpty || (widget.receive && _product == null)) {
      setState(() => _error = widget.receive ? context.l10n.stockSerialNeedProductAndOne : context.l10n.stockSerialNeedOne);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final notice = await _once.send(() => widget.receive
          ? widget.api.receiveSerials({'product': _product!.id, 'received_on': _today(), 'serials': _serials.text.trim()})
          : widget.api.issueSerials({
              'serials': _serials.text.trim(),
              'issued_on': _today(),
              if (_soldTo.text.trim().isNotEmpty) 'sold_to': _soldTo.text.trim(),
              if (_warranty.text.trim().isNotEmpty) 'warranty_months': int.tryParse(_warranty.text.trim()) ?? 0,
            }));
      if (!mounted) return;
      _tell(context, notice);
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.stockSerialSaveFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ সিরিয়াল বা পণ্য লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লম্বা সিরিয়ালের তালিকা এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _written && !_busy, child: _screen(context));

  Widget _screen(BuildContext context) {
    const gap = SizedBox(height: AppSpacing.sm);
    final l = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: widget.receive ? l.stockSerialReceive : l.stockSerialIssue),
      body: ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
        if (widget.receive) ...[
          Card(
            child: ListTile(
              key: const ValueKey('serial-product'),
              leading: const Icon(Icons.inventory_2_outlined),
              title: Text(_product?.name ?? l.stockWritePickProduct),
              trailing: const Icon(Icons.search),
              onTap: _pick,
            ),
          ),
          gap,
        ],
        TextField(
          key: const ValueKey('serial-numbers'),
          controller: _serials,
          minLines: 4,
          maxLines: 12,
          decoration: InputDecoration(labelText: l.stockSerialNumbersLabel, alignLabelWithHint: true),
        ),
        if (!widget.receive) ...[
          gap,
          TextField(key: const ValueKey('serial-sold-to'), controller: _soldTo, decoration: InputDecoration(labelText: l.stockSerialSoldToOptional, isDense: true)),
          gap,
          TextField(key: const ValueKey('serial-warranty'), controller: _warranty, keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: l.stockSerialWarrantyOptional, isDense: true)),
        ],
        if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
        const SizedBox(height: AppSpacing.md),
        FilledButton(key: const ValueKey('serial-send'), onPressed: _busy ? null : _send, child: Text(widget.receive ? l.stockSerialReceiveButton : l.stockSerialIssueButton)),
      ]),
    );
  }
}
