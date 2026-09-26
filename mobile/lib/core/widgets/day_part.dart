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
