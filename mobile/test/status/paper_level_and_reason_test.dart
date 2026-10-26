import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/hr/claims_api.dart';
import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart' show PushOutcome;
import 'package:abos_mobile/features/claims/claims_screens.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';

/// ⭐ সইয়ের ধাপ আর আটকে-থাকা পোস্টের কারণ — সার্ভারের নতুন ঘর (a6/paper-level-reason, ২০ অক্টোবর ২০২৬):
/// `approval_level` · `approval_levels` (ভাউচার আর দাবি), `posting_failed` · `post_problem` (কেবল ভাউচার)। পুরনো সার্ভার
/// এগুলো পাঠায় না — তখনো সব আগের মতো চলে।
Finder _words(String words) => find.byWidgetPredicate(
    (w) => w is RichText && w.text.toPlainText().replaceAll('￼', '') == words,
    description: 'chip text "$words"');

Finder _chip(PaperState s) => find.byWidgetPredicate((w) => w is AppStatusChip && w.state == s);

const _stuck = <String, dynamic>{
  'id': 'v9', 'no': 'PV-0009', 'type': 'payment', 'state': 'draft', 'state_label': 'খসড়া', 'amount': '1200.00',
  'posting_failed': true, 'post_problem': 'রহিম স্টোরের বাকির সীমা পার হয়েছে, তাই খাতায় ওঠেনি।',
};

const _waiting = <String, dynamic>{
  'id': 'v1', 'no': 'RV-0001', 'type': 'receipt', 'state': 'awaiting', 'state_label': 'অনুমোদনের অপেক্ষায়', 'amount': '500.00',
  'approval_level': 1, 'approval_levels': 2,
};

Future<void> _pump(WidgetTester tester, Widget screen) async {
  tester.view.physicalSize = const Size(800, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

void main() {
  group('parsing', () {
    test('a voucher row reads the level, the levels and a stuck posting', () {
      final w = VoucherRow.fromJson(Map<String, dynamic>.from(_waiting));
      expect((w.approvalLevel, w.approvalLevels), (1, 2));
      expect(w.postingFailed, isFalse);
      final s = VoucherRow.fromJson(Map<String, dynamic>.from(_stuck));
      expect(s.postingFailed, isTrue);
      expect(s.postProblem, 'রহিম স্টোরের বাকির সীমা পার হয়েছে, তাই খাতায় ওঠেনি।');
      expect(s.state, 'draft', reason: 'the server keeps it a draft');
    });

    test('an old answer without the fields still reads: null levels, not stuck', () {
      final old = VoucherRow.fromJson({'id': 'v', 'no': 'JV-1', 'type': 'journal', 'state': 'awaiting', 'amount': '1'});
      expect((old.approvalLevel, old.approvalLevels, old.postingFailed, old.postProblem), (null, null, false, null));
      final nulls = VoucherRow.fromJson(
          {'id': 'v', 'state': 'awaiting', 'approval_level': null, 'approval_levels': null, 'posting_failed': null, 'post_problem': null});
      expect((nulls.approvalLevel, nulls.approvalLevels, nulls.postingFailed, nulls.postProblem), (null, null, false, null));
      expect(VoucherRow.fromJson({'id': 'v', 'state': 'draft', 'post_problem': '  '}).postProblem, isNull);
    });

    test('the page and the post answer read the same fields (the page is a row too)', () {
      final page = VoucherPage.fromJson({..._stuck, 'can_post': true});
      expect(page.row.postingFailed, isTrue);
      expect(page.canPost, isTrue);
    });

    test('a claim reads its level and levels, and an old claim has none', () {
      final c = Claim.fromJson({'id': 'c', 'status': 'submitted', 'approval_level': '2', 'approval_levels': 3});
      expect((c.approvalLevel, c.approvalLevels), (2, 3));
      final old = Claim.fromJson({'id': 'c', 'status': 'submitted'});
      expect((old.approvalLevel, old.approvalLevels), (null, null));
    });
  });

  group('the voucher chip', () {
    Widget app(Widget w) => MaterialApp(home: Scaffold(body: Center(child: w)));

    testWidgets('waiting with both numbers says "2-এর 1"; the page says it in full', (tester) async {
      final v = VoucherRow.fromJson(Map<String, dynamic>.from(_waiting));
      await tester.pumpWidget(app(Column(children: [voucherStateChip(v, dense: true), voucherStateChip(v)])));
      expect(_words('2-এর 1'), findsOneWidget);
      expect(_words('অনুমোদনের অপেক্ষায় · 2-এর 1'), findsOneWidget);
    });

    testWidgets('only one of the two numbers is no "x-এর y" at all', (tester) async {
      final v = VoucherRow.fromJson({..._waiting, 'approval_levels': null});
      await tester.pumpWidget(app(voucherStateChip(v, dense: true)));
      expect(_words('অনুমোদনের অপেক্ষায়'), findsOneWidget);
    });

    testWidgets('posting_failed wins over the draft state: "পোস্ট হয়নি", and the tap shows the server\'s sentence',
        (tester) async {
      final v = VoucherRow.fromJson(Map<String, dynamic>.from(_stuck));
      await tester.pumpWidget(app(voucherStateChip(v, dense: true)));
      expect(_chip(PaperState.notPosted), findsOneWidget);
      expect(_chip(PaperState.draft), findsNothing);
      await tester.tap(find.byType(AppStatusChip));
      await tester.pumpAndSettle();
      expect(find.text('রহিম স্টোরের বাকির সীমা পার হয়েছে, তাই খাতায় ওঠেনি।'), findsOneWidget);
    });

    testWidgets('an old answer: a draft is a draft, waiting is plain waiting', (tester) async {
      await tester.pumpWidget(app(Column(children: [
        voucherStateChip(VoucherRow.fromJson({'id': 'a', 'state': 'draft'}), dense: true),
        voucherStateChip(VoucherRow.fromJson({'id': 'b', 'state': 'awaiting'}), dense: true),
      ])));
      expect(_chip(PaperState.draft), findsOneWidget);
      expect(_words('অনুমোদনের অপেক্ষায়'), findsOneWidget);
    });
  });

  testWidgets('the voucher list: the stuck one is "পোস্ট হয়নি", the waiting one "2-এর 1"', (tester) async {
    await _pump(tester, VoucherListScreen(api: _Vouchers(), newChangeId: () => 'k', branches: () async => const []));
    expect(_chip(PaperState.notPosted), findsOneWidget);
    expect(_words('2-এর 1'), findsOneWidget);
  });

  testWidgets('the stuck voucher\'s page: "পোস্ট হয়নি" with its reason, and the post button stays', (tester) async {
    await _pump(tester, VoucherScreen(id: 'v9', api: _Vouchers()));
    expect(_chip(PaperState.notPosted), findsOneWidget);
    expect(find.byKey(const ValueKey('voucher-post')), findsOneWidget, reason: 'can_post stays true: try again');
    await tester.tap(find.byType(AppStatusChip));
    await tester.pumpAndSettle();
    expect(find.text('রহিম স্টোরের বাকির সীমা পার হয়েছে, তাই খাতায় ওঠেনি।'), findsOneWidget);
  });

  testWidgets('the claim list: a claim waiting at level 1 of 2 says "2-এর 1"; an old one plain waiting', (tester) async {
    await _pump(
        tester,
        ClaimListScreen(
          api: _Claims([
            Claim.fromJson({'id': 'c1', 'number': 'EXC-1', 'status': 'submitted', 'amount': '100', 'approval_level': 1, 'approval_levels': 2}),
            Claim.fromJson({'id': 'c2', 'number': 'EXC-2', 'status': 'submitted', 'amount': '200'}),
          ]),
        ));
    expect(_words('2-এর 1'), findsOneWidget);
    expect(_words('অনুমোদনের অপেক্ষায়'), findsOneWidget);
    expect(_chip(PaperState.notPosted), findsNothing, reason: 'claims never stick in posting');
  });
}

class _Vouchers implements VoucherApi {
  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      VoucherListPage(rows: [
        VoucherRow.fromJson(Map<String, dynamic>.from(_stuck)),
        VoucherRow.fromJson(Map<String, dynamic>.from(_waiting)),
      ], count: 2, total: 1700);

  @override
  Future<VoucherPage> one(String id) async => VoucherPage.fromJson({..._stuck, 'can_post': true});

  @override
  Future<VoucherSetup> setup(String type) => throw UnimplementedError();

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) => throw UnimplementedError();

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) => throw UnimplementedError();
}

class _Claims implements ClaimsApi {
  _Claims(this.claims);

  final List<Claim> claims;

  @override
  Future<List<ClaimHead>> heads() async => const [];

  @override
  Future<ClaimsPage> mine() async => ClaimsPage(claims: claims);

  @override
  Future<Claim> one(String id) async => claims.firstWhere((c) => c.id == id);

  @override
  Future<Claim> send(ClaimDraft draft) => throw UnimplementedError();
}
