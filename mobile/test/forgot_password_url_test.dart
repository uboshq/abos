import 'package:abos_mobile/features/auth/login_screen.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ "forget passworads kaj korena app e" (মালিক, ২ অক্টোবর ২০২৬) — বোতাম এখন ওয়েবের রিসেট-পাতা খোলে।
void main() {
  test('the reset page sits beside the api, not under it', () {
    expect(forgotPasswordUrl('https://erp.adi.com.bd/api/v1').toString(), 'https://erp.adi.com.bd/forgot-password');
    expect(forgotPasswordUrl('https://demo.adi.com.bd/abos/api/v1/').toString(), 'https://demo.adi.com.bd/abos/forgot-password');
  });
}
