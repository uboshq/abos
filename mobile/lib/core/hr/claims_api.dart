import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../records/money.dart';

/// ⭐ খরচের দাবি আর অগ্রিম অনুরোধ — `/hr/claims` (টাকা-আসা-যাওয়ার পরিকল্পনা ১৩, ৭ অক্টোবর ২০২৬; সার্ভার f727b846)।
///
/// <p>কর্মী নিজের জন্য টাকা চান — খরচ হয়ে গেছে (রসিদসহ), নয়তো আগাম। সই হয় অনুমোদনের তালিকায়, অঙ্ক ধরে; শেষ সইয়ের পরে
/// ক্যাশিয়ার টাকা দেন। খরচ আগে মেটে খোলা অগ্রিম থেকে, বাকিটা নগদে। ফোন কেবল চায় আর দেখে — সিদ্ধান্ত সব সার্ভারের।
class ClaimHead {
  const ClaimHead({required this.id, required this.name, this.code = ''});

  final int id;
  final String name;
  final String code;

  factory ClaimHead.fromJson(Map<String, dynamic> j) => ClaimHead(
        id: (j['id'] as num?)?.toInt() ?? 0,
        name: j['name']?.toString() ?? '',
        code: j['code']?.toString() ?? '',
      );
}

class Claim {
  const Claim({
    required this.id,
    required this.number,
    required this.kind,
    required this.status,
    required this.amount,
    this.statusLabel = '',
    this.fromAdvance = 0,
    this.cash = 0,
    this.head,
    this.spentOn,
    this.reason = '',
    this.submittedAt,
    this.decidedAt,
    this.paidAt,
  });

  final String id;
  final String number;

  /// expense · advance
  final String kind;

  /// submitted · approved · paid · rejected
  final String status;
  final String statusLabel;
  final double amount;
  final double fromAdvance;
  final double cash;
  final String? head;
  final String? spentOn;
  final String reason;
  final String? submittedAt;
  final String? decidedAt;
  final String? paidAt;

  bool get isAdvance => kind == 'advance';

  String get kindLabel => isAdvance ? 'অগ্রিম' : 'খরচের দাবি';

  /// সার্ভারের নিজের লেখা আগে; না থাকলে চার অবস্থার নাম — কখনো কাঁচা চাবি নয়
  String get stateLabel => statusLabel.isNotEmpty
      ? statusLabel
      : switch (status) {
          'submitted' => 'সইয়ের অপেক্ষায়',
          'approved' => 'অনুমোদিত — টাকার অপেক্ষায়',
          'paid' => 'পরিশোধিত',
          'rejected' => 'প্রত্যাখ্যাত',
          _ => 'অপেক্ষায়',
        };

  factory Claim.fromJson(Map<String, dynamic> j) {
    final head = j['head'];
    return Claim(
      id: j['id']?.toString() ?? '',
      number: j['number']?.toString() ?? '',
      kind: j['kind']?.toString() ?? 'expense',
      status: j['status']?.toString() ?? '',
      statusLabel: j['status_label']?.toString() ?? '',
      amount: Money.valueOrZero(j['amount']),
      fromAdvance: Money.valueOrZero(j['from_advance']),
      cash: Money.valueOrZero(j['cash']),
      head: head is Map ? head['name']?.toString() : null,
      spentOn: j['spent_on']?.toString(),
      reason: j['reason']?.toString() ?? '',
      submittedAt: j['submitted_at']?.toString(),
      decidedAt: j['decided_at']?.toString(),
      paidAt: j['paid_at']?.toString(),
    );
  }
}

class ClaimsPage {
  const ClaimsPage({required this.claims, this.openAdvance});

  final List<Claim> claims;

  /// খোলা অগ্রিম — কর্মী-জোড়া না থাকলে null
  final double? openAdvance;
}

/// যা পাঠানো হবে — সার্ভারের `validated()` এই ঘরগুলোই পড়ে
class ClaimDraft {
  const ClaimDraft({
    required this.kind,
    required this.amount,
    required this.reason,
    this.headId,
    this.spentOn,
    this.receiptPath,
  });

  final String kind;
  final String amount;
  final String reason;
  final int? headId;
  final DateTime? spentOn;
  final String? receiptPath;

  Map<String, dynamic> toFields() => {
        'kind': kind,
        'amount': amount,
        'reason': reason.trim(),
        if (kind == 'expense' && headId != null) 'expense_account_id': '$headId',
        if (kind == 'expense' && spentOn != null)
          'spent_on':
              '${spentOn!.year.toString().padLeft(4, '0')}-${spentOn!.month.toString().padLeft(2, '0')}-${spentOn!.day.toString().padLeft(2, '0')}',
      };
}

abstract class ClaimsApi {
  Future<List<ClaimHead>> heads();

  Future<ClaimsPage> mine();

  Future<Claim> one(String id);

  Future<Claim> send(ClaimDraft draft);
}

class ServerClaimsApi implements ClaimsApi {
  const ServerClaimsApi();

  @override
  Future<List<ClaimHead>> heads() async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/hr/claims/heads');
    return [
      for (final h in (response.data?['heads'] as List?) ?? const [])
        if (h is Map) ClaimHead.fromJson(Map<String, dynamic>.from(h)),
    ];
  }

  @override
  Future<ClaimsPage> mine() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/hr/claims');
    final body = response.data ?? const <String, dynamic>{};
    return ClaimsPage(
      openAdvance: body['open_advance'] == null
          ? null
          : Money.valueOrZero(body['open_advance']),
      claims: [
        for (final c in (body['claims'] as List?) ?? const [])
          if (c is Map) Claim.fromJson(Map<String, dynamic>.from(c)),
      ],
    );
  }

  @override
  Future<Claim> one(String id) async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/hr/claims/$id');
    return Claim.fromJson(response.data ?? const {});
  }

  @override
  Future<Claim> send(ClaimDraft draft) async {
    final path = draft.receiptPath;
    final form = FormData.fromMap({
      ...draft.toFields(),
      if (draft.kind == 'expense' && path != null)
        'receipt': await MultipartFile.fromFile(path,
            filename: path.split(RegExp(r'[\\/]')).last),
    });
    final response =
        await ApiClient.dio.post<Map<String, dynamic>>('/hr/claims', data: form);
    return Claim.fromJson(response.data ?? const {});
  }
}
