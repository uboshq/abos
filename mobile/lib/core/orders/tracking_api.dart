import '../api_client/api_client.dart';
import '../records/money.dart';

/// ডেলিভারি ট্র্যাকিং — `GET /sales/tracking`, `GET /sales/tracking/{kind}/{id}` (0.4.6)।
///
/// <p>⭐ মালিক, ২ অক্টোবর ২০২৬: "Order Traking" → *"Etar Nam 'Delivery Traking' Daw"*। ধাপের হিসাব
/// সার্ভারের ([[SaleTracking]]) — ওয়েবের পাতাও একই হিসাব পড়ে, তাই দুই জায়গায় দুই রকম ধাপ কখনো নয়।
class TrackedSale {
  const TrackedSale({
    required this.kind,
    required this.id,
    required this.no,
    required this.date,
    required this.customer,
    required this.total,
    required this.step,
    required this.billed,
  });

  final String kind;
  final String id;
  final String no;
  final String? date;
  final String? customer;
  final double total;
  final String step;
  final bool billed;

  factory TrackedSale.fromJson(Map<String, dynamic> json) => TrackedSale(
        kind: json['kind']?.toString() ?? 'challan',
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '—',
        date: json['date']?.toString(),
        customer: json['customer']?.toString(),
        total: Money.valueOrZero(json['total']),
        step: json['step']?.toString() ?? '',
        billed: json['billed'] == true,
      );
}

class TrackingEvent {
  const TrackingEvent({required this.at, required this.step, required this.by, required this.text});

  final DateTime? at;
  final String step;
  final String? by;
  final String text;

  factory TrackingEvent.fromJson(Map<String, dynamic> json) => TrackingEvent(
        at: DateTime.tryParse(json['at']?.toString() ?? '')?.toLocal(),
        step: json['step']?.toString() ?? '',
        by: json['by']?.toString(),
        text: json['text']?.toString() ?? '',
      );
}

class TrackingList {
  const TrackingList(this.rows, this.counts);

  final List<TrackedSale> rows;
  final Map<String, int> counts;
}

/// ধাপের বাংলা নাম — সার্ভারের `sales::tracking.step` তালিকার হুবহু।
const Map<String, String> trackingStepLabels = {
  'ordered': 'অর্ডার এসেছে',
  'draft': 'খসড়া',
  'approval': 'অনুমোদনের অপেক্ষায়',
  'warehouse': 'গুদামে',
  'gate_out': 'গেট পেরিয়েছে',
  'partial': 'কিছু পৌঁছেছে',
  'delivered': 'পৌঁছেছে',
  'cancelled': 'বাতিল',
  'billed': 'বিল হয়েছে',
};

abstract class TrackingApi {
  Future<TrackingList> list({String? query, String? step});

  Future<(TrackedSale, List<TrackingEvent>)> story(TrackedSale sale);
}

class ServerTrackingApi implements TrackingApi {
  const ServerTrackingApi();

  @override
  Future<TrackingList> list({String? query, String? step}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/sales/tracking', queryParameters: {
      if (query != null && query.isNotEmpty) 'q': query,
      if (step != null) 'step': step,
    });
    final body = response.data ?? const {};
    return TrackingList(
      [
        for (final row in (body['rows'] as List?) ?? const [])
          if (row is Map) TrackedSale.fromJson(Map<String, dynamic>.from(row)),
      ],
      {
        for (final e in ((body['counts'] as Map?) ?? const {}).entries)
          e.key.toString(): (e.value as num?)?.toInt() ?? 0,
      },
    );
  }

  @override
  Future<(TrackedSale, List<TrackingEvent>)> story(TrackedSale sale) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/sales/tracking/${sale.kind}/${sale.id}');
    final body = response.data ?? const {};
    return (
      TrackedSale.fromJson(body),
      [
        for (final e in (body['events'] as List?) ?? const [])
          if (e is Map) TrackingEvent.fromJson(Map<String, dynamic>.from(e)),
      ],
    );
  }
}
