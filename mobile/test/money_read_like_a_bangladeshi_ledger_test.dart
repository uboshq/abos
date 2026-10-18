import 'package:abos_mobile/core/records/money.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ টাকা বাংলাদেশের হিসাবের ধারায় — লাখ-কোটির ভাগ আর সবসময় দুই দশমিক (পূর্ণ নিরীক্ষা §৯ ⚠️৬, ১৮ অক্টোবর ২০২৬)।
///
/// <p>⛔ আগে `#,##0.##`: ১২,৩৪,৫৬৭ দেখাত "1,234,567", আর ১,২৫০.৫০ দেখাত "1,250.5"; কয়েকটা মোটের পট্টিতে সার্ভারের কাঁচা
/// লেখা ("৳ 12500.00") সোজা বসত। ⓘ পরিমাণ নিজের দশমিক রাখে — ০.১২৫ কেজি কাটা চলে না।
void main() {
  test('money is grouped in lakh and crore and always has two decimals', () {
    expect(Money.taka('1234567.5000'), '৳12,34,567.50');
    expect(Money.taka('12345678.1'), '৳1,23,45,678.10');
    expect(Money.taka('1250.5'), '৳1,250.50');
    expect(Money.taka(40), '৳40.00');
    expect(Money.taka('0'), '৳0.00');
    expect(Money.amount('-5000'), '-5,000.00');
  });

  test('a quantity keeps its own fraction and gets the same grouping', () {
    expect(Money.plain('0.1250'), '0.125');
    expect(Money.plain('123456.0000'), '1,23,456');
    expect(Money.plain('12.5000'), '12.5');
  });

  test('text that is not a number passes through rather than vanishing', () {
    expect(Money.taka('45,000.00'), '৳45,000.00');
    expect(Money.amount(null), '—');
  });
}
