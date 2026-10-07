import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⛔ পুরনো সার্ভার সুইচ না পাঠালে — পর্দা আড়াল, উইজেটে অঙ্ক নয় (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬)
void main() {
  test('no word from the server means private screens and hidden widget money', () {
    final p = SessionProfile.fromJson(const {});
    expect(p.secureScreens, isTrue, reason: '⛔ সুইচ না এলে পর্দা খোলা');
    expect(p.widgetAmounts, isFalse, reason: '⛔ সুইচ না এলে উইজেটে অঙ্ক');
  });

  test('the owner\'s switches are followed when they come', () {
    final p = SessionProfile.fromJson(const {'secureScreens': false, 'widgetAmounts': true});
    expect(p.secureScreens, isFalse);
    expect(p.widgetAmounts, isTrue);
  });
}
