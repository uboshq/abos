import 'package:abos_mobile/core/orders/direct_sale_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ একই বিল দুবার — ফোন কী পাঠায় আর সার্ভারের ৪২২ থেকে কী পড়ে (0.4.15, সমন্বয়ক ৬ অক্টোবর ২০২৬)।
DioException _answer(int status, Object? body) => DioException(
      requestOptions: RequestOptions(path: '/sales/direct'),
      response: Response(
          requestOptions: RequestOptions(path: '/sales/direct'),
          statusCode: status,
          data: body),
    );

Map<String, dynamic> _sent(CounterExtras extras) => ServerDirectSaleApi.payload(
      customerId: 'cus-1',
      paymentTerm: 'cash',
      lines: const [],
      draft: false,
      extras: extras,
    );

void main() {
  test('confirm_duplicate goes only when the person said "again"', () {
    expect(
        _sent(const CounterExtras()).containsKey('confirm_duplicate'), isFalse,
        reason: '⛔ টিক ছাড়াই সার্ভারকে "জেনেশুনে" বলা হলো');
    expect(_sent(const CounterExtras().repeating())['confirm_duplicate'], '1');
  });

  test('"again" keeps everything else the bill carried', () {
    const extras = CounterExtras(
        deposits: [CounterDeposit(accountId: 'ac-1', amount: 300)],
        resumeId: 'drf-1',
        note: 'দোকানের নোট');
    final again = extras.repeating();
    expect(again.deposits, extras.deposits);
    expect(again.resumeId, 'drf-1');
    expect(again.note, 'দোকানের নোট');
  });

  test(
      'the duplicate warning is read only from a 422 that names confirm_duplicate',
      () {
    expect(
        duplicateWarning(_answer(422, {
          'message': 'x',
          'errors': {
            'confirm_duplicate': ['আগেই হয়েছে (S-0006)।']
          }
        })),
        'আগেই হয়েছে (S-0006)।');

    // ⓘ অন্য দেয়াল (ঋণসীমা) — সাধারণ বার্তা, টিক নয়
    expect(
        duplicateWarning(_answer(422, {
          'errors': {
            'credit_limit': ['সীমা পার']
          }
        })),
        isNull);
    expect(
        duplicateWarning(_answer(500, {
          'errors': {
            'confirm_duplicate': ['x']
          }
        })),
        isNull);
    expect(duplicateWarning(Exception('no')), isNull);
  });
}
