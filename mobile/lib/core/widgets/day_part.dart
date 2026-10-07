/// The greeting a depot actually says at this hour.
///
/// <p>Takes a [DateTime] rather than reading the clock itself, so every
/// boundary can be checked in a test without faking one — the same shape the
/// Nexus app settled on after greeting people with "শুভ সকাল" at eight in the
/// evening because nothing ever looked at the clock.
///
/// <p>Night is the one band that wraps midnight, so the small hours are
/// handled first: written as a chain of ascending checks without that, one
/// in the morning falls through to whatever comes first.
///
/// <p>"শুভ ভোর" is not a greeting anybody says, and the late one is
/// "শুভ রাত্রি" rather than "শুভ রাত" — so this is its own small map, not a
/// day-part label with শুভ in front of it.
String banglaGreeting(DateTime when) {
  final hour = when.hour;
  if (hour < 6) return 'শুভ রাত্রি';
  if (hour < 12) return 'শুভ সকাল';
  if (hour < 15) return 'শুভ দুপুর';
  if (hour < 18) return 'শুভ বিকাল';
  if (hour < 20) return 'শুভ সন্ধ্যা';
  return 'শুভ রাত্রি';
}

/// রাত · ভোর · সকাল · দুপুর · বিকাল · সন্ধ্যা — the label that goes in front
/// of a clock time.
///
/// <p>Six, not three. Bangla names these parts and a depot says them; three
/// would collapse everything before noon into "সকাল", which is how two in the
/// morning becomes morning on a widget that is refreshed all night.
String banglaDayPart(DateTime when) {
  final hour = when.hour;
  if (hour < 4) return 'রাত';
  if (hour < 6) return 'ভোর';
  if (hour < 12) return 'সকাল';
  if (hour < 15) return 'দুপুর';
  if (hour < 18) return 'বিকাল';
  if (hour < 20) return 'সন্ধ্যা';
  return 'রাত';
}

/// "সকাল 4:12" — a twelve-hour clock time with its part of day in front.
///
/// <p>The part of day is said once, here. A caller that also put
/// [banglaDayPart] in front would print "রাত রাত 12:30".
///
/// <p>Digits as the rest of this app writes them. A widget that counted in
/// one script beside an app that counts in another would be the same number
/// read two ways.
String banglaClock(DateTime when) {
  final hour = when.hour % 12 == 0 ? 12 : when.hour % 12;
  final minute = when.minute.toString().padLeft(2, '0');
  return '${banglaDayPart(when)} $hour:$minute';
}
