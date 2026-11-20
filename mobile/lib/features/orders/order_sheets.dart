import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/orders/order_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/look_widgets.dart';
import '../../core/widgets/customer_line.dart';

/// The order screen's own tinted pairs — the owner-approved samples of 1 October 2026. Each background travels with its
/// own ink, so the pair reads the same in light and dark.
///
/// <p>⭐ পট, লেখা, রেখা আর বোতামের রং এখানে নেই — সেগুলো [OrderInk] দিয়ে থিম থেকে আসে (নকশার নিয়ম ০.৪.৩৮ §১, a4,
/// ১৯ অক্টোবর ২০২৬)। ⛔ আগে `page`/`ink`/`muted`/`row`/`field`/`brand`/`pill`/`line`/`outline` এখানে বাঁধা ছিল — অন্ধকার
/// মোডে কালো পটে কালো লেখা হত।
class OrderPalette {
  static const offer = Color(0xFFC4EED0);
  static const onOffer = Color(0xFF072711);
  static const free = Color(0xFFECFDF3);
  static const freeBorder = Color(0xFF6CE9A6);
  static const onFree = Color(0xFF054F31);
  static const inCart = Color(0xFF067647);
  static const owe = Color(0xFFFEF3F2);
  static const onOwe = Color(0xFF7A271A);
  static const waiting = Color(0xFFEFF4FF);
  static const onWaiting = Color(0xFF1D2939);
  static const warn = Color(0xFFFFFAEB);
}

/// ⭐ অর্ডারের পর্দার পট, লেখা আর রেখা — আলোতে মালিকের ১ অক্টোবরের নমুনার রং হুবহু (মালিকের পর্দা এক বিন্দুও বদলায়
/// না), অন্ধকারে থিমের `colorScheme` থেকে (পট surface, কার্ড surfaceContainerLow, লেখা onSurface, মলিন
/// onSurfaceVariant, রেখা outlineVariant, বোতাম primary, ভুল error) — নকশার নিয়ম ০.৪.৩৮ §১, a4-এর `context.palette`-এর
/// একই ধারা।
class OrderInk {
  OrderInk.of(BuildContext context)
      : cs = Theme.of(context).colorScheme,
        _scheme = context.look != AppLook.abos,
        _glass = context.look == AppLook.glass;

  final ColorScheme cs;

  /// ⭐ নতুন দুই রূপে (০.৪.৬০) রং থিমের `colorScheme` থেকে — Liquid Glass-এ কাচ, Material 3-এ বেগুনি সুর; ABOS আগের মতো
  final bool _scheme;

  /// Liquid Glass — নিচের মোটের পট্টি ঘন কাচ, সাদা লেখা (উল্টো রঙের সাদা পট্টি কাচের উপর বেমানান)
  final bool _glass;

  bool get _dark => cs.brightness == Brightness.dark;

  Color _pick(int light, Color dark) => _dark || _scheme ? dark : Color(light);

  Color get page => _pick(0xFFFBFCFF, cs.surface);
  Color get ink => _pick(0xFF1A1C20, cs.onSurface);
  Color get muted => _pick(0xFF44474F, cs.onSurfaceVariant);
  Color get row => _pick(0xFFF1F5FE, cs.surfaceContainerLow);
  Color get field => _pick(0xFFEEF0F7, cs.surfaceContainerHigh);
  Color get brand => _pick(0xFF3456B8, cs.primary);
  Color get onBrand => _pick(0xFFFFFFFF, cs.onPrimary);
  Color get pill => _pick(0xFFD8E2FF, cs.primaryContainer);
  Color get onPill => _pick(0xFF001A41, cs.onPrimaryContainer);
  Color get line => _pick(0xFFEEF0F4, cs.outlineVariant);
  Color get outline => _pick(0xFFC4C6D0, cs.outline);
  Color get badge => _pick(0xFFF04438, cs.error);
  Color get onBadge => _pick(0xFFFFFFFF, cs.onError);

  /// ফাঁকা ঘর (ফ্রি নেই, − আর + বোতামের গোল) — আলোতে সাদা
  Color get blank => _pick(0xFFFFFFFF, cs.surface);
  Color get blankBorder => _pick(0xFFD0D5DD, cs.outline);
  Color get blankInk => _pick(0xFF98A2B3, cs.onSurfaceVariant);
  Color get head => _pick(0xFF667085, cs.onSurfaceVariant);

  /// নিচের মোটের পট্টি — আলোতে গাঢ় পট্টিতে সাদা লেখা, অন্ধকারে থিমের উল্টো রং
  Color get bar => _glass ? const Color(0xE0161C33) : _pick(0xFF1A1C20, cs.inverseSurface);
  Color get onBar => _glass ? const Color(0xFFFFFFFF) : _pick(0xFFFFFFFF, cs.onInverseSurface);
  Color get onBarSoft => _glass ? const Color(0xD9FFFFFF) : _pick(0xB3FFFFFF, cs.onInverseSurface.withValues(alpha: 0.7));
  Color get barDisabled => _glass ? const Color(0x3DFFFFFF) : _pick(0xFF4A4D55, cs.onInverseSurface.withValues(alpha: 0.24));

  /// "✓ ঝুড়িতে আছে" — সবুজ; অন্ধকার পটে গাঢ় সবুজ মিলিয়ে যায়, তাই সেখানে হালকা সবুজ
  Color get inCart => _dark ? OrderPalette.freeBorder : OrderPalette.inCart;
}

/// ⭐ পরিমাণ — [Money.plain]: ইংরেজি অঙ্ক, হাজারের কমা (1500 → 1,500), দুই ভাষাতেই। ⛔ আগে এই পর্দায় বাংলা অঙ্ক (১,৫০০) —
/// মালিকের স্থায়ী নিয়ম: অঙ্ক সবখানে ইংরেজি, কেবল মাসের নাম বাংলা (a4, 0.4.44)।
String qtyText(num value) => Money.plain(value);

/// ⭐ টাকা — [Money.taka]: ইংরেজি অঙ্ক, লাখের কমা, দুই দশমিক (৳2,820.00) — সবখানে এক রূপ (নকশার নিয়ম ০.৪.৩৮ §৩,
/// মালিকের সিদ্ধান্ত খ)। ⛔ আগে এই পর্দায় বাংলা অঙ্কে (৳২,৮২০.০০) — বাকি অ্যাপের সাথে মিলত না।
String bnTaka(num value) => Money.taka(value);

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

Widget _grabber(BuildContext context) => Container(
      width: 40,
      height: 5,
      margin: const EdgeInsets.only(bottom: 4),
      decoration: BoxDecoration(color: OrderInk.of(context).outline, borderRadius: BorderRadius.circular(3)),
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
      backgroundColor: OrderInk.of(context).page,
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

  /// কার্টনের নাম — পণ্যের নিজের, না থাকলে এখনকার ভাষার "কার্টন"
  String get _carton => widget.cartonName ?? context.l10n.ordersKeypadCarton;

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
    final o = OrderInk.of(context);
    final selected = selects != null && selects == _onCartons;
    // ⓘ উচ্চতা বাঁধা নয়, কেবল অন্তত ৫৬ — বড় অক্ষরে (২০০%) অঙ্ক কাটা পড়ে না (নকশার নিয়ম ০.৪.৩৮ §২)
    final box = Container(
      constraints: const BoxConstraints(minHeight: 56),
      padding: const EdgeInsets.symmetric(vertical: 4),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: selects == null ? o.blank : (selected ? o.pill : o.field),
        borderRadius: BorderRadius.circular(16),
        border: selects == null
            ? Border.all(color: o.outline)
            : selected
                ? Border.all(color: o.brand, width: 2)
                : null,
      ),
      child: Text(value,
          style: TextStyle(
              fontSize: selects == null ? 22 : 26,
              fontWeight: FontWeight.w800,
              color: selected ? o.onPill : o.ink)),
    );
    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(caption, style: TextStyle(fontSize: 13, color: o.muted)),
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

  // ⓘ চাপার জায়গা অন্তত ৪৮dp (নকশার নিয়ম ০.৪.৩৮ §২) — আগে ৪০
  Widget _quick(String label, VoidCallback onTap) => Padding(
        padding: const EdgeInsets.only(right: 8),
        child: TextButton(
          style: TextButton.styleFrom(
            backgroundColor: OrderInk.of(context).field,
            foregroundColor: OrderInk.of(context).ink,
            minimumSize: const Size(48, 48),
            padding: const EdgeInsets.symmetric(horizontal: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
          ),
          onPressed: onTap,
          child: Text(label),
        ),
      );

  // ⓘ বোতাম অন্তত ৫৪ উঁচু, কিন্তু বাঁধা নয় — বড় অক্ষরে নিজে বাড়ে (নকশার নিয়ম ০.৪.৩৮ §২)
  Widget _key(String label, VoidCallback onTap, {bool primary = false, String? semantics, Key? key}) {
    final o = OrderInk.of(context);
    return TextButton(
      key: key,
      style: TextButton.styleFrom(
        backgroundColor: primary ? o.brand : o.field,
        foregroundColor: primary ? o.onBrand : o.ink,
        minimumSize: const Size(48, 54),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        padding: const EdgeInsets.symmetric(vertical: 4),
        textStyle: TextStyle(fontSize: primary ? 16 : 24, fontWeight: FontWeight.w700),
      ),
      onPressed: onTap,
      child: Semantics(label: semantics, child: Text(label, textAlign: TextAlign.center)),
    );
  }

  /// তিনটা বোতামের এক সারি — সারির উচ্চতা সবচেয়ে উঁচু বোতামের মাপে
  Widget _keyRow(List<Widget> keys) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (var i = 0; i < keys.length; i++) ...[
                if (i > 0) const SizedBox(width: 8),
                Expanded(child: keys[i]),
              ],
            ],
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final factor = _factor;
    final rate = widget.rate;
    final o = OrderInk.of(context);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 12, 16, 20 + MediaQuery.of(context).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(child: _grabber(context)),
              Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Expanded(
                    child: Text(widget.productName,
                        style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700)),
                  ),
                  // ⓘ Flexible — বড় অক্ষরে (২০০%) সারি ছাপিয়ে যেত (নকশার নিয়ম ০.৪.৩৮ §২)
                  if (factor != null)
                    Flexible(
                      child: Text(context.l10n.ordersKeypadCartonFactor('1', _carton, '$factor'),
                          textAlign: TextAlign.end, style: TextStyle(color: o.muted)),
                    ),
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
                    _box(_carton, '$_cartons',
                        selects: true, key: const ValueKey('keypad-cartons')),
                    const SizedBox(width: 10),
                  ],
                  _box(context.l10n.ordersKeypadPieces, '$_pieces', selects: false, key: const ValueKey('keypad-pieces')),
                  if (factor != null) ...[
                    const SizedBox(width: 10),
                    _box(context.l10n.ordersKeypadTotalPieces, qtyText(_total)),
                  ],
                ],
              ),
              const SizedBox(height: 12),
              Wrap(
                runSpacing: 8,
                children: [
                  if (factor != null) ...[
                    _quick('+1 $_carton', () => _addCartons(1)),
                    _quick('+10 $_carton', () => _addCartons(10)),
                  ],
                  if ((widget.lastQty ?? 0) > 0)
                    _quick(context.l10n.ordersKeypadLikeLast(qtyText(widget.lastQty!)), () => _setTotal(widget.lastQty!)),
                ],
              ),
              const SizedBox(height: 12),
              // ⭐ ৩×৪ বোতাম — GridView-এর বাঁধা অনুপাত নয়, সারি ধরে; বড় অক্ষরে সারি উঁচু হয় (নকশার নিয়ম ০.৪.৩৮ §২)
              for (var r = 0; r < 3; r++)
                _keyRow([
                  for (var d = r * 3 + 1; d <= r * 3 + 3; d++)
                    _key('$d', () => _digit(d), key: ValueKey('keypad-$d')),
                ]),
              _keyRow([
                _key('⌫', _back, semantics: context.l10n.ordersKeypadBackspace, key: const ValueKey('keypad-back')),
                _key('0', () => _digit(0), key: const ValueKey('keypad-0')),
                _key(context.l10n.ordersKeypadAddToCart, () => Navigator.of(context).pop(_total),
                    primary: true, key: const ValueKey('keypad-add')),
              ]),
              const SizedBox(height: 2),
              Text(
                [
                  if (rate != null) context.l10n.ordersKeypadLineAmount(bnTaka(rate * _total)),
                  if (factor != null) context.l10n.ordersKeypadEitherUnit,
                ].join(' · '),
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 14, color: o.muted),
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
        OrderSort.catalogue => AppLocale.l10n.ordersSortCatalogue,
        OrderSort.name => AppLocale.l10n.ordersSortName,
        OrderSort.price => AppLocale.l10n.ordersSortPrice,
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
      backgroundColor: OrderInk.of(context).page,
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

  // ⓘ চিপ অন্তত ৪৮dp (নকশার নিয়ম ০.৪.৩৮ §২) — আগে ৪০
  Widget _choice(String label, bool on, VoidCallback onTap) {
    final o = OrderInk.of(context);
    return Padding(
      padding: const EdgeInsets.only(right: 8, bottom: 8),
      child: TextButton(
        style: TextButton.styleFrom(
          backgroundColor: on ? o.pill : null,
          foregroundColor: on ? o.onPill : o.ink,
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 14),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: BorderSide(color: on ? o.pill : o.outline),
          ),
          textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
        ),
        onPressed: onTap,
        child: Text(label),
      ),
    );
  }

  Widget _heading(String text) => Padding(
        padding: const EdgeInsets.only(top: 6, bottom: 6),
        child: Text(text,
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: OrderInk.of(context).muted)),
      );

  @override
  Widget build(BuildContext context) {
    final count = widget.countFor(_filter);
    final o = OrderInk.of(context);
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 22),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(child: _grabber(context)),
            Row(
              children: [
                Expanded(
                    child: Text(context.l10n.ordersFilter, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
                TextButton(
                  onPressed: () => setState(() => _filter = const OrderFilter()),
                  child: Text(context.l10n.ordersFilterClearAll,
                      style: TextStyle(color: o.brand, fontWeight: FontWeight.w700)),
                ),
              ],
            ),
            _heading(context.l10n.ordersFilterShowHeading),
            Wrap(children: [
              _choice(context.l10n.ordersFilterOffer, _filter.offerOnly,
                  () => setState(() => _filter = _filter.copyWith(offerOnly: !_filter.offerOnly))),
              _choice(context.l10n.ordersFilterBoughtBefore, _filter.boughtBefore,
                  () => setState(() => _filter = _filter.copyWith(boughtBefore: !_filter.boughtBefore))),
            ]),
            _heading(context.l10n.ordersFilterSortHeading),
            Wrap(children: [
              for (final sort in OrderSort.values)
                _choice(OrderFilter.sortLabel(sort), _filter.sort == sort,
                    () => setState(() => _filter = _filter.copyWith(sort: sort))),
            ]),
            const SizedBox(height: 10),
            // ⓘ অন্তত ৫৬ উঁচু, বাঁধা নয় — বড় অক্ষরে নিজে বাড়ে (নকশার নিয়ম ০.৪.৩৮ §২)
            FilledButton(
              key: const ValueKey('filter-apply'),
              style: FilledButton.styleFrom(
                backgroundColor: o.brand,
                foregroundColor: o.onBrand,
                minimumSize: const Size.fromHeight(56),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                textStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17),
              ),
              onPressed: () => Navigator.of(context).pop(_filter),
              child: Text(context.l10n.ordersFilterApply(count, '$count')),
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
  String? customer,
}) =>
    showModalBottomSheet<ConfirmChoice>(
      context: context,
      isScrollControlled: true,
      backgroundColor: OrderInk.of(context).page,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      builder: (context) => OrderConfirmSheet(lines: lines, standing: standing, note: note, customer: customer),
    );

class OrderConfirmSheet extends StatelessWidget {
  const OrderConfirmSheet({super.key, required this.lines, required this.standing, this.note, this.customer});

  /// ⭐ কার অর্ডার — "নাম · পয়েন্ট · শাখা" (`CustomerRecord.label`; মালিকের স্থায়ী নিয়ম, ১৯ অক্টোবর ২০২৬)। ⓘ নম্বর নেই:
  /// অর্ডারের নম্বর সার্ভার দেয় সিঙ্কে (docs/Contract §০, সিদ্ধান্ত ২)
  final String? customer;

  final List<ConfirmLine> lines;
  final Future<CustomerStanding> standing;
  final TextEditingController? note;

  // ⓘ টাকার ঘর লেখার মাপে (IntrinsicColumnWidth) — দুই দশমিকের টাকা বাঁধা ৭৬-এ ভাঙত
  static const _cols = [FlexColumnWidth(), FixedColumnWidth(46), FixedColumnWidth(40), IntrinsicColumnWidth()];

  TableRow _row(BuildContext context, List<Widget> cells, {bool last = false}) => TableRow(
        decoration: BoxDecoration(
            border: last ? null : Border(bottom: BorderSide(color: OrderInk.of(context).line))),
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

  Widget _standing(BuildContext context, AsyncSnapshot<CustomerStanding> snap) {
    final o = OrderInk.of(context);
    if (snap.connectionState != ConnectionState.done) {
      return Row(children: [
        const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
        const SizedBox(width: 10),
        Expanded(child: Text(context.l10n.ordersConfirmStandingLoading, style: TextStyle(color: o.muted))),
      ]);
    }
    if (snap.hasError || snap.data == null) {
      final offline = snap.error != null && isNetworkError(snap.error!);
      return Container(
        key: const ValueKey('confirm-no-standing'),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(color: o.field, borderRadius: BorderRadius.circular(14)),
        child: Text(
          offline ? context.l10n.ordersConfirmStandingOffline : context.l10n.ordersConfirmStandingFailed,
          style: TextStyle(fontSize: 14, color: o.muted),
        ),
      );
    }
    final s = snap.data!;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(children: [
          _tile(context.l10n.ordersConfirmAdvance, s.advance, OrderPalette.free, OrderPalette.onFree),
          const SizedBox(width: 8),
          _tile(context.l10n.ordersConfirmPendingClaims, s.pendingClaims, OrderPalette.waiting, OrderPalette.onWaiting),
          const SizedBox(width: 8),
          _tile(context.l10n.ordersConfirmToPay, s.toPay, OrderPalette.owe, OrderPalette.onOwe),
        ]),
        if (s.overLimit) ...[
          const SizedBox(height: 12),
          Container(
            key: const ValueKey('confirm-over-limit'),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            decoration: BoxDecoration(color: OrderPalette.warn, borderRadius: BorderRadius.circular(14)),
            child: Text(
              context.l10n.ordersConfirmOverLimit(bnTaka(s.toPay)),
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
    final o = OrderInk.of(context);
    final head = TextStyle(fontSize: 12, color: o.head);
    const bold = TextStyle(fontWeight: FontWeight.w800);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 12, 16, 22 + MediaQuery.of(context).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(child: _grabber(context)),
              Text(context.l10n.ordersConfirmTitle, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              if (customer != null)
                CustomerLine(customer!,
                    key: const ValueKey('confirm-customer'),
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: o.ink)),
              const SizedBox(height: 8),
              Table(
                columnWidths: {for (var i = 0; i < _cols.length; i++) i: _cols[i]},
                defaultVerticalAlignment: TableCellVerticalAlignment.middle,
                children: [
                  _row(context, [
                    Text(context.l10n.ordersConfirmColProduct, style: head),
                    Text(context.l10n.ordersSale, style: head, textAlign: TextAlign.center),
                    Text(context.l10n.ordersFree, style: head, textAlign: TextAlign.center),
                    Text(context.l10n.ordersConfirmColAmount, style: head, textAlign: TextAlign.right),
                  ]),
                  for (final l in lines)
                    _row(context, [
                      Text(l.name, style: const TextStyle(fontSize: 14)),
                      Text(qtyText(l.qty), textAlign: TextAlign.center),
                      Text(qtyText(l.free),
                          textAlign: TextAlign.center,
                          style: l.free > 0
                              ? TextStyle(fontWeight: FontWeight.w800, color: o.inCart)
                              : TextStyle(color: o.blankInk)),
                      // ⓘ টাকা Money.amount — ইংরেজি অঙ্ক, দুই দশমিক (নকশার নিয়ম ০.৪.৩৮ §৩)
                      Text(Money.amount(l.taka), textAlign: TextAlign.right),
                    ]),
                  _row(context, [
                    Text(context.l10n.ordersConfirmTotal, style: bold),
                    Text(qtyText(qty), style: bold, textAlign: TextAlign.center),
                    Text(qtyText(free),
                        style: bold.copyWith(color: o.inCart), textAlign: TextAlign.center),
                    Text(Money.amount(taka), key: const ValueKey('confirm-total'), style: bold, textAlign: TextAlign.right),
                  ], last: true),
                ],
              ),
              const SizedBox(height: 12),
              FutureBuilder<CustomerStanding>(future: standing, builder: (context, snap) => _standing(context, snap)),
              if (note != null) ...[
                const SizedBox(height: 12),
                TextField(
                  controller: note,
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: context.l10n.ordersConfirmRemarks,
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                  ),
                ),
              ],
              const SizedBox(height: 12),
              // ⓘ বোতাম অন্তত ৫৮/৪৮ উঁচু, বাঁধা নয় — বড় অক্ষরে নিজে বাড়ে (নকশার নিয়ম ০.৪.৩৮ §২)
              FilledButton(
                key: const ValueKey('confirm-send'),
                style: FilledButton.styleFrom(
                  backgroundColor: o.brand,
                  foregroundColor: o.onBrand,
                  minimumSize: const Size.fromHeight(58),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                  textStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
                ),
                onPressed: () => Navigator.of(context).pop(ConfirmChoice.send),
                child: Text(context.l10n.ordersConfirmSend),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    flex: 10,
                    child: OutlinedButton(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: o.ink,
                        minimumSize: const Size.fromHeight(48),
                        side: BorderSide(color: o.outline),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                        textStyle: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                      onPressed: () => Navigator.of(context).pop(ConfirmChoice.back),
                      child: Text(context.l10n.ordersConfirmBack),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 13,
                    child: SizedBox(
                      child: TextButton(
                        style: TextButton.styleFrom(
                          backgroundColor: o.field,
                          foregroundColor: o.brand,
                          minimumSize: const Size.fromHeight(48),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                          textStyle: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        // The bank-slip feature is the next release's work.
                        onPressed: () => showDialog<void>(
                          context: context,
                          builder: (context) => AlertDialog(
                            content: Text(context.l10n.ordersConfirmSoon),
                            actions: [
                              TextButton(
                                onPressed: () => Navigator.of(context).pop(),
                                child: Text(context.l10n.ordersOk),
                              ),
                            ],
                          ),
                        ),
                        child: Text(context.l10n.ordersConfirmBankSlip),
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
