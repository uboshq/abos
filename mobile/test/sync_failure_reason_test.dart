import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/sync/sync_status_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// Telling "waiting" apart from "refused".
///
/// <p>On 15 September every order written on a live server sat in the queue
/// for good: the sync screen said "১ অপেক্ষমাণ", nothing was marked rejected,
/// and the cause — the server answering 422 to every push — was only found by
/// reading `adb logcat`. Nobody in a shop has adb.
///
/// <p>The two states leave the queue looking identical and are not the same
/// thing at all: one is fixed by walking to a window, the other by somebody at
/// a desk. A screen that cannot say which sends the rep to the window forever.
void main() {
  DioException dio({int? status, Object? body, DioExceptionType? type}) =>
      DioException(
        requestOptions: RequestOptions(path: '/sync/sales/push'),
        type: type ?? DioExceptionType.badResponse,
        response: status == null
            ? null
            : Response(
                requestOptions: RequestOptions(path: '/sync/sales/push'),
                statusCode: status,
                data: body,
              ),
      );

  test('no signal is ordinary, and says so', () {
    final failure =
        SyncAttemptFailure.from(dio(type: DioExceptionType.connectionError));

    expect(failure.isNetwork, isTrue);
    expect(failure.isServerRefusal, isFalse);
    expect(failure.sentence, contains('সংযোগ পেলেই'));
    // Not somebody's fault, and not something to report — the queue is doing
    // exactly what it was built for.
    expect(failure.sentence, isNot(contains('অফিসে জানান')));
  });

  test('a refused request names the code and who has to act', () {
    // The real one: SyncController answered 422 because $request->all()
    // merges the ?deviceId= query into the body, so a JSON list stopped being
    // a list. No amount of waiting fixes that.
    final failure = SyncAttemptFailure.from(dio(
      status: 422,
      body: {'message': 'এন্ট্রিটা খালি এসেছে — ভেতরে কিছুই নেই।'},
    ));

    expect(failure.isNetwork, isFalse);
    expect(failure.isServerRefusal, isTrue);
    expect(failure.sentence, contains('422'));
    expect(failure.sentence, contains('এন্ট্রিটা খালি'));
    expect(failure.sentence, contains('অপেক্ষা করে ঠিক হবে না'),
        reason: 'the one thing a rep must not do is keep waiting');
  });

  test('a refusal with no message still says the code', () {
    final failure = SyncAttemptFailure.from(dio(status: 500));

    expect(failure.isServerRefusal, isTrue);
    expect(failure.sentence, contains('500'));
    expect(failure.sentence, contains('অফিসে জানান'));
  });

  test('something that is not a Dio failure at all is still admitted', () {
    // flush() deliberately catches everything, including a TypeError from a
    // malformed response body. An unknown cause must read as unknown rather
    // than silently as "no signal".
    final failure = SyncAttemptFailure.from(StateError('anything'));

    expect(failure.isNetwork, isFalse);
    expect(failure.isServerRefusal, isFalse);
    expect(failure.sentence, contains('কারণ জানা যায়নি'));
  });

  // The wiring, driven by a real failed attempt rather than a stubbed one.
  //
  // <p>⚠️ Not driven through the screen's own pull-to-refresh gesture, and
  // that is deliberate: `adb`'s synthetic swipe cannot reliably produce the
  // drag a RefreshIndicator recognises — this repository already found that
  // out once (commit b2392ce) and answered it the same way, by asserting the
  // thing itself instead of the gesture that reaches it.
  group('the reason reaches the screen', () {
    late HiveTestHarness harness;

    setUpAll(() async {
      FakeSecureStorage.install();
      harness = await HiveTestHarness.setUp();
      await SyncEngine.instance.init();
    });

    tearDownAll(() async {
      await SyncEngine.instance.dispose();
      await harness.tearDown();
    });

    testWidgets('a queue that failed says why, next to the count',
        (tester) async {
      // ⓘ Inside TestWidgetsFlutterBinding every HTTP request comes back 400
      // without leaving the machine — so this is a *server refusal* rather
      // than a lost connection, which is the more interesting of the two to
      // prove: it is the branch that has to say "waiting will not fix this".
      await tester.runAsync(() => SyncEngine.instance.enqueue(
            module: 'sales',
            entityType: 'SalesOrder',
            operation: 'CREATE',
            payload: const {
              'customerId': 'c1',
              'lines': [
                {'productId': 'p1', 'qty': 1},
              ],
            },
          ));

      expect(SyncEngine.instance.lastFailureFor('sales'), isNotNull,
          reason: 'the attempt failed, so something must have been recorded');

      await tester.pumpWidget(const MaterialApp(home: SyncStatusScreen()));
      // The screen starts in a loading state while it asks for conflicts and
      // last-sync times; those fail fast here, so a couple of pumps is enough
      // to reach the state a person would actually see.
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));
      await tester.pump(const Duration(seconds: 1));

      // The count alone said "waiting", which a rep reads as "no signal"
      // whether or not that is what happened. Now the line underneath says
      // what actually came back, and that this is not something to wait out.
      expect(find.textContaining('সংযোগের অপেক্ষায়'), findsOneWidget);
      expect(find.textContaining('400'), findsOneWidget);
      expect(find.textContaining('অপেক্ষা করে ঠিক হবে না'), findsOneWidget);
    });
  });
}
