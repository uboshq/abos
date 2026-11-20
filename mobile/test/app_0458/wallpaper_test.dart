import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/core/theme/glass_wallpaper.dart';
import 'package:abos_mobile/features/profile/look_screen.dart';

/// ⭐ Liquid Glass-এর পটভূমি (মালিক, ২০ নভেম্বর ২০২৬): নয়টা, সবই নিজেদের আঁকা; শুরুতে রঙিন বল; লেখা সব পটে পড়া যায়;
/// বেগুনি ঢেউ বাছাইয়ে প্রথমে, কিন্তু শুরুর পট নয়
AppLooks _memory(Map<String, String> box) => AppLooks(read: (k) async => box[k], write: (k, v) async => box[k] = v);

double _contrast(Color a, Color b) {
  final la = a.computeLuminance(), lb = b.computeLuminance();
  return ((la > lb ? la : lb) + 0.05) / ((la > lb ? lb : la) + 0.05);
}

void main() {
  late AppLooks saved;
  setUp(() => saved = AppLooks.instance);
  tearDown(() => AppLooks.instance = saved);

  test('nine backgrounds, purple wave first; the colour balls are still the default; a choice is kept and read back', () async {
    expect(GlassWallpaper.values, hasLength(9));
    expect(GlassWallpaper.values.first, GlassWallpaper.purpleWave);
    final box = <String, String>{};
    final first = _memory(box);
    await first.load();
    expect(first.wallpaper.value, GlassWallpaper.balls, reason: 'শুরুতে আগের রঙিন বল');
    await first.chooseWallpaper(GlassWallpaper.purple);
    final next = _memory(box);
    await next.load();
    expect(next.wallpaper.value, GlassWallpaper.purple);
    await next.chooseWallpaper(GlassWallpaper.purpleWave);
    final third = _memory(box);
    await third.load();
    expect(third.wallpaper.value, GlassWallpaper.purpleWave);
    expect(AppLooks.parseWallpaper('nonsense'), GlassWallpaper.balls);
  });

  // ⓘ সবচেয়ে খারাপ অবস্থা: প্রতিটা রং (আভা আর ঢেউ নিজের স্বচ্ছতায়, পটের সবচেয়ে উজ্জ্বল রঙের ওপর), তার ওপর কালচে পর্দা,
  // তার ওপর সবচেয়ে ঘন কাচের সাদা আভা (১৮%) — সাদা লেখা তবু ৪.৫:১ (WCAG 1.4.3)। ⓘ দুই আভা একে অন্যের ওপর পড়লে একটু
  // উজ্জ্বল হতে পারে — এটা এক-স্তরের হিসাব
  for (final paper in GlassWallpaper.values.where((w) => w != GlassWallpaper.balls)) {
    test('white text on the glass reads at 4.5:1 everywhere on "${paper.name}"', () {
      final spec = paper.spec;
      final brightest = spec.base.reduce((a, b) => a.computeLuminance() >= b.computeLuminance() ? a : b);
      final points = <String, Color>{
        for (final c in spec.base) 'base $c': c,
        for (final g in spec.glows) 'glow ${g.color}': Color.alphaBlend(g.color.withValues(alpha: g.alpha), brightest),
        for (final w in spec.waves) ...{
          'wave ${w.to}': Color.alphaBlend(w.to.withValues(alpha: w.alpha), brightest),
          'wave ${w.from}': Color.alphaBlend(w.from.withValues(alpha: w.alpha), brightest),
        },
      };
      final glass = GlassSpec.of(100).fill;
      for (final MapEntry(key: where, value: c) in points.entries) {
        final scrimmed = Color.alphaBlend(Color.fromRGBO(0, 0, 0, spec.scrim), c);
        final underGlass = Color.alphaBlend(Colors.white.withValues(alpha: glass), scrimmed);
        expect(_contrast(Colors.white, underGlass), greaterThanOrEqualTo(4.5), reason: '${paper.name}: $where');
      }
    });
  }

  testWidgets('a chosen background is a still picture (cached), not the moving balls', (tester) async {
    AppLooks.instance = _memory({});
    AppLooks.instance.look.value = AppLook.glass;
    await tester.pumpWidget(const MaterialApp(home: GlassBackdrop(child: SizedBox.expand())));
    expect(find.byKey(const ValueKey('wallpaper-purple')), findsNothing);
    await AppLooks.instance.chooseWallpaper(GlassWallpaper.purple);
    await tester.pump();
    final paint = tester.widget<CustomPaint>(find.byKey(const ValueKey('wallpaper-purple')));
    expect(paint.isComplex, isTrue);
    expect(paint.willChange, isFalse);
    expect(find.ancestor(of: find.byKey(const ValueKey('wallpaper-purple')), matching: find.byType(RepaintBoundary)), findsWidgets);
    // ⓘ নড়ে না — আর কোনো ফ্রেম বাকি নেই
    expect(tester.hasRunningAnimations, isFalse);
  });

  test('purple wave is drawn by its own still painter, the others by the shared one', () {
    expect(GlassWallpaper.purpleWave.painter, isA<PurpleWavePainter>());
    expect(GlassWallpaper.purpleWave.painter.shouldRepaint(const PurpleWavePainter()), isFalse);
    for (final w in GlassWallpaper.values.where((w) => w != GlassWallpaper.purpleWave)) {
      expect(w.painter, isA<WallpaperPainter>(), reason: w.name);
    }
  });

  testWidgets('purple wave is a still picture (cached) too', (tester) async {
    AppLooks.instance = _memory({});
    AppLooks.instance.look.value = AppLook.glass;
    await AppLooks.instance.chooseWallpaper(GlassWallpaper.purpleWave);
    await tester.pumpWidget(const MaterialApp(home: GlassBackdrop(child: SizedBox.expand())));
    final paint = tester.widget<CustomPaint>(find.byKey(const ValueKey('wallpaper-purpleWave')));
    expect(paint.painter, isA<PurpleWavePainter>());
    expect(paint.isComplex, isTrue);
    expect(paint.willChange, isFalse);
    expect(tester.takeException(), isNull);
    expect(tester.hasRunningAnimations, isFalse);
  });

  testWidgets('the picker: only under Liquid Glass, nine swatches with names, purple wave first, a tap chooses', (tester) async {
    final looks = _memory({});
    AppLooks.instance = looks;
    tester.view.physicalSize = const Size(412, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: LookScreen(looks: looks)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('look-wallpaper-purple')), findsNothing, reason: 'ম্যাটেরিয়াল/ক্লাসিকে পটভূমি নেই');
    await tester.tap(find.byKey(const ValueKey('look-glass')));
    await tester.pumpAndSettle();
    for (final w in GlassWallpaper.values) {
      expect(find.byKey(ValueKey('look-wallpaper-${w.name}')), findsOneWidget, reason: w.name);
    }
    expect(find.text('গ্লাস চেহারার পটভূমি'), findsOneWidget);
    for (final name in ['বেগুনি ঢেউ', 'রঙিন বল', 'বেগুনি আভা', 'নীল ঢেউ', 'সূর্যাস্ত', 'সবুজ আলো', 'সমুদ্র', 'রাতের কালো', 'রুপালি']) {
      expect(find.text(name), findsOneWidget, reason: name);
    }
    // ⭐ বেগুনি ঢেউ প্রথম ঘর — রঙিন বলের আগে
    final wave = tester.getTopLeft(find.byKey(const ValueKey('look-wallpaper-purpleWave')));
    final balls = tester.getTopLeft(find.byKey(const ValueKey('look-wallpaper-balls')));
    expect(wave.dy, balls.dy);
    expect(wave.dx, lessThan(balls.dx));
    expect(looks.wallpaper.value, GlassWallpaper.balls, reason: 'প্রথমে থাকলেও শুরুর পট রঙিন বল');
    // ⓘ পটভূমি কাচের ঝাপসার দাগের ঠিক ওপরে
    expect(tester.getTopLeft(find.byKey(const ValueKey('look-wallpaper-balls'))).dy,
        lessThan(tester.getTopLeft(find.byKey(const ValueKey('look-blur'))).dy));
    await tester.tap(find.byKey(const ValueKey('look-wallpaper-sunset')));
    await tester.pumpAndSettle();
    expect(looks.wallpaper.value, GlassWallpaper.sunset);
  });
}
