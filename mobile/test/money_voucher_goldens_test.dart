import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/vouchers/money_voucher_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/bar_fonts.dart';

/// ⭐ পরিশোধ আর খরচের ভাউচারের ছবি — ওয়েবের টাকা দেওয়ার পর্দার গড়ন (নমুনা: মালিকের-ফাইল/টাকা-নেওয়া-ও-দেওয়ার-পর্দা-নমুনা-২১-অক্টোবর,
/// ডানের "টাকা দেওয়া"), এক কলামে; 412dp বাংলা আর ইংরেজি, 360dp ২০০%। আসল ফন্টে, এক PC-তে বানানো; বদলালে ছবি খুলে দেখে
/// `--update-goldens`।
final _bengali = RegExp('[ঀ-৲৴-৿]');

/// সার্ভার থেকে আসা নাম — ইংরেজিতেও বাংলা থাকে
const _serverWords = ['প্রধান নগদ', 'বিকাশ', 'ডাচ-বাংলা', 'জাবেদ', 'সরবরাহকারী', 'কর্মী', 'সোনালী', 'মতিঝিল', 'ভাড়া', 'বাড়িওয়ালা', 'নতুন সাপ্লাই'];

const _cash = VoucherAccount(id: 11, code: '1101-01', name: 'প্রধান নগদ', kind: 'cash');
const _bkash = VoucherAccount(id: 13, code: '1105-01', name: 'বিকাশ এজেন্ট', kind: 'mfs');
const _bank = VoucherAccount(id: 12, code: '1102', name: 'ডাচ-বাংলা ব্যাংক চলতি', kind: 'bank');
const _rent = VoucherAccount(id: 51, code: '5101', name: 'ভাড়া');

class _Fake implements VoucherApi, VoucherPreviewer {
  _Fake({this.contract = 1});

  /// ⓘ ২ — চুক্তি ২-এর পরিশোধ (মোট টাকা, AIT/VDS, বিল, সার্ভারের হিসাব)
  final int contract;

  @override
  Future<VoucherPreview?> preview(VoucherDraft draft) async => VoucherPreview.fromJson({
        'net_amount': '116500.0000',
        'ledger': [
          {'account': '2111 সরবরাহকারীর দেনা', 'debit': '120000.0000', 'credit': '0'},
          {'account': '2121 উৎসে কর্তিত কর', 'debit': '0', 'credit': '2000.0000'},
          {'account': '2122 উৎসে কর্তিত ভ্যাট', 'debit': '0', 'credit': '1500.0000'},
          {'account': '5211 ব্যাংক চার্জ', 'debit': '25.0000', 'credit': '0'},
          {'account': '1102 ডাচ-বাংলা ব্যাংক চলতি', 'debit': '0', 'credit': '116525.0000'},
        ],
      });

  @override
  Future<VoucherSetup> setup(String type) async => VoucherSetup(
        type: type,
        today: DateTime(2026, 10, 24),
        from: const VoucherSide(label: '', accounts: [_cash, _bkash, _bank]),
        to: const VoucherSide(label: '', accounts: [_rent]),
        parties: const [
          VoucherParty(
            type: 'supplier',
            id: 3,
            label: 'জাবেদ অ্যাগ্রো ফুড প্রসেসিং লিমিটেড · SUP-0003 · সরবরাহকারী',
            name: 'জাবেদ অ্যাগ্রো ফুড প্রসেসিং লিমিটেড · SUP-0003',
            due: 12000000,
            bank: PayeeBank(state: 'signed', line: 'সোনালী ব্যাংক · মতিঝিল · Jabed Agro Food Processing Ltd · হিসাব ••••4521'),
            bills: [
              VoucherBill(id: 'bill-57', no: 'PB-0057', date: '2026-10-15', due: 8000000),
              VoucherBill(id: 'bill-61', no: 'PB-0061', date: '2026-10-20', due: 4000000),
            ],
          ),
          VoucherParty(type: 'supplier', id: 4, label: 'নতুন সাপ্লাই · সরবরাহকারী', name: 'নতুন সাপ্লাই', bank: PayeeBank(state: 'pending')),
        ],
        partyTypes: const [('supplier', 'সরবরাহকারী'), ('staff', 'কর্মী')],
        transferModes: const [VoucherTransferMode(id: 1, name: 'BEFTN'), VoucherTransferMode(id: 2, name: 'NPSB')],
        contract: contract,
      );

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) async => const PushOutcome();

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: []);

  @override
  Future<VoucherPage> one(String id) => throw UnimplementedError();

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) => throw UnimplementedError();
}

Future<void> _tap(WidgetTester tester, String key) async {
  final f = find.byKey(ValueKey(key));
  await tester.ensureVisible(f);
  await tester.pumpAndSettle();
  await tester.tap(f);
  await tester.pumpAndSettle();
}

Future<void> _type(WidgetTester tester, String key, String text) async {
  final f = find.byKey(ValueKey(key));
  await tester.ensureVisible(f);
  await tester.enterText(f, text);
  await tester.pumpAndSettle();
}

/// [unsigned]: সই-না-পাওয়া ব্যাংকের সরবরাহকারী — ট্রান্সফার আটকে থাকার ছবি
Future<void> _open(WidgetTester tester, String type,
    {Locale locale = AppLocale.locale, double width = 412, double textScale = 1, bool unsigned = false, int contract = 1}) async {
  tester.view.physicalSize =
      Size(width, (type == VoucherTypes.payment ? (contract >= 2 ? 3000 : 1900) : 1700) * (textScale > 1 ? 2.1 : 1));
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  AppLocale.current = locale;
  addTearDown(() => AppLocale.current = AppLocale.locale);
  await tester.pumpWidget(RepaintBoundary(
    key: const ValueKey('shot'),
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: withBarFonts(AppTheme.light()),
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      builder: (context, child) => MediaQuery.withClampedTextScaling(minScaleFactor: textScale, maxScaleFactor: textScale, child: child!),
      home: MoneyVoucherScreen(type: type, api: _Fake(contract: contract), newChangeId: () => 'k', today: DateTime(2026, 10, 24)),
    ),
  ));
  await tester.pumpAndSettle();
  if (type == VoucherTypes.payment) {
    await _tap(tester, 'pay-party');
    await tester.tap(find.byKey(ValueKey('pay-party-option-${unsigned ? 1 : 0}')));
    await tester.pumpAndSettle();
    if (contract >= 2) {
      // ⓘ দুই বিলে টিক — মোটে বকেয়ার যোগফল বসে; তারপর মোট বাড়িয়ে অগ্রিমের লাইন, আর AIT/VDS
      await _tap(tester, 'pay-bill-tick-PB-0057');
      await _tap(tester, 'pay-bill-tick-PB-0061');
      await _type(tester, 'pay-amount', '125000');
      await _type(tester, 'pay-ait', '2000');
      await _type(tester, 'pay-vds', '1500');
    } else {
      await _type(tester, 'pay-amount', '120000');
    }
    await _tap(tester, 'pay-method-transfer');
    await _tap(tester, 'pay-transfer-mode');
    await tester.tap(find.text('BEFTN').last);
    await tester.pumpAndSettle();
    await _type(tester, 'pay-reference', 'BF26102100981');
    await _type(tester, 'pay-charge', '25');
    if (contract >= 2) await _tap(tester, 'pay-preview');
  } else {
    await _tap(tester, 'pay-head');
    await tester.tap(find.byKey(const ValueKey('pay-head-option-0')));
    await tester.pumpAndSettle();
    await _type(tester, 'pay-payee', 'বাড়িওয়ালা');
    await _type(tester, 'pay-bill-no', 'B-77');
    await _type(tester, 'pay-amount', '15000');
  }
  FocusManager.instance.primaryFocus?.unfocus();
  await tester.pumpAndSettle();
  await tester.drag(find.byKey(const ValueKey('pay-list')), const Offset(0, 20000));
  await tester.pumpAndSettle();
}

List<String> _texts(WidgetTester tester) => [
      for (final t in tester.widgetList<Text>(find.byType(Text))) t.data ?? t.textSpan?.toPlainText() ?? '',
      for (final d in tester.widgetList<InputDecorator>(find.byType(InputDecorator))) d.decoration.labelText ?? '',
    ];

void main() {
  setUpAll(loadBarFonts);

  for (final type in [VoucherTypes.payment, VoucherTypes.expense]) {
    testWidgets('$type at 412dp, light, in Bengali', (tester) async {
      await _open(tester, type);
      expect(tester.takeException(), isNull);
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/vouchers/${type}_412_light_bn.png'));
    });

    testWidgets('$type at 412dp in English: no Bengali of the screen\'s own, only the server\'s names', (tester) async {
      await _open(tester, type, locale: AppLocale.english);
      final own = _texts(tester).where((t) => _bengali.hasMatch(t) && !_serverWords.any(t.contains)).toList();
      expect(own, isEmpty, reason: 'ইংরেজিতে পর্দার নিজের বাংলা: $own');
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/vouchers/${type}_412_light_en.png'));
    });

    testWidgets('$type at 360dp and 200% text: nothing overflows', (tester) async {
      await _open(tester, type, width: 360, textScale: 2);
      expect(tester.takeException(), isNull, reason: '⛔ ২০০% লেখায় উপচে পড়ল');
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/vouchers/${type}_360_light_bn_200.png'));
    });
  }

  // ⭐ চুক্তি ২ (cb, ২৫ অক্টোবর ২০২৬): মোট টাকা, AIT/VDS, বিল, অগ্রিম, আর সার্ভারের হিসাব (নিট ও খাতা)
  testWidgets('payment, contract 2, at 412dp, light, in Bengali', (tester) async {
    await _open(tester, VoucherTypes.payment, contract: 2);
    expect(tester.takeException(), isNull);
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/vouchers/payment_c2_412_light_bn.png'));
  });

  testWidgets("payment, contract 2, at 412dp in English: no Bengali of the screen's own", (tester) async {
    await _open(tester, VoucherTypes.payment, contract: 2, locale: AppLocale.english);
    final own = _texts(tester).where((t) => _bengali.hasMatch(t) && !_serverWords.any(t.contains) && !t.contains('উৎসে কর্তিত') && !t.contains('দেনা') && !t.contains('ব্যাংক চার্জ')).toList();
    expect(own, isEmpty, reason: 'ইংরেজিতে পর্দার নিজের বাংলা: $own');
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/vouchers/payment_c2_412_light_en.png'));
  });

  testWidgets('payment, contract 2, at 360dp and 200% text: nothing overflows', (tester) async {
    await _open(tester, VoucherTypes.payment, contract: 2, width: 360, textScale: 2);
    expect(tester.takeException(), isNull, reason: '⛔ ২০০% লেখায় উপচে পড়ল');
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/vouchers/payment_c2_360_light_bn_200.png'));
  });

  testWidgets('payment to a supplier whose bank is not signed: the box, the reason, and the post button off', (tester) async {
    await _open(tester, VoucherTypes.payment, unsigned: true);
    expect(tester.takeException(), isNull);
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/vouchers/payment_unsigned_412_light_bn.png'));
  });
}
