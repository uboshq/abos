import 'package:flutter/widgets.dart';

import '../../l10n/app_localizations.dart';
import 'app_locale.dart';

export '../../l10n/app_localizations.dart' show AppL10n, lookupAppL10n;

/// ⭐ `context.l10n.avatarMenuSignOut` — পর্দার লেখা ARB থেকে (নির্দেশিকা: E:\ABOS\app-0443-ভাষা-নির্দেশিকা.md)।
///
/// <p>ⓘ গাছে AppL10n না থাকলে (একা-চলা পরীক্ষার খালি `MaterialApp`) [AppLocale.current]-এর ভাষায় — ভাঙে না, আর আজকের মতোই
/// বাংলা পড়ে।
extension L10nContext on BuildContext {
  AppL10n get l10n => Localizations.of<AppL10n>(this, AppL10n) ?? AppLocale.l10n;
}
