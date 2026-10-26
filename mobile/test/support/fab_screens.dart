// ⓘ "＋ নতুন" বোতামওয়ালা প্রতিটা তালিকার পর্দা, নকল সার্ভারসহ — fab_never_hides_data_test এদের প্রতিটায় ঘোরে।
// সারি ইচ্ছে করে অনেক, যাতে ২০০% অক্ষরে আর ছোট ফোনে তালিকা গড়ায়।
import 'package:flutter/material.dart';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/approvals/approval_rows_api.dart';
import 'package:abos_mobile/core/hr/claims_api.dart';
import 'package:abos_mobile/core/hr/self_service_api.dart';
import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/orders/lead_api.dart';
import 'package:abos_mobile/core/orders/quotation_api.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart' show PushOutcome;
import 'package:abos_mobile/features/approval_rows/approval_rows_screens.dart';
import 'package:abos_mobile/features/claims/claims_screens.dart';
import 'package:abos_mobile/features/delivery_orders/delivery_order_screens.dart';
import 'package:abos_mobile/features/leads/lead_screens.dart';
import 'package:abos_mobile/features/money/money_screens.dart';
import 'package:abos_mobile/features/quotations/quotation_screens.dart';
import 'package:abos_mobile/features/self_service/self_service_screens.dart';
import 'package:abos_mobile/features/stock_writes/quality_serial_screens.dart';
import 'package:abos_mobile/features/stock_writes/stock_write_screens.dart' show StockChoice;
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';

/// পর্দা আর তার বোতামের চাবি
typedef FabScreen = ({Widget Function() screen, String fab});

Map<String, FabScreen> fabScreens() => {
      'vouchers': (
        screen: () => VoucherListScreen(
            api: const FakeVouchers(), newChangeId: () => 'k', branches: () async => const [], today: DateTime(2026, 10, 20), canCreate: true),
        fab: 'voucher-new'
      ),
      'claims': (screen: () => ClaimListScreen(api: _Claims()), fab: 'claim-new'),
      'leads': (screen: () => LeadListScreen(api: _Leads()), fab: 'lead-new'),
      'quotations': (screen: () => QuotationListScreen(api: _Quotes()), fab: 'quotation-new'),
      'DOs': (screen: () => DeliveryOrderListScreen(api: FakeDos()), fab: 'do-new'),
      'transfers': (
        screen: () => TransferListScreen(api: _Money({
              '/accounts/transfers': {
                'rows': [
                  for (var i = 1; i <= 12; i++) {'id': 't$i', 'no': 'TR-26-00$i', 'amount': '${i * 1250}.00', 'from': 'নগদ', 'to': 'ব্যাংক'},
                ],
                'total': '97500.00',
                'count': 12,
              },
            })),
        fab: 'transfer-new'
      ),
      'cash counts': (
        screen: () => CashCountListScreen(api: _Money({
              '/accounts/cash-counts': {
                'rows': [
                  for (var i = 1; i <= 12; i++) {'id': 'c$i', 'no': 'CC-26-00$i'},
                ],
                'count': 12,
              },
            })),
        fab: 'count-new'
      ),
      'leave': (screen: () => LeaveScreen(api: _Leave()), fab: 'leave-new'),
      'quality': (screen: () => QualityListScreen(api: _Qs(), canCreate: true), fab: 'qc-new'),
      'delegations': (screen: () => DelegationScreen(api: _Delegations(), today: DateTime(2026, 10, 17)), fab: 'delegation-new'),
    };

/// প্রতিটা পর্দার নমুনার কাগজের নম্বর — নম্বর কখনো না-কাটার দাবি এগুলো খোঁজে (যে পর্দার সারিতে নম্বর নেই, সেটা এখানে নেই)
const fabScreenNumbers = <String, List<String>>{
  'vouchers': ['RV-2610-0142', 'PV-2610-0057', 'EV-2610-0031', 'RV-2610-0143', 'PV-2610-0058', 'EV-2610-0032'],
  'claims': ['EXC-0001', 'EXC-0002', 'EXC-0003', 'EXC-0004', 'EXC-0005', 'EXC-0006', 'EXC-0007', 'EXC-0008', 'EXC-0009', 'EXC-00010'],
  'leads': ['LD-1', 'LD-2', 'LD-3', 'LD-4', 'LD-5', 'LD-6', 'LD-7', 'LD-8', 'LD-9', 'LD-10', 'LD-11', 'LD-12'],
  'quotations': ['QTN-0001', 'QTN-0002', 'QTN-0003', 'QTN-0004', 'QTN-0005', 'QTN-0006', 'QTN-0007', 'QTN-0008', 'QTN-0009', 'QTN-00010'],
  'DOs': ['DO-0001', 'DO-0002', 'DO-0003', 'DO-0004', 'DO-0005', 'DO-0006', 'DO-0007', 'DO-0008', 'DO-0009', 'DO-00010'],
  'transfers': ['TR-26-001', 'TR-26-002', 'TR-26-003', 'TR-26-004', 'TR-26-005', 'TR-26-006', 'TR-26-007', 'TR-26-008', 'TR-26-009', 'TR-26-0010', 'TR-26-0011', 'TR-26-0012'],
  'cash counts': ['CC-26-001', 'CC-26-002', 'CC-26-003', 'CC-26-004', 'CC-26-005', 'CC-26-006', 'CC-26-007', 'CC-26-008', 'CC-26-009', 'CC-26-0010', 'CC-26-0011', 'CC-26-0012'],
  'quality': ['QC-0001', 'QC-0002', 'QC-0003', 'QC-0004', 'QC-0005', 'QC-0006', 'QC-0007', 'QC-0008', 'QC-0009', 'QC-00010', 'QC-00011', 'QC-00012'],
};

class FakeVouchers implements VoucherApi {
  const FakeVouchers();

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: [
        VoucherRow(id: 'v1', no: 'RV-2610-0142', type: 'receipt', state: 'awaiting', amount: 48500, stateLabel: 'অনুমোদনের অপেক্ষায়', party: 'রহিম ট্রেডার্স'),
        VoucherRow(id: 'v2', no: 'PV-2610-0057', type: 'payment', state: 'posted', amount: 125000, stateLabel: 'পাকা', party: 'মেসার্স রহমান ট্রেডার্স'),
        VoucherRow(id: 'v3', no: 'EV-2610-0031', type: 'expense', state: 'draft', amount: 3200, stateLabel: 'খসড়া', party: 'গাড়িভাড়া'),
        VoucherRow(id: 'v4', no: 'RV-2610-0143', type: 'receipt', state: 'posted', amount: 22000, stateLabel: 'পাকা', party: 'করিম স্টোর'),
        VoucherRow(id: 'v5', no: 'PV-2610-0058', type: 'payment', state: 'posted', amount: 75000, stateLabel: 'পাকা', party: 'আকিজ ফুড'),
        VoucherRow(id: 'v6', no: 'EV-2610-0032', type: 'expense', state: 'posted', amount: 1500, stateLabel: 'পাকা', party: 'চা-নাস্তা'),
      ], count: 37, total: 1842650);

  @override
  Future<VoucherSetup> setup(String type) => throw UnimplementedError();

  @override
  Future<VoucherPage> one(String id) => throw UnimplementedError();

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) => throw UnimplementedError();

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) => throw UnimplementedError();
}

class _Claims implements ClaimsApi {
  @override
  Future<List<ClaimHead>> heads() async => const [ClaimHead(id: 51, name: 'যাতায়াত খরচ')];

  @override
  Future<ClaimsPage> mine() async => ClaimsPage(claims: [
        for (var i = 1; i <= 10; i++)
          Claim(id: 'c$i', number: 'EXC-000$i', kind: 'expense', status: 'submitted', statusLabel: 'জমা', amount: i * 350.0, reason: 'যাতায়াত'),
      ], openAdvance: 2500);

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Leads implements LeadApi {
  @override
  Future<LeadSetup> setup() async => const LeadSetup(sources: [], statuses: []);

  @override
  Future<(List<Lead>, int?, int?)> list({String? query, String? status, int page = 1}) async => (
        [
          for (var i = 1; i <= 12; i++)
            Lead(
                id: 'l$i', no: 'LD-$i', name: 'নতুন মুদি $i', source: 'walk_in', sourceLabel: 'নিজে এসেছেন', status: 'new', statusLabel: 'নতুন',
                converted: false),
        ],
        null,
        12,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Quotes implements QuotationApi {
  @override
  Future<(List<Quotation>, int?, PaperTotal?)> list({String? status, int page = 1}) async => (
        [
          for (var i = 1; i <= 10; i++)
            Quotation(
                id: 'q$i', no: 'QTN-000$i', customer: 'রহিম স্টোর', status: 'approved', statusLabel: 'অনুমোদিত', total: i * 400.0, can: const {}, lines: const []),
        ],
        null,
        const PaperTotal(sum: 22000, count: 10),
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class FakeDos implements DeliveryOrderApi {
  @override
  bool get forOrders => false;

  @override
  Future<(List<DeliveryOrder>, PaperTotal?, int?)> list({bool awaitingMe = false, int page = 1}) async => (
        [
          for (var i = 1; i <= 10; i++)
            DeliveryOrder(
                id: 'do-$i', no: 'DO-000$i', date: '2026-10-03', customer: 'মেসার্স রহমান ট্রেডার্স অ্যান্ড সন্স $i · ফুলবাড়িয়া · ময়মনসিংহ', status: i == 3 ? 'rejected' : 'approved',
                statusLabel: i == 3 ? 'প্রত্যাখ্যাত' : 'অনুমোদিত', total: i * 12500.0, editable: false, awaitingMe: false),
        ],
        const PaperTotal(sum: 1842650.5, count: 37),
        null,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Money implements MoneyApi {
  _Money(this.answers);

  final Map<String, Map<String, dynamic>> answers;

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, Object?>? query}) async => answers[path] ?? const {};

  @override
  Future<MoneyResult> post(String path, Map<String, Object?> body) async => const MoneyResult('done', '');
}

class _Leave implements SelfServiceApi {
  @override
  Future<LeaveBook> leave() async => LeaveBook(employee: true, types: const [
        LeaveKind(id: '1', name: 'নৈমিত্তিক ছুটি', left: '8', allowed: '10', taken: '2'),
        LeaveKind(id: '2', name: 'অসুস্থতার ছুটি', left: '12', allowed: '14', taken: '2'),
      ], applications: [
        for (var i = 1; i <= 10; i++)
          LeaveRequest(id: 'r$i', type: 'নৈমিত্তিক ছুটি', fromDate: '0$i-10-2026', toDate: '0$i-10-2026', days: '1', status: 'approved', reason: 'পারিবারিক'),
      ]);

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Qs implements QualitySerialApi {
  @override
  Future<(List<InspectionRow>, List<StockChoice>, int?, int?)> inspections({String? status, int page = 1}) async => (
        [
          for (var i = 1; i <= 12; i++)
            InspectionRow(id: 'q$i', no: 'QC-000$i', product: 'কসমস বিস্কুট', qty: '$i', status: 'pending', statusLabel: 'অপেক্ষায়'),
        ],
        const <StockChoice>[],
        null,
        12,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Delegations implements ApprovalRowsApi {
  @override
  Future<DelegationSetup> delegations() async => DelegationSetup({
        'given': [
          for (var i = 1; i <= 6; i++)
            {'id': 'd$i', 'person': 'করিম $i', 'starts_on': '17-10-2026', 'ends_on': '23-10-2026', 'modules': [], 'reason': 'ছুটিতে', 'revoked': false, 'active': true},
        ],
        'held': [
          for (var i = 1; i <= 4; i++)
            {'id': 'h$i', 'person': 'মালিক', 'starts_on': '15-10-2026', 'ends_on': '20-10-2026', 'modules': ['বিক্রয়'], 'active': true},
        ],
        'people': [
          {'id': 'u-karim', 'name': 'করিম'},
        ],
        'modules': [
          {'code': 'sales', 'label': 'বিক্রয়'},
        ],
      });

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
