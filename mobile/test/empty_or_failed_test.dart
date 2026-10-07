import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';
import 'package:abos_mobile/features/orders/order_list_screen.dart';
import 'package:abos_mobile/features/products/product_list_screen.dart';
import 'package:abos_mobile/features/stock/stock_list_screen.dart';

/// "Nothing is here" and "nothing arrived" are opposite situations that had
/// looked identical.
///
/// <p>Every list screen catches a failed pull and leaves what it had on
/// screen, which is right. What was missing is that the reason went nowhere —
/// so a phone with an expired token, a 403, or a server that is simply not
/// answering kept saying *এখনো কোনো গ্রাহক সিঙ্ক হয়নি — নিচে টেনে আবার চেষ্টা
/// করুন* after every pull, forever, sending somebody to do the one thing that
/// cannot work.
///
/// <p>This is the pull half of the afternoon that
/// [SyncEngine.lastFailureFor]'s own doc comment records, where every order
/// sat pending against a server answering 422 and the only way to find out was
/// `adb logcat`. Nobody in a shop has adb.
void main() {
  tearDown(ReferenceSync.forgetLastFailure);

  DioException refusal(int code, {String? message}) => DioException(
        requestOptions: RequestOptions(path: '/sync/capabilities'),
        response: Response(
          requestOptions: RequestOptions(path: '/sync/capabilities'),
          statusCode: code,
          data: message == null ? null : {'message': message},
        ),
        type: DioExceptionType.badResponse,
      );

  group('the sentence names who has to act', () {
    test('no signal, pulling — not "it will go on its own"', () {
      final failure = SyncAttemptFailure.from(
        DioException(
          requestOptions: RequestOptions(path: '/sync/customers/pull'),
          type: DioExceptionType.connectionError,
        ),
        direction: SyncDirection.pull,
      );

      // ⛔ The push sentence promises the queue will empty itself once there
      // is signal. On a pull nothing is queued — the list stays empty until
      // somebody pulls again, and the promise is simply false.
      expect(failure.sentence, contains('আবার নিচে টানুন'));
      expect(failure.sentence, isNot(contains('নিজে থেকে চলে যাবে')));
    });

    test('no signal, pushing — the queue really will empty itself', () {
      final failure = SyncAttemptFailure.from(
        DioException(
          requestOptions: RequestOptions(path: '/sync/sales/push'),
          type: DioExceptionType.connectionError,
        ),
      );
      expect(failure.sentence, contains('নিজে থেকে চলে যাবে'));
    });

    test('a refusal sends the person to the office either way', () {
      final pulling =
          SyncAttemptFailure.from(refusal(403), direction: SyncDirection.pull);

      expect(pulling.isServerRefusal, isTrue);
      expect(pulling.sentence, contains('403'));
      expect(pulling.sentence, contains('অফিসে জানান'));
      // ⚠️ And says plainly that repeating the gesture will not help, because
      // the screen it appears on is the one printing "নিচে টেনে" right above.
      expect(pulling.sentence, contains('বারবার টেনে'));
    });

    test("the server's own words are carried through", () {
      final failure = SyncAttemptFailure.from(
        refusal(422, message: 'deviceId is required'),
        direction: SyncDirection.pull,
      );
      expect(failure.sentence, contains('deviceId is required'));
    });

    test('push keeps its wording, unchanged', () {
      // The direction defaults to push, so every existing call site — the
      // sync status screen among them — reads exactly as it did.
      expect(SyncAttemptFailure.from(refusal(422)).sentence,
          contains('অপেক্ষা করে ঠিক হবে না'));
    });
  });

  group('what an empty screen says', () {
    Future<void> pump(WidgetTester tester, Widget screen) async {
      await tester.pumpWidget(MaterialApp(home: screen));
      await tester.pump();
    }

    testWidgets('a clean pull leaves the plain empty message', (tester) async {
      await pump(tester, const CustomerListScreen());

      expect(find.text('এখনো কোনো গ্রাহক সিঙ্ক হয়নি'), findsOneWidget);
      expect(find.text('নিচে টেনে আবার চেষ্টা করুন।'), findsOneWidget);
    });

    testWidgets('a refused pull says so instead', (tester) async {
      ReferenceSync.rememberFailureForTest(
          SyncAttemptFailure.from(refusal(401), direction: SyncDirection.pull));

      await pump(tester, const CustomerListScreen());

      expect(find.text('গ্রাহকের তালিকা আনা যায়নি'), findsOneWidget);
      expect(find.textContaining('401'), findsOneWidget);
      // The instruction that cannot work is gone, not merely joined.
      expect(find.text('নিচে টেনে আবার চেষ্টা করুন।'), findsNothing);
    });

    testWidgets('⛔ বকেয়া never claims nobody owes anything on no data',
        (tester) async {
      ReferenceSync.rememberFailureForTest(
          SyncAttemptFailure.from(refusal(403), direction: SyncDirection.pull));

      await pump(tester, const DueListScreen());

      // This is the one that costs money rather than time. "কারো কাছে বকেয়া
      // নেই" after a failed pull is a statement about money made on nothing,
      // and it is acted on: no collection round, no call, a credit limit read
      // as headroom.
      expect(find.text('কারো কাছে বকেয়া নেই'), findsNothing);
      expect(find.text('বকেয়ার তালিকা আনা যায়নি'), findsOneWidget);
    });

    testWidgets('with a clean pull বকেয়া is allowed to be good news',
        (tester) async {
      await pump(tester, const DueListScreen());
      expect(find.text('কারো কাছে বকেয়া নেই'), findsOneWidget);
    });

    testWidgets('পণ্য, মজুদ and অর্ডার carry the reason too', (tester) async {
      ReferenceSync.rememberFailureForTest(
          SyncAttemptFailure.from(refusal(500), direction: SyncDirection.pull));

      await pump(tester, const ProductListScreen());
      expect(find.text('পণ্যের তালিকা আনা যায়নি'), findsOneWidget);

      await pump(tester, const StockListScreen());
      expect(find.text('মজুদের তালিকা আনা যায়নি'), findsOneWidget);

      await pump(tester, const OrderListScreen());
      expect(find.text('অর্ডারের তালিকা আনা যায়নি'), findsOneWidget);
    });
  });

  test('a reason does not outlive the trouble it described', () {
    ReferenceSync.rememberFailureForTest(
        SyncAttemptFailure.from(refusal(500), direction: SyncDirection.pull));
    expect(ReferenceSync.troubleSentence, isNotNull);

    ReferenceSync.forgetLastFailure();

    // Cleared on sign-out — the same tenant boundary ReferenceCache.clearAll
    // draws. Left behind, the previous account's 403 greets the next person on
    // this handset.
    expect(ReferenceSync.troubleSentence, isNull);
  });
}
