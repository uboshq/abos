import 'package:abos_mobile/core/hr/self_service_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/features/self_service/self_service_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ উৎসব ভাতার স্লিপ (a4, ২১ অক্টোবর ২০২৬): সার্ভারের নতুন `run_type` — "bonus" হলে তালিকার সারিতে আর স্লিপের পাতায়
/// "উৎসব ভাতা" চিহ্ন; "salary" হলে বা পুরনো সার্ভার না পাঠালে (null) স্লিপ আগের মতোই, কোনো চিহ্ন নেই।
class _Slips implements SelfServiceApi {
  _Slips(this.runType);

  /// null মানে JSON-এ `run_type` নেই
  final String? runType;

  Payslip get _slip => Payslip.fromJson({
        'id': 's1',
        'month': '2026-09-01',
        'gross': 30000,
        'deductions': 2000,
        'net': 28000,
        'name': 'করিম',
        'payment_method': 'cash',
        if (runType != null) 'run_type': runType,
      });

  @override
  Future<PayslipPage> payslips({int page = 1}) async => PayslipPage(employee: true, rows: [_slip]);

  @override
  Future<Payslip> payslip(String id) async => _slip;

  @override
  Future<LeaveBook> leave() => throw UnimplementedError();

  @override
  Future<LeaveRequest> applyLeave(LeaveDraft draft) => throw UnimplementedError();
}

void main() {
  group('the model reads run_type', () {
    test('bonus is a festival bonus slip', () {
      final s = Payslip.fromJson(const {'id': 'a', 'run_type': 'bonus'});
      expect(s.runType, 'bonus');
      expect(s.isFestivalBonus, isTrue);
    });

    test('salary is a plain slip', () {
      final s = Payslip.fromJson(const {'id': 'a', 'run_type': 'salary'});
      expect(s.runType, 'salary');
      expect(s.isFestivalBonus, isFalse);
    });

    test('an old server without the field gives null, a plain slip', () {
      final s = Payslip.fromJson(const {'id': 'a'});
      expect(s.runType, isNull);
      expect(s.isFestivalBonus, isFalse);
    });
  });

  for (final (runType, shown) in [('bonus', true), ('salary', false), (null, false)]) {
    testWidgets('run_type $runType: the label is ${shown ? 'on' : 'not on'} the list row and the slip', (tester) async {
      await tester.pumpWidget(MaterialApp(home: PayslipListScreen(api: _Slips(runType))));
      await tester.pumpAndSettle();
      expect(find.text('সেপ্টেম্বর 2026'), findsOneWidget, reason: 'the row is there either way');
      expect(find.byKey(const ValueKey('payslip-bonus-s1')), shown ? findsOneWidget : findsNothing);
      expect(find.text('উৎসব ভাতা'), shown ? findsOneWidget : findsNothing);

      await tester.tap(find.byKey(const ValueKey('payslip-s1')));
      await tester.pumpAndSettle();
      expect(find.text('নিট বেতন'), findsOneWidget, reason: 'the slip page is open');
      expect(find.byKey(const ValueKey('payslip-festival-bonus')), shown ? findsOneWidget : findsNothing);
      expect(find.text('উৎসব ভাতা'), shown ? findsOneWidget : findsNothing);
    });
  }

  testWidgets('in an English app the label reads "Festival bonus"', (tester) async {
    await tester.pumpWidget(MaterialApp(
      locale: AppLocale.english,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: PayslipListScreen(api: _Slips('bonus')),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Festival bonus'), findsOneWidget);
    expect(find.text('উৎসব ভাতা'), findsNothing);
  });
}
