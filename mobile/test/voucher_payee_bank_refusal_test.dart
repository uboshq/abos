import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ সই-না-পাওয়া ব্যাংকে পরিশোধ — সার্ভার ফেরায় (cb 81674fcc2): ৪২২, কথা `errors.payee_bank[0]` (supplier::bank.unsigned_blocks_voucher),
/// সরবরাহকারীর নাম, করণীয় আর পাতার ঠিকানা লেখায়। ⛔ চাবিটা কোনো ঘরের নয়, তবু কথাটা পুরোটা পর্দার উপরে; খসড়া থাকে, পাতা চলে।
const _message = 'স্টার লাইন ফুড প্রোডাক্টস-এর ব্যাংক তথ্যে এখনো দ্বিতীয় অনুমোদন হয়নি — ব্যাংকে (ট্রান্সফার বা জমা) দেওয়া যাবে না। '
    'করণীয়: সরবরাহকারীর পাতায় ব্যাংক তথ্য দিন, আরেকজন দ্বিতীয় অনুমোদন দিলে তবেই ব্যাংকে দেওয়া যাবে। '
    'পাতা: https://adi.abos.app/suppliers/0c9a3e52-6b1f-4f7a-9d2e-1a2b3c4d5e6f — নগদ, MFS বা চেকে এখনই দেওয়া যায়।';

VoucherPage _draft() => const VoucherPage(
      row: VoucherRow(id: 'v7', no: 'PV-0010', type: 'payment', state: 'draft', amount: 120000, stateLabel: 'খসড়া'),
      lines: [
        VoucherLineView(account: '2111 সরবরাহকারীর দেনা', debit: 120000, credit: 0),
        VoucherLineView(account: '1102 ডাচ-বাংলা ব্যাংক চলতি', debit: 0, credit: 120000),
      ],
      instrument: 'transfer',
      canPost: true,
    );

class _Refusing implements VoucherApi {
  int posts = 0;

  @override
  Future<VoucherPage> one(String id) async => _draft();

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) async {
    posts++;
    final options = RequestOptions(path: '/accounts/vouchers/$id/post');
    throw DioException(
      requestOptions: options,
      type: DioExceptionType.badResponse,
      response: Response(requestOptions: options, statusCode: 422, data: {
        'errors': {
          'payee_bank': [_message],
        },
      }),
    );
  }

  @override
  Future<VoucherSetup> setup(String type) => throw UnimplementedError();

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: []);

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) => throw UnimplementedError();
}

void main() {
  for (final width in [360.0, 412.0]) {
    testWidgets('a 422 payee_bank refusal shows the whole server message on top at ${width.toInt()}dp; the draft stays and can be posted again',
        (tester) async {
      tester.view.physicalSize = Size(width, 2000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final api = _Refusing();
      await tester.pumpWidget(MaterialApp(home: VoucherScreen(id: 'v7', api: api)));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('voucher-trx')), 'BF26102500001');
      await tester.tap(find.byKey(const ValueKey('voucher-post')));
      await tester.pumpAndSettle();

      final shown = find.text(_message);
      expect(shown, findsOneWidget, reason: '⛔ সার্ভারের payee_bank কথা পর্দায় নেই, বা পুরোটা নয়');
      final paragraph = tester.renderObject<RenderParagraph>(shown);
      expect(paragraph.didExceedMaxLines, isFalse, reason: '⛔ কথাটা "…"-এ কাটা');
      expect(tester.getTopLeft(shown).dy < tester.getTopLeft(find.byKey(const ValueKey('voucher-state'))).dy, isTrue,
          reason: '⛔ কথাটা পাতার উপরে নয়');

      // ⓘ খসড়া খসড়াই — পাতা, অবস্থা, পোস্টের বোতাম আর লেনদেন নম্বরের ঘর আগের মতো, আবার চাপা যায়
      expect(tester.widget<AppStatusChip>(find.byKey(const ValueKey('voucher-state'))).state, PaperState.draft, reason: '⛔ খসড়া আর খসড়া নেই');
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('voucher-post'))).onPressed, isNotNull);
      expect(tester.widget<TextField>(find.byKey(const ValueKey('voucher-trx'))).enabled ?? true, isTrue);
      expect(tester.widget<TextField>(find.byKey(const ValueKey('voucher-trx'))).controller!.text, 'BF26102500001',
          reason: '⛔ লেখা নম্বর হারাল');
      await tester.tap(find.byKey(const ValueKey('voucher-post')));
      await tester.pumpAndSettle();
      expect(api.posts, 2);
      expect(find.text(_message), findsOneWidget);
    });
  }

  test('every key\'s sentence is read, a form field or not; English-only noise still falls back', () {
    DioException refusal(Map<String, dynamic> body) {
      final o = RequestOptions(path: '/x');
      return DioException(requestOptions: o, response: Response(requestOptions: o, statusCode: 422, data: body));
    }

    expect(voucherRefusal(refusal({
      'message': 'ছোট কথা (and 1 more error)',
      'errors': {
        'instrument_no': ['লেনদেন নম্বর লিখুন।'],
        'payee_bank': [_message],
      },
    })), 'লেনদেন নম্বর লিখুন।\n$_message');
    expect(voucherRefusal(refusal({'message': 'The given data was invalid.', 'errors': {'x': ['The x field is required.']}})), isNull);
    expect(voucherRefusal(refusal({'message': 'ভাউচারটা আর খসড়া নয়।'})), 'ভাউচারটা আর খসড়া নয়।');
    // ⓘ ইংরেজিতে চলা অ্যাপ: সার্ভার মানুষের কথা ইংরেজিতে বলে — সেটাও পুরোটা
    AppLocale.current = AppLocale.english;
    addTearDown(() => AppLocale.current = AppLocale.locale);
    expect(voucherRefusal(refusal({
      'errors': {
        'payee_bank': ["The supplier's bank details have no second signature yet."],
      },
    })), "The supplier's bank details have no second signature yet.");
  });
}
