import 'package:abos_mobile/core/hr/hr_register_api.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/hr_registers/hr_register_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeHr implements HrRegisterApi {
  _FakeHr({this.empty = false});

  final bool empty;
  final asked = <String>[];
  final decided = <String>[];

  @override
  Future<DeskRow> attendance(String date) async {
    asked.add('day:$date');
    if (empty) return const DeskRow({'rows': [], 'count': 0, 'counts': []});
    return const DeskRow({
      'date': '2026-10-17',
      'count': 2,
      'counts': [
        {'status': 'present', 'label': 'উপস্থিত', 'count': 1},
        {'status': 'unmarked', 'label': 'বসানো হয়নি', 'count': 1},
      ],
      'rows': [
        {'id': 'e1', 'code': 'E-1', 'name': 'রহিম', 'status': 'present', 'status_label': 'উপস্থিত', 'late': true},
        {'id': 'e2', 'code': 'E-2', 'name': 'করিম', 'status': null, 'status_label': 'বসানো হয়নি'},
      ],
    });
  }

  @override
  Future<DeskRow> leaves({bool pending = false, int page = 1}) async {
    asked.add('leave:${pending ? 'pending' : 'all'}');
    if (empty) return const DeskRow({'rows': [], 'count': 0});
    return const DeskRow({
      'rows': [
        {'id': 'l1', 'employee': 'নিজে', 'type': 'নৈমিত্তিক', 'from': '20-10-2026', 'to': '20-10-2026', 'days': '1',
          'status_label': 'অপেক্ষায়', 'can_decide': false},
        {'id': 'l2', 'employee': 'করিম', 'type': 'নৈমিত্তিক', 'from': '21-10-2026', 'to': '22-10-2026', 'days': '2',
          'status_label': 'অপেক্ষায়', 'can_decide': true},
      ],
      'count': 2,
    });
  }

  @override
  Future<void> approve(String id, {String? remarks}) async => decided.add('approve:$id');

  @override
  Future<void> reject(String id, {required String remarks}) async => decided.add('reject:$id:$remarks');
}

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬ — হাজিরা আর ছুটির রেজিস্টার (নাগালের সব কর্মী) ফোনের পর্দায়; বেতন ওয়েবে।
void main() {
  test('attendance and leave open the registers, claims the own claims, payroll stays on the web', () {
    expect(NativeForWeb.screenFor('/hr/attendance'), isA<AttendanceRegisterScreen>());
    expect(NativeForWeb.screenFor('/hr/leave'), isA<LeaveRegisterScreen>());
    expect(NativeForWeb.screenFor('/hr/claims').toString(), contains('ModuleGateView'));
    expect(NativeForWeb.screenFor('/hr/payroll'), isNull, reason: '⛔ বেতন ফোনে নয়');
  });

  testWidgets('the attendance register asks for the day and shows the total and counts by state', (tester) async {
    final api = _FakeHr();
    await tester.pumpWidget(MaterialApp(home: AttendanceRegisterScreen(api: api, today: DateTime(2026, 10, 17))));
    await tester.pumpAndSettle();

    expect(api.asked, ['day:2026-10-17']);
    expect(find.text('মোট 2 জন'), findsOneWidget);
    expect(find.text('উপস্থিত: 1'), findsOneWidget);
    expect(find.text('বসানো হয়নি: 1'), findsOneWidget);
    expect(find.text('উপস্থিত · দেরি'), findsOneWidget);
  });

  testWidgets('the leave register shows no buttons on ones own leave, approves another, and refusing needs a reason', (tester) async {
    final api = _FakeHr();
    await tester.pumpWidget(MaterialApp(home: LeaveRegisterScreen(api: api)));
    await tester.pumpAndSettle();

    expect(api.asked.last, 'leave:pending', reason: 'শুরু অপেক্ষার আবেদন দিয়ে');
    expect(find.text('মোট 2 টি আবেদন'), findsOneWidget);
    expect(find.byKey(const ValueKey('leave-approve-l1')), findsNothing, reason: '⛔ নিজের আবেদনে মঞ্জুরের বোতাম');

    await tester.tap(find.byKey(const ValueKey('leave-approve-l2')));
    await tester.pumpAndSettle();
    expect(api.decided, ['approve:l2']);

    await tester.tap(find.byKey(const ValueKey('leave-reject-l2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('leave-reason-send')));
    await tester.pumpAndSettle();
    expect(api.decided, ['approve:l2'], reason: 'কারণ ছাড়া ফেরত নয়');

    await tester.tap(find.byKey(const ValueKey('leave-reject-l2')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('leave-reason')), 'ব্যস্ত সময়');
    await tester.tap(find.byKey(const ValueKey('leave-reason-send')));
    await tester.pumpAndSettle();
    expect(api.decided.last, 'reject:l2:ব্যস্ত সময়');

    await tester.tap(find.byKey(const ValueKey('leave-all')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'leave:all');
  });

  testWidgets('empty registers can be pulled to refresh', (tester) async {
    for (final screen in [AttendanceRegisterScreen(api: _FakeHr(empty: true)), LeaveRegisterScreen(api: _FakeHr(empty: true))]) {
      await tester.pumpWidget(MaterialApp(home: screen));
      await tester.pumpAndSettle();
      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
    }
  });
}
