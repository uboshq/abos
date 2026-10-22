import '../../core/api_client/once_key.dart';
import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../books/books_widgets.dart';
import '../../core/records/customer_record.dart';

/// ⭐ লোডিং শিট — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৪, ৬ অক্টোবর ২০২৬: "পণ্য ধরে কত তুলতে হবে, চালান ধরে কার জন্য"।
///
/// <p>খোলা ট্রিপ (এখনো রওনা হয়নি), দেখার শাখায়। শিটে পণ্য ধরে যোগ — কোন লট থেকে কত, কোন দোকানের জন্য কত — আর চালান
/// ধরে মাল। "প্যাক হয়েছে" ওয়েবের "লোডিং নিশ্চিত"-এর একই সেবা: বরাদ্দ বা তোলার ধাপের চালান প্যাকে যায়, আগেরগুলো যেমন আছে।
/// ⓘ হিসাব সব সার্ভারের — এখানে কেবল দেখানো। "প্যাক" চাবি (ডেলিভারি বদলানো) না থাকলে সার্ভার ফেরায়, বার্তা দেখায়।
class LoadingTrip {
  const LoadingTrip({
    required this.id,
    required this.documentNo,
    this.date,
    this.vehicle,
    this.driver,
    this.driverPhone,
    this.carrier,
    this.challans = 0,
  });

  final String id;
  final String documentNo;
  final String? date;
  final String? vehicle;
  final String? driver;
  final String? driverPhone;
  final String? carrier;
  final int challans;

  factory LoadingTrip.fromJson(Map<String, dynamic> j) => LoadingTrip(
        id: j['id']?.toString() ?? '',
        documentNo: j['document_no']?.toString() ?? '',
        date: j['date']?.toString(),
        vehicle: j['vehicle']?.toString(),
        driver: j['driver']?.toString(),
        driverPhone: j['driver_phone']?.toString(),
        carrier: j['carrier']?.toString(),
        challans: (j['challans'] as num?)?.toInt() ?? 0,
      );

  /// গাড়ি · চালক · ফোন · বাহক — যা আছে
  String get crew => [
        if (vehicle != null && vehicle!.isNotEmpty) AppLocale.l10n.loadingTripVehicle(vehicle!),
        if (driver != null && driver!.isNotEmpty) AppLocale.l10n.loadingTripDriver(driver!),
        if (driverPhone != null && driverPhone!.isNotEmpty) driverPhone!,
        if (carrier != null && carrier!.isNotEmpty) carrier!,
      ].join(' · ');
}

/// নাম আর পরিমাণ — লট বা দোকান
class LoadingShare {
  const LoadingShare(this.label, this.qty);

  final String label;
  final double qty;
}

class LoadingProduct {
  const LoadingProduct({
    required this.product,
    this.unit = '',
    this.qty = 0,
    this.free = 0,
    this.lots = const [],
    this.forWhom = const [],
  });

  final String product;
  final String unit;
  final double qty;
  final double free;
  final List<LoadingShare> lots;
  final List<LoadingShare> forWhom;

  factory LoadingProduct.fromJson(Map<String, dynamic> j) => LoadingProduct(
        product: j['product']?.toString() ?? '—',
        unit: j['unit']?.toString() ?? '',
        qty: Money.valueOrZero(j['qty']),
        free: Money.valueOrZero(j['free']),
        lots: [
          for (final l in (j['lots'] as List?) ?? const [])
            if (l is Map)
              LoadingShare(
                  l['lot']?.toString() ?? '', Money.valueOrZero(l['qty'])),
        ],
        forWhom: [
          for (final f in (j['for'] as List?) ?? const [])
            if (f is Map)
              LoadingShare(
                  f['who']?.toString() ?? '', Money.valueOrZero(f['qty'])),
        ],
      );
}

class LoadingChallanLine {
  const LoadingChallanLine(this.product, this.qty, this.free);

  final String product;
  final double qty;
  final double free;
}

class LoadingChallan {
  const LoadingChallan({
    required this.documentNo,
    required this.customer,
    this.packed = false,
    this.lines = const [],
  });

  final String documentNo;
  final String customer;
  final bool packed;
  final List<LoadingChallanLine> lines;

  factory LoadingChallan.fromJson(Map<String, dynamic> j) => LoadingChallan(
        documentNo: j['document_no']?.toString() ?? '',
        customer: withPoint(j['customer']?.toString() ?? '', j['customer_point'], j['customer_branch']),
        packed: j['packed'] == true,
        lines: [
          for (final l in (j['lines'] as List?) ?? const [])
            if (l is Map)
              LoadingChallanLine(l['product']?.toString() ?? '—',
                  Money.valueOrZero(l['qty']), Money.valueOrZero(l['free'])),
        ],
      );
}

class LoadingSheet {
  const LoadingSheet({
    required this.trip,
    this.open = true,
    this.products = const [],
    this.challans = const [],
  });

  final LoadingTrip trip;
  final bool open;
  final List<LoadingProduct> products;
  final List<LoadingChallan> challans;

  factory LoadingSheet.fromJson(Map<String, dynamic> j) => LoadingSheet(
        trip: LoadingTrip.fromJson(j),
        open: j['open'] != false,
        products: [
          for (final p in (j['products'] as List?) ?? const [])
            if (p is Map) LoadingProduct.fromJson(Map<String, dynamic>.from(p)),
        ],
        challans: [
          for (final c in (j['challans'] as List?) ?? const [])
            if (c is Map) LoadingChallan.fromJson(Map<String, dynamic>.from(c)),
        ],
      );
}

/// এক পাতা ট্রিপ — সার্ভার ৫০টা করে দেয়, পরের পাতার নম্বরসহ
class LoadingTripPage {
  const LoadingTripPage(this.rows, {this.nextPage, this.count});

  final List<LoadingTrip> rows;
  final int? nextPage;

  /// ⭐ খোলা সব ট্রিপের গোনা — সার্ভারের, সব পাতার; পুরনো সার্ভারে `null` (পূর্ণ নিরীক্ষা §৯ ⚠️৭)
  final int? count;
}

abstract class LoadingApi {
  Future<LoadingTripPage> trips({int page = 1});
  Future<LoadingSheet> sheet(String id);

  /// কয়টা চালান প্যাকে গেল, আর সার্ভারের বার্তা
  Future<(int, String)> packed(String id);
}

class ServerLoadingApi implements LoadingApi {
  const ServerLoadingApi();

  @override
  Future<LoadingTripPage> trips({int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
        '/sales/loading',
        queryParameters: {'page': page});
    return LoadingTripPage([
      for (final r in (response.data?['rows'] as List?) ?? const [])
        if (r is Map) LoadingTrip.fromJson(Map<String, dynamic>.from(r)),
    ],
        nextPage: (response.data?['next_page'] as num?)?.toInt(),
        count: (response.data?['count'] as num?)?.toInt());
  }

  @override
  Future<LoadingSheet> sheet(String id) async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/sales/loading/$id');
    return LoadingSheet.fromJson(response.data ?? const {});
  }

  @override
  Future<(int, String)> packed(String id) async {
    final response = await OnceKey.of('loading-packed:$id').send(() => ApiClient.dio
        .post<Map<String, dynamic>>('/sales/loading/$id/packed'));
    return (
      (response.data?['packed'] as num?)?.toInt() ?? 0,
      response.data?['message']?.toString() ?? '',
    );
  }
}

/// খোলা ট্রিপের তালিকা
class LoadingListScreen extends StatefulWidget {
  const LoadingListScreen({super.key, this.api = const ServerLoadingApi()});

  final LoadingApi api;

  @override
  State<LoadingListScreen> createState() => _LoadingListScreenState();
}

class _LoadingListScreenState extends State<LoadingListScreen> {
  List<LoadingTrip>? _rows;
  int? _next;
  int? _count;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool more = false}) async {
    final page = more ? _next : 1;
    if (page == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final got = await widget.api.trips(page: page);
      if (mounted) {
        setState(() {
          _rows = more ? [...?_rows, ...got.rows] : got.rows;
          _next = got.nextPage;
          _count = got.count;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.loadingListLoadFailed,
            whenAbsent: context.l10n.loadingListNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(LoadingTrip trip) async {
    await Navigator.of(context).push<void>(MaterialPageRoute(
      builder: (_) => LoadingSheetScreen(trip: trip, api: widget.api),
    ));
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows ?? const <LoadingTrip>[];
    final l = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: l.loadingListTitle),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) _ErrorCard(_error!),
            // ⭐ গোটা তালিকার গোনা — সার্ভারের, সব পাতার; ⛔ পুরনো সার্ভারে পট্টি নেই (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
            if (_count != null && rows.isNotEmpty)
              TotalStrip(label: l.loadingListTotal, value: l.loadingListCountValue(_count!)),
            if (_rows != null && rows.isEmpty && !_busy)
              Padding(
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Center(child: Text(l.loadingListEmpty)),
              ),
            for (final trip in rows)
              Card(
                key: ValueKey('trip-${trip.documentNo}'),
                child: ListTile(
                  title: Text(trip.documentNo,
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text([
                    if (trip.date != null) trip.date!,
                    l.loadingListChallans(trip.challans),
                    if (trip.crew.isNotEmpty) trip.crew,
                  ].join(' · ')),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _open(trip),
                ),
              ),
            if (_next != null)
              Center(
                child: OutlinedButton(
                  key: const ValueKey('loading-more'),
                  onPressed: _busy ? null : () => _load(more: true),
                  child: Text(l.loadingListMore),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// এক ট্রিপের শিট — পণ্য ধরে, চালান ধরে, আর "প্যাক হয়েছে"
class LoadingSheetScreen extends StatefulWidget {
  const LoadingSheetScreen(
      {super.key, required this.trip, this.api = const ServerLoadingApi()});

  final LoadingTrip trip;
  final LoadingApi api;

  @override
  State<LoadingSheetScreen> createState() => _LoadingSheetScreenState();
}

class _LoadingSheetScreenState extends State<LoadingSheetScreen> {
  LoadingSheet? _sheet;
  bool _busy = false;
  String? _error;
  String? _notice;

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
      final sheet = await widget.api.sheet(widget.trip.id);
      if (mounted) setState(() => _sheet = sheet);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.loadingSheetLoadFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _packed() async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.l10n.loadingSheetPackedQuestion),
        content: Text(ctx.l10n.loadingSheetPackedExplain),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: Text(ctx.l10n.loadingSheetNo)),
          FilledButton(
              key: const ValueKey('loading-packed-sure'),
              onPressed: () => Navigator.of(ctx).pop(true),
              child: Text(ctx.l10n.loadingSheetYesPacked)),
        ],
      ),
    );
    if (sure != true || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (count, message) = await widget.api.packed(widget.trip.id);
      if (mounted) {
        setState(() => _notice = message.isNotEmpty
            ? message
            : context.l10n.loadingSheetPackedNotice(widget.trip.documentNo, count));
      }
      await _load();
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.loadingSheetPackFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sheet = _sheet;
    final trip = sheet?.trip ?? widget.trip;
    final allPacked = sheet != null &&
        sheet.challans.isNotEmpty &&
        sheet.challans.every((c) => c.packed);
    final muted = TextStyle(color: context.palette.onSurfaceMuted);
    final l = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: l.loadingSheetTitle(trip.documentNo)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (trip.crew.isNotEmpty)
              Text(trip.crew,
                  key: const ValueKey('loading-crew'), style: muted),
            if (_notice != null)
              Card(
                color: context.palette.successSurface,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Text(_notice!, key: const ValueKey('loading-notice')),
                ),
              ),
            if (_error != null) _ErrorCard(_error!),
            if (sheet != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(l.loadingSheetByProduct,
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
              for (final p in sheet.products)
                Card(
                  key: ValueKey('loading-product-${p.product}'),
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [
                          Expanded(
                              child: Text(p.product,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w700))),
                          Text(
                              '${Money.plain(p.qty)} ${p.unit}'
                              '${p.free > 0 ? ' (+${Money.plain(p.free)})' : ''}',
                              style:
                                  const TextStyle(fontWeight: FontWeight.w700)),
                        ]),
                        if (p.lots.isNotEmpty)
                          Text(
                              l.loadingSheetLots(p.lots.map((lot) => '${lot.label} ${Money.plain(lot.qty)}').join(' · ')),
                              style: muted),
                        for (final w in p.forWhom)
                          Text('• ${w.label} — ${Money.plain(w.qty)}'),
                      ],
                    ),
                  ),
                ),
              const SizedBox(height: AppSpacing.sm),
              Text(l.loadingSheetByChallan,
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
              for (final c in sheet.challans)
                Card(
                  key: ValueKey('loading-challan-${c.documentNo}'),
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [
                          Expanded(
                              child: Text('${c.documentNo} · ${c.customer}',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w600))),
                          if (c.packed)
                            Chip(
                                label: Text(l.loadingSheetPacked),
                                backgroundColor: context.palette.successSurface),
                        ]),
                        for (final l in c.lines)
                          Text('• ${l.product} — ${Money.plain(l.qty)}'
                              '${l.free > 0 ? ' (+${Money.plain(l.free)})' : ''}'),
                      ],
                    ),
                  ),
                ),
              const SizedBox(height: AppSpacing.md),
              if (sheet.open && !allPacked)
                FilledButton.icon(
                  key: const ValueKey('loading-packed'),
                  onPressed: _busy ? null : _packed,
                  icon: const Icon(Icons.inventory_2_outlined),
                  label: Text(l.loadingSheetPacked),
                )
              else if (allPacked)
                Center(
                    child: Text(
                        l.loadingSheetAllPacked,
                        key: const ValueKey('loading-all-packed'))),
            ],
          ],
        ),
      ),
    );
  }
}

class _ErrorCard extends StatelessWidget {
  const _ErrorCard(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Card(
        color: context.palette.dangerSurface,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Text(text, style: TextStyle(color: context.palette.danger)),
        ),
      );
}
