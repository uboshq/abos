import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/navigation/top_bar/avatar_menu.dart';

/// ⭐ ছবির মেনু কেবল যা সার্ভার পারে তা-ই দেখায় (মালিকের নিয়ম: অচল সারি নয়; a6-এর `/me` features, ২০ অক্টোবর ২০২৬):
/// ছবি বদলের দরজা থাকলে "প্রোফাইল ও ছবি বদল", নইলে "আমার প্রোফাইল"; ফোনের দরজা থাকলে তবেই "আমার ফোনগুলো"।
void main() {
  Future<void> open(WidgetTester tester, MeFeatures? features) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [meFeaturesProvider.overrideWith((ref) => features)],
      child: MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: Center(
              child: TextButton(
                key: const ValueKey('open'),
                onPressed: () => showAvatarMenu(context, top: 60, name: 'আল-আমিন শুভ', branch: 'সব শাখা'),
                child: const Text('খোলো'),
              ),
            ),
          ),
        ),
      ),
    ));
    await tester.tap(find.byKey(const ValueKey('open')));
    await tester.pumpAndSettle();
  }

  testWidgets('an old server (no features): "আমার প্রোফাইল", and no phones row', (tester) async {
    await open(tester, null);
    expect(find.text('আমার প্রোফাইল'), findsOneWidget);
    expect(find.text('প্রোফাইল ও ছবি বদল'), findsNothing, reason: '⛔ যে সার্ভার ছবি নেয় না, তার মেনুতে ছবি বদলের কথা');
    expect(find.byKey(const ValueKey('avatar-devices')), findsNothing, reason: '⛔ দরজা ছাড়া "আমার ফোনগুলো"');
  });

  testWidgets('a server with both doors: "প্রোফাইল ও ছবি বদল" and "আমার ফোনগুলো"', (tester) async {
    await open(tester, const MeFeatures(profilePhoto: true, myPhones: true));
    expect(find.text('প্রোফাইল ও ছবি বদল'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('avatar-devices')), matching: find.text('আমার ফোনগুলো')),
        findsOneWidget);
  });

  testWidgets('each row follows its own door: photo without phones, and phones without photo', (tester) async {
    await open(tester, const MeFeatures(profilePhoto: true));
    expect(find.text('প্রোফাইল ও ছবি বদল'), findsOneWidget);
    expect(find.byKey(const ValueKey('avatar-devices')), findsNothing);

    await tester.pumpWidget(const SizedBox());
    await open(tester, const MeFeatures(myPhones: true));
    expect(find.text('আমার প্রোফাইল'), findsOneWidget);
    expect(find.byKey(const ValueKey('avatar-devices')), findsOneWidget);
  });
}
