import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/api_client/network_errors.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:abos_mobile/features/reports/reports_screen.dart';
import 'package:abos_mobile/features/today/today_screen.dart';

/// What a phone says when it is newer than the server it is talking to.
///
/// <p>⚠️ <b>Not a temporary state.</b> Handsets are updated one at a time, by
/// hand, from a download link — so an app ahead of its server is the ordinary
/// condition of this fleet rather than an accident, and it will be true again
/// at every release after this one.
///
/// <p>It is live right now: 0.3.0 ships screens for approvals, today's figures
/// and reports, and none of those three routes exists on erp.adi.com.bd yet.
/// The owner is about to install this build and tap all three. Before this
/// change every one of them said <i>তালিকা আনা গেল না</i> — which reads as
/// *this is broken* or *the server is down*, and sends somebody to check a
/// connection that is fine and report a fault that does not exist.
void main() {
  DioException notFound({String? message}) => DioException(
        requestOptions: RequestOptions(path: '/dashboard/today'),
        response: Response(
          requestOptions: RequestOptions(path: '/dashboard/today'),
          statusCode: 404,
          data: message == null ? null : {'message': message},
        ),
        type: DioExceptionType.badResponse,
      );

  group('the sentence', () {
    test('a 404 is told apart from everything else', () {
      expect(isRouteAbsent(notFound()), isTrue);
      expect(
        isRouteAbsent(DioException(
          requestOptions: RequestOptions(path: '/x'),
          response: Response(
              requestOptions: RequestOptions(path: '/x'), statusCode: 500),
          type: DioExceptionType.badResponse,
        )),
        isFalse,
      );
    });

    test('whenAbsent is used for a 404, the fallback is not', () {
      expect(
        errorMessageFor(notFound(),
            fallback: 'আনা গেল না।', whenAbsent: 'এখনো এই সার্ভারে নেই।'),
        'এখনো এই সার্ভারে নেই।',
      );
    });

    test('a caller that passes no whenAbsent is unchanged', () {
      // ⛔ The endpoints that name a particular row — GET /reports/{key}, the
      // documents sheet — pass nothing, because a 404 there is at least as
      // likely to mean that row is gone. Same status code, opposite sentence.
      expect(
        errorMessageFor(notFound(), fallback: 'রিপোর্ট আনা গেল না।'),
        'রিপোর্ট আনা গেল না।',
      );
    });

    test('no signal still wins over everything', () {
      // A phone out of coverage never reaches a route to find it missing, so
      // this ordering is the only correct one.
      final offline = DioException(
        requestOptions: RequestOptions(path: '/dashboard/today'),
        type: DioExceptionType.connectionError,
      );
      expect(
        errorMessageFor(offline,
            fallback: 'আনা গেল না।', whenAbsent: 'এখনো এই সার্ভারে নেই।'),
        contains('সংযোগ নেই'),
      );
    });

    test("a Bangla 404 body does not overwrite what the caller knows", () {
      // Laravel's own 404 is English and serverSentence already declines it.
      // But a server that someday answers 404 with a Bangla sentence must not
      // replace the one fact this file cannot work out for itself.
      expect(
        errorMessageFor(notFound(message: 'পাওয়া যায়নি।'),
            fallback: 'আনা গেল না।', whenAbsent: 'এখনো এই সার্ভারে নেই।'),
        'এখনো এই সার্ভারে নেই।',
      );
    });
  });

  group('on the screens the owner is about to tap', () {
    Future<void> pumpAndFind(WidgetTester tester, Widget screen) async {
      await tester.pumpWidget(MaterialApp(home: screen));
      await tester.pumpAndSettle();

      expect(find.textContaining('সার্ভারের চেয়ে নতুন'), findsOneWidget);
      // And not the sentence that sends somebody to check their connection.
      expect(find.textContaining('ইন্টারনেট চেক'), findsNothing);
    }

    testWidgets('অনুমোদন', (tester) async {
      await pumpAndFind(
        tester,
        ApprovalInboxScreen(loadPending: () async => throw notFound()),
      );
    });

    testWidgets('আজকের হিসাব', (tester) async {
      await pumpAndFind(
        tester,
        TodayScreen(fetch: () async => throw notFound(), lastKnown: () => null),
      );
    });

    testWidgets('রিপোর্ট', (tester) async {
      await pumpAndFind(
        tester,
        ReportsScreen(loadList: () async => throw notFound()),
      );
    });
  });
}
