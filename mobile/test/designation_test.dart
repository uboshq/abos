import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ "role nadekiye designation dekhabe" (মালিক, ২ অক্টোবর ২০২৬) — /me-র পদবি আর ছবি পড়া, রোল নয়।
void main() {
  test('designation and photo are read; an empty one stays empty, never the role', () {
    final u = AuthUser.fromJson({
      'public_id': 'u1', 'name': 'মালিক', 'email': 'o@x', 'roles': ['super_admin'],
      'designation': 'গ্রুপ চেয়ারম্যান ও সিইও', 'avatar_url': 'https://erp.adi.com.bd/storage/a.jpg',
    });
    expect(u.designation, 'গ্রুপ চেয়ারম্যান ও সিইও');
    expect(u.avatarUrl, 'https://erp.adi.com.bd/storage/a.jpg');

    final none = AuthUser.fromJson({'name': 'SR', 'roles': ['field_sales'], 'designation': '  '});
    expect(none.designation, isNull);
    expect(none.avatarUrl, isNull);
  });
}
