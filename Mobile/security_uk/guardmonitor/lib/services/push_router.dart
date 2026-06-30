/// Parses + dispatches an incoming FCM push **data payload** into the app's
/// existing check flows. Deliberately decoupled from both Firebase and Riverpod
/// so it's pure and unit-testable: the FCM handler supplies the `data` map and
/// the callbacks, which it wires to the providers/services.
///
/// FCM data values are always strings, so everything here parses from strings.
///
/// Confirmed payloads (backend, 2026-06-24 — see docs/FCM_SETUP.md):
///   WAKEFULNESS_CHALLENGE: { type, check_id, shift_id, code, response_seconds }
///   PHOTO_REQUEST:         { type, request_id, shift_id, nonce_value }
///   PHOTO_REVIEWED:        { type, request_id, shift_id, decision, note }
library;

enum PushKind { wakefulness, photo, photoReviewed, unknown }

class PushMessage {
  const PushMessage({
    required this.kind,
    this.checkId,
    this.shiftId,
    this.code,
    this.responseSeconds,
    this.issuedAt,
    this.requestId,
    this.nonceValue,
    this.decision,
    this.note,
  });

  final PushKind kind;
  final String? checkId;
  final String? shiftId;
  final String? code;
  final int? responseSeconds;
  // Server issue time of a wakefulness challenge, when sent — lets the app
  // anchor the response countdown to the server clock rather than restarting it
  // from scratch on a late-delivered push.
  final DateTime? issuedAt;
  final String? requestId;
  final String? nonceValue;
  // PHOTO_REVIEWED: the supervisor's verdict + optional note.
  final String? decision; // 'APPROVED' | 'REJECTED'
  final String? note;

  /// True only when every field the app needs to act on this push is present.
  bool get isActionable => switch (kind) {
        PushKind.wakefulness =>
          (checkId?.isNotEmpty ?? false) && (code?.isNotEmpty ?? false),
        PushKind.photo => (requestId?.isNotEmpty ?? false) &&
            (nonceValue?.isNotEmpty ?? false),
        PushKind.photoReviewed => (requestId?.isNotEmpty ?? false) &&
            (decision?.isNotEmpty ?? false),
        PushKind.unknown => false,
      };

  factory PushMessage.fromData(Map<String, dynamic> data) {
    String? s(String k) => data[k]?.toString();

    final kind = switch (s('type')) {
      'WAKEFULNESS_CHALLENGE' => PushKind.wakefulness,
      'PHOTO_REQUEST' => PushKind.photo,
      'PHOTO_REVIEWED' => PushKind.photoReviewed,
      _ => PushKind.unknown,
    };

    return PushMessage(
      kind: kind,
      checkId: s('check_id'),
      shiftId: s('shift_id'),
      code: s('code'),
      responseSeconds: int.tryParse(s('response_seconds') ?? ''),
      issuedAt: DateTime.tryParse(s('issued_at') ?? '')?.toUtc(),
      requestId: s('request_id'),
      nonceValue: s('nonce_value'),
      decision: s('decision'),
      note: s('note'),
    );
  }
}

/// Routes a parsed push to the app. Callbacks keep this free of Riverpod:
/// - [onConfirmReceived] → `POST /wakefulness/{id}/received` (fire-and-forget,
///   wakefulness only), so a dropped push can't masquerade as an ignored check.
/// - [onWakefulness] → show the challenge with the server-pushed code (online,
///   not the offline TOTP path).
/// - [onPhoto] → open the photo capture flow with the request's nonce. Carries
///   the optional server `issued_at` / `response_seconds` so the capture screen
///   can anchor its countdown to when the request was raised (both are null
///   today — the backend doesn't send them yet — and the app falls back to the
///   arrival time + default window).
///
/// Returns the parsed message so the caller can log/inspect it.
PushMessage routePush(
  Map<String, dynamic> data, {
  required void Function(String checkId) onConfirmReceived,
  required void Function(
          String checkId, String code, int? responseSeconds, DateTime? issuedAt)
      onWakefulness,
  required void Function(
          String requestId, String nonceValue, DateTime? issuedAt, int? responseSeconds)
      onPhoto,
  required void Function(
          String requestId, String? shiftId, String decision, String? note)
      onPhotoReviewed,
}) {
  final msg = PushMessage.fromData(data);
  if (!msg.isActionable) return msg;

  switch (msg.kind) {
    case PushKind.wakefulness:
      // Acknowledge delivery first, then raise the challenge.
      onConfirmReceived(msg.checkId!);
      onWakefulness(msg.checkId!, msg.code!, msg.responseSeconds, msg.issuedAt);
    case PushKind.photo:
      onPhoto(msg.requestId!, msg.nonceValue!, msg.issuedAt, msg.responseSeconds);
    case PushKind.photoReviewed:
      onPhotoReviewed(msg.requestId!, msg.shiftId, msg.decision!, msg.note);
    case PushKind.unknown:
      break;
  }
  return msg;
}
