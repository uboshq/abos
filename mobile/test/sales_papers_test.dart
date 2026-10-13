import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/navigation/recent_papers.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/sales_papers/sales_paper_screens.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিক, ১৩ অক্টোবর ২০২৬: *"Mobile App e kora invoice, challan, voucher — kono kichuri list show korche na"*।
/// বিক্রয় বিল আর চালানের তালিকা ও পাতা; আর মালিকের নিজের `/me`-র আকারে ভাউচারের টাইল আর সব ধরনের তালিকা।
class _FakePapers implements SalesPaperApi {
  _FakePapers({this.empty = false});

  final bool empty;
  final List<String> asked = [];

  @override
  Future<SalesPaperPage> list(SalesPaperKind kind,
      {required String from, required String to, String q = '', int page = 1}) async {
    asked.add('${kind.name}|$from|$to|$q');
    if (empty) return const SalesPaperPage(rows: []);
    return SalesPaperPage(
      rows: [
        if (kind == SalesPaperKind.invoice) ...const [
          SalesPaperRow(id: 'inv-2', no: 'INV-0059', saleNo: 'S-0059', date: '2026-10-13', customer: 'রহিম স্টোর',
              point: 'চরপাড়া', status: 'confirmed', total: '3000.00', due: '1000.00'),
          SalesPaperRow(id: 'inv-1', no: 'INV-0058', date: '2026-10-12', customer: 'করিম স্টোর',
              status: 'confirmed', total: '500.00', due: '0.00'),
        ] else
          const SalesPaperRow(id: 'cha-2', no: 'CHA-0059', date: '2026-10-13', customer: 'রহিম স্টোর',
              status: 'confirmed', total: '3000.00'),
      ],
      count: 2,
      sum: '3500.00',
    );
  }

  @override
  Future<SalesPaperDetail> one(SalesPaperKind kind, String id) async => SalesPaperDetail.fromJson({
        'id': id,
        'no': kind == SalesPaperKind.invoice ? 'INV-0059' : 'CHA-0059',
        'date': '2026-10-13',
        'status': 'confirmed',
        'status_label': 'Confirmed',
        'customer': 'রহিম স্টোর',
        'lines': [
          {'name': 'কসমস বিস্কুট', 'qty': '2.0000', 'unit': 'কার্টন', 'rate': '1500.00', 'amount': '3000.00', 'free_qty': '1.0000'},
        ],
        'total': '3000.00',
        if (kind == SalesPaperKind.invoice) ...{
          'collected': '2000.00',
          'due': '1000.00',
          'challans': [
            {'id': 'cha-2', 'no': 'CHA-0059'},
          ],
        } else
          'bills': [
            {'id': 'inv-2', 'no': 'INV-0059'},
          ],
      });
}

class _FakeVouchers implements VoucherApi {
  final List<String?> askedTypes = [];

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1}) async {
    askedTypes.add(type);
    return VoucherListPage(rows: [
      for (final t in const ['receipt', 'payment', 'expense', 'journal', 'contra'])
        VoucherRow.fromJson({
          'id': 'v-$t',
          'no': '${t.substring(0, 2).toUpperCase()}-0001',
          'type': t,
          'type_label': '',
          'date': '2026-10-13',
          'amount': '100.00',
          'state': 'posted',
          'state_label': 'পাকা',
          'party': null,
          'party_label': 'পক্ষ',
          'narration': '',
        }),
    ], count: 118);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  testWidgets('the bill list asks this month by default and shows each bill, its due, and the total', (tester) async {
    final api = _FakePapers();
    await tester.pumpWidget(MaterialApp(
        home: SalesPaperListScreen(kind: SalesPaperKind.invoice, api: api, today: DateTime(2026, 10, 13))));
    await tester.pumpAndSettle();

    expect(api.asked.single, 'invoice|2026-10-01|2026-10-13|');
    expect(find.text('বিক্রয় বিল'), findsOneWidget);
    expect(find.text('INV-0059 · S-0059'), findsOneWidget);
    expect(find.text('বাকি ${Money.taka('1000')}'), findsOneWidget);
    expect(find.text('শোধ'), findsOneWidget);
    expect(find.text(Money.taka('3500')), findsOneWidget);
  });

  testWidgets('the challan list and a search go to the server', (tester) async {
    final api = _FakePapers();
    await tester.pumpWidget(MaterialApp(
        home: SalesPaperListScreen(kind: SalesPaperKind.challan, api: api, today: DateTime(2026, 10, 13))));
    await tester.pumpAndSettle();
    expect(find.text('CHA-0059'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('papers-search')), 'রহিম');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.asked.last, 'challan|2026-10-01|2026-10-13|রহিম');
  });

  testWidgets('nothing this month is an empty state that a pull refreshes', (tester) async {
    await tester.pumpWidget(MaterialApp(
        home: SalesPaperListScreen(kind: SalesPaperKind.invoice, api: _FakePapers(empty: true), today: DateTime(2026, 10, 13))));
    await tester.pumpAndSettle();
    expect(find.text('এই সময়ে কোনো বিক্রয় বিল নেই'), findsOneWidget);
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
  });

  testWidgets('a bill\'s page: lines, collected, due in Bengali, and its challan', (tester) async {
    await tester.pumpWidget(MaterialApp(home: SalesPaperScreen(kind: SalesPaperKind.invoice, id: 'inv-2', api: _FakePapers())));
    await tester.pumpAndSettle();

    expect(find.text('বিক্রয় বিল · INV-0059'), findsOneWidget);
    expect(find.text('নিশ্চিত'), findsOneWidget);
    expect(find.text('Confirmed'), findsNothing);
    expect(find.text('কসমস বিস্কুট'), findsOneWidget);
    expect(find.textContaining('ফ্রি 1'), findsOneWidget);
    expect(find.text(Money.taka('2000')), findsOneWidget);
    expect(find.text(Money.taka('1000')), findsOneWidget);
    expect(find.text('এই বিলের চালান'), findsOneWidget);
    expect(find.byKey(const ValueKey('paper-link-cha-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('paper-print')), findsOneWidget);
  });

  testWidgets('a challan\'s page names its bill and shows no due', (tester) async {
    await tester.pumpWidget(MaterialApp(home: SalesPaperScreen(kind: SalesPaperKind.challan, id: 'cha-2', api: _FakePapers())));
    await tester.pumpAndSettle();
    expect(find.text('এই চালানের বিল'), findsOneWidget);
    expect(find.text('INV-0059'), findsOneWidget);
    expect(find.text('বাকি'), findsNothing);
  });

  group('the doors', () {
    test('both lists hang under বিক্রি → লেনদেন with their own view keys, behind the sales switch', () {
      const user = AuthUser(
          id: 'u', name: 'x', email: 'x@x', roles: [], permissions: ['sales.invoice.view', 'sales.challan.view']);
      final tree = NavTree.from(const MenuRepository().ordered(const [], user));
      final deal = tree.module('sales')!.groups.firstWhere((g) => g.title == 'লেনদেন');
      expect(deal.rows.map((r) => r.path), containsAll(['invoices', 'challans']));

      const none = AuthUser(id: 'u', name: 'x', email: 'x@x', roles: [], permissions: ['sales.order.view']);
      final paths = const MenuRepository().ordered(const [], none).map((i) => i.routeName);
      expect(paths, isNot(contains('invoices')));
      expect(paths, isNot(contains('challans')));

      expect(ModuleGate.allows({'accounts'}, 'invoices'), isFalse);
      expect(ModuleGate.allows({'sales'}, 'challans'), isTrue);
    });

    test('an opened bill or challan goes into সাম্প্রতিক behind the list\'s own door', () {
      expect(RecentPapers.path('invoice', 'inv-2'), 'paper/invoice/inv-2');
      expect(RecentPapers.listPathOf('paper/invoice/inv-2'), 'invoices');
      expect(RecentPapers.listPathOf('paper/challan/cha-2'), 'challans');
    });
  });

  group('vouchers, with the owner\'s own /me (live, 13 Oct 2026)', () {
    // ⓘ মালিকের /me — super_admin, accounts.* চাবি, আর এই সাতটা ফোন-সুইচ
    const phoneModules = {'accounts', 'approval', 'customer', 'inventory', 'sales', 'purchase', 'system_admin'};
    const owner = AuthUser(
      id: '1',
      name: 'মালিক',
      email: 'owner@x',
      roles: ['super_admin'],
      permissions: [
        'accounts.view', 'accounts.report', 'accounts.voucher.create', 'accounts.voucher.update', 'accounts.voucher.post',
        'sales.order.view', 'sales.invoice.view', 'sales.challan.view', 'customer.view',
      ],
    );

    test('the ভাউচার tile shows under হিসাব', () {
      final items = ModuleGate.visible(const MenuRepository().ordered(const [], owner), phoneModules);
      expect(items.map((i) => i.routeName), contains('vouchers'));
      expect(NavTree.from(items).module('accounts')!.screens.map((r) => r.path), contains('vouchers'));
      expect(items.map((i) => i.routeName), containsAll(['invoices', 'challans']));
    });

    testWidgets('the list asks for every type and draws all five', (tester) async {
      final api = _FakeVouchers();
      await tester.pumpWidget(MaterialApp(home: VoucherListScreen(api: api, newChangeId: () => 'k')));
      await tester.pumpAndSettle();

      expect(api.askedTypes.first, isNull, reason: 'no type filter by default — every voucher, not only phone-made');
      for (final no in ['RE-0001', 'PA-0001', 'EX-0001', 'JO-0001', 'CO-0001']) {
        await tester.scrollUntilVisible(find.textContaining(no), 120);
        expect(find.textContaining(no), findsWidgets, reason: no);
      }
    });
  });
}
