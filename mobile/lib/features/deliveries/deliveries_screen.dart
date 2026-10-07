import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/orders/paper_scan_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../scan/receive_screen.dart';
import '../../core/records/customer_record.dart';

/// ⭐ আজকের ডেলিভারি — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬।
///
/// <p>পথে থাকা চালান (রওনা হয়েছে, পৌঁছায়নি), দেখার শাখায়: ক্রেতা, ফোন, ঠিকানা, গাড়ি আর চালক, মাল। "বুঝিয়ে দিন" চাপলে
/// নাম, ফোন আর প্রতিটা সারির নেওয়া ও ভাঙা ([[ReceiveScreen]]) — পাঠানো হয় QR-এর একই দরজায়, চালানের সই-করা টোকেনে।
/// ⓘ চালক এখনো ব্যবহারকারী নন, তাই "আমার" নয় — শাখার সব (মালিকের উত্তর এলে বদলাবে)।
class DeliveryRunRow {
  const DeliveryRunRow({
    required this.token,
    required this.documentNo,
    required this.customer,
    this.saleNo,
    this.phone = '',
    this.address = '',
    this.vehicle,
    this.driver,
    this.lines = const [],
  });

  final String token;
  final String documentNo;
  final String customer;

  /// ⭐ বিক্রির নম্বর (S-0154) — চালানের নিজের নম্বর CHA-0154 থেকে আলাদা; পুরনো কাগজে null
  final String? saleNo;
  final String phone;
  final String address;
  final String? vehicle;
  final String? driver;
  final List<ScannedLine> lines;

  factory DeliveryRunRow.fromJson(Map<String, dynamic> j) => DeliveryRunRow(
        token: j['token']?.toString() ?? '',
        documentNo: j['document_no']?.toString() ?? '',
        customer: withPoint(j['customer']?.toString() ?? '', j['customer_point']),
        saleNo: j['sale_no']?.toString(),
        phone: j['phone']?.toString() ?? '',
        address: j['address']?.toString() ?? '',
        vehicle: j['vehicle']?.toString(),
        driver: j['driver']?.toString(),
        lines: [
          for (final l in (j['lines'] as List?) ?? const [])
            if (l is Map)
              ScannedLine(
                line: (l['line'] as num?)?.toInt() ?? 0,
                product: l['product']?.toString() ?? '—',
                qty: Money.valueOrZero(l['qty']),
                freeQty: 0,
              ),
        ],
      );
}

/// এক পাতা — সার্ভার ৫০টা করে দেয়, পরের পাতার নম্বরসহ (নেই তো `null`)
class DeliveryRunPage {
  const DeliveryRunPage(this.rows, {this.nextPage});

  final List<DeliveryRunRow> rows;
  final int? nextPage;
}

abstract class DeliveryRunApi {
  Future<DeliveryRunPage> today({int page = 1});
}

class ServerDeliveryRunApi implements DeliveryRunApi {
  const ServerDeliveryRunApi();

  @override
  Future<DeliveryRunPage> today({int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
        '/sales/deliveries',
        queryParameters: {'page': page});
    return DeliveryRunPage([
      for (final r in (response.data?['rows'] as List?) ?? const [])
        if (r is Map) DeliveryRunRow.fromJson(Map<String, dynamic>.from(r)),
    ], nextPage: (response.data?['next_page'] as num?)?.toInt());
  }
}

class DeliveriesScreen extends StatefulWidget {
  const DeliveriesScreen(
      {super.key,
      this.api = const ServerDeliveryRunApi(),
      this.scan = const ServerPaperScanApi()});

  final DeliveryRunApi api;

  /// "বুঝিয়ে দিন" — QR-এর একই দরজা
  final PaperScanApi scan;

  @override
  State<DeliveriesScreen> createState() => _DeliveriesScreenState();
}

class _DeliveriesScreenState extends State<DeliveriesScreen> {
  List<DeliveryRunRow>? _rows;
  int? _next;
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
      final page = await widget.api.today();
      if (mounted) {
        setState(() {
          _rows = page.rows;
          _next = page.nextPage;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback:
                'ডেলিভারির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে আজকের ডেলিভারি এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// "আরও দেখুন" — পরের পাতা নিচে জোড়া লাগে
  Future<void> _more() async {
    final next = _next;
    if (next == null) return;
    setState(() => _busy = true);
    try {
      final page = await widget.api.today(page: next);
      if (mounted) {
        setState(() {
          _rows = [...?_rows, ...page.rows];
          _next = page.nextPage;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'পরের পাতা আনা গেল না। আবার চেষ্টা করুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _handOver(DeliveryRunRow row) async {
    final result =
        await Navigator.of(context).push<ReceiveResult>(MaterialPageRoute(
      builder: (_) => ReceiveScreen(
          documentNo: row.documentNo, customer: row.customer, lines: row.lines),
    ));
    if (result == null || !mounted) return;
    setState(() => _busy = true);
    try {
      final paper = await widget.scan.deliver(row.token,
          receiver: result.receiver,
          phone: result.phone,
          lines: row.lines,
          taken: result.taken,
          damaged: result.damaged);
      if (mounted) {
        setState(() => _notice = paper.stage == 'partially_delivered'
            ? '${row.documentNo}: আংশিক পৌঁছেছে — কম আর ভাঙা মাল ফেরতের কাগজে উঠেছে।'
            : '${row.documentNo}: পৌঁছেছে — ${result.receiver} বুঝে নিলেন।');
      }
      await _load();
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'পৌঁছানো লেখা গেল না। আবার চেষ্টা করুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows ?? const <DeliveryRunRow>[];
    return Scaffold(
      appBar: AppBar(title: const Text('আজকের ডেলিভারি')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (_notice != null)
              Card(
                color: AppColors.successSurface,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child:
                      Text(_notice!, key: const ValueKey('deliveries-notice')),
                ),
              ),
            if (_error != null)
              Card(
                color: AppColors.dangerSurface,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Text(_error!,
                      style: const TextStyle(color: AppColors.danger)),
                ),
              ),
            if (_rows != null && rows.isEmpty && !_busy)
              const Padding(
                padding: EdgeInsets.all(AppSpacing.lg),
                child: Center(child: Text('পথে কোনো চালান নেই।')),
              ),
            for (final row in rows)
              Card(
                key: ValueKey('delivery-${row.documentNo}'),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(row.customer,
                          style: const TextStyle(
                              fontSize: 16, fontWeight: FontWeight.w700)),
                      Text(
                          [
                            row.documentNo,
                            if (row.saleNo != null &&
                                row.saleNo!.isNotEmpty &&
                                row.saleNo != row.documentNo)
                              row.saleNo!,
                            if (row.phone.isNotEmpty) row.phone
                          ].join(' · '),
                          key: ValueKey('delivery-no-${row.documentNo}')),
                      if (row.address.isNotEmpty)
                        Text(row.address,
                            style: const TextStyle(
                                color: AppColors.onSurfaceMuted)),
                      if (row.vehicle != null || row.driver != null)
                        Text(
                            [
                              if (row.vehicle != null) 'গাড়ি ${row.vehicle}',
                              if (row.driver != null) 'চালক ${row.driver}'
                            ].join(' · '),
                            style: const TextStyle(
                                color: AppColors.onSurfaceMuted)),
                      const SizedBox(height: AppSpacing.xs),
                      for (final l in row.lines)
                        Text('• ${l.product} — ${Money.plain(l.qty)}'),
                      Align(
                        alignment: Alignment.centerRight,
                        child: FilledButton.icon(
                          key: ValueKey('deliver-${row.documentNo}'),
                          onPressed: _busy ? null : () => _handOver(row),
                          icon: const Icon(Icons.how_to_reg),
                          label: const Text('বুঝিয়ে দিন'),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            if (_next != null)
              Center(
                child: OutlinedButton(
                  key: const ValueKey('deliveries-more'),
                  onPressed: _busy ? null : _more,
                  child: const Text('আরও দেখুন'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
