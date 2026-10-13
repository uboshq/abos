import 'package:abos_mobile/core/hr/self_service_api.dart';
import 'package:abos_mobile/core/promotion/offers_api.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/features/promotion/offers_screen.dart';
import 'package:abos_mobile/features/self_service/self_service_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ চলতি অফার, নিজের ছুটি আর নিজের বেতনের স্লিপ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
class _FakeOffers implements OffersApi {
  _FakeOffers({this.enabled = true});

  final bool enabled;

  @override
  Future<OffersPage> running() async => OffersPage(enabled: enabled, rows: enabled
      ? const [
          Offer(
            id: 'o1',
            code: 'PROM-1',
            name: 'শীতের অফার',
            products: ['মিনিকেট চাল'],
            endsOn: '2026-10-15',
            steps: [
              OfferStep(kind: 'quantity', from: '10', benefits: [OfferBenefit(kind: 'goods', amount: '1', gift: 'মিনিকেট চাল')]),
            ],
          ),
          Offer(
            id: 'o2',
            code: 'PROM-2',
            name: 'তেলের ছাড়',
            brands: ['রূপচাঁদা'],
            shops: ['রহিম স্টোর'],
            endsOn: '2026-10-31',
            benefits: [OfferBenefit(kind: 'percent', amount: '5')],
          ),
        ]
      : const []);
}

class _FakeSelf implements SelfServiceApi {
  _FakeSelf({this.employee = true});

  final bool employee;
  final List<LeaveDraft> sent = [];

  @override
  Future<LeaveBook> leave() async => LeaveBook(
        employee: employee,
        types: employee ? const [LeaveKind(id: 't1', name: 'বার্ষিক', left: '4.0', allowed: '5', taken: '1.0')] : const [],
        applications: [
          if (employee)
            const LeaveRequest(id: 'a1', type: 'বার্ষিক', fromDate: '2026-10-20', toDate: '2026-10-21', days: '2.0', status: 'pending'),
          for (final d in sent)
            LeaveRequest(id: 'new', type: 'বার্ষিক', fromDate: '2026-10-25', toDate: '2026-10-25', days: d.days, status: 'pending'),
        ],
      );

  @override
  Future<LeaveRequest> applyLeave(LeaveDraft draft) async {
    sent.add(draft);
    return LeaveRequest(id: 'new', type: 'বার্ষিক', days: draft.days, status: 'pending');
  }

  @override
  Future<PayslipPage> payslips({int page = 1}) async => PayslipPage(
        employee: employee,
        rows: employee ? const [Payslip(id: 's1', month: '2026-09-01', gross: 30000, deductions: 2000, net: 28000)] : const [],
      );

  @override
  Future<Payslip> payslip(String id) async => const Payslip(
        id: 's1',
        month: '2026-09-01',
        gross: 30000,
        deductions: 2000,
        net: 28000,
        name: 'করিম',
        code: 'E-1',
        paymentMethod: 'cash',
        lines: [
          PayslipLineItem(name: 'মূল বেতন', earning: true, amount: 30000),
          PayslipLineItem(name: 'ভবিষ্য তহবিল', earning: false, amount: 2000),
        ],
      );
}

void main() {
  group('offers', () {
    testWidgets('each offer says on what, what for how much, for whom, and till when', (tester) async {
      await tester.pumpWidget(MaterialApp(home: OffersScreen(api: _FakeOffers(), today: DateTime(2026, 10, 13))));
      await tester.pumpAndSettle();

      expect(find.text('শীতের অফার'), findsOneWidget);
      expect(find.text('• 10টা বা বেশি কিনলে → 1টা মিনিকেট চাল ফ্রি'), findsOneWidget);
      expect(find.text('• 5% ছাড়'), findsOneWidget);
      expect(find.text('রূপচাঁদা (ব্র্যান্ড)'), findsOneWidget);
      expect(find.text('দোকান: রহিম স্টোর'), findsOneWidget);
      expect(find.text('15/10/2026 · আর 2 দিন'), findsOneWidget);
    });

    testWidgets('searching a product keeps only its offers', (tester) async {
      await tester.pumpWidget(MaterialApp(home: OffersScreen(api: _FakeOffers(), today: DateTime(2026, 10, 13))));
      await tester.pumpAndSettle();

      await tester.enterText(find.byKey(const ValueKey('offer-search')), 'রূপচাঁদা');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pumpAndSettle();
      expect(find.text('তেলের ছাড়'), findsOneWidget);
      expect(find.text('শীতের অফার'), findsNothing);
    });

    testWidgets('with offers switched off in this branch it says so', (tester) async {
      await tester.pumpWidget(MaterialApp(home: OffersScreen(api: _FakeOffers(enabled: false))));
      await tester.pumpAndSettle();
      expect(find.text('এই শাখায় অফার এখন বন্ধ'), findsOneWidget);
    });
  });

  test('a rule reads as a sentence, the unit on the number', () {
    expect(const OfferStep(kind: 'quantity', from: '10', to: '24').condition, '10টা–24টা কিনলে');
    expect(const OfferStep(kind: 'value', from: '5000').condition, '৳5000 বা বেশি কিনলে');
    expect(const OfferBenefit(kind: 'amount', amount: '50', cap: '500').words, '৳50 ছাড় (এক বিলে সর্বোচ্চ ৳500)');
  });

  group('leave', () {
    testWidgets('shows what is left and my applications with their state', (tester) async {
      await tester.pumpWidget(MaterialApp(home: LeaveScreen(api: _FakeSelf())));
      await tester.pumpAndSettle();

      expect(find.text('4.0 দিন বাকি · মোট 5'), findsOneWidget);
      expect(find.text('অনুমোদনের অপেক্ষায়'), findsOneWidget);
      expect(find.byKey(const ValueKey('leave-new')), findsOneWidget);
    });

    testWidgets('a new application checks the days before sending and then sends once', (tester) async {
      final api = _FakeSelf();
      await tester.pumpWidget(MaterialApp(
          home: NewLeaveScreen(api: api, types: const [LeaveKind(id: 't1', name: 'বার্ষিক', left: '4.0')], today: DateTime(2026, 10, 13))));
      await tester.pumpAndSettle();

      await tester.enterText(find.byKey(const ValueKey('leave-days')), '3');
      await tester.tap(find.byKey(const ValueKey('leave-send')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('leave-error')), findsOneWidget, reason: 'one calendar day cannot hold three days of leave');
      expect(api.sent, isEmpty);

      await tester.enterText(find.byKey(const ValueKey('leave-days')), '0.5');
      await tester.enterText(find.byKey(const ValueKey('leave-reason')), 'ডাক্তার');
      await tester.tap(find.byKey(const ValueKey('leave-send')));
      await tester.pumpAndSettle();
      expect(api.sent.single.days, '0.5');
      expect(api.sent.single.typeId, 't1');
      expect(api.sent.single.reason, 'ডাক্তার');
    });

    testWidgets('without an employee record it explains instead of offering a form', (tester) async {
      await tester.pumpWidget(MaterialApp(home: LeaveScreen(api: _FakeSelf(employee: false))));
      await tester.pumpAndSettle();
      expect(find.text('আপনার নামে কর্মীর খাতা নেই'), findsOneWidget);
      expect(find.byKey(const ValueKey('leave-new')), findsNothing);
    });
  });

  group('payslips', () {
    testWidgets('the month list opens one slip with its earnings and cuts', (tester) async {
      await tester.pumpWidget(MaterialApp(home: PayslipListScreen(api: _FakeSelf())));
      await tester.pumpAndSettle();

      expect(find.text('সেপ্টেম্বর 2026'), findsOneWidget);
      expect(find.text(Money.taka(28000)), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('payslip-s1')));
      await tester.pumpAndSettle();
      expect(find.text('মূল বেতন'), findsOneWidget);
      expect(find.text('ভবিষ্য তহবিল'), findsOneWidget);
      expect(find.text('নগদ'), findsOneWidget);
    });

    testWidgets('without an employee record the list says why it is empty', (tester) async {
      await tester.pumpWidget(MaterialApp(home: PayslipListScreen(api: _FakeSelf(employee: false))));
      await tester.pumpAndSettle();
      expect(find.text('আপনার নামে কর্মীর খাতা নেই'), findsOneWidget);
    });
  });
}
