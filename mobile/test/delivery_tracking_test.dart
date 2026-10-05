import 'package:abos_mobile/core/orders/tracking_api.dart';
import 'package:abos_mobile/features/orders/delivery_tracking_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ডেলিভারি ট্র্যাকিং — মালিক, ২ অক্টোবর ২০২৬। ধাপ সার্ভারের; ফোন কেবল সত্যি করে দেখায়।
class _FakeApi implements TrackingApi {
  String? askedStep;

  static const sale = TrackedSale(
    kind: 'challan', id: 'c1', no: 'S-0007', date: '2026-10-02', customer: 'রহিম স্টোর',
    total: 4820, step: 'gate_out', billed: true,
  );

  @override
  Future<TrackingList> list({String? query, String? step}) async {
    askedStep = step;
    return TrackingList(step == null || step == 'gate_out' ? [sale] : [], {'all': 1, 'gate_out': 1});
  }

  @override
  Future<(TrackedSale, List<TrackingEvent>, List<Milestone>)> story(TrackedSale s) async => (
        sale,
        [
          TrackingEvent(at: DateTime(2026, 10, 2, 9), step: 'draft', by: 'করিম', text: 'ডিও DC-0007 লেখা হলো'),
          TrackingEvent(at: DateTime(2026, 10, 2, 11), step: 'gate_out', by: 'গেটম্যান', text: 'গেট পাস GP-3, গাড়ি ঢাকা-১২'),
        ],
        const [
          Milestone(key: 'order_created', label: 'অর্ডার তৈরি', category: 'approved', state: 'done'),
          Milestone(key: 'approval_1_1', label: 'হিসাব বিভাগ', category: 'approved', state: 'done'),
          Milestone(key: 'dispatched', label: 'রওনা', category: 'dispatched', state: 'done'),
          Milestone(key: 'delivered', label: 'পৌঁছেছে', category: 'delivered', state: 'current'),
        ],
      );
}

class _BackOrderApi extends _FakeApi {
  @override
  Future<TrackingList> list({String? query, String? step}) async => const TrackingList([
        TrackedSale(kind: 'challan', id: 'c3', no: 'S-0008', date: '2026-10-05', customer: 'রহিম স্টোর',
            total: 160, step: 'gate_out', billed: false, backOrder: true),
      ], {'all': 1, 'gate_out': 1});
}

void main() {
  testWidgets('a sale shows its step in words, and opens its story', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: DeliveryTrackingScreen(api: api)));
    await tester.pumpAndSettle();

    expect(find.text('S-0007'), findsOneWidget);
    expect(find.text('গেট পেরিয়েছে · বিল হয়েছে'), findsOneWidget);

    await tester.tap(find.text('S-0007'));
    await tester.pumpAndSettle();
    expect(find.text('ডিও DC-0007 লেখা হলো'), findsOneWidget);
    expect(find.text('গেট পাস GP-3, গাড়ি ঢাকা-১২'), findsOneWidget);

    // ⭐ টিকচিহ্নের দাগ — কোম্পানির নিজের স্তরের নাম, হয়ে যাওয়ায় টিক, পরেরটা "এখন"
    expect(find.text('হিসাব বিভাগ'), findsOneWidget);
    expect(find.byKey(const ValueKey('milestone-dispatched-done')), findsOneWidget);
    expect(find.byKey(const ValueKey('milestone-delivered-current')), findsOneWidget);
    expect(find.text('✓'), findsNWidgets(3));
  });

  // ⭐ DO+SO মেশানো, ধাপ ১১ — সীমায় আটকানো আদেশের নিজের ধাপ, আর আংশিক চালানে "বাকি মাল পরে যাবে"
  test('a held order and a back order read in words from the server', () {
    final held = TrackedSale.fromJson({'kind': 'order', 'id': 'o1', 'no': 'SO-1', 'step': 'credit_hold', 'category': 'hold'});
    expect(trackingStepLabels[held.step], 'বাকির সীমায় আটকে');
    expect(held.backOrder, isFalse);
    expect(TrackedSale.fromJson({'kind': 'challan', 'id': 'c2', 'step': 'gate_out', 'back_order': true}).backOrder, isTrue);
  });

  testWidgets('a partly sent sale says the rest goes later', (tester) async {
    final api = _BackOrderApi();
    await tester.pumpWidget(MaterialApp(home: DeliveryTrackingScreen(api: api)));
    await tester.pumpAndSettle();
    expect(find.text('গেট পেরিয়েছে · বাকি মাল পরে যাবে'), findsOneWidget);
  });

  testWidgets('a step chip asks the server for that step', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: DeliveryTrackingScreen(api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.text('পৌঁছেছে (0)'));
    await tester.pumpAndSettle();
    expect(api.askedStep, 'delivered');
    expect(find.text('কোনো বিক্রি নেই।'), findsOneWidget);
  });
}
