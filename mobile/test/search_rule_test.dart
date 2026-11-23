import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/party_search.dart';
import 'package:abos_mobile/core/widgets/search_debouncer.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ তালিকার খোঁজা ওয়েবের নিয়মে (মালিক, ২৩ নভেম্বর ২০২৬): ৩ অক্ষর + শেষ চাপের ৬০০ ms পরে, Enter সাথে সাথে, খালি করলে সাথে সাথে;
/// আর পক্ষ নাম, মোবাইল (শেষ ৪ অঙ্কেও), ঠিকানা বা পয়েন্ট দিয়ে — সার্ভারের `PartySearch`-এর মতো, অফলাইনেও।
void main() {
  group('SearchDebouncer', () {
    late List<String> fired;
    late SearchDebouncer d;

    setUp(() {
      fired = [];
      d = SearchDebouncer(onSearch: fired.add);
    });
    tearDown(() => d.dispose());

    testWidgets('two characters and a long wait: no search', (tester) async {
      d.onChanged('r');
      d.onChanged('ra');
      await tester.pump(const Duration(seconds: 2));
      expect(fired, isEmpty);
    });

    testWidgets('three characters: one search, 600 ms after the last key', (tester) async {
      d.onChanged('r');
      d.onChanged('ra');
      d.onChanged('rah');
      await tester.pump(const Duration(milliseconds: 599));
      expect(fired, isEmpty);
      await tester.pump(const Duration(milliseconds: 1));
      expect(fired, ['rah']);
      await tester.pump(const Duration(seconds: 2));
      expect(fired, ['rah'], reason: 'একবারই');
    });

    testWidgets('typing again restarts the wait; only the last text is searched', (tester) async {
      d.onChanged('rah');
      await tester.pump(const Duration(milliseconds: 400));
      d.onChanged('rahi');
      await tester.pump(const Duration(milliseconds: 400));
      expect(fired, isEmpty, reason: '৪০০ + ৪০০ — কিন্তু শেষ চাপের পর মাত্র ৪০০');
      await tester.pump(const Duration(milliseconds: 200));
      expect(fired, ['rahi']);
    });

    testWidgets('going back under three characters cancels the waiting search', (tester) async {
      d.onChanged('rah');
      await tester.pump(const Duration(milliseconds: 300));
      d.onChanged('ra');
      await tester.pump(const Duration(seconds: 2));
      expect(fired, isEmpty);
    });

    testWidgets('Enter searches at once, even with one character, and cancels the timer', (tester) async {
      d.onChanged('rah');
      d.onSubmitted(' r ');
      expect(fired, ['r']);
      await tester.pump(const Duration(seconds: 2));
      expect(fired, ['r'], reason: 'অপেক্ষমাণ খোঁজা বাতিল');
    });

    testWidgets('clearing the box brings the whole list back at once', (tester) async {
      d.onChanged('rahim');
      await tester.pump(const Duration(milliseconds: 600));
      d.onChanged('');
      expect(fired, ['rahim', '']);
    });

    testWidgets('spaces do not count and the same text is not searched twice', (tester) async {
      d.onChanged('  ra  ');
      await tester.pump(const Duration(seconds: 1));
      expect(fired, isEmpty);
      d.onChanged('rah');
      await tester.pump(const Duration(milliseconds: 600));
      d.onChanged('rahi');
      d.onChanged('rah');
      await tester.pump(const Duration(milliseconds: 600));
      expect(fired, ['rah']);
    });

    testWidgets('three Bangla letters are enough', (tester) async {
      d.onChanged('রহি');
      await tester.pump(const Duration(milliseconds: 600));
      expect(fired, ['রহি']);
    });
  });

  group('party matching, offline as online', () {
    const shop = CustomerRecord({
      'id': 'c1',
      'code': 'C-0042',
      'nameBn': 'রহিম স্টোর',
      'nameEn': 'Rahim Store',
      'ownerName': 'Abdur Rahim',
      'phone': '+880 1711-22 3344',
      'addressBn': 'বাড়ি ১২, মিরপুর রোড',
      'addressEn': 'House 12, Mirpur Road',
      'pointName': 'কারওয়ান বাজার',
      'branchName': 'ঢাকা',
    });

    test('by the last four digits of the mobile, spaces and dashes ignored', () {
      expect(shop.matches('3344'), isTrue);
      expect(shop.matches('2233 44'), isTrue);
      expect(shop.matches('01711223344'), isTrue);
      expect(shop.matches('+880 1711 223344'), isTrue);
      expect(shop.matches('9999'), isFalse);
    });

    test('by the address, either language', () {
      expect(shop.matches('mirpur'), isTrue);
      expect(shop.matches('মিরপুর'), isTrue);
      expect(shop.matches('gulshan'), isFalse);
    });

    test('by the point, by the name, by the code', () {
      expect(shop.matches('কারওয়ান'), isTrue);
      expect(shop.matches('rahim'), isTrue);
      expect(shop.matches('c-0042'), isTrue);
    });

    test('every word must match somewhere', () {
      expect(shop.matches('rahim mirpur'), isTrue);
      expect(shop.matches('rahim gulshan'), isFalse);
      expect(shop.matches('  '), isTrue, reason: 'খালি খোঁজা — সব');
    });

    test('the digit rule matches the server', () {
      expect(partyPhoneDigits('+880 1711-223344'), '01711223344');
      expect(partyPhoneDigits('123'), isNull, reason: 'চার অঙ্কের কম');
      expect(partyPhoneDigits('12a4'), isNull);
      expect(partySearchWords('01711 223344').length, 1, reason: 'সংখ্যার মতো পুরো লেখা এক টুকরো');
      expect(partyMatches('3344', fields: const ['Karim'], phones: const ['01711-223344']), isTrue);
      expect(partyMatches('3344', fields: const ['Karim'], phones: const [null]), isFalse);
    });
  });
}
