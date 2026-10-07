import 'package:abos_mobile/core/api_client/once_key.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/hr/claims_api.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/features/claims/claims_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ খরচের দাবি আর অগ্রিম (0.4.22) — টাকা-আসা-যাওয়ার পরিকল্পনা ১৩, ৭ অক্টোবর ২০২৬; সার্ভার f727b846
/// (AnEmployeeAsksForMoneyTest)।
class _FakeClaims implements ClaimsApi {
  _FakeClaims({this.claims = const [], this.openAdvance});

  final List<Claim> claims;
  final double? openAdvance;
  ClaimDraft? sent;
  String? sentKey;

  @override
  Future<List<ClaimHead>> heads() async =>
      const [ClaimHead(id: 51, name: 'যাতায়াত খরচ'), ClaimHead(id: 52, name: 'আপ্যায়ন')];

  @override
  Future<ClaimsPage> mine() async => ClaimsPage(claims: claims, openAdvance: openAdvance);

  @override
  Future<Claim> one(String id) async => claims.firstWhere((c) => c.id == id);

  @override
  Future<Claim> send(ClaimDraft draft) async {
    sent = draft;
    sentKey = OnceKey.current;
    return Claim(id: 'new', number: 'EXC-0009', kind: draft.kind, status: 'submitted', amount: double.parse(draft.amount));
  }
}

Future<void> _pump(WidgetTester tester, Widget screen) async {
  tester.view.physicalSize = const Size(800, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

void main() {
  test('the tile comes with hr.claim.self, and the HR switch closes it', () async {
    const repository = MenuRepository();
    const worker = AuthUser(id: '1', name: 'SR', email: 's@abos.test', roles: ['salesman'], permissions: ['hr.claim.self']);
    const other = AuthUser(id: '2', name: 'Office', email: 'o@abos.test', roles: ['accountant'], permissions: ['customer.view']);

    expect((await repository.menuFor(worker)).map((i) => i.key), contains('hr.claim.self'));
    expect((await repository.menuFor(other)).map((i) => i.key), isNot(contains('hr.claim.self')));
    expect(ModuleGate.moduleOfPath['claims'], 'hr');

    // ⛔ ফোনে HR বন্ধ (লাইভে mobile.modules.hr বসানো নেই) — টাইলই নেই, ৪০৩-এর বার্তা নয় (সমন্বয়ক, ৭ অক্টোবর ২০২৬)
    final tiles = await repository.menuFor(worker);
    expect(ModuleGate.visible(tiles, {'sales'}).map((i) => i.key), isNot(contains('hr.claim.self')));
    expect(ModuleGate.visible(tiles, {'sales', 'hr'}).map((i) => i.key), contains('hr.claim.self'));
    expect(ModuleGate.allows({'sales'}, 'claims'), isFalse);
  });

  test('the server row reads; the label is the server\'s, never a raw key', () {
    final c = Claim.fromJson({
      'id': 'c1', 'number': 'EXC-0001', 'kind': 'expense', 'status': 'approved', 'status_label': 'অনুমোদিত',
      'amount': '1200.00', 'from_advance': '500.00', 'cash': '700.00', 'head': {'id': 51, 'name': 'যাতায়াত খরচ'},
      'spent_on': '2026-10-06', 'reason': 'বাজারে যাওয়া',
    });
    expect(c.amount, 1200);
    expect(c.fromAdvance, 500);
    expect(c.cash, 700);
    expect(c.head, 'যাতায়াত খরচ');
    expect(c.stateLabel, 'অনুমোদিত');
    expect(Claim.fromJson({'status': 'paid'}).stateLabel, 'পরিশোধিত');
    // ⛔ নতুন দাবি সইয়ের আগে — "submitted" (cb b1a1e7a2, ৭ অক্টোবর ২০২৬: মালিকের সই ছাড়া পার নয়); কখনো "অনুমোদিত" নয়
    expect(Claim.fromJson({'status': 'submitted'}).stateLabel, 'সইয়ের অপেক্ষায়');
    expect(Claim.fromJson({'status': 'submitted'}).stateLabel, isNot(contains('অনুমোদিত')));
    expect(Claim.fromJson({'status': 'somethingNew'}).stateLabel, 'অপেক্ষায়');
  });

  test('an advance sends no head, date or receipt; an expense sends them', () {
    final advance = ClaimDraft(kind: 'advance', amount: '3000', reason: 'বাজার', headId: 51, spentOn: DateTime(2026, 10, 6)).toFields();
    expect(advance.containsKey('expense_account_id'), isFalse);
    expect(advance.containsKey('spent_on'), isFalse);

    final expense = ClaimDraft(kind: 'expense', amount: '250.50', reason: 'রিকশা', headId: 51, spentOn: DateTime(2026, 10, 6)).toFields();
    expect(expense['expense_account_id'], '51');
    expect(expense['spent_on'], '2026-10-06');
  });

  testWidgets('the list shows the open advance and each claim\'s state', (tester) async {
    await _pump(
        tester,
        ClaimListScreen(
          api: _FakeClaims(openAdvance: 2500, claims: const [
            Claim(id: 'a', number: 'EXC-0001', kind: 'advance', status: 'paid', amount: 3000, statusLabel: 'পরিশোধিত'),
            Claim(id: 'b', number: 'EXC-0002', kind: 'expense', status: 'rejected', amount: 400, statusLabel: 'প্রত্যাখ্যাত'),
          ]),
        ));

    expect(find.byKey(const ValueKey('claim-open-advance')), findsOneWidget);
    expect(find.textContaining('2,500'), findsOneWidget);
    expect(find.text('EXC-0001 · অগ্রিম'), findsOneWidget);
    expect(find.text('পরিশোধিত'), findsOneWidget);
    expect(find.text('প্রত্যাখ্যাত'), findsOneWidget);
  });

  testWidgets('nothing is sent without a head, a reason or a proper amount; then it is', (tester) async {
    final api = _FakeClaims();
    await _pump(tester, NewClaimScreen(api: api, picker: () async => '/tmp/r.jpg', today: DateTime(2026, 10, 7)));

    await tester.enterText(find.byKey(const ValueKey('claim-amount')), '250.50');
    await tester.enterText(find.byKey(const ValueKey('claim-reason')), 'রিকশা ভাড়া');
    await tester.tap(find.byKey(const ValueKey('claim-send')));
    await tester.pumpAndSettle();
    expect(find.text('কোন খাতের খরচ, বাছুন।'), findsOneWidget);
    expect(api.sent, isNull, reason: '⛔ খাত ছাড়া খরচের দাবি গেল');

    await tester.tap(find.byKey(const ValueKey('claim-head')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('যাতায়াত খরচ').last);
    await tester.pumpAndSettle();

    // ⓘ খাত বাছা, কারণ লেখা — এবার কেবল অঙ্কটাই ভুল
    await tester.enterText(find.byKey(const ValueKey('claim-amount')), '1e5');
    await tester.tap(find.byKey(const ValueKey('claim-send')));
    await tester.pumpAndSettle();
    expect(find.textContaining('টাকার অঙ্ক ঠিকভাবে লিখুন'), findsOneWidget);
    expect(api.sent, isNull, reason: '⛔ ভুল অঙ্কে পাঠানো হলো');

    await tester.enterText(find.byKey(const ValueKey('claim-amount')), '250.50');
    await tester.tap(find.byKey(const ValueKey('claim-receipt')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('claim-send')));
    await tester.pumpAndSettle();

    expect(api.sent, isNotNull);
    expect(api.sent!.toFields()['expense_account_id'], '51');
    expect(api.sent!.toFields()['spent_on'], '2026-10-07');
    expect(api.sent!.receiptPath, '/tmp/r.jpg');
    expect(api.sentKey, isNotNull, reason: '⛔ দাবি চাবি ছাড়া গেল (অডিট ফোন ⚠️১২)');
  });

  testWidgets('an advance needs no head and shows no receipt button', (tester) async {
    final api = _FakeClaims();
    await _pump(tester, NewClaimScreen(api: api));

    await tester.tap(find.text('অগ্রিম চাই'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('claim-head')), findsNothing);
    expect(find.byKey(const ValueKey('claim-receipt')), findsNothing);

    await tester.enterText(find.byKey(const ValueKey('claim-amount')), '3000');
    await tester.tap(find.byKey(const ValueKey('claim-send')));
    await tester.pumpAndSettle();
    expect(find.text('কারণ লিখুন।'), findsOneWidget);
    expect(api.sent, isNull);

    await tester.enterText(find.byKey(const ValueKey('claim-reason')), 'বাজারের খরচ');
    await tester.tap(find.byKey(const ValueKey('claim-send')));
    await tester.pumpAndSettle();
    expect(api.sent!.kind, 'advance');
    expect(api.sent!.toFields().containsKey('expense_account_id'), isFalse);
  });

  testWidgets('a claim page shows what came from the advance and what comes in cash', (tester) async {
    await _pump(
        tester,
        ClaimScreen(
          id: 'c',
          api: _FakeClaims(claims: const [
            Claim(id: 'c', number: 'EXC-0003', kind: 'expense', status: 'approved', amount: 1200, fromAdvance: 500, cash: 700, head: 'যাতায়াত খরচ', reason: 'বাজারে যাওয়া'),
          ]),
        ));

    expect(find.text('অগ্রিম থেকে মিটেছে'), findsOneWidget);
    expect(find.text('নগদে পাবেন'), findsOneWidget);
    expect(find.textContaining('700'), findsOneWidget);
  });
}
