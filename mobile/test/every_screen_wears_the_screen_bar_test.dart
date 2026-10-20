import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ প্রতিটা ভেতরের পর্দায় হোমের একই নীল বার — "কোম্পানি · শাখা › মডিউল › পাতা" (মালিকের অনুমোদিত নকশা ৩, ২০ অক্টোবর
/// ২০২৬: "প্রতিটা পাতায়, সবসময়")। সাদামাটা `AppBar(` কেবল নিচের তালিকায়; তালিকা শুধু ছোট হয় (ratchet)।
const _stillPlain = {
  // ⓘ ওয়েব-দেখার পর্দা — নিজের বন্ধ, ছাপা, আবার-আনা বোতাম আর লোড-ফিতা বারেই; নীল বার ওগুলো ঢেকে দিত
  'lib/features/web_inside/web_inside_screen.dart',
  // ⏳ a5-এর ভাগ (০.৪.৪১) — সরানো হলে এখান থেকে মুছে দাও
  'lib/features/books/money_in_screens.dart',
  'lib/features/customers/due_list_screen.dart',
  'lib/features/dashboards/dashboards_screen.dart',
  'lib/features/delivery_orders/delivery_order_screens.dart',
  'lib/features/direct_sale/counter_screen.dart',
  'lib/features/direct_sale/return_screen.dart',
  'lib/features/home/paper_route.dart',
  'lib/features/orders/delivery_tracking_screen.dart',
  'lib/features/orders/new_order_screen.dart',
  'lib/features/orders/order_list_screen.dart',
};

void main() {
  test('no inner screen draws a plain AppBar outside the shrinking list', () {
    final plain = <String>{};
    for (final f in Directory('lib').listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.dart'))) {
      final path = f.path.replaceAll('\\', '/');
      if (path.contains('/core/navigation/top_bar/')) continue; // ⓘ বার নিজে — সেশন না থাকলে সাদামাটা AppBar
      final code = f.readAsLinesSync().where((l) => !l.trimLeft().startsWith('//')).join('\n');
      if (RegExp(r'(?<![A-Za-z])(Sliver)?AppBar\(').hasMatch(code)) plain.add(path);
    }
    expect(plain.difference(_stillPlain), isEmpty, reason: '⛔ নতুন পর্দায় AbosScreenBar নাও, AppBar নয়');
    expect(_stillPlain.difference(plain), isEmpty, reason: 'এই পর্দা এখন নীল বার পরে — তালিকা থেকে মুছে দাও');
  });
}
