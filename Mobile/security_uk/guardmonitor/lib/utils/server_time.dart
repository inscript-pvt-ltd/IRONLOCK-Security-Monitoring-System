/// Parses a server ISO-8601 timestamp as a true **UTC instant**.
///
/// The backend contract is that every datetime is UTC. Dart's `DateTime.parse`
/// treats a string **without a zone designator** (no trailing `Z`, no `±hh:mm`
/// offset) as device-**local** — so a zone-less server value would be silently
/// misread by the device's UTC offset (e.g. `13:00` shown hours off for a guard
/// in another timezone). This normalises that: a value carrying a zone (`Z` or a
/// numeric offset) is honoured as-is, and a zone-less value is interpreted as
/// UTC. Returns null for a null/blank/unparseable input.
///
/// Callers that want wall-clock for display should chain `?.toLocal()`.
DateTime? parseServerUtc(String? raw) {
  if (raw == null) return null;
  final s = raw.trim();
  if (s.isEmpty) return null;
  // Trailing `Z`, or a numeric offset `±HH:MM` / `±HHMM`. The date's `-`
  // separators can't false-match (an offset needs sign + 4 digits at the end).
  final hasZone = RegExp(r'(?:Z|[+-]\d{2}:?\d{2})$').hasMatch(s);
  final normalized = hasZone ? s : '${s}Z';
  // Fall back to the raw parse if appending `Z` produced something unparseable.
  return (DateTime.tryParse(normalized) ?? DateTime.tryParse(s))?.toUtc();
}
