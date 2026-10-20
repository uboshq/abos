import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/navigation/nav_catalog.dart';

/// ⭐ মডিউলের নাম ফোনে আর ওয়েবে এক — বারের "› বিক্রয় › আদায়", মডিউলের ঘর আর ওয়েবের মেনু একই শব্দে (মালিকের অনুমোদিত নমুনা;
/// a5, ২০ অক্টোবর ২০২৬: ফোনে "বিক্রি", ওয়েবে আর নমুনায় "বিক্রয়" ছিল)। নাম পড়া হয় ওয়েবের নিজের ভাষার ফাইল থেকে।
void main() {
  test('the sales module reads as the web names it, and the other spelling still finds it', () {
    final web = File('../app/Modules/Sales/Resources/lang/bn/route.php').readAsStringSync();
    final name = RegExp(r"'sales'\s*=>\s*'([^']+)'").firstMatch(web)!.group(1);
    final sales = NavCatalog.moduleDef('sales')!;
    expect(sales.label, name);
    expect(sales.label, 'বিক্রয়');
    expect(sales.aliases, contains('বিক্রি'), reason: 'খোঁজায় পুরনো শব্দও');
  });
}
