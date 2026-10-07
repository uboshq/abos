import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/orders/order_api.dart';
import '../../core/records/money.dart';

/// The order screen's colours — the owner-approved samples of 1 October
/// 2026 (light only: the app has no dark theme yet).
class OrderPalette {
  static const page = Color(0xFFFBFCFF);
  static const ink = Color(0xFF1A1C20);
  static const muted = Color(0xFF44474F);
  static const row = Color(0xFFF1F5FE);
  static const field = Color(0xFFEEF0F7);
  static const brand = Color(0xFF3456B8);
  static const pill = Color(0xFFD8E2FF);
  static const onPill = Color(0xFF001A41);
  static const offer = Color(0xFFC4EED0);
  static const onOffer = Color(0xFF072711);
  static const free = Color(0xFFECFDF3);
  static const freeBorder = Color(0xFF6CE9A6);
  static const onFree = Color(0xFF054F31);
  static const inCart = Color(0xFF067647);
  static const badge = Color(0xFFF04438);
  static const owe = Color(0xFFFEF3F2);
  static const onOwe = Color(0xFF7A271A);
  static const waiting = Color(0xFFEFF4FF);
  static const warn = Color(0xFFFFFAEB);
  static const line = Color(0xFFEEF0F4);
  static const outline = Color(0xFFC4C6D0);
}

/// Western digits to Bangla ones — the order screen speaks in ০–৯, as the
/// owner's samples do.
String bn(Object value) {
  const digits = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
  return value.toString().replaceAllMapped(RegExp('[0-9]'), (m) => digits[int.parse(m[0]!)]);
}

/// A quantity in Bangla digits with thousands commas: 1500 → ১,৫০০.
String bnQty(num value) => bn(Money.plain(value));

/// Taka in Bangla digits: ৳২,৮২০.
String bnTaka(num value) => '৳${bnQty(value)}';

/// The offer sentence the server sends, in a green chip.
class OfferChip extends StatelessWidget {
  const OfferChip(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 1),
        decoration: BoxDecoration(color: OrderPalette.offer, borderRadius: BorderRadius.circular(8)),
        child: Text(text,
            style: const TextStyle(
                color: OrderPalette.onOffer, fontWeight: FontWeight.w700, fontSize: 13)),
      );
}

Widget _grabber() => Container(
      width: 40,
      height: 5,
      margin: const EdgeInsets.only(bottom: 4),
      decoration: BoxDecoration(color: OrderPalette.outline, borderRadius: BorderRadius.circular(3)),
    );

// ── Screen 2: the big keypad ─────────────────────────────────────────────

/// A big numeric keypad for one line — for 200, 500, 1,500 that nobody
/// should reach with a + button. Resolves to the new total in pieces, or
/// null when closed without "ঝুড়িতে যোগ".
///
/// <p>With a pack ladder there are two boxes, কার্টন and পিস, and either can
/// be typed into; the total is cartons × pack + pieces.
Future<int?> showQtyKeypad(
  BuildContext context, {
  required String productName,
  required int current,
  double? rate,
  String? offer,
  String? cartonName,
  int? cartonFactor,
  int? lastQty,
}) =>
    showModalBottomSheet<int>(
      context: context,
      isScrollControlled: true,
      backgroundColor: OrderPalette.page,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      builder: (context) => QtyKeypadSheet(
        productName: productName,
        current: current,
        rate: rate,
        offer: offer,
        cartonName: cartonName,
        cartonFactor: cartonFactor,
        lastQty: lastQty,
      ),
    );

class QtyKeypadSheet extends StatefulWidget {
  const QtyKeypadSheet({
    super.key,
    required this.productName,
    required this.current,
    this.rate,
    this.offer,
    this.cartonName,
    this.cartonFactor,
    this.lastQty,
  });

  final String productName;
  final int current;
  final double? rate;
  final String? offer;
  final String? cartonName;
  final int? cartonFactor;

  /// What this phone last ordered of it for this shop — "আগের মতো".
  final int? lastQty;

  @override
  State<QtyKeypadSheet> createState() => _QtyKeypadSheetState();
}

class _QtyKeypadSheetState extends State<QtyKeypadSheet> {
  static const _maxDigits = 6;

  late int _cartons;
  late int _pieces;

  /// Which box the keys type into: true = কার্টন.
  late bool _onCartons;

  /// A box just selected is replaced by the first key, then typed onto.
  bool _fresh = true;

  int? get _factor => (widget.cartonFactor ?? 0) > 1 ? widget.cartonFactor : null;

  int get _total => (_factor ?? 0) * _cartons + _pieces;

  @override
  void initState() {
    super.initState();
    final factor = _factor;
    if (factor == null) {
      _cartons = 0;
      _pieces = widget.current;
      _onCartons = false;
    } else {
      _cartons = widget.current ~/ factor;
      _pieces = widget.current % factor;
      _onCartons = true;
    }
  }

  void _select(bool cartons) => setState(() {
        _onCartons = cartons;
        _fresh = true;
      });

  void _digit(int d) => setState(() {
        final now = _onCartons ? _cartons : _pieces;
        final text = _fresh ? '$d' : '$now$d';
        if (text.replaceFirst(RegExp('^0+'), '').length > _maxDigits) return;
        final value = int.parse(text);
        if (_onCartons) {
          _cartons = value;
        } else {
          _pieces = value;
        }
        _fresh = false;
      });

  void _back() => setState(() {
        final now = '${_onCartons ? _cartons : _pieces}';
        final value = now.length <= 1 ? 0 : int.parse(now.substring(0, now.length - 1));
        if (_onCartons) {
          _cartons = value;
        } else {
          _pieces = value;
        }
        _fresh = false;
      });

  void _addCartons(int n) => setState(() {
        _cartons += n;
        _fresh = true;
      });

  void _setTotal(int total) => setState(() {
        final factor = _factor;
        if (factor == null) {
          _pieces = total;
        } else {
          _cartons = total ~/ factor;
          _pieces = total % factor;
        }
        _fresh = true;
      });

  Widget _box(String caption, String value, {bool? selects, Key? key}) {
    final selected = selects != null && selects == _onCartons;
    final box = Container(
      height: 56,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: selects == null ? Colors.white : (selected ? OrderPalette.pill : OrderPalette.field),
        borderRadius: BorderRadius.circular(16),
        border: selects == null
            ? Border.all(color: OrderPalette.outline)
            : selected
                ? Border.all(color: OrderPalette.brand, width: 2)
                : null,
      ),
      child: Text(value,
          style: TextStyle(
              fontSize: selects == null ? 22 : 26,
              fontWeight: FontWeight.w800,
              color: selected ? OrderPalette.onPill : OrderPalette.ink)),
    );
    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(caption, style: const TextStyle(fontSize: 13, color: OrderPalette.muted)),
          const SizedBox(height: 4),
          selects == null
              ? box
              : Semantics(
                  button: true,
                  selected: selected,
                  child: InkWell(
                    key: key,
                    borderRadius: BorderRadius.circular(16),
                    onTap: () => _select(selects),
                    child: box,
                  ),
                ),
        ],
      ),
    );
  }

  Widget _quick(String label, VoidCallback onTap) => Padding(
        padding: const EdgeInsets.only(right: 8),
        child: TextButton(
          style: TextButton.styleFrom(
            backgroundColor: OrderPalette.field,
            foregroundColor: OrderPalette.ink,
            minimumSize: const Size(0, 40),
            padding: const EdgeInsets.symmetric(horizontal: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
          ),
          onPressed: onTap,
          child: Text(label),
        ),
      );

  Widget _key(String label, VoidCallback onTap, {bool primary = false, String? semantics, Key? key}) =>
      SizedBox(
        height: 54,
        child: TextButton(
          key: key,
          style: TextButton.styleFrom(
            backgroundColor: primary ? OrderPalette.brand : OrderPalette.field,
            foregroundColor: primary ? Colors.white : OrderPalette.ink,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            padding: EdgeInsets.zero,
            textStyle: TextStyle(fontSize: primary ? 16 : 24, fontWeight: FontWeight.w700),
          ),
          onPressed: onTap,
          child: Semantics(label: semantics, child: Text(label, textAlign: TextAlign.center)),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final factor = _factor;
    final rate = widget.rate;

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 12, 16, 20 + MediaQuery.of(context).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(child: _grabber()),
              Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Expanded(
                    child: Text(widget.productName,
                        style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700)),
                  ),
                  if (factor != null)
                    Text('১ ${widget.cartonName ?? 'কার্টন'} = ${bn(factor)} পিস',
                        style: const TextStyle(color: OrderPalette.muted)),
                ],
              ),
              if (widget.offer != null) ...[
                const SizedBox(height: 8),
                Align(alignment: Alignment.centerLeft, child: OfferChip(widget.offer!)),
              ],
              const SizedBox(height: 12),
              Row(
                children: [
                  if (factor != null) ...[
                    _box(widget.cartonName ?? 'কার্টন', bn(_cartons),
                        selects: true, key: const ValueKey('keypad-cartons')),
                    const SizedBox(width: 10),
                  ],
                  _box('পিস', bn(_pieces), selects: false, key: const ValueKey('keypad-pieces')),
                  if (factor != null) ...[
                    const SizedBox(width: 10),
                    _box('মোট পিস', bnQty(_total)),
                  ],
                ],
              ),
              const SizedBox(height: 12),
              Wrap(
                runSpacing: 8,
                children: [
                  if (factor != null) ...[
                    _quick('+১ ${widget.cartonName ?? 'কার্টন'}', () => _addCartons(1)),
                    _quick('+১০ ${widget.cartonName ?? 'কার্টন'}', () => _addCartons(10)),
                  ],
                  if ((widget.lastQty ?? 0) > 0)
                    _quick('আগের মতো (${bnQty(widget.lastQty!)})', () => _setTotal(widget.lastQty!)),
                ],
              ),
              const SizedBox(height: 12),
              GridView.count(
                crossAxisCount: 3,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 8,
                crossAxisSpacing: 8,
                childAspectRatio: 2.1,
                children: [
                  for (var d = 1; d <= 9; d++)
                    _key(bn(d), () => _digit(d), key: ValueKey('keypad-$d')),
                  _key('⌫', _back, semantics: 'মুছুন', key: const ValueKey('keypad-back')),
                  _key('০', () => _digit(0), key: const ValueKey('keypad-0')),
                  _key('ঝুড়িতে যোগ', () => Navigator.of(context).pop(_total),
                      primary: true, key: const ValueKey('keypad-add')),
                ],
              ),
              const SizedBox(height: 10),
              Text(
                [
                  if (rate != null) 'এই লাইনে ${bnTaka(rate * _total)}',
                  if (factor != null) 'কার্টন বা পিস যেটায় খুশি লিখুন',
                ].join(' · '),
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 14, color: OrderPalette.muted),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Screen 3: the filter sheet ───────────────────────────────────────────

enum OrderSort { catalogue, name, price }

/// What the list is narrowed to. Only filters this phone has data for:
/// brand, category, "নতুন পণ্য" and "বেশি বিক্রি" are not in the product
/// payload yet, so they are not offered — a filter that cannot filter is a
/// lie on a button.
class OrderFilter {
  const OrderFilter({this.offerOnly = false, this.boughtBefore = false, this.sort = OrderSort.catalogue});

  final bool offerOnly;
  final bool boughtBefore;
  final OrderSort sort;

  int get activeCount => (offerOnly ? 1 : 0) + (boughtBefore ? 1 : 0) + (sort == OrderSort.catalogue ? 0 : 1);

  OrderFilter copyWith({bool? offerOnly, bool? boughtBefore, OrderSort? sort}) => OrderFilter(
        offerOnly: offerOnly ?? this.offerOnly,
        boughtBefore: boughtBefore ?? this.boughtBefore,
        sort: sort ?? this.sort,
      );

  static String sortLabel(OrderSort sort) => switch (sort) {
        OrderSort.catalogue => 'তালিকার ক্রমে',
        OrderSort.name => 'নাম ধরে',
        OrderSort.price => 'দাম ধরে',
      };
}

Future<OrderFilter?> showOrderFilter(
  BuildContext context, {
  required OrderFilter current,
  required int Function(OrderFilter) countFor,
}) =>
    showModalBottomSheet<OrderFilter>(
      context: context,
      isScrollControlled: true,
      backgroundColor: OrderPalette.page,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      builder: (context) => OrderFilterSheet(current: current, countFor: countFor),
    );

class OrderFilterSheet extends StatefulWidget {
  const OrderFilterSheet({super.key, required this.current, required this.countFor});

  final OrderFilter current;
  final int Function(OrderFilter) countFor;

  @override
  State<OrderFilterSheet> createState() => _OrderFilterSheetState();
}

class _OrderFilterSheetState extends State<OrderFilterSheet> {
  late OrderFilter _filter = widget.current;

  Widget _choice(String label, bool on, VoidCallback onTap) => Padding(
        padding: const EdgeInsets.only(right: 8, bottom: 8),
        child: TextButton(
          style: TextButton.styleFrom(
            backgroundColor: on ? OrderPalette.pill : Colors.transparent,
            foregroundColor: on ? OrderPalette.onPill : OrderPalette.ink,
            minimumSize: const Size(0, 40),
            padding: const EdgeInsets.symmetric(horizontal: 14),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
              side: BorderSide(color: on ? OrderPalette.pill : OrderPalette.outline),
            ),
            textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
          ),
          onPressed: onTap,
          child: Text(label),
        ),
      );

  Widget _heading(String text) => Padding(
        padding: const EdgeInsets.only(top: 6, bottom: 6),
        child: Text(text,
            style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: OrderPalette.muted)),
      );

  @override
  Widget build(BuildContext context) {
    final count = widget.countFor(_filter);
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 22),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(child: _grabber()),
            Row(
              children: [
                const Expanded(
                    child: Text('ফিল্টার', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
                TextButton(
                  onPressed: () => setState(() => _filter = const OrderFilter()),
                  child: const Text('সব মুছুন',
                      style: TextStyle(color: OrderPalette.brand, fontWeight: FontWeight.w700)),
                ),
              ],
            ),
            _heading('দেখান'),
            Wrap(children: [
              _choice('অফার আছে', _filter.offerOnly,
                  () => setState(() => _filter = _filter.copyWith(offerOnly: !_filter.offerOnly))),
              _choice('এই দোকান আগে নিয়েছে', _filter.boughtBefore,
                  () => setState(() => _filter = _filter.copyWith(boughtBefore: !_filter.boughtBefore))),
            ]),
            _heading('সাজান'),
            Wrap(children: [
              for (final sort in OrderSort.values)
                _choice(OrderFilter.sortLabel(sort), _filter.sort == sort,
                    () => setState(() => _filter = _filter.copyWith(sort: sort))),
            ]),
            const SizedBox(height: 10),
            SizedBox(
              height: 56,
              child: FilledButton(
                key: const ValueKey('filter-apply'),
                style: FilledButton.styleFrom(
                  backgroundColor: OrderPalette.brand,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                  textStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17),
                ),
                onPressed: () => Navigator.of(context).pop(_filter),
                child: Text('${bn(count)}টা পণ্য দেখান'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Screen 4: before sending ─────────────────────────────────────────────

class ConfirmLine {
  const ConfirmLine({required this.name, required this.qty, required this.free, required this.taka});

  final String name;
  final int qty;
  final double free;
  final double taka;
}

enum ConfirmChoice { send, back }

/// "পাঠানোর আগে দেখে নিন". It never blocks: ঠিক আছে, পাঠান is always live,
/// whatever the standing says and whether or not it arrived.
Future<ConfirmChoice?> showOrderConfirm(
  BuildContext context, {
  required List<ConfirmLine> lines,
  required Future<CustomerStanding> standing,
  TextEditingController? note,
}) =>
    showModalBottomSheet<ConfirmChoice>(
      context: context,
      isScrollControlled: true,
      backgroundColor: OrderPalette.page,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      builder: (context) => OrderConfirmSheet(lines: lines, standing: standing, note: note),
    );

class OrderConfirmSheet extends StatelessWidget {
  const OrderConfirmSheet({super.key, required this.lines, required this.standing, this.note});

  final List<ConfirmLine> lines;
  final Future<CustomerStanding> standing;
  final TextEditingController? note;

  static const _cols = [FlexColumnWidth(), FixedColumnWidth(46), FixedColumnWidth(40), FixedColumnWidth(76)];

  TableRow _row(List<Widget> cells, {bool last = false}) => TableRow(
        decoration: BoxDecoration(
            border: last ? null : const Border(bottom: BorderSide(color: OrderPalette.line))),
        children: [for (final cell in cells) Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: cell)],
      );

  Widget _tile(String caption, double amount, Color fill, Color ink) => Expanded(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(color: fill, borderRadius: BorderRadius.circular(16)),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(caption, style: TextStyle(fontSize: 12, color: ink)),
              Text(bnTaka(amount), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: ink)),
            ],
          ),
        ),
      );

  Widget _standing(AsyncSnapshot<CustomerStanding> snap) {
    if (snap.connectionState != ConnectionState.done) {
      return const Row(children: [
        SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
        SizedBox(width: 10),
        Text('হিসাব আনা হচ্ছে…', style: TextStyle(color: OrderPalette.muted)),
      ]);
    }
    if (snap.hasError || snap.data == null) {
      final offline = snap.error != null && isNetworkError(snap.error!);
      return Container(
        key: const ValueKey('confirm-no-standing'),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(color: OrderPalette.field, borderRadius: BorderRadius.circular(14)),
        child: Text(
          offline ? 'নেট নেই — হিসাব পরে দেখা যাবে' : 'হিসাব এখন আনা গেল না — অর্ডার তবু যাবে, হিসাব পরে দেখা যাবে',
          style: const TextStyle(fontSize: 14, color: OrderPalette.muted),
        ),
      );
    }
    final s = snap.data!;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(children: [
          _tile('জমা আছে', s.advance, OrderPalette.free, OrderPalette.onFree),
          const SizedBox(width: 8),
          _tile('অনুমোদনের অপেক্ষায়', s.pendingClaims, OrderPalette.waiting, const Color(0xFF1D2939)),
          const SizedBox(width: 8),
          _tile('দিতে হবে', s.toPay, OrderPalette.owe, OrderPalette.onOwe),
        ]),
        if (s.overLimit) ...[
          const SizedBox(height: 12),
          Container(
            key: const ValueKey('confirm-over-limit'),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            decoration: BoxDecoration(color: OrderPalette.warn, borderRadius: BorderRadius.circular(14)),
            child: Text(
              'বাকির সীমা পার হচ্ছে — অর্ডার যাবে, কিন্তু ${bnTaka(s.toPay)} জমা না হলে ডেলিভারি হবে না। '
              'এই সীমা কেউ পার করাতে পারেন না, মালিকও না।',
              style: const TextStyle(fontSize: 14, color: OrderPalette.onOwe),
            ),
          ),
        ],
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final qty = lines.fold<int>(0, (sum, l) => sum + l.qty);
    final free = lines.fold<double>(0, (sum, l) => sum + l.free);
    final taka = lines.fold<double>(0, (sum, l) => sum + l.taka);
    const head = TextStyle(fontSize: 12, color: Color(0xFF667085));
    const bold = TextStyle(fontWeight: FontWeight.w800);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 12, 16, 22 + MediaQuery.of(context).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(child: _grabber()),
              const Text('পাঠানোর আগে দেখে নিন', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Table(
                columnWidths: {for (var i = 0; i < _cols.length; i++) i: _cols[i]},
                defaultVerticalAlignment: TableCellVerticalAlignment.middle,
                children: [
                  _row(const [
                    Text('পণ্য', style: head),
                    Text('বিক্রি', style: head, textAlign: TextAlign.center),
                    Text('ফ্রি', style: head, textAlign: TextAlign.center),
                    Text('টাকা', style: head, textAlign: TextAlign.right),
                  ]),
                  for (final l in lines)
                    _row([
                      Text(l.name, style: const TextStyle(fontSize: 14)),
                      Text(bnQty(l.qty), textAlign: TextAlign.center),
                      Text(bnQty(l.free),
                          textAlign: TextAlign.center,
                          style: l.free > 0
                              ? const TextStyle(fontWeight: FontWeight.w800, color: OrderPalette.inCart)
                              : const TextStyle(color: Color(0xFF98A2B3))),
                      Text(bnQty(l.taka), textAlign: TextAlign.right),
                    ]),
                  _row([
                    const Text('মোট', style: bold),
                    Text(bnQty(qty), style: bold, textAlign: TextAlign.center),
                    Text(bnQty(free),
                        style: bold.copyWith(color: OrderPalette.inCart), textAlign: TextAlign.center),
                    Text(bnQty(taka), key: const ValueKey('confirm-total'), style: bold, textAlign: TextAlign.right),
                  ], last: true),
                ],
              ),
              const SizedBox(height: 12),
              FutureBuilder<CustomerStanding>(future: standing, builder: (context, snap) => _standing(snap)),
              if (note != null) ...[
                const SizedBox(height: 12),
                TextField(
                  controller: note,
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: 'মন্তব্য (ঐচ্ছিক)',
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                  ),
                ),
              ],
              const SizedBox(height: 12),
              SizedBox(
                height: 58,
                child: FilledButton(
                  key: const ValueKey('confirm-send'),
                  style: FilledButton.styleFrom(
                    backgroundColor: OrderPalette.brand,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                    textStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
                  ),
                  onPressed: () => Navigator.of(context).pop(ConfirmChoice.send),
                  child: const Text('ঠিক আছে, পাঠান'),
                ),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    flex: 10,
                    child: SizedBox(
                      height: 48,
                      child: OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: OrderPalette.ink,
                          side: const BorderSide(color: OrderPalette.outline),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                          textStyle: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        onPressed: () => Navigator.of(context).pop(ConfirmChoice.back),
                        child: const Text('ফিরে যান'),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 13,
                    child: SizedBox(
                      height: 48,
                      child: TextButton(
                        style: TextButton.styleFrom(
                          backgroundColor: OrderPalette.field,
                          foregroundColor: OrderPalette.brand,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                          textStyle: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        // The bank-slip feature is the next release's work.
                        onPressed: () => showDialog<void>(
                          context: context,
                          builder: (context) => AlertDialog(
                            content: const Text('শীঘ্রই আসছে'),
                            actions: [
                              TextButton(
                                onPressed: () => Navigator.of(context).pop(),
                                child: const Text('ঠিক আছে'),
                              ),
                            ],
                          ),
                        ),
                        child: const Text('ব্যাংক স্লিপ পাঠান'),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
