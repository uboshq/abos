import 'dart:convert';
import 'dart:io';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/bar_fonts.dart';

/// ⭐ সই-না-পাওয়া ব্যাংকে পরিশোধ — সার্ভার ফেরানোর পরে ভাউচারের পাতা, মালিককে দেখানোর ছবি (fe, ২৫ অক্টোবর ২০২৬)।
///
/// <p>সার্ভারের কথা হুবহু: cb 40b094e42, app/Modules/Supplier/Resources/lang/{bn,en}/bank.php `unsigned_blocks_voucher`
/// (bn লাইন 47, en লাইন 46), ভরা হয় SupplierPayeeBankGuard.php:45-এ — `supplier` সরবরাহকারীর নাম, `url`
/// route('supplier.show', id), পুরো ঠিকানা। সার্ভার মানুষের ভাষায় উত্তর দেয়, তাই ইংরেজি ছবিতে ইংরেজি কথা।

const _serverCommit = '40b094e42';
const _url = 'https://erp.adi.com.bd/suppliers/37';

/// নমুনা সরবরাহকারী — পরিশোধের ছবিগুলোর একই (বাংলা নাম; ইংরেজিতে তার ব্যাংকের হিসাবের নাম)
const _supplierBn = 'জাবেদ অ্যাগ্রো ফুড প্রসেসিং লিমিটেড';
const _supplierEn = 'Jabed Agro Food Processing Ltd';

/// ⭐ ফেক যা পাঠায় — হুবহু, হাতে বসানো; নিচের পরীক্ষা সার্ভারের লেখার সাথে মিলিয়ে দেখে
const _bn = 'জাবেদ অ্যাগ্রো ফুড প্রসেসিং লিমিটেড-এর ব্যাংক তথ্যে এখনো দ্বিতীয় সই হয়নি — ব্যাংকে (ট্রান্সফার বা জমা) দেওয়া যাবে না। '
    'করণীয়: সরবরাহকারীর পাতায় ব্যাংক তথ্য দিন, আরেকজন দ্বিতীয় সই দিলে তবেই ব্যাংকে দেওয়া যাবে। '
    'পাতা: https://erp.adi.com.bd/suppliers/37 — নগদ, MFS বা চেকে এখনই দেওয়া যায়।';
const _en = "Jabed Agro Food Processing Ltd's bank details have no second signature yet — nothing can be paid into the bank "
    '(transfer or deposit). Next: enter the bank details on the supplier\'s page; once a second person signs them, bank payments '
    'open. Page: https://erp.adi.com.bd/suppliers/37 — cash, MFS or cheque can be paid now.';

/// সার্ভারের lang ফাইল থেকে কাঁচা বাক্য — `git show <commit>:<path>`, PHP-র একক-উদ্ধৃতির লেখা খুলে
String _serverLine(String locale) {
  final r = Process.runSync('git', ['-C', '..', 'show', '$_serverCommit:app/Modules/Supplier/Resources/lang/$locale/bank.php'],
      stdoutEncoding: utf8, stderrEncoding: utf8);
  if (r.exitCode != 0) fail('⛔ সার্ভারের $_serverCommit পড়া গেল না: ${r.stderr}');
  final m = RegExp(r"'unsigned_blocks_voucher'\s*=>\s*'((?:[^'\\]|\\.)*)'").firstMatch(r.stdout as String);
  if (m == null) fail('⛔ $_serverCommit-এ unsigned_blocks_voucher নেই ($locale)');
  return m.group(1)!.replaceAll(r"\'", "'").replaceAll(r'\\', r'\');
}

VoucherPage _page({required bool english}) => VoucherPage(
      row: VoucherRow(
        id: 'v10',
        no: 'PV-0010',
        type: 'payment',
        typeLabel: english ? 'Payment voucher' : 'পরিশোধ ভাউচার',
        state: 'draft',
        stateLabel: english ? 'Draft' : 'খসড়া',
        amount: 120000,
        date: '2026-10-25',
        party: english ? _supplierEn : '$_supplierBn · SUP-0003',
        partyLabel: english ? 'Paid to' : 'কাকে',
        narration: english ? 'Bills PB-0057 and PB-0061' : 'বিল PB-0057 আর PB-0061',
        writtenBy: 'আবু কাওসার',
      ),
      instrument: 'transfer',
      moneyAccount: '1102 · ডাচ-বাংলা ব্যাংক চলতি',
      moneyLabel: english ? 'Paid from' : 'যেখান থেকে দেওয়া',
      lines: const [
        VoucherLineView(account: '2111 সরবরাহকারীর দেনা', debit: 120000, credit: 0),
        VoucherLineView(account: '1102 ডাচ-বাংলা ব্যাংক চলতি', debit: 0, credit: 120000),
      ],
      canPost: true,
    );

class _Refusing implements VoucherApi {
  _Refusing(this.english);

  final bool english;
  int posts = 0;

  @override
  Future<VoucherPage> one(String id) async => _page(english: english);

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) async {
    posts++;
    final text = english ? _en : _bn;
    final o = RequestOptions(path: '/accounts/vouchers/$id/post');
    // ⭐ সার্ভারের উত্তর হুবহু: ৪২২ {message, errors: {payee_bank: [...]}}
    throw DioException(
      requestOptions: o,
      type: DioExceptionType.badResponse,
      response: Response(requestOptions: o, statusCode: 422, data: {
        'message': text,
        'errors': {
          'payee_bank': [text],
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

Future<_Refusing> _open(WidgetTester tester, {required bool english}) async {
  tester.view.physicalSize = const Size(412, 1300);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final locale = english ? AppLocale.english : AppLocale.locale;
  AppLocale.current = locale;
  addTearDown(() => AppLocale.current = AppLocale.locale);
  final api = _Refusing(english);
  await tester.pumpWidget(RepaintBoundary(
    key: const ValueKey('shot'),
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: withBarFonts(AppTheme.light()),
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: VoucherScreen(id: 'v10', api: api),
    ),
  ));
  await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const ValueKey('voucher-trx')), 'BF26102500981');
  await tester.tap(find.byKey(const ValueKey('voucher-post')));
  await tester.pumpAndSettle();
  FocusManager.instance.primaryFocus?.unfocus();
  await tester.pumpAndSettle();
  return api;
}

void main() {
  setUpAll(() async {
    await loadBarFonts();
    // ⓘ অবস্থার চিপের আইকন — material_symbols_icons-এর Rounded ফন্ট (আদায়ের ছবির একই পথ); না থাকলে ফাঁকা বাক্স
    final config = File('.dart_tool/package_config.json').readAsStringSync();
    final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
    final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
    final loader = FontLoader('packages/material_symbols_icons/MaterialSymbolsRounded')
      ..addFont(Future.value(ByteData.view(Uint8List.fromList(File('$root/lib/fonts/MaterialSymbolsRounded.ttf').readAsBytesSync()).buffer)));
    await loader.load();
  });

  test('the fake speaks the server\'s own words ($_serverCommit), placeholders filled', () {
    String fill(String line, String supplier) => line.replaceAll(':supplier', supplier).replaceAll(':url', _url);
    expect(_bn, fill(_serverLine('bn'), _supplierBn), reason: '⛔ ফেকের বাংলা সার্ভারের unsigned_blocks_voucher থেকে সরে গেছে');
    expect(_en, fill(_serverLine('en'), _supplierEn), reason: '⛔ ফেকের ইংরেজি সার্ভারের unsigned_blocks_voucher থেকে সরে গেছে');
  });

  for (final english in [false, true]) {
    final tag = english ? 'en' : 'bn';
    testWidgets('payment refused for an unsigned supplier bank, 412dp light $tag: the whole message on top, the URL whole, the draft kept',
        (tester) async {
      final api = await _open(tester, english: english);
      final text = english ? _en : _bn;
      expect(tester.takeException(), isNull);

      final shown = find.text(text);
      expect(shown, findsOneWidget, reason: '⛔ সার্ভারের পুরো কথা পর্দায় নেই');
      final p = tester.renderObject<RenderParagraph>(shown);
      expect(p.didExceedMaxLines, isFalse, reason: '⛔ কথাটা কাটা');
      // ⓘ ঠিকানাটা পুরো আঁকা — লেখার ভিতরে তার প্রতিটা অক্ষরের বাক্স পর্দার ভেতরে
      final at = text.indexOf(_url);
      final boxes = p.getBoxesForSelection(TextSelection(baseOffset: at, extentOffset: at + _url.length));
      final screen = Offset.zero & const Size(412, 1300);
      final origin = p.localToGlobal(Offset.zero);
      expect(boxes, isNotEmpty);
      for (final b in boxes) {
        final r = b.toRect().shift(origin);
        expect(r.left >= 0 && r.right <= screen.right, isTrue, reason: '⛔ ঠিকানার অংশ পর্দার বাইরে: $r');
      }
      // ⓘ উপরে, খসড়া খসড়াই, ঘর আর বোতাম চলে, কিছু কিছুর উপর পড়ে না
      final card = tester.getRect(find.byKey(const ValueKey('books-error')));
      expect(card.bottom <= tester.getRect(find.byKey(const ValueKey('voucher-state'))).top, isTrue, reason: '⛔ কথাটা উপরে নয়');
      expect(tester.widget<AppStatusChip>(find.byKey(const ValueKey('voucher-state'))).state, PaperState.draft);
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('voucher-post'))).onPressed, isNotNull);
      expect(tester.widget<TextField>(find.byKey(const ValueKey('voucher-trx'))).controller!.text, 'BF26102500981');
      expect(api.posts, 1);

      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/vouchers/payment_refused_payee_bank_412_light_$tag.png'));
    });
  }
}
