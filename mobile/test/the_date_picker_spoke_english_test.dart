import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ তারিখ বাছাই আর Material-এর নিজের লেখা বাংলায় (পূর্ণ নিরীক্ষা §৯ ⚠️৫, ১৮ অক্টোবর ২০২৬)।
///
/// <p>⛔ আগে "OK/CANCEL", মাসের নাম আর "Select date" ইংরেজিতে আসত, কারণ অ্যাপ বাংলার কোনো ভাষা-সম্ভার চিনত না।
void main() {
  testWidgets('a date picker opened in the app speaks Bangla', (tester) async {
    await tester.pumpWidget(MaterialApp(
      locale: AppLocale.locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: Builder(
        builder: (context) => TextButton(
          onPressed: () => showDatePicker(
              context: context, initialDate: DateTime(2026, 10, 18), firstDate: DateTime(2020), lastDate: DateTime(2030)),
          child: const Text('খুলুন'),
        ),
      ),
    ));

    await tester.tap(find.text('খুলুন'));
    await tester.pumpAndSettle();

    final l10n = MaterialLocalizations.of(tester.element(find.byType(DatePickerDialog)));
    expect(l10n.okButtonLabel, isNot('OK'), reason: '⛔ তারিখ বাছাইয়ের বোতাম ইংরেজিতে');
    expect(find.text('OK'), findsNothing);
    expect(find.text('CANCEL'), findsNothing);
    expect(find.text(l10n.okButtonLabel), findsOneWidget);
    expect(RegExp('[ঀ-৿]').hasMatch(l10n.okButtonLabel), isTrue, reason: 'বোতামের লেখা বাংলা অক্ষরে নয়');
  });

  test('the app itself is set to Bangla', () {
    expect(AppLocale.locale.languageCode, 'bn');
    expect(AppLocale.supported.first.languageCode, 'bn');
  });
}
