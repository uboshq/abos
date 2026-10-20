import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/theme/app_palette.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';

/// ⭐ WCAG 2.2 AA ১.৪.৩: প্রতিটা অবস্থার লেখা আর আইকন (একই রং) নিজের পটে আর অ্যাপের পাতার পটে কমপক্ষে ৪.৫:১ —
/// আলো আর অন্ধকার দুটোতেই। আপেক্ষিক উজ্জ্বলতা এখানেই হিসাব করা (WCAG-এর সূত্র), অ্যাপের নিজের হিসাবের ওপর ভর নয়।
double _channel(int c8) {
  final c = c8 / 255.0;
  return c <= 0.04045 ? c / 12.92 : math.pow((c + 0.055) / 1.055, 2.4).toDouble();
}

double _luminance(Color c) {
  final v = c.toARGB32();
  return 0.2126 * _channel((v >> 16) & 0xFF) + 0.7152 * _channel((v >> 8) & 0xFF) + 0.0722 * _channel(v & 0xFF);
}

double contrast(Color a, Color b) {
  final x = _luminance(a), y = _luminance(b);
  return (math.max(x, y) + 0.05) / (math.min(x, y) + 0.05);
}

void main() {
  test('the formula is WCAG\'s: black on white is 21:1, white on white 1:1', () {
    expect(contrast(const Color(0xFF000000), const Color(0xFFFFFFFF)), closeTo(21, 0.001));
    expect(contrast(const Color(0xFFFFFFFF), const Color(0xFFFFFFFF)), closeTo(1, 0.001));
  });

  final light = AppTheme.light();
  final dark = AppTheme.dark();
  final darkPalette = dark.extension<AppPalette>() ?? AppPalette.darkFrom(dark.colorScheme);
  final pages = <Brightness, Map<String, Color>>{
    Brightness.light: {
      'card ${AppPalette.light.surface}': AppPalette.light.surface,
      'page ${AppPalette.light.surfaceMuted}': AppPalette.light.surfaceMuted,
      'scaffold': light.scaffoldBackgroundColor,
      'colorScheme.surface': light.colorScheme.surface,
    },
    Brightness.dark: {
      'card (surfaceContainerLow)': dark.colorScheme.surfaceContainerLow,
      'page (surface)': dark.colorScheme.surface,
      'scaffold': dark.scaffoldBackgroundColor,
      'palette.surface': darkPalette.surface,
      'sheet (surfaceContainerLow)': dark.colorScheme.surfaceContainerLow,
    },
  };

  for (final state in PaperState.values) {
    for (final b in Brightness.values) {
      test('${state.key} (${b.name}): text and icon reach 4.5:1 on the chip and on every page surface', () {
        final c = state.spec.colours.of(b);
        final onChip = contrast(c.foreground, c.background);
        expect(onChip, greaterThanOrEqualTo(4.5), reason: 'on its own background: ${onChip.toStringAsFixed(2)}');
        for (final page in pages[b]!.entries) {
          final r = contrast(c.foreground, page.value);
          expect(r, greaterThanOrEqualTo(4.5), reason: 'on ${page.key}: ${r.toStringAsFixed(2)}');
        }
      });
    }
  }

  // ⭐ WCAG ১.৪.১১: যেখানে ধার আঁকা হয় (পুরো চিপ; ছোট চিপে কেবল "পোস্ট হয়নি"-এর ভাঙা ধার), সেখানে ধার নিজের পটে আর
  // পাতার পটে কমপক্ষে ৩:১ — আলো আর অন্ধকারে
  for (final role in PaperRole.values) {
    for (final b in Brightness.values) {
      test('${role.name} border (${b.name}) reaches 3:1 on the chip and on every page surface', () {
        final c = paperRoleSpecs[role]!.of(b);
        final onChip = contrast(c.border, c.background);
        expect(onChip, greaterThanOrEqualTo(3.0), reason: 'on its own background: ${onChip.toStringAsFixed(2)}');
        for (final page in pages[b]!.entries) {
          final r = contrast(c.border, page.value);
          expect(r, greaterThanOrEqualTo(3.0), reason: 'on ${page.key}: ${r.toStringAsFixed(2)}');
        }
      });
    }
  }

  test('print the table (for the report)', () {
    for (final b in Brightness.values) {
      for (final role in PaperRole.values) {
        final c = paperRoleSpecs[role]!.of(b);
        final ps = pages[b]!.values.map((p) => contrast(c.border, p).toStringAsFixed(2)).join(' ');
        // ignore: avoid_print
        print('BORDER ${b.name} ${role.name} chip=${contrast(c.border, c.background).toStringAsFixed(2)} pages=$ps');
      }
    }
    for (final b in Brightness.values) {
      for (final state in PaperState.values) {
        final c = state.spec.colours.of(b);
        final ps = pages[b]!.values.map((p) => contrast(c.foreground, p).toStringAsFixed(2)).join(' ');
        // ignore: avoid_print
        print('CONTRAST ${b.name} ${state.key} chip=${contrast(c.foreground, c.background).toStringAsFixed(2)} pages=$ps');
      }
    }
  });
}
