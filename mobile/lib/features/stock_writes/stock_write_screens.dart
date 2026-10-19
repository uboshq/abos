import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';
import '../../core/widgets/unsaved_guard.dart';

/// ⭐ মজুদের পাঁচ লেখা, ফোনের নিজের ফর্মে — মাল বসানো, গণনা-সমন্বয়, ইস্যু, খোলা মজুদ, লট (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
///
/// <p>⛔ নিয়ম সব সার্ভারের ([[StockPhoneController]]) — ওয়েবের একই সেবা, একই চাবি, শাখার দেয়াল আর সই। ফোন কেবল ঘর ভরে পাঠায়
/// আর উত্তরটা বলে: বসেছে (২০১), মেনে নেওয়ার অপেক্ষায় বা সইয়ের অপেক্ষায় (২০২), নয়তো সার্ভারের বাংলা কারণ (৪২২/৪০৩)।
/// ⭐ প্রতিটা লেখা একবারই — [OnceKey]; দুবার চাপলে বা উত্তর হারালে একই চাবি, সার্ভার আগের উত্তর ফেরায়। ⛔ কেবল নেট থাকলে।

// ── সার্ভার ─────────────────────────────────────────────────────────────────────────────────────

class StockChoice {
  const StockChoice(this.id, this.name);

  final String id;
  final String name;
}

class StockWarehouse {
  const StockWarehouse(this.id, this.name, this.places);

  final String id;
  final String name;
  final List<StockChoice> places;
}

class StockSetup {
  const StockSetup({
    this.warehouses = const [],
    this.adjustReasons = const [],
    this.issueReasons = const [],
    this.can = const {},
  });

  final List<StockWarehouse> warehouses;
  final List<StockChoice> adjustReasons;
  final List<StockChoice> issueReasons;

  /// place · adjust · approve_count · opening · lot
  final Map<String, bool> can;

  factory StockSetup.fromJson(Map<dynamic, dynamic> j) {
    List<Map> maps(Object? raw) => raw is List ? raw.whereType<Map>().toList() : const [];
    StockChoice choice(Map m) => StockChoice(m['id']?.toString() ?? '', m['name']?.toString() ?? '');
    final reasons = j['reasons'] is Map ? j['reasons'] as Map : const {};
    final can = j['can'] is Map ? j['can'] as Map : const {};
    return StockSetup(
      warehouses: [
        for (final w in maps(j['warehouses']))
          StockWarehouse(w['id']?.toString() ?? '', w['name']?.toString() ?? '', [for (final p in maps(w['places'])) choice(p)]),
      ],
      adjustReasons: [for (final r in maps(reasons['adjust'])) choice(r)],
      issueReasons: [for (final r in maps(reasons['issue'])) choice(r)],
      can: {for (final e in can.entries) e.key.toString(): e.value == true},
    );
  }
}

/// সার্ভারের উত্তর — বসেছে, না অপেক্ষায়
class StockResult {
  const StockResult(this.status, this.notice, {this.no});

  /// done · waiting · held
  final String status;
  final String notice;
  final String? no;

  bool get done => status == 'done';

  factory StockResult.fromJson(Map<dynamic, dynamic>? j) =>
      StockResult(j?['status']?.toString() ?? 'done', j?['notice']?.toString() ?? '', no: j?['no']?.toString());
}

class WaitingLine {
  const WaitingLine({required this.ref, required this.product, this.warehouse, this.warehouseId = '', this.lot, this.waiting = '0', this.waitingFree = '0'});

  final String ref;
  final String product;
  final String? warehouse;
  final String warehouseId;
  final String? lot;
  final String waiting;
  final String waitingFree;
}

class WaitingPaper {
  const WaitingPaper(this.no, this.lines);

  final String no;
  final List<WaitingLine> lines;
}

class WaitingCount {
  const WaitingCount({required this.id, required this.no, this.warehouse, this.lines = const []});

  final String id;
  final String no;
  final String? warehouse;

  /// "পণ্য: খাতা → গোনা (পার্থক্য)"
  final List<String> lines;
}

abstract class StockWriteApi {
  Future<StockSetup> setup();
  Future<List<WaitingPaper>> placement();
  Future<StockResult> place(List<Map<String, Object?>> lines);
  Future<StockResult> adjust(Map<String, Object?> body);
  Future<List<WaitingCount>> counts();
  Future<StockResult> approveCount(String id, String reason);
  Future<StockResult> issue(Map<String, Object?> body);
  Future<StockResult> opening(Map<String, Object?> body);
  Future<StockResult> lot(Map<String, Object?> body);
}

class ServerStockWriteApi implements StockWriteApi {
  const ServerStockWriteApi();

  Future<StockResult> _post(String path, Map<String, Object?> body) async {
    final r = await ApiClient.dio.post<Map<String, dynamic>>('/inventory/$path', data: body);
    return StockResult.fromJson(r.data);
  }

  @override
  Future<StockSetup> setup() async =>
      StockSetup.fromJson((await ApiClient.dio.get<Map<String, dynamic>>('/inventory/setup')).data ?? const {});

  @override
  Future<List<WaitingPaper>> placement() async {
    final body = (await ApiClient.dio.get<Map<String, dynamic>>('/inventory/placement')).data ?? const {};
    return [
      for (final p in (body['papers'] is List ? body['papers'] as List : const []).whereType<Map>())
        WaitingPaper(p['no']?.toString() ?? '', [
          for (final l in (p['lines'] is List ? p['lines'] as List : const []).whereType<Map>())
            WaitingLine(
              ref: l['ref']?.toString() ?? '',
              product: l['product']?.toString() ?? '',
              warehouse: l['warehouse']?.toString(),
              warehouseId: l['warehouse_id']?.toString() ?? '',
              lot: l['lot']?.toString(),
              waiting: l['waiting']?.toString() ?? '0',
              waitingFree: l['waiting_free']?.toString() ?? '0',
            ),
        ]),
    ];
  }

  @override
  Future<StockResult> place(List<Map<String, Object?>> lines) => _post('placement', {'lines': lines});

  @override
  Future<StockResult> adjust(Map<String, Object?> body) => _post('adjust', body);

  @override
  Future<List<WaitingCount>> counts() async {
    final body = (await ApiClient.dio.get<Map<String, dynamic>>('/inventory/counts')).data ?? const {};
    return [
      for (final c in (body['rows'] is List ? body['rows'] as List : const []).whereType<Map>())
        WaitingCount(
          id: c['id']?.toString() ?? '',
          no: c['no']?.toString() ?? '',
          warehouse: c['warehouse']?.toString(),
          lines: [
            for (final l in (c['lines'] is List ? c['lines'] as List : const []).whereType<Map>())
              '${l['product']}: খাতায় ${l['book']} → গোনা ${l['counted']} (পার্থক্য ${l['difference']})',
          ],
        ),
    ];
  }

  @override
  Future<StockResult> approveCount(String id, String reason) => _post('counts/${Uri.encodeComponent(id)}/approve', {'reason': reason});

  @override
  Future<StockResult> issue(Map<String, Object?> body) => _post('issue', body);

  @override
  Future<StockResult> opening(Map<String, Object?> body) => _post('opening', body);

  @override
  Future<StockResult> lot(Map<String, Object?> body) => _post('lot', body);
}

// ── কোন লেখা ─────────────────────────────────────────────────────────────────────────────────────

/// চারটা ফর্মের ধরন — প্রতিটা ওয়েবের একই পর্দার ঘর চায়
enum StockForm { adjust, issue, opening, lot }

extension StockFormText on StockForm {
  String get title => switch (this) {
        StockForm.adjust => 'গণনা-সমন্বয়',
        StockForm.issue => 'মাল ইস্যু',
        StockForm.opening => 'খোলা মজুদ',
        StockForm.lot => 'লট দেওয়া',
      };

  String get qtyLabel => switch (this) {
        StockForm.adjust => 'গুনে পেলাম',
        StockForm.issue => 'কতটা বের হবে',
        StockForm.opening => 'পরিমাণ',
        StockForm.lot => 'কতটা লটে যাবে',
      };

  String get ability => switch (this) {
        StockForm.adjust || StockForm.issue => 'adjust',
        StockForm.opening => 'opening',
        StockForm.lot => 'lot',
      };
}

/// ⭐ মজুদের কাজ — যা যা এই মানুষ পারেন, কেবল সেগুলো
class StockWorkScreen extends StatefulWidget {
  const StockWorkScreen({super.key, this.api = const ServerStockWriteApi()});

  final StockWriteApi api;

  @override
  State<StockWorkScreen> createState() => _StockWorkScreenState();
}

class _StockWorkScreenState extends State<StockWorkScreen> {
  StockSetup? _setup;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final setup = await widget.api.setup();
      if (mounted) {
        setState(() {
          _setup = setup;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'মজুদের কাজ আনা গেল না।', whenAbsent: 'এই কাজ এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'));
      }
    }
  }

  void _open(Widget screen) => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => screen));

  @override
  Widget build(BuildContext context) {
    final s = _setup;
    Widget tile(String key, IconData icon, String title, String hint, Widget screen) => Card(
          margin: const EdgeInsets.only(bottom: AppSpacing.xs),
          child: ListTile(
            key: ValueKey('stock-work-$key'),
            leading: Icon(icon, color: AppColors.primary),
            title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(hint),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => _open(screen),
          ),
        );

    return Scaffold(
      appBar: AppBar(title: const Text('মজুদের কাজ')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (s == null && _error == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (s != null) ...[
              if (s.can['place'] == true)
                tile('place', Icons.move_to_inbox_outlined, 'মাল বসানো', 'এসে পৌঁছানো মাল তাকে তোলা',
                    PlacementScreen(api: widget.api, setup: s)),
              for (final form in StockForm.values)
                if (s.can[form.ability] == true)
                  tile(form.name, switch (form) {
                    StockForm.adjust => Icons.fact_check_outlined,
                    StockForm.issue => Icons.outbox_outlined,
                    StockForm.opening => Icons.inventory_2_outlined,
                    StockForm.lot => Icons.qr_code_2_outlined,
                  }, form.title, switch (form) {
                    StockForm.adjust => 'গুনে খাতার সাথে মেলানো',
                    StockForm.issue => 'বিক্রি ছাড়া মাল বের করা',
                    StockForm.opening => 'শুরুর মজুদ বসানো',
                    StockForm.lot => 'লট-ছাড়া মালকে লট',
                  }, StockFormScreen(form: form, api: widget.api, setup: s)),
              if (s.can['approve_count'] == true)
                tile('counts', Icons.rule_outlined, 'মেনে নেওয়ার অপেক্ষার গণনা', 'অন্যের গোনা মেনে নেওয়া',
                    WaitingCountsScreen(api: widget.api, setup: s)),
              if (!s.can.values.any((v) => v))
                const EmptyState(
                  icon: Icons.lock_outline,
                  title: 'মজুদের কোনো লেখার অনুমতি নেই',
                  message: 'দরকার হলে যিনি অনুমতি দেন তাঁকে বলুন।',
                ),
            ],
          ],
        ),
      ),
    );
  }
}

/// ফলাফল জানানো — বসেছে সবুজ, অপেক্ষা হলুদ
void _tell(BuildContext context, StockResult result) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    key: const ValueKey('stock-result'),
    backgroundColor: result.done ? AppColors.success : AppColors.pending,
    content: Text(result.notice.isEmpty ? (result.done ? 'বসেছে।' : 'অপেক্ষায় রাখা হলো।') : result.notice),
  ));
}

// ── চার ফর্ম ─────────────────────────────────────────────────────────────────────────────────────

class StockFormScreen extends StatefulWidget {
  const StockFormScreen({super.key, required this.form, required this.api, required this.setup, this.products});

  final StockForm form;
  final StockWriteApi api;
  final StockSetup setup;

  /// পরীক্ষার জন্য — না দিলে ফোনের সিঙ্ক করা পণ্য
  final List<ProductRecord>? products;

  @override
  State<StockFormScreen> createState() => _StockFormScreenState();
}

class _StockFormScreenState extends State<StockFormScreen> {
  final _once = OnceKey();
  final _qty = TextEditingController();
  final _free = TextEditingController();
  final _cost = TextEditingController();
  final _lotNo = TextEditingController();
  final _expiry = TextEditingController();
  final _note = TextEditingController();
  ProductRecord? _product;
  String? _warehouse;
  String? _reason;
  bool _freePool = false;
  bool _busy = false;
  String? _error;

  StockForm get _form => widget.form;

  List<StockChoice> get _reasons => _form == StockForm.adjust
      ? widget.setup.adjustReasons
      : _form == StockForm.issue
          ? widget.setup.issueReasons
          : const [];

  @override
  void initState() {
    super.initState();
    if (widget.setup.warehouses.length == 1) _warehouse = widget.setup.warehouses.first.id;
    for (final c in _texts) {
      c.addListener(_typed);
    }
  }

  List<TextEditingController> get _texts => [_qty, _free, _cost, _lotNo, _expiry, _note];

  void _typed() {
    if (mounted) setState(() {});
  }

  bool get _written => _texts.any((c) => c.text.trim().isNotEmpty);

  @override
  void dispose() {
    for (final c in [_qty, _free, _cost, _lotNo, _expiry, _note]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickProduct() async {
    final all = widget.products ?? ProductRecord.all().where((p) => p.isActive).toList(growable: false);
    final picked = await showModalBottomSheet<ProductRecord>(
      context: context,
      isScrollControlled: true,
      builder: (_) => ProductPickSheet(products: all),
    );
    if (picked != null && mounted) setState(() => _product = picked);
  }

  Future<void> _send() async {
    final product = _product;
    if (product == null || _warehouse == null || _qty.text.trim().isEmpty || (_reasons.isNotEmpty && _reason == null)) {
      setState(() => _error = 'পণ্য, গুদাম${_reasons.isNotEmpty ? ', কারণ' : ''} আর পরিমাণ লাগবেই।');
      return;
    }
    String? text(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
    final body = <String, Object?>{
      'product': product.id,
      'warehouse': _warehouse,
      if (_reason != null) 'reason': _reason,
      (_form == StockForm.adjust ? 'counted' : 'qty'): _qty.text.trim(),
      if (_form == StockForm.adjust && _freePool) 'pool': 'free',
      if (_form == StockForm.lot && text(_free) != null) 'free_qty': text(_free),
      if ((_form == StockForm.adjust || _form == StockForm.opening) && text(_cost) != null) 'unit_cost': text(_cost),
      if (_form != StockForm.issue && text(_lotNo) != null) 'batch_no': text(_lotNo),
      if (_form != StockForm.issue && text(_expiry) != null) 'expiry_date': text(_expiry),
      if (text(_note) != null) 'narration': text(_note),
    };

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await _once.send(() => switch (_form) {
            StockForm.adjust => widget.api.adjust(body),
            StockForm.issue => widget.api.issue(body),
            StockForm.opening => widget.api.opening(body),
            StockForm.lot => widget.api.lot(body),
          });
      if (!mounted) return;
      _tell(context, result);
      setState(() {
        _qty.clear();
        _free.clear();
        _cost.clear();
        _lotNo.clear();
        _expiry.clear();
        _note.clear();
      });
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: '${_form.title} বসানো গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ পরিমাণ বা কোনো ঘর লেখা থাকলে "পিছনে" জিজ্ঞেস করে — মজুদের লেখা এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _written && !_busy, child: _screen(context));

  Widget _screen(BuildContext context) {
    final p = _product;
    InputDecoration box(String label, {String? hint}) => InputDecoration(labelText: label, hintText: hint, isDense: true);
    const gap = SizedBox(height: AppSpacing.sm);

    return Scaffold(
      appBar: AppBar(title: Text(_form.title)),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Card(
            child: ListTile(
              key: const ValueKey('stock-product'),
              leading: const Icon(Icons.inventory_2_outlined),
              title: Text(p == null ? 'পণ্য বাছুন' : p.name),
              subtitle: p == null ? null : Text([p.code, p.unit].whereType<String>().join(' · ')),
              trailing: const Icon(Icons.search),
              onTap: _pickProduct,
            ),
          ),
          gap,
          DropdownButtonFormField<String>(
            key: const ValueKey('stock-warehouse'),
            initialValue: _warehouse,
            decoration: box('গুদাম'),
            items: [for (final w in widget.setup.warehouses) DropdownMenuItem(value: w.id, child: Text(w.name))],
            onChanged: (v) => setState(() => _warehouse = v),
          ),
          if (_reasons.isNotEmpty) ...[
            gap,
            DropdownButtonFormField<String>(
              key: const ValueKey('stock-reason'),
              initialValue: _reason,
              decoration: box('কারণ'),
              items: [for (final r in _reasons) DropdownMenuItem(value: r.id, child: Text(r.name))],
              onChanged: (v) => setState(() => _reason = v),
            ),
          ],
          gap,
          TextField(
            key: const ValueKey('stock-qty'),
            controller: _qty,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: box(_form.qtyLabel),
          ),
          if (_form == StockForm.adjust)
            SwitchListTile(
              key: const ValueKey('stock-free-pool'),
              contentPadding: EdgeInsets.zero,
              title: const Text('ফ্রি মাল গুনছি'),
              value: _freePool,
              onChanged: (v) => setState(() => _freePool = v),
            ),
          if (_form == StockForm.lot) ...[
            gap,
            TextField(
              key: const ValueKey('stock-free'),
              controller: _free,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: box('ফ্রি (ঐচ্ছিক)'),
            ),
          ],
          if (_form == StockForm.opening || (_form == StockForm.adjust && !_freePool)) ...[
            gap,
            TextField(
              key: const ValueKey('stock-cost'),
              controller: _cost,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: box(_form == StockForm.opening ? 'একক দর' : 'বাড়তি মালের দর (ঐচ্ছিক)'),
            ),
          ],
          if (_form != StockForm.issue) ...[
            gap,
            TextField(
              key: const ValueKey('stock-lot'),
              controller: _lotNo,
              decoration: box(_form == StockForm.lot ? 'লট নম্বর' : 'লট নম্বর (লট-ধরা পণ্যে)'),
            ),
            gap,
            TextField(
              key: const ValueKey('stock-expiry'),
              controller: _expiry,
              keyboardType: TextInputType.datetime,
              decoration: box('মেয়াদ (ঐচ্ছিক)', hint: 'YYYY-MM-DD'),
            ),
          ],
          gap,
          TextField(key: const ValueKey('stock-note'), controller: _note, decoration: box('বিবরণ (ঐচ্ছিক)')),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            key: const ValueKey('stock-send'),
            onPressed: _busy ? null : _send,
            icon: _busy ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.check),
            label: Text('${_form.title} পাঠান'),
          ),
        ],
      ),
    );
  }
}

class ProductPickSheet extends StatefulWidget {
  const ProductPickSheet({super.key, required this.products});

  final List<ProductRecord> products;

  @override
  State<ProductPickSheet> createState() => _ProductPickSheetState();
}

class _ProductPickSheetState extends State<ProductPickSheet> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final q = _q.toLowerCase();
    final shown = widget.products
        .where((p) => q.isEmpty || p.name.toLowerCase().contains(q) || (p.code ?? '').toLowerCase().contains(q) || p.barcode == _q)
        .take(100)
        .toList(growable: false);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.75,
          child: Column(children: [
            Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: TextField(
                key: const ValueKey('stock-product-search'),
                autofocus: true,
                decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'নাম, কোড বা বারকোড', isDense: true),
                onChanged: (v) => setState(() => _q = v.trim()),
              ),
            ),
            Expanded(
              child: shown.isEmpty
                  ? const Center(child: Text('কোনো পণ্য মেলেনি'))
                  : ListView(children: [
                      for (final p in shown)
                        ListTile(
                          key: ValueKey('stock-product-${p.id}'),
                          title: Text(p.name),
                          subtitle: Text([p.code, p.unit].whereType<String>().join(' · ')),
                          onTap: () => Navigator.of(context).pop(p),
                        ),
                    ]),
            ),
          ]),
        ),
      ),
    );
  }
}

// ── মাল বসানো ────────────────────────────────────────────────────────────────────────────────────

class PlacementScreen extends StatefulWidget {
  const PlacementScreen({super.key, required this.api, required this.setup});

  final StockWriteApi api;
  final StockSetup setup;

  @override
  State<PlacementScreen> createState() => _PlacementScreenState();
}

class _PlacementScreenState extends State<PlacementScreen> {
  final _once = OnceKey();
  List<WaitingPaper>? _papers;
  final Map<String, TextEditingController> _qty = {};
  final Map<String, String?> _place = {};
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _qty.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final papers = await widget.api.placement();
      if (!mounted) return;
      setState(() {
        _papers = papers;
        _error = null;
        for (final l in papers.expand((p) => p.lines)) {
          _qty.putIfAbsent(l.ref, () => TextEditingController(text: l.waiting));
        }
      });
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'বসানোর অপেক্ষার মাল আনা গেল না।'));
    }
  }

  Future<void> _placeAll(WaitingPaper paper) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await _once.send(() => widget.api.place([
            for (final l in paper.lines)
              {
                'ref': l.ref,
                'qty': _qty[l.ref]?.text.trim().isEmpty ?? true ? '0' : _qty[l.ref]!.text.trim(),
                'free_qty': l.waitingFree,
                if (_place[l.ref] != null) 'place': _place[l.ref],
              },
          ]));
      if (!mounted) return;
      _tell(context, result);
      await _load();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'মাল বসানো গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final papers = _papers;
    List<StockChoice> placesOf(String warehouseId) =>
        widget.setup.warehouses.where((w) => w.id == warehouseId).expand((w) => w.places).toList(growable: false);

    return Scaffold(
      appBar: AppBar(title: const Text('মাল বসানো')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (papers == null && _error == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (papers != null && papers.isEmpty)
              const EmptyState(icon: Icons.check_circle_outline, title: 'বসানোর অপেক্ষায় কিছু নেই', message: 'নিচে টেনে আবার দেখুন।'),
            for (final paper in papers ?? const <WaitingPaper>[])
              Card(
                key: ValueKey('placement-${paper.no}'),
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(paper.no, style: const TextStyle(fontWeight: FontWeight.w700)),
                    for (final l in paper.lines) ...[
                      const Divider(),
                      Text(l.product, style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text([
                        if (l.warehouse != null) l.warehouse!,
                        if (l.lot != null && l.lot!.isNotEmpty) 'লট ${l.lot}',
                        'অপেক্ষায় ${l.waiting}',
                        if (l.waitingFree != '0') 'ফ্রি ${l.waitingFree}',
                      ].join(' · '), style: const TextStyle(color: AppColors.onSurfaceMuted)),
                      Row(children: [
                        Expanded(
                          child: TextField(
                            key: ValueKey('placement-qty-${l.ref}'),
                            controller: _qty[l.ref],
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            decoration: const InputDecoration(labelText: 'বসাচ্ছি', isDense: true),
                          ),
                        ),
                        if (placesOf(l.warehouseId).isNotEmpty) ...[
                          const SizedBox(width: AppSpacing.sm),
                          Expanded(
                            child: DropdownButtonFormField<String>(
                              key: ValueKey('placement-place-${l.ref}'),
                              initialValue: _place[l.ref],
                              isExpanded: true,
                              decoration: const InputDecoration(labelText: 'জায়গা', isDense: true),
                              items: [for (final p in placesOf(l.warehouseId)) DropdownMenuItem(value: p.id, child: Text(p.name))],
                              onChanged: (v) => setState(() => _place[l.ref] = v),
                            ),
                          ),
                        ],
                      ]),
                    ],
                    const SizedBox(height: AppSpacing.sm),
                    Align(
                      alignment: Alignment.centerRight,
                      child: FilledButton.icon(
                        key: ValueKey('placement-send-${paper.no}'),
                        onPressed: _busy ? null : () => _placeAll(paper),
                        icon: const Icon(Icons.move_to_inbox_outlined),
                        label: const Text('বসান'),
                      ),
                    ),
                  ]),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

// ── মেনে নেওয়ার অপেক্ষার গণনা ──────────────────────────────────────────────────────────────────────

class WaitingCountsScreen extends StatefulWidget {
  const WaitingCountsScreen({super.key, required this.api, required this.setup});

  final StockWriteApi api;
  final StockSetup setup;

  @override
  State<WaitingCountsScreen> createState() => _WaitingCountsScreenState();
}

class _WaitingCountsScreenState extends State<WaitingCountsScreen> {
  final Map<String, OnceKey> _once = {};
  final Map<String, String?> _reason = {};
  List<WaitingCount>? _counts;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final counts = await widget.api.counts();
      if (mounted) {
        setState(() {
          _counts = counts;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'অপেক্ষার গণনা আনা গেল না।'));
    }
  }

  Future<void> _approve(WaitingCount count) async {
    final reason = _reason[count.id];
    if (reason == null) {
      setState(() => _error = 'কারণ বাছুন — পার্থক্যটা কোন খাতে যাবে তা কারণ বলে।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await _once.putIfAbsent(count.id, OnceKey.new).send(() => widget.api.approveCount(count.id, reason));
      if (!mounted) return;
      _tell(context, result);
      await _load();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'গণনাটা মেনে নেওয়া গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final counts = _counts;

    return Scaffold(
      appBar: AppBar(title: const Text('মেনে নেওয়ার অপেক্ষার গণনা')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (counts == null && _error == null) const LinearProgressIndicator(),
            // ⭐ গোটা তালিকার গোনা — সার্ভার পাতা ভাঙে না (১০০টা, পুরোটা), তাই ফোনের হাতের সারিই সব (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
            if (counts != null && counts.isNotEmpty) TotalStrip(label: 'অপেক্ষায় মোট', value: '${counts.length} টি'),
            if (_error != null) BooksError(_error!),
            if (counts != null && counts.isEmpty)
              const EmptyState(icon: Icons.check_circle_outline, title: 'অপেক্ষায় কোনো গণনা নেই', message: 'নিচে টেনে আবার দেখুন।'),
            for (final c in counts ?? const <WaitingCount>[])
              Card(
                key: ValueKey('count-${c.id}'),
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text([c.no, if (c.warehouse != null) c.warehouse!].join(' · '), style: const TextStyle(fontWeight: FontWeight.w700)),
                    for (final l in c.lines) Text(l),
                    const SizedBox(height: AppSpacing.sm),
                    DropdownButtonFormField<String>(
                      key: ValueKey('count-reason-${c.id}'),
                      initialValue: _reason[c.id],
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'কারণ', isDense: true),
                      items: [for (final r in widget.setup.adjustReasons) DropdownMenuItem(value: r.id, child: Text(r.name))],
                      onChanged: (v) => setState(() => _reason[c.id] = v),
                    ),
                    Align(
                      alignment: Alignment.centerRight,
                      child: FilledButton.icon(
                        key: ValueKey('count-approve-${c.id}'),
                        onPressed: _busy ? null : () => _approve(c),
                        icon: const Icon(Icons.check),
                        label: const Text('মেনে নিন'),
                      ),
                    ),
                  ]),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

// ── ওয়েবের পথ থেকে সোজা ফর্মে ────────────────────────────────────────────────────────────────────

/// ⭐ মেনুর ওয়েব-সারি (`/inventory/stock/issue` …) সোজা তার ফোন-ফর্মে — আগে গুদাম আর কারণ আনা ([NativeForWeb])
class StockDoor extends StatefulWidget {
  const StockDoor({super.key, required this.open, this.api = const ServerStockWriteApi()});

  /// place · counts · অথবা একটা [StockForm]-এর নাম
  final String open;
  final StockWriteApi api;

  @override
  State<StockDoor> createState() => _StockDoorState();
}

class _StockDoorState extends State<StockDoor> {
  StockSetup? _setup;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final setup = await widget.api.setup();
      if (mounted) {
        setState(() {
          _setup = setup;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'মজুদের কাজ আনা গেল না।', whenAbsent: 'এই কাজ এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _setup;
    if (s == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('মজুদের কাজ')),
        body: _error == null ? const LinearProgressIndicator() : Padding(padding: const EdgeInsets.all(AppSpacing.md), child: BooksError(_error!)),
      );
    }
    final form = StockForm.values.where((f) => f.name == widget.open).firstOrNull;
    return switch (widget.open) {
      'place' => PlacementScreen(api: widget.api, setup: s),
      'counts' => WaitingCountsScreen(api: widget.api, setup: s),
      _ when form != null => StockFormScreen(form: form, api: widget.api, setup: s),
      _ => StockWorkScreen(api: widget.api),
    };
  }
}
