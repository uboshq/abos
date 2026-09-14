import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/records/attendance_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/attendance/attendance_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// Attendance — docs/Contract §৭.
///
/// <p>Run with `--dart-define=ABOS_API_BASE_URL=http://127.0.0.1:1/api/v1`,
/// like the other queue tests: [SyncEngine.enqueue] always attempts an
/// immediate flush, and nothing listens there.
void main() {
  const queueBoxName = 'abos_sync_queue';
  final today = DateTime(2026, 9, 14, 9, 12);
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await SyncEngine.instance.init();
    await ReferenceCache.instance.init();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(() async {
    if (Hive.isBoxOpen(queueBoxName)) {
      await Hive.box<Map>(queueBoxName).clear();
    }
    await ReferenceCache.instance.clearAll();
  });

  group('what goes up', () {
    test('the payload is what AttendanceSync::apply reads', () {
      final payload = AttendanceDraft(day: today, inTime: '09:12').toPayload();

      expect(payload['workDate'], '2026-09-14');
      expect(payload['status'], 'present');
      expect(payload['inTime'], '09:12');
    });

    test('⛔ no employeeId is ever sent', () {
      // The handler accepts one only if it is the sender's own and rejects
      // anything else outright — it will not quietly substitute the right
      // one, because a silent correction would later raise "who marked my
      // attendance". Omitting it uses the signed-in person's own record,
      // which is the only thing this screen ever wants. Sending it adds a way
      // to be wrong and no way to be more right.
      final payload = AttendanceDraft(day: today).toPayload();

      expect(payload.containsKey('employeeId'), isFalse);
    });

    test('an empty remark is left out rather than sent blank', () {
      final payload = AttendanceDraft(day: today, remarks: '   ').toPayload();
      expect(payload.containsKey('remarks'), isFalse);
    });
  });

  group('what comes down', () {
    test('reads the fields the handler sends', () {
      const row = AttendanceRecord({
        'id': '01a0c3f0-0000-7000-8000-0000000000c1',
        'workDate': '2026-09-13',
        'status': 'present',
        'inTime': '09:05',
        'outTime': '18:00',
        'isLate': false,
        'remarks': '২ দিন পরে সিঙ্ক হয়েছে',
      });

      expect(row.workDate, '2026-09-13');
      expect(row.statusLabel, 'উপস্থিত');
      expect(row.inTime, '09:05');
      expect(row.remarks, '২ দিন পরে সিঙ্ক হয়েছে');
    });

    test('every status has a word a person reads', () {
      expect(const AttendanceRecord({'status': 'absent'}).statusLabel,
          'অনুপস্থিত');
      expect(const AttendanceRecord({'status': 'leave'}).statusLabel, 'ছুটি');
      expect(const AttendanceRecord({'status': 'holiday'}).statusLabel,
          'সরকারি ছুটি');
    });
  });

  group('the offline-write guard lets attendance through', () {
    test('an attendance change can be queued', () async {
      await SyncEngine.instance.enqueue(
        module: 'hr',
        entityType: 'Attendance',
        operation: 'CREATE',
        payload: AttendanceDraft(day: today).toPayload(),
      );

      expect(SyncEngine.instance.pendingCount, 1);
      // The guard added for collections must not have caught this one: the
      // spec asks for attendance offline by name, and AttendanceSync has
      // accepted the push all along.
      expect(
          SyncEngine.instance.pendingPayloadsOf('Attendance').single['workDate'],
          '2026-09-14');
    });

    test('a collection is still refused', () async {
      await expectLater(
        SyncEngine.instance.enqueue(
          module: 'sales',
          entityType: 'Collection',
          operation: 'CREATE',
          payload: const {'amount': '1'},
        ),
        throwsUnsupportedError,
      );
    });
  });

  group('the screen', () {
    Widget screen({Future<void> Function(AttendanceDraft)? onMark}) =>
        MaterialApp(
          home: AttendanceScreen(now: () => today, onMark: onMark),
        );

    testWidgets('offers the button when today is not marked', (tester) async {
      await tester.pumpWidget(screen(onMark: (_) async {}));
      await tester.pumpAndSettle();

      expect(find.text('14/09/2026'), findsOneWidget);
      expect(find.text('আজকের হাজিরা দিন'), findsOneWidget);
    });

    testWidgets('marking sends today, at the phone\'s own clock',
        (tester) async {
      AttendanceDraft? sent;
      await tester.pumpWidget(screen(onMark: (d) async => sent = d));
      await tester.pumpAndSettle();

      await tester.tap(find.text('আজকের হাজিরা দিন'));
      await tester.pumpAndSettle();

      expect(sent, isNotNull);
      expect(sent!.toPayload()['workDate'], '2026-09-14');
      // In a field there is no other clock. The server marks the gap between
      // the day claimed and the day received rather than refusing it.
      expect(sent!.toPayload()['inTime'], '09:12');
    });

    testWidgets('a day already queued cannot be marked again', (tester) async {
      // The server refuses a second row for the same day with a CONFLICT, so
      // a phone that allowed it would send a change that comes back refused —
      // and a refused row reads as "my attendance did not go through" when in
      // fact it had.
      // runAsync, because testWidgets runs in a fake-clock zone where real
      // async I/O never completes — a Hive write or a queue flush started
      // inside it simply hangs, which is exactly what this test did before.
      await tester.runAsync(() => SyncEngine.instance.enqueue(
            module: 'hr',
            entityType: 'Attendance',
            operation: 'CREATE',
            payload: AttendanceDraft(day: today).toPayload(),
          ));

      await tester.pumpWidget(screen());
      await tester.pumpAndSettle();

      expect(find.text('আজকের হাজিরা দিন'), findsNothing);
      expect(find.text('আজকের হাজিরা তোলা হয়েছে'), findsOneWidget);
      // And it says honestly that it has not left the phone yet.
      expect(find.textContaining('এখনো এই ফোনেই আছে'), findsOneWidget);
    });

    testWidgets('a day the server has sent back shows no pending line',
        (tester) async {
      await tester.runAsync(() => ReferenceCache.instance.put(
            entityType: 'Attendance',
            entityId: 'a1',
            updatedAt: today,
            payload: const {
              'id': 'a1',
              'workDate': '2026-09-14',
              'status': 'present',
              'inTime': '09:12',
            },
          ));

      await tester.pumpWidget(screen());
      await tester.pumpAndSettle();

      expect(find.text('আজকের হাজিরা তোলা হয়েছে'), findsOneWidget);
      expect(find.textContaining('এখনো এই ফোনেই আছে'), findsNothing);
    });

    testWidgets('the server\'s late-sync note is shown, not swallowed',
        (tester) async {
      await tester.runAsync(() => ReferenceCache.instance.put(
            entityType: 'Attendance',
            entityId: 'a2',
            updatedAt: today,
            payload: const {
              'id': 'a2',
              'workDate': '2026-09-11',
              'status': 'present',
              'remarks': '৩ দিন পরে সিঙ্ক হয়েছে',
            },
          ));

      await tester.pumpWidget(screen());
      await tester.pumpAndSettle();

      // A phone's clock can be changed; the handler's answer is a mark rather
      // than a refusal, and a screen that hid the mark would undo the only
      // thing that makes the claim checkable.
      expect(find.textContaining('৩ দিন পরে সিঙ্ক হয়েছে'), findsOneWidget);
    });
  });

  group('the tile comes from the permission, never the menu', () {
    const repository = MenuRepository();

    test('someone with hr.attendance.self gets the tile', () async {
      const worker = AuthUser(
        id: '1',
        name: 'Warehouse',
        email: 'w@abos.test',
        roles: ['warehouse'],
        permissions: ['hr.attendance.self'],
      );

      final keys =
          (await repository.menuFor(worker)).map((i) => i.key).toSet();

      // ⛔ /me can never carry this row: hr.attendance.index needs
      // hr.attendance.view, the whole-team permission field staff are
      // deliberately not given. Built from the menu, the feature would have
      // been invisible to exactly the people it is for.
      expect(keys, contains('hr.attendance.self'));
    });

    test('someone without it does not', () async {
      const office = AuthUser(
        id: '2',
        name: 'Office',
        email: 'o@abos.test',
        roles: ['accountant'],
        permissions: ['customer.view'],
      );

      final keys =
          (await repository.menuFor(office)).map((i) => i.key).toSet();

      expect(keys, isNot(contains('hr.attendance.self')));
    });
  });
}
