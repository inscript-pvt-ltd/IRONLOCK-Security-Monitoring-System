import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:drift/drift.dart' show Value;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../data/offline_queue_db.dart';
import 'nonce_pool_service.dart';
import 'photo_service.dart';
import 'secure_storage_service.dart';
import 'time_anchor_service.dart';

/// Queues a photo batch captured while offline so it flushes on reconnect.
///
/// Draws a single-use **pool nonce**, captures the tamper-proof time proof,
/// HMAC-signs each image over the exact strings that will be re-sent, copies the
/// files to durable storage (camera temp files get reaped), and enqueues. The
/// signature is computed here, at capture, and stored verbatim — the flush
/// re-sends it unchanged.
class OfflinePhotoService {
  OfflinePhotoService(this._db, this._nonces, this._timeAnchor);

  final OfflineQueueDb _db;
  final NoncePoolService _nonces;
  final TimeAnchorService _timeAnchor;

  /// Queues a 1–5 image batch. Returns the queue row id on success, or null when
  /// it can't be queued (no pool nonce, or no signing key). Best-effort.
  Future<int?> enqueueCapture({
    required String shiftId,
    required List<String> filePaths,
    double? latitude,
    double? longitude,
  }) async {
    assert(filePaths.isNotEmpty && filePaths.length <= kMaxPhotosPerRequest);

    final secret = await SecureStorageService.getHmacSecret();
    if (secret == null) return null; // re-login needed
    final nonce = await _nonces.draw(shiftId);
    if (nonce == null) return null; // pool dry — surface "reconnect to verify"

    // From here the nonce is CLAIMED (marked drawn). Any failure before the row
    // is durably enqueued must roll it back — and delete any durable copies —
    // so a partial capture can't silently burn a scarce pool nonce or leak
    // orphaned files (M3). The signature must cover the drawn nonce, so we can't
    // draw later; instead we release on failure.
    var durable = const <String>[];
    try {
      final proof = _timeAnchor.capture();
      final capturedAt = proof.capturedAt.toIso8601String();
      // The exact strings the flush will send, so the signature covers them.
      final lat = latitude?.toString() ?? '';
      final lng = longitude?.toString() ?? '';

      durable = await _persistFiles(filePaths);

      final signatures = <String>[];
      for (final path in durable) {
        final hash = sha256.convert(await File(path).readAsBytes()).toString();
        signatures.add(PhotoService.buildSignature(
          secret: secret,
          nonceValue: nonce,
          requestId: '', // offline / self-initiated → no request id
          capturedAt: capturedAt,
          latitude: lat,
          longitude: lng,
          imageHash: hash,
        ));
      }

      return await _db.enqueuePhoto(PhotoQueueCompanion.insert(
        shiftId: shiftId,
        nonceValue: nonce,
        filePaths: jsonEncode(durable),
        signatures: jsonEncode(signatures),
        ntpReference: Value(proof.ntpReference),
        elapsedSeconds: proof.elapsedSeconds,
        latitude: Value(latitude),
        longitude: Value(longitude),
        capturedAt: capturedAt,
        createdAt: DateTime.now().millisecondsSinceEpoch,
      ));
    } catch (_) {
      await _rollback(nonce, durable);
      return null;
    }
  }

  /// Undoes a failed capture that already claimed a nonce: returns the nonce to
  /// the pool and deletes any durable copies made. Best-effort — never throws.
  Future<void> _rollback(String nonce, List<String> files) async {
    try {
      await _db.releaseNonce(nonce);
    } catch (_) {}
    for (final f in files) {
      try {
        await File(f).delete();
      } catch (_) {}
    }
  }

  /// Deletes the whole durable `queued_photos/` directory — used on sign-out so a
  /// guard's un-flushed captures don't linger for the next session. Best-effort.
  Future<void> purgeFiles() async {
    try {
      final dir = await getApplicationDocumentsDirectory();
      final queueDir = Directory(p.join(dir.path, 'queued_photos'));
      if (await queueDir.exists()) await queueDir.delete(recursive: true);
    } catch (_) {}
  }

  /// Copies capture files into a durable `queued_photos/` dir under app
  /// documents so they survive until the flush (camera temp files may be reaped).
  Future<List<String>> _persistFiles(List<String> paths) async {
    final dir = await getApplicationDocumentsDirectory();
    final queueDir = Directory(p.join(dir.path, 'queued_photos'));
    if (!await queueDir.exists()) await queueDir.create(recursive: true);
    final out = <String>[];
    for (final path in paths) {
      final dest = p.join(queueDir.path,
          '${DateTime.now().microsecondsSinceEpoch}_${p.basename(path)}');
      await File(path).copy(dest);
      out.add(dest);
    }
    return out;
  }
}

final offlinePhotoServiceProvider = Provider<OfflinePhotoService>((ref) {
  return OfflinePhotoService(
    ref.read(offlineQueueDbProvider),
    ref.read(noncePoolServiceProvider),
    ref.read(timeAnchorServiceProvider),
  );
});
