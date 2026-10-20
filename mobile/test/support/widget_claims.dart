import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';

/// ⭐ প্রতিটা উইজেটের দাবি-টেস্ট এটা ডাকবে — দলের চার নিয়ম এক জায়গায় (`E:\ABOS\app-widgets-ভিত্তি.md`):
///  ১) টাকার প্রতিটা লেখা গোপন-যোগ্য ([WidgetText.secret]) আর ইংরেজি অঙ্কে (৳1,25,000.00);
///  ২) বেতন বা মজুরির কোনো কথা নেই;
///  ৩) [customers]-এ দেওয়া প্রতিটা গ্রাহকের নাম যেখানে আছে, সেখানে "নাম · পয়েন্ট · শাখা";
///  ৪) নথি JSON-এ যায় আর "কখনকার হিসাব" আছে।
void expectWidgetRules(WidgetDoc doc, {Map<String, (String point, String branch)> customers = const {}}) {
  final texts = doc.texts.toList();
  for (final t in texts) {
    if (t.text.contains('৳')) {
      expect(t.secret, isTrue, reason: '⛔ "${t.text}": টাকা গোপন-যোগ্য নয় — তালাবন্ধ ফোনে দেখা যাবে');
      expect(RegExp('[০-৯]').hasMatch(t.text), isFalse, reason: '⛔ "${t.text}": টাকা ইংরেজি অঙ্কে (মালিকের সিদ্ধান্ত খ)');
    }
    expect(RegExp('বেতন|মজুরি|salary|payslip', caseSensitive: false).hasMatch(t.text), isFalse,
        reason: '⛔ "${t.text}": বেতন কোনো উইজেটে নয়');
  }
  for (final MapEntry(key: name, value: (point, branch)) in customers.entries) {
    final withName = texts.where((t) => t.text.contains(name)).toList();
    expect(withName, isNotEmpty, reason: '$name নথিতে নেই — টেস্ট ফাঁকা');
    for (final t in withName) {
      expect(t.text, contains('$name · $point · $branch'), reason: '⛔ "${t.text}": নাম · পয়েন্ট · শাখা নয়');
    }
  }
  expect(doc.encode(), contains('"asOf"'));
}
