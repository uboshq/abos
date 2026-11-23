import 'package:flutter/material.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';

import '../../core/navigation/recent_papers.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/locale/l10n.dart';
import '../../core/orders/delivery_order_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../home/nav_bottom_bar.dart' show ActionBottomBar, BarFab;
import '../printing/document_actions_sheet.dart';
import '../printing/row_actions.dart';
import '../../core/widgets/app_status_pill.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/design_list_row.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/row_icon.dart';
import '../../core/widgets/shown_rows.dart';
import '../books/books_widgets.dart' show MoreButton;
import '../../core/widgets/state_views.dart';
import '../../core/widgets/totals_bar.dart';
import '../../core/widgets/unsaved_guard.dart';

/// ডেলিভারি অর্ডার (DO) — তালিকা, নতুন DO, আর একটা DO-র পাতা (0.4.8, মালিকের বিক্রয়-ধারা §২ক-খ)।
///
/// <p>ⓘ মালিকের নিয়ম: এক লাইনে এক জিনিস, টেবিল নয়। দাম পণ্যের — ফোনে দামের ঘর নেই। সুপারভাইজার এই পাতাতেই
/// পরিমাণ কমিয়ে সই দেন; সইটা অনুমোদন-বাক্সের একই দরজায় যায়।

String _qty(double v) => v == v.roundToDouble() ? v.toInt().toString() : v.toString();

/// ⭐ কাগজের নাম — কোম্পানি বিক্রয় আদেশে চলে গেলে একই পর্দা "আদেশ" বলে (DO+SO মেশানো, ধাপ ১০)।
/// ⓘ ARB-এর select-এর চাবি: `order` (বিক্রয় আদেশ) বা `do`
String _kind(DeliveryOrderApi api) => api.forOrders ? 'order' : 'do';

String _title(BuildContext context, DeliveryOrderApi api) => context.l10n.deliveryOrdersTitle(_kind(api));

/// ⭐ অবস্থার রঙের মানে — চিপের একই চার রং ([AppStatusPill]); না চিনলে নীল "চলছে"
AppStatusKind orderStatusKind(String status) => switch (status) {
      'draft' => AppStatusKind.neutral,
      'rejected' || 'cancelled' || 'credit_held' || 'accounts_held' => AppStatusKind.danger,
      'invoiced' || 'closed' || 'delivered' || 'confirmed' || 'supervisor_approved' || 'accounts_approved' => AppStatusKind.success,
      'submitted' || 'supervisor_pending' || 'depot_check' || 'pending' => AppStatusKind.warning,
      _ => AppStatusKind.pending,
    };

/// ⭐ নকশা ৩-এর তালিকার সারি (মোবাইলের নকশা ৩, পাতা ৩; a4-এর নিয়ম §৪, ১৯ অক্টোবর ২০২৬) — বাঁয়ে [RowIcon], প্রথম লাইনে
/// গ্রাহক "নাম · পয়েন্ট · শাখা" ([CustomerLine], 16 w600), দ্বিতীয় লাইনে কাগজের নম্বর · তারিখ/ধরন (13, মলিন), ডানে টাকা
/// ([Money.taka], 15 w700) আর অবস্থার চিপ। পুরো সারিতে চাপলে খোলে।
///
/// <p>ⓘ নম্বর নিজের আলাদা লেখা ([numberKey]) — খোঁজা আর পড়া দুটোই নম্বর ধরে। ⓘ সারি অন্তত ৭২dp, উচ্চতা বাঁধা নয়: ২০০%
/// অক্ষরে ডানের টাকা আর চিপ নিজের ভাগে ভাঙে, ছাপিয়ে যায় না (নিয়ম §২)।
class PaperListRow extends StatelessWidget {
  const PaperListRow({
    super.key,
    required this.icon,
    required this.party,
    required this.number,
    this.tone,
    this.detail,
    this.amount,
    this.pill,
    this.below,
    this.numberKey,
    this.onTap,
  });

  final IconData icon;
  final Color? tone;

  /// গ্রাহক — সবসময় "নাম · পয়েন্ট · শাখা" ([withPoint] বা `CustomerRecord.label`); ⛔ খালি নাম নয় (মালিকের স্থায়ী নিয়ম)
  final String party;
  final String number;
  final String? detail;
  final num? amount;
  final Widget? pill;
  final Widget? below;
  final Key? numberKey;
  final VoidCallback? onTap;

  /// ⭐ একটাই সারি অ্যাপজুড়ে — a4-এর [DesignListRow] (২০ অক্টোবর ২০২৬); এখানে কেবল "নম্বর · বিবরণ" জোড়া হয়
  @override
  Widget build(BuildContext context) => DesignListRow(
        icon: icon,
        tone: tone,
        name: party,
        second: (detail == null || detail!.isEmpty) ? number : '$number · $detail',
        secondKey: numberKey,
        amount: amount,
        pill: pill,
        below: below,
        onTap: onTap,
      );
}

class DeliveryOrderListScreen extends StatefulWidget {
  const DeliveryOrderListScreen({super.key, this.api = const ServerDeliveryOrderApi(), this.canWrite = true, this.title});

  final DeliveryOrderApi api;

  /// `sales.do.create` (আদেশে `sales.order.create`) — না থাকলে "নতুন" বোতাম নেই।
  final bool canWrite;

  /// মেনুর নিজের নাম (যেমন "অপেক্ষমাণ অর্ডার") — না দিলে "ডেলিভারি অর্ডার"/"বিক্রয় আদেশ"
  final String? title;

  @override
  State<DeliveryOrderListScreen> createState() => _DeliveryOrderListScreenState();
}

class _DeliveryOrderListScreenState extends State<DeliveryOrderListScreen> {
  bool _awaitingMe = false;
  List<DeliveryOrder>? _rows;
  PaperTotal? _total;

  /// সার্ভারের পরের পাতা — null মানে শেষ
  int? _next;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// [more] — "আরও দেখুন": পরের পাতা এনে আগের সারির পিছনে জোড়ে; নাহলে প্রথম পাতা থেকে নতুন করে
  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (rows, total, next) = await widget.api.list(awaitingMe: _awaitingMe, page: more ? (_next ?? 1) : 1);
      if (mounted) {
        setState(() {
          _rows = [if (more) ...?_rows, ...rows];
          _total = total;
          _next = next;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error =
            errorMessageFor(e, fallback: context.l10n.deliveryOrdersListLoadFailed, whenAbsent: context.l10n.deliveryOrdersNotOnServer(_kind(widget.api))));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(DeliveryOrder order) async {
    await Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => DeliveryOrderScreen(id: order.id, api: widget.api),
    ));
    _load();
  }

  Future<void> _new() async {
    final made = await Navigator.of(context).push<DeliveryOrder>(MaterialPageRoute(
      builder: (_) => NewDeliveryOrderScreen(api: widget.api),
    ));
    if (made != null && mounted) {
      await _open(made);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows ?? const <DeliveryOrder>[];
    final total = _total;
    // ⭐ এই পাতার মোট — দেখানো সব সারি, "আরও দেখুন"-এর সব পাতা মিলে ([ShownRows], অ্যাপজুড়ে এক মানে); প্রত্যাখ্যাত আর
    // বাতিল সারিতে থাকে, মোটে নয় (সার্ভারের `DeliveryOrderStatus::UNCOUNTED`, [PaperTotal.counts])
    final page = ShownRows.of(rows, (o) => o.total, counts: (o) => PaperTotal.counts(o.status));
    // ⭐ দেখানো প্রত্যাখ্যাত আদেশ — মোটে নেই, নিজের আলাদা লাইনে (fe-র নিয়ম, ২৪ অক্টোবর ২০২৬)
    final rejectedRows = ShownRows.of(rows.where((o) => o.status == 'rejected'), (o) => o.total);
    return Scaffold(
      appBar: AbosScreenBar(title: widget.title ?? _title(context, widget.api), module: context.l10n.deliveryOrdersModule),
      // ⭐ নিচে স্থির পট্টি — "এই পাতা" হাতে আসা সারির, "সর্বমোট" সার্ভারের পুরো ছাঁকা তালিকার (মালিক, ১৭ অক্টোবর ২০২৬;
      // নকশা ৩, পাতা ৩)। ⓘ পুরনো সার্ভার মোট না পাঠালে পট্টিই নেই — পাতার যোগফলকে সর্বমোট বলা মিথ্যা হত।
      // ⭐ "＋ নতুন" একই বারে মোটের পাশে ([ActionBottomBar]) — তালিকার ওপরে ভাসে না
      bottomNavigationBar: ActionBottomBar.maybe(
          totals: total != null && rows.isNotEmpty
              ? TotalsBar(
                  pageCount: page.count,
                  pageSum: page.sum,
                  allCount: total.count,
                  allSum: total.sum,
                  // ⓘ ডেস্কে তারিখের ছাঁকনি নেই (সার্ভারও তারিখে ছাঁকে না) — সব তারিখ
                  period: const TotalsPeriod.allDates(),
                  rejectedCount: rejectedRows.count,
                  rejectedSum: rejectedRows.sum)
              : null,
          action: widget.canWrite
              ? BarFab(
                  key: const ValueKey('do-new'),
                  onPressed: _new,
                  label: context.l10n.deliveryOrdersNew(_kind(widget.api)),
                )
              : null),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Wrap(spacing: AppSpacing.xs, children: [
              ChoiceChip(
                label: Text(context.l10n.deliveryOrdersFilterAll),
                selected: !_awaitingMe,
                onSelected: (_) {
                  setState(() => _awaitingMe = false);
                  _load();
                },
              ),
              ChoiceChip(
                key: const ValueKey('do-awaiting-me'),
                label: Text(context.l10n.deliveryOrdersFilterMine),
                selected: _awaitingMe,
                onSelected: (_) {
                  setState(() => _awaitingMe = true);
                  _load();
                },
              ),
            ]),
            const SizedBox(height: AppSpacing.sm),
            // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): প্রথম আনায় খসড়া সারি, ভুলে "আবার চেষ্টা করুন", খালিতে "নতুন …"
            if (_busy && _rows == null && _error == null) const LoadingSkeleton(),
            if (_busy && _rows != null) const LinearProgressIndicator(),
            if (_error != null) ErrorState(key: const ValueKey('do-error'), message: _error!, onRetry: _busy ? null : _load),
            if (_rows != null && rows.isEmpty && !_busy && _error == null)
              EmptyState(
                icon: Icons.local_shipping_outlined,
                title: context.l10n.deliveryOrdersEmpty(_kind(widget.api)),
                message: _awaitingMe ? context.l10n.deliveryOrdersEmptyMine : null,
                action: widget.canWrite
                    ? FilledButton.icon(
                        key: const ValueKey('do-empty-new'),
                        style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
                        onPressed: _new,
                        icon: const Icon(Icons.add),
                        label: Text(context.l10n.deliveryOrdersNew(_kind(widget.api))),
                      )
                    : null,
              ),
            for (final order in rows)
              PaperListRow(
                key: ValueKey('do-row-${order.id}'),
                icon: order.awaitingMe ? Icons.draw_outlined : (widget.api.forOrders ? Icons.receipt_long_outlined : Icons.local_shipping_outlined),
                tone: order.awaitingMe ? context.palette.warning : null,
                party: order.customer ?? context.l10n.deliveryOrdersNoCustomer,
                number: order.no,
                detail: order.date,
                amount: order.total,
                // ⭐ ওয়েবের চিপ — ব্যাক অর্ডার, সীমায় আটকে, চালান আর বিল (সমন্বয়ক, ৬ অক্টোবর ২০২৬); না থাকলে অবস্থার চিপ
                pill: order.chips.isEmpty ? AppStatusPill(label: order.statusLabel, kind: orderStatusKind(order.status)) : null,
                // ⭐ ওয়েবের মতো "অ্যাকশন ▾" (মালিক, ৯ নভেম্বর ২০২৬: "Abos er sokol list") — দেখা, আর বিক্রয় আদেশে ছাপা ও পাঠানো;
                //   ⓘ DO-তে ছাপা নেই — কাগজের পাতার মতোই (সার্ভারের `SalesOrder` ধরন আদেশের কাগজ দেয়, DO-র নয়)
                below: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (order.chips.isNotEmpty) OrderChips(order.chips),
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: RowActionsButton(
                        number: order.no,
                        state: order.statusLabel.isEmpty ? null : order.statusLabel,
                        onView: () => _open(order),
                        paperType: widget.api.forOrders ? 'SalesOrder' : null,
                        paperId: widget.api.forOrders ? order.id : null,
                        paperTitle: context.l10n.deliveryOrdersPrintTitle(order.no),
                      ),
                    ),
                  ],
                ),
                onTap: () => _open(order),
              ),
            // ⭐ পরের পাতা — বাকি তালিকার একই "আরও দেখুন" (সার্ভার পাতায় ৫০টা দেয়)
            if (_next != null && rows.isNotEmpty) MoreButton(busy: _busy, onPressed: () => _load(more: true)),
          ],
        ),
      ),
    );
  }
}

/// নতুন DO — ডিলার, তারপর এক লাইনে এক পণ্য আর পরিমাণ।
class NewDeliveryOrderScreen extends StatefulWidget {
  const NewDeliveryOrderScreen({super.key, this.api = const ServerDeliveryOrderApi(), this.customers, this.products});

  final DeliveryOrderApi api;

  /// পরীক্ষার জন্য — না দিলে ফোনে জমা তালিকা ([[CustomerRecord.all]], [[ProductRecord.all]])।
  final List<CustomerRecord>? customers;
  final List<ProductRecord>? products;

  @override
  State<NewDeliveryOrderScreen> createState() => _NewDeliveryOrderScreenState();
}

class _NewDeliveryOrderScreenState extends State<NewDeliveryOrderScreen> {
  CustomerRecord? _customer;
  final List<(ProductRecord, int)> _lines = [];
  final _note = TextEditingController();

  /// ⭐ এই কাজের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]], অডিট ফোন ⚠️১২)
  final _once = OnceKey();
  bool _busy = false;
  String? _error;

  late final List<CustomerRecord> _customers = widget.customers ?? CustomerRecord.all();
  late final List<ProductRecord> _products = (widget.products ?? ProductRecord.all()).where((p) => p.isActive).toList();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<T?> _pick<T>(String title, List<T> items, String Function(T) label) => showModalBottomSheet<T>(
        context: context,
        isScrollControlled: true,
        builder: (_) => _PickSheet<T>(title: title, items: items, label: label),
      );

  Future<void> _addLine() async {
    final product = await _pick<ProductRecord>(context.l10n.deliveryOrdersPickProduct, _products, (p) => p.name);
    if (product == null || !mounted) return;
    final qty = await _askQty(product.name, 1);
    if (qty == null || qty <= 0) return;
    setState(() {
      _lines.removeWhere((l) => l.$1.id == product.id); // ⓘ এক পণ্য একবার — মালিকের নিয়ম
      _lines.add((product, qty));
    });
  }

  Future<int?> _askQty(String title, int initial) => showDialog<int>(context: context, builder: (_) => _QtyDialog(title: title, initial: initial));

  Future<void> _save({required bool submit}) async {
    final customer = _customer;
    if (customer == null || _lines.isEmpty) {
      setState(() => _error = customer == null ? context.l10n.deliveryOrdersNeedDealer : context.l10n.deliveryOrdersNeedLine);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final made = await _once.send(() => widget.api.create(
            customerId: customer.id,
            lines: [for (final l in _lines) WantedLine(l.$1.id, l.$2)],
            submit: submit,
            note: _note.text,
          ));
      if (mounted) Navigator.of(context).pop(made);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: context.l10n.deliveryOrdersSaveFailed(_kind(widget.api))));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ ডিলার বা পণ্য বা মন্তব্য লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা DO এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) =>
      UnsavedGuard(dirty: !_busy && (_customer != null || _lines.isNotEmpty || _note.text.trim().isNotEmpty), child: _screen(context));

  Widget _screen(BuildContext context) {
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.deliveryOrdersNew(_kind(widget.api)), module: context.l10n.deliveryOrdersModule),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Card(
            child: ListTile(
              key: const ValueKey('do-customer'),
              leading: const Icon(Icons.storefront_outlined),
              title: Text(_customer?.label ?? context.l10n.deliveryOrdersPickDealer),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                final picked = await _pick<CustomerRecord>(context.l10n.deliveryOrdersPickDealer, _customers, (c) => c.label);
                if (picked != null) setState(() => _customer = picked);
              },
            ),
          ),
          for (final line in _lines)
            Card(
              child: ListTile(
                title: Text(line.$1.name),
                subtitle: Text(context.l10n.deliveryOrdersQty('${line.$2}')),
                trailing: IconButton(
                  icon: const Icon(Icons.close),
                  tooltip: context.l10n.deliveryOrdersRemoveLine,
                  onPressed: () => setState(() => _lines.remove(line)),
                ),
                onTap: () async {
                  final qty = await _askQty(line.$1.name, line.$2);
                  if (qty != null && qty > 0) {
                    setState(() => _lines[_lines.indexOf(line)] = (line.$1, qty));
                  }
                },
              ),
            ),
          OutlinedButton.icon(
            key: const ValueKey('do-add-line'),
            onPressed: _addLine,
            icon: const Icon(Icons.add),
            label: Text(context.l10n.deliveryOrdersAddLine),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(controller: _note, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: context.l10n.deliveryOrdersNote)),
          const SizedBox(height: AppSpacing.md),
          if (_error != null) _ErrorCard(_error!),
          if (_busy) const LinearProgressIndicator(),
          FilledButton(
            key: const ValueKey('do-submit'),
            onPressed: _busy ? null : () => _save(submit: true),
            child: Text(context.l10n.deliveryOrdersSubmit),
          ),
          const SizedBox(height: AppSpacing.xs),
          OutlinedButton(
            key: const ValueKey('do-keep-draft'),
            onPressed: _busy ? null : () => _save(submit: false),
            child: Text(context.l10n.deliveryOrdersKeepDraft),
          ),
        ],
      ),
    );
  }
}

/// একটা DO — মাথা, লাইন, আর যার হাতে যে কাজ।
class DeliveryOrderScreen extends StatefulWidget {
  const DeliveryOrderScreen({super.key, required this.id, this.api = const ServerDeliveryOrderApi()});

  final String id;
  final DeliveryOrderApi api;

  @override
  State<DeliveryOrderScreen> createState() => _DeliveryOrderScreenState();
}

class _DeliveryOrderScreenState extends State<DeliveryOrderScreen> {
  DeliveryOrder? _order;
  final Map<int, TextEditingController> _boxes = {};
  final _reason = TextEditingController();
  bool _busy = false;
  String? _error;
  String? _done;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _boxes.values) {
      c.dispose();
    }
    _reason.dispose();
    super.dispose();
  }

  void _take(DeliveryOrder order) {
    _order = order;
    // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ — DO, বা বিক্রয় আদেশ (মালিক, ১২ অক্টোবর ২০২৬)
    RecentPapers.note(kind: widget.api.forOrders ? 'order' : 'do', id: widget.id, number: order.no, party: order.customer);
    for (final line in order.lines) {
      (_boxes[line.id] ??= TextEditingController()).text = _qty(line.finalQty);
    }
  }

  Future<void> _run(Future<DeliveryOrder?> Function() work, String doneText) async {
    setState(() {
      _busy = true;
      _error = null;
      _done = null;
    });
    try {
      final next = await work();
      final fresh = next ?? await widget.api.show(widget.id);
      if (mounted) {
        setState(() {
          _take(fresh);
          _done = doneText;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.deliveryOrdersActFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final order = await widget.api.show(widget.id);
      if (mounted) setState(() => _take(order));
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.deliveryOrdersLoadFailed(_kind(widget.api))));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Map<int, int> _wanted() => {
        for (final e in _boxes.entries) e.key: int.tryParse(e.value.text.trim()) ?? -1,
      };

  @override
  Widget build(BuildContext context) {
    final order = _order;
    return Scaffold(
      appBar: AbosScreenBar(title: order?.no ?? _title(context, widget.api), module: context.l10n.deliveryOrdersModule, actions: [
        // ⭐ ছাপা — নরমাল বা থার্মাল (মালিক, ১৪ অক্টোবর ২০২৬)। ⓘ কেবল বিক্রয় আদেশ: সার্ভারের ছাপার দরজায় আলাদা
        // DO মডেলের কাগজ নেই (`sales_delivery_order` অর্ডারের মডেলই বাঁধে)।
        if (order != null && widget.api.forOrders)
          IconButton(
            key: const ValueKey('order-print'),
            tooltip: context.l10n.deliveryOrdersPrint,
            icon: const Icon(Icons.print_outlined),
            onPressed: () => DocumentActionsSheet.show(context,
                type: 'SalesOrder', id: widget.id, title: context.l10n.deliveryOrdersPrintTitle(order.no), fileStem: order.no),
          ),
      ]),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          // ⭐ তিন অবস্থা: প্রথম আনায় খসড়া সারি, আনা না গেলে "আবার চেষ্টা করুন"; কাগজ হাতে থাকলে সরু দাগ আর কাজের ভুল কার্ডে
          if (_busy && order == null) const LoadingSkeleton(rows: 3),
          if (_busy && order != null) const LinearProgressIndicator(),
          if (_error != null && order == null) ErrorState(key: const ValueKey('do-error'), message: _error!, onRetry: _busy ? null : _load),
          if (_error != null && order != null) _ErrorCard(_error!),
          // ⓘ সবুজ পটে নিজের গাঢ় সবুজ লেখা — থিমের লেখা অন্ধকার মোডে এই হালকা পটে হারাত (নকশার নিয়ম ০.৪.৩৮ §১)
          if (_done != null)
            Card(
              color: context.palette.successSurface,
              child: Padding(padding: const EdgeInsets.all(AppSpacing.md), child: Text(_done!, style: TextStyle(color: context.palette.success))),
            ),
          if (order != null) ...[
            // ⭐ মাথায় গ্রাহক "নাম · পয়েন্ট · শাখা", তার নিচে কাগজের নম্বর · তারিখ (মালিকের স্থায়ী নিয়ম, ১৯ অক্টোবর ২০২৬)
            CustomerLine(order.customer ?? context.l10n.deliveryOrdersNoCustomer,
                key: const ValueKey('do-customer-line'),
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: Theme.of(context).colorScheme.onSurface)),
            Text([order.no, if (order.date != null) order.date!].join(' · '),
                key: const ValueKey('do-number-line'), style: TextStyle(fontSize: 13, color: Theme.of(context).colorScheme.onSurfaceVariant)),
            const SizedBox(height: AppSpacing.xs),
            Text(context.l10n.deliveryOrdersStatus(order.statusLabel), key: const ValueKey('do-status')),
            Text(context.l10n.deliveryOrdersTotal(Money.taka(order.total))),
            // ⓘ বিক্রয় আদেশ বাকির সীমায় আটকে — লেখক দেখেন কেন থেমে আছে; টাকা এলে সার্ভার নিজেই আবার দেখে
            if (order.creditShort != null)
              Text(context.l10n.deliveryOrdersCreditShort(Money.taka(order.creditShort)),
                  key: const ValueKey('do-credit-short'), style: TextStyle(color: Theme.of(context).colorScheme.error)),
            if (order.chips.isNotEmpty) OrderChips(order.chips),
            // ⭐ মজুদ এখন — ধরা হয়নি: আছে, চাই, কম (ওয়েবের আদেশের পাতার একই উৎস)
            if (order.stockNow.isNotEmpty) _StockNowCard(order.stockNow),
            const SizedBox(height: AppSpacing.md),
            // ⭐ আংশিক অনুমোদন — অনুমোদনকারী চাওয়ার চেয়ে কম পরিমাণে অনুমোদন দিতে পারেন; এটা সিদ্ধান্তেরই অংশ, কাগজ বদলানো নয়
            // (মালিক, ২০ নভেম্বর ২০২৬ "২ক")। সার্ভার অপেক্ষার সময় `/approved-quantities` খোলা রাখে।
            if (order.awaitingMe) ...[
              Text(context.l10n.deliveryOrdersPartialApproval,
                  key: const ValueKey('do-partial-title'), style: const TextStyle(fontWeight: FontWeight.w700)),
              Text(context.l10n.deliveryOrdersPartialHint,
                  key: const ValueKey('do-partial-hint'),
                  style: TextStyle(fontSize: 13, color: Theme.of(context).colorScheme.onSurfaceVariant)),
              const SizedBox(height: AppSpacing.xs),
            ],
            for (final line in order.lines)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(line.product, style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(context.l10n.deliveryOrdersAsked(_qty(line.qty))),
                      if (order.awaitingMe)
                        TextField(
                          key: ValueKey('do-line-${line.id}'),
                          controller: _boxes[line.id],
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(labelText: context.l10n.deliveryOrdersPartialApproval),
                        )
                      else if (line.approvedQty != null)
                        Text(context.l10n.deliveryOrdersApprovedQty(_qty(line.approvedQty!))),
                      Text(Money.taka(line.lineTotal)),
                    ],
                  ),
                ),
              ),
            if (order.editable)
              FilledButton(
                key: const ValueKey('do-send'),
                onPressed: _busy ? null : () => _run(() => widget.api.submit(order.id), context.l10n.deliveryOrdersSubmitted(_kind(widget.api))),
                child: Text(context.l10n.deliveryOrdersSubmit),
              ),
            if (order.awaitingMe && order.approvalId != null) ...[
              OutlinedButton(
                key: const ValueKey('do-save-qty'),
                onPressed: _busy ? null : () => _run(() => widget.api.setQuantities(order.id, _wanted()), context.l10n.deliveryOrdersQtySaved),
                child: Text(context.l10n.deliveryOrdersSaveQty),
              ),
              const SizedBox(height: AppSpacing.xs),
              FilledButton(
                key: const ValueKey('do-approve'),
                onPressed: _busy
                    ? null
                    : () => _run(() async {
                          // ⓘ আগে ঘরের পরিমাণ রাখা, তারপর সই — যা দেখছেন তাতেই সই
                          await widget.api.setQuantities(order.id, _wanted());
                          await widget.api.approve(order.approvalId!);
                          return null;
                        }, context.l10n.deliveryOrdersApproved),
                child: Text(context.l10n.deliveryOrdersApprove),
              ),
              const SizedBox(height: AppSpacing.sm),
              TextField(
                key: const ValueKey('do-reason'),
                controller: _reason,
                decoration: InputDecoration(labelText: context.l10n.deliveryOrdersRejectReason),
              ),
              OutlinedButton(
                key: const ValueKey('do-reject'),
                style: OutlinedButton.styleFrom(foregroundColor: Theme.of(context).colorScheme.error),
                onPressed: _busy
                    ? null
                    : () {
                        if (_reason.text.trim().isEmpty) {
                          setState(() => _error = context.l10n.deliveryOrdersRejectNeedReason);
                          return;
                        }
                        _run(() async {
                          await widget.api.reject(order.approvalId!, _reason.text);
                          return null;
                        }, context.l10n.deliveryOrdersRejected(_kind(widget.api)));
                      },
                child: Text(context.l10n.deliveryOrdersReject),
              ),
            ],
          ],
        ],
      ),
    );
  }
}

/// পরিমাণের ছোট ঘর — নিজের controller নিজে রাখে, ডায়ালগ পুরো বন্ধ হলে তবেই ছাড়ে।
class _QtyDialog extends StatefulWidget {
  const _QtyDialog({required this.title, required this.initial});

  final String title;
  final int initial;

  @override
  State<_QtyDialog> createState() => _QtyDialogState();
}

class _QtyDialogState extends State<_QtyDialog> {
  late final _controller = TextEditingController(text: '${widget.initial}');

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(widget.title),
        content: TextField(
          key: const ValueKey('do-qty'),
          controller: _controller,
          autofocus: true,
          keyboardType: TextInputType.number,
          decoration: InputDecoration(labelText: context.l10n.deliveryOrdersDialogQty),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(context.l10n.deliveryOrdersDialogCancel)),
          FilledButton(
            onPressed: () => Navigator.pop(context, int.tryParse(_controller.text.trim())),
            child: Text(context.l10n.deliveryOrdersDialogOk),
          ),
        ],
      );
}

class _PickSheet<T> extends StatefulWidget {
  const _PickSheet({required this.title, required this.items, required this.label});

  final String title;
  final List<T> items;
  final String Function(T) label;

  @override
  State<_PickSheet<T>> createState() => _PickSheetState<T>();
}

class _PickSheetState<T> extends State<_PickSheet<T>> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final q = _query.trim().toLowerCase();
    final shown = q.isEmpty ? widget.items : widget.items.where((i) => i is CustomerRecord ? i.matches(q) : widget.label(i).toLowerCase().contains(q)).toList();
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.8,
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              key: const ValueKey('pick-search'),
              autofocus: false,
              decoration: InputDecoration(hintText: widget.title, prefixIcon: const Icon(Icons.search)),
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
          Expanded(
            child: ListView.builder(
              itemCount: shown.length,
              itemBuilder: (_, i) => ListTile(
                minTileHeight: 72,
                title: Text(widget.label(shown[i])),
                onTap: () => Navigator.of(context).pop(shown[i]),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

/// ওয়েবের চিপের মতো — ছোট গোল ব্যাজ, রং সার্ভারের নামে
class OrderChips extends StatelessWidget {
  const OrderChips(this.chips, {super.key});

  final List<OrderChip> chips;

  /// ⭐ অবস্থার রং জোড়ায় (পট, লেখা) — রঙিন জোড়া দুই মোডে একই; ধরন না চিনলে থিমের মলিন পট আর লেখা
  /// (নকশার নিয়ম ০.৪.৩৮ §১; a4, ১৯ অক্টোবর ২০২৬: context.palette — আলোতে হুবহু আগের রং)
  static (Color, Color) colours(String tone, AppPalette p) => switch (tone) {
        'success' => (p.successSurface, p.success),
        'warning' => (p.warningSurface, p.warning),
        'danger' => (p.dangerSurface, p.danger),
        'info' || 'pending' => (p.pendingSurface, p.pending),
        _ => (p.surfaceMuted, p.onSurfaceMuted),
      };

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
        child: Wrap(
          spacing: AppSpacing.xs,
          runSpacing: AppSpacing.xs,
          children: [
            for (final c in chips)
              Container(
                key: ValueKey('order-chip-${c.key}'),
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm, vertical: 2),
                decoration: BoxDecoration(color: colours(c.tone, context.palette).$1, borderRadius: BorderRadius.circular(12)),
                child: Text(c.label, style: TextStyle(fontSize: 12, color: colours(c.tone, context.palette).$2, fontWeight: FontWeight.w600)),
              ),
          ],
        ),
      );
}

class _StockNowCard extends StatelessWidget {
  const _StockNowCard(this.rows);

  final List<OrderStockNow> rows;

  @override
  Widget build(BuildContext context) => Card(
        key: const ValueKey('order-stock-now'),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(context.l10n.deliveryOrdersStockTitle, style: const TextStyle(fontWeight: FontWeight.w700)),
              Text(context.l10n.deliveryOrdersStockNote, style: TextStyle(color: context.palette.onSurfaceMuted)),
              for (final r in rows) ...[
                const SizedBox(height: AppSpacing.xs),
                Text(r.product, style: const TextStyle(fontWeight: FontWeight.w600)),
                Text(r.have != null
                    ? context.l10n.deliveryOrdersStockHaveWant(_qty(r.have!), _qty(r.want))
                    : context.l10n.deliveryOrdersStockWant(_qty(r.want))),
                if (!r.enough)
                  Text(r.short != null ? context.l10n.deliveryOrdersStockShort(_qty(r.short!)) : context.l10n.deliveryOrdersStockNotEnough,
                      style: TextStyle(color: Theme.of(context).colorScheme.error, fontWeight: FontWeight.w600)),
              ],
            ],
          ),
        ),
      );
}

class _ErrorCard extends StatelessWidget {
  const _ErrorCard(this.text);

  final String text;

  @override
  // ⭐ ভুলের পট আর লেখা জোড়া রং — লাল পটে নিজের লাল লেখা, দুই মোডেই পড়া যায় (নকশার নিয়ম ০.৪.৩৮ §১)
  Widget build(BuildContext context) => Card(
        color: context.palette.dangerSurface,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Text(text, style: TextStyle(color: context.palette.danger)),
        ),
      );
}
