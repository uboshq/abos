import 'dart:typed_data';

import 'package:abos_mobile/features/profile/my_phones_screen.dart';
import 'package:abos_mobile/features/profile/profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

import '../support/bar_fonts.dart';
import '../support/main_screens.dart' show setScreenSize;
import 'profile_fakes.dart';
import 'profile_host.dart';

/// ⭐ দুই পর্দার ছবি — আসল ফন্টে (Roboto, Shonar Bangla, Material Symbols), ৩৬০dp, আলো আর অন্ধকার, নমুনার নাম ও নীল বার
/// (a4-এর AbosScreenBar)। ⓘ ছবি এই PC-তে তোলা, এই PC-র ফন্টে।
void main() {
  setUpAll(loadBarFonts);

  /// মুখের জায়গায় একটা স্থির ছবি — কমলা থেকে বাদামি, প্রতিবার একই
  final face = () {
    final image = img.Image(width: 96, height: 96);
    for (final p in image) {
      p
        ..r = 230 - p.y
        ..g = 140 - p.y ~/ 2
        ..b = 60;
    }
    return Uint8List.fromList(img.encodePng(image));
  }();

  final screens = <String, Widget Function()>{
    'profile': () => ProfileScreen(api: FakeProfileApi(), faceOf: (_) => MemoryImage(face)),
    'phones': () => MyPhonesScreen(
          api: FakeProfileApi(pages: [
            [
              phone('aaaa1111', current: true, used: DateTime(2026, 10, 20, 10, 30)),
              phone('bbbb2222', used: DateTime(2026, 10, 18, 19, 45)),
              phone('cccc3333', name: 'iOS ফোন · 0.4.39 · #CCCC', used: DateTime(2026, 10, 2, 9, 5)),
            ],
            [phone('dddd4444')],
          ]),
        ),
  };

  for (final dark in [false, true]) {
    for (final s in screens.entries) {
      final name = '${s.key}_360_${dark ? 'dark' : 'light'}';
      testWidgets('$name looks as approved', (tester) async {
        setScreenSize(tester, const Size(360, 800));
        final c = profileContainer(face: s.key == 'profile' ? oldFace : null);
        addTearDown(c.dispose);
        await tester.pumpWidget(RepaintBoundary(
          key: const ValueKey('shot'),
          child: profileHost(c, s.value(), dark: dark, font: withBarFonts),
        ));
        await tester.pumpAndSettle();
        if (s.key == 'profile') {
          // ⓘ ছবি ভাঙা আসল async-এ — নইলে ছবিতে মুখের জায়গা ফাঁকা
          await tester.runAsync(() => precacheImage(MemoryImage(face), tester.element(find.byKey(const ValueKey('profile-screen')))));
          await tester.pumpAndSettle();
        }
        await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/profile/$name.png'));
      });
    }
  }
}
