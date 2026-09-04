import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/sync_engine.dart';

/// [reasonIfRejected] is the one function this whole app leans on to answer
/// "did the server actually accept this change" — see its own doc comment
/// for the exact bug a wrong answer here reproduces: a refused sale
/// disappearing from the queue the same way an applied one correctly does,
/// because both were "acknowledged". This test is the guard the doc comment
/// says exists to make that bug provable without a Hive box or a mocked Dio.
void main() {
  group('reasonIfRejected', () {
    test('APPLIED is not a rejection', () {
      expect(reasonIfRejected({'status': 'APPLIED', 'entityId': '01a0'}),
          isNull);
    });

    test('DUPLICATE is not a rejection', () {
      expect(reasonIfRejected({'status': 'DUPLICATE', 'entityId': '01a0'}),
          isNull);
    });

    test('REJECTED carries its reason forward, never silently', () {
      expect(
        reasonIfRejected({
          'status': 'REJECTED',
          'message': 'দোকানের বকেয়া সীমা পার হয়ে গেছে।',
        }),
        'দোকানের বকেয়া সীমা পার হয়ে গেছে।',
      );
    });

    test('CONFLICT is treated the same as REJECTED — both are refusals', () {
      expect(
        reasonIfRejected({
          'status': 'CONFLICT',
          'message': 'সার্ভারে নতুনতর বদল আছে।',
        }),
        'সার্ভারে নতুনতর বদল আছে।',
      );
    });

    test('a refusal with no message still returns a non-null reason', () {
      // The trap this guards: treating "no message" as "not rejected" would
      // let a REJECTED outcome slip past every caller that checks for null
      // and delete the row as if it had been applied.
      expect(reasonIfRejected({'status': 'REJECTED'}), isNotNull);
    });

    test('no outcome for this changeId at all is not a rejection', () {
      // Kept pending by the caller, not treated as done or refused — see
      // SyncEngine.flush's own handling of this exact case.
      expect(reasonIfRejected(null), isNull);
    });
  });
}
