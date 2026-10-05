import '../api_client/api_client.dart';

/// ⭐ লিড — `/sales/leads` (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)।
///
/// <p>ওয়েবের একই সেবা আর চাবি: কে কোনটা দেখেন, মালিক কে, কোন অবস্থা হাতে বসে — সব সার্ভারের। লিড থেকে গ্রাহক
/// বানানো ওয়েবে (ইংরেজি নাম আর ধরন সেখানে ঠিক হয়)।
class Lead {
  const Lead({
    required this.id,
    required this.no,
    required this.name,
    this.contactPerson,
    this.phone,
    this.address,
    required this.source,
    required this.sourceLabel,
    required this.status,
    required this.statusLabel,
    this.lostReason,
    this.notes,
    this.owner,
    required this.converted,
  });

  final String id;
  final String no;
  final String name;
  final String? contactPerson;
  final String? phone;
  final String? address;
  final String source;
  final String sourceLabel;
  final String status;
  final String statusLabel;
  final String? lostReason;
  final String? notes;
  final String? owner;

  /// গ্রাহক হয়ে গেছেন — আর বদলানো যায় না।
  final bool converted;

  factory Lead.fromJson(Map<String, dynamic> json) => Lead(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '—',
        name: json['name']?.toString() ?? '—',
        contactPerson: json['contact_person']?.toString(),
        phone: json['phone']?.toString(),
        address: json['address']?.toString(),
        source: json['source']?.toString() ?? '',
        sourceLabel: json['source_label']?.toString() ?? json['source']?.toString() ?? '',
        status: json['status']?.toString() ?? '',
        statusLabel: json['status_label']?.toString() ?? json['status']?.toString() ?? '',
        lostReason: json['lost_reason']?.toString(),
        notes: json['notes']?.toString(),
        owner: json['owner']?.toString(),
        converted: json['converted'] == true,
      );
}

/// উৎস বা অবস্থা — চাবি আর পর্দার নাম।
class LeadChoice {
  const LeadChoice(this.key, this.label);

  final String key;
  final String label;
}

class LeadSetup {
  const LeadSetup({required this.sources, required this.statuses});

  final List<LeadChoice> sources;
  final List<LeadChoice> statuses;

  static List<LeadChoice> _read(dynamic rows) => [
        for (final r in (rows as List?) ?? const [])
          if (r is Map) LeadChoice(r['key'].toString(), r['label']?.toString() ?? r['key'].toString()),
      ];

  factory LeadSetup.fromJson(Map<String, dynamic> json) =>
      LeadSetup(sources: _read(json['sources']), statuses: _read(json['statuses']));
}

/// লেখা বা বদলানোর ঘর — ফোন যা পাঠায়।
class LeadForm {
  const LeadForm({
    required this.name,
    this.contactPerson,
    this.phone,
    this.address,
    required this.source,
    this.status,
    this.lostReason,
    this.notes,
  });

  final String name;
  final String? contactPerson;
  final String? phone;
  final String? address;
  final String source;
  final String? status;
  final String? lostReason;
  final String? notes;

  Map<String, dynamic> toJson() {
    String? clean(String? v) => v == null || v.trim().isEmpty ? null : v.trim();
    return {
      'name': name.trim(),
      'contact_person': clean(contactPerson),
      'phone': clean(phone),
      'address': clean(address),
      'source': source,
      if (status != null) 'status': status,
      'lost_reason': clean(lostReason),
      'notes': clean(notes),
    };
  }
}

abstract class LeadApi {
  Future<LeadSetup> setup();
  Future<(List<Lead>, int?)> list({String? query, String? status, int page = 1});
  Future<Lead> show(String id);
  Future<Lead> create(LeadForm form);
  Future<Lead> update(String id, LeadForm form);
}

class ServerLeadApi implements LeadApi {
  const ServerLeadApi();

  static const _base = '/sales/leads';

  @override
  Future<LeadSetup> setup() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('$_base/setup');
    return LeadSetup.fromJson(response.data ?? const {});
  }

  @override
  Future<(List<Lead>, int?)> list({String? query, String? status, int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(_base, queryParameters: {
      if (query != null && query.trim().isNotEmpty) 'q': query.trim(),
      if (status != null) 'status': status,
      if (page > 1) 'page': page,
    });
    final data = response.data ?? const {};
    return (
      [
        for (final row in (data['leads'] as List?) ?? const [])
          if (row is Map) Lead.fromJson(Map<String, dynamic>.from(row)),
      ],
      (data['next_page'] as num?)?.toInt(),
    );
  }

  @override
  Future<Lead> show(String id) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('$_base/$id');
    return Lead.fromJson(response.data ?? const {});
  }

  @override
  Future<Lead> create(LeadForm form) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>(_base, data: form.toJson());
    return Lead.fromJson(response.data ?? const {});
  }

  @override
  Future<Lead> update(String id, LeadForm form) async {
    final response = await ApiClient.dio.put<Map<String, dynamic>>('$_base/$id', data: form.toJson());
    return Lead.fromJson(response.data ?? const {});
  }
}
