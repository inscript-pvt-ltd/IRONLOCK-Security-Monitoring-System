import 'dart:async';
import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqlite3/sqlite3.dart';

import '../services/secure_storage_service.dart';

part 'offline_queue_db.g.dart';

/// Phase 7 offline-sync queue. Holds captures taken while disconnected so they
/// can be flushed to the (idempotent) server endpoints on reconnect. Encrypted
/// at rest via SQLCipher; the passphrase lives in the Keychain / Keystore and is
/// wiped on sign-out, so the queue never outlives a session. It is a best-effort
/// buffer — never the source of truth — so losing it on logout (or a key
/// mismatch) is safe.

/// Buffered GPS pings (steady state 1/15s; offline they accumulate and flush as
/// one `pings[]` batch). `recordedAt` is diagnostic — the server stamps its own
/// receipt time — but it drives the dashboard's comms-gap detection.
class GpsQueue extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get shiftId => text()();
  RealColumn get latitude => real()();
  RealColumn get longitude => real()();
  RealColumn get accuracy => real().nullable()();
  RealColumn get battery => real().nullable()(); // 0..1 fraction
  TextColumn get recordedAt => text()(); // ISO-8601 UTC at fix time
  IntColumn get attempts => integer().withDefault(const Constant(0))();
  IntColumn get nextAttempt => integer().withDefault(const Constant(0))();
  IntColumn get createdAt => integer()(); // epoch ms, ordering
}

/// Offline-answered wakefulness challenges. Validity is proven by the absolute
/// TOTP `windowReference` recorded at answer time — never by arrival order — so a
/// late flush still lands on the right window. Flushed to
/// `POST /shifts/{shiftId}/wakefulness/offline` (the schedule-fired path has no
/// server check_id), so `shiftId` is stored per-row rather than read from the
/// live shift — the flush must survive the shift ending mid-backlog.
class WakefulnessQueue extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get shiftId => text()();
  // Synthetic local id (`totp-<window>`) — kept for dedup/diagnostics only; the
  // offline endpoint is keyed on (shift, window_reference), not this.
  TextColumn get checkId => text()();
  TextColumn get code => text()();
  IntColumn get windowReference => integer()();
  TextColumn get scheduledAt => text().nullable()(); // ISO UTC schedule mark
  TextColumn get respondedAt => text()(); // ISO UTC, audit only
  IntColumn get attempts => integer().withDefault(const Constant(0))();
  IntColumn get nextAttempt => integer().withDefault(const Constant(0))();
  IntColumn get createdAt => integer()();
}

/// Photos captured offline against a prefetched pool nonce. Capture time is
/// reconstructed server-side from `ntpReference + elapsedSeconds`, so those are
/// the trusted time proof; `capturedAt` is diagnostic. File bytes stay on the
/// filesystem — `filePaths`/`signatures` are JSON arrays (1–5, positionally
/// matched).
class PhotoQueue extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get shiftId => text()();
  TextColumn get nonceValue => text()();
  TextColumn get filePaths => text()(); // JSON array of sandbox paths
  TextColumn get signatures => text()(); // JSON array, positionally matched
  TextColumn get ntpReference => text().nullable()(); // ISO UTC anchor
  RealColumn get elapsedSeconds => real()(); // monotonic since the anchor
  RealColumn get latitude => real().nullable()();
  RealColumn get longitude => real().nullable()();
  TextColumn get capturedAt => text()(); // ISO UTC, diagnostic
  IntColumn get attempts => integer().withDefault(const Constant(0))();
  IntColumn get nextAttempt => integer().withDefault(const Constant(0))();
  IntColumn get createdAt => integer()();
}

/// Prefetched single-use offline nonces (`TYPE_OFFLINE_POOL`, 15-min TTL). One
/// is drawn per offline capture and never reused.
class NoncePool extends Table {
  TextColumn get nonceValue => text()();
  TextColumn get shiftId => text()();
  IntColumn get expiresAt => integer()(); // epoch ms
  BoolColumn get drawn => boolean().withDefault(const Constant(false))();

  @override
  Set<Column> get primaryKey => {nonceValue};
}

@DriftDatabase(tables: [GpsQueue, WakefulnessQueue, PhotoQueue, NoncePool])
class OfflineQueueDb extends _$OfflineQueueDb {
  OfflineQueueDb() : super(_openConnection());

  /// Test/visible-for-override constructor accepting an in-memory or custom
  /// executor (used by unit tests — no SQLCipher/native libs needed there).
  OfflineQueueDb.forTesting(super.executor);

  @override
  int get schemaVersion => 2;

  /// Fires **after** a compliance-critical row (wakefulness answer / proof photo)
  /// is queued, so the flush engine can attempt an immediate drain instead of
  /// waiting for the next reconnect/heartbeat. GPS is intentionally excluded — it
  /// is high-volume and latency-tolerant, so kicking a flush on every 15s ping
  /// would be pure noise. Broadcast so a late-starting listener never blocks it.
  final StreamController<void> _importantEnqueue =
      StreamController<void>.broadcast();
  Stream<void> get onImportantEnqueue => _importantEnqueue.stream;

  @override
  Future<void> close() async {
    await _importantEnqueue.close();
    await super.close();
  }

  // The queue is a best-effort, session-scoped buffer (dropped on sign-out, and
  // an undecryptable file is discarded on open) — never the source of truth. So
  // a schema bump destructively recreates every table rather than carrying old
  // rows forward: losing an in-flight buffer is safe, and it keeps migrations
  // trivial as the offline surface grows.
  @override
  MigrationStrategy get migration => MigrationStrategy(
        onUpgrade: (m, from, to) async {
          for (final table in allTables) {
            await m.deleteTable(table.actualTableName);
          }
          await m.createAll();
        },
      );

  // ---- GPS -----------------------------------------------------------------

  Future<int> enqueueGps(GpsQueueCompanion ping) =>
      into(gpsQueue).insert(ping);

  /// GPS rows for [shiftId] whose backoff gate has elapsed, oldest first.
  Future<List<GpsQueueData>> dueGps(String shiftId, int nowMs) =>
      (select(gpsQueue)
            ..where((t) => t.shiftId.equals(shiftId) & t.nextAttempt.isSmallerOrEqualValue(nowMs))
            ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
          .get();

  /// GPS rows across **all** shifts whose backoff gate has elapsed, oldest
  /// first. The flush engine groups these by each row's own `shiftId` so a
  /// backlog still drains after the shift ends (H1) — the flush must not be
  /// gated on the currently-active shift.
  Future<List<GpsQueueData>> dueGpsAll(int nowMs) =>
      (select(gpsQueue)
            ..where((t) => t.nextAttempt.isSmallerOrEqualValue(nowMs))
            ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
          .get();

  Future<void> deleteGps(Iterable<int> ids) =>
      (delete(gpsQueue)..where((t) => t.id.isIn(ids))).go();

  /// Sets the next-attempt gate WITHOUT incrementing `attempts`. Used when a
  /// flush fails only because the device is offline / the server didn't respond
  /// — that's "not yet", not a strike, so an offline stretch can't burn a row's
  /// retry budget and delete it before it ever reaches the server.
  Future<void> gateGps(Iterable<int> ids, int nextAttemptMs) async {
    final idList = ids.toList();
    if (idList.isEmpty) return;
    await (update(gpsQueue)..where((t) => t.id.isIn(idList)))
        .write(GpsQueueCompanion(nextAttempt: Value(nextAttemptMs)));
  }

  /// Increments `attempts` and sets the next backoff gate in one statement
  /// (Drift companions can't express `attempts = attempts + 1`).
  Future<void> bumpGpsAttempts(Iterable<int> ids, int nextAttemptMs) async {
    final idList = ids.toList();
    if (idList.isEmpty) return;
    final placeholders = List.filled(idList.length, '?').join(',');
    await customUpdate(
      'UPDATE gps_queue SET attempts = attempts + 1, next_attempt = ? '
      'WHERE id IN ($placeholders)',
      variables: [
        Variable.withInt(nextAttemptMs),
        ...idList.map(Variable.withInt),
      ],
      updates: {gpsQueue},
    );
  }

  // ---- Wakefulness ---------------------------------------------------------

  Future<int> enqueueWakefulness(WakefulnessQueueCompanion answer) async {
    final id = await into(wakefulnessQueue).insert(answer);
    if (!_importantEnqueue.isClosed) _importantEnqueue.add(null);
    return id;
  }

  Future<List<WakefulnessQueueData>> dueWakefulness(int nowMs) =>
      (select(wakefulnessQueue)
            ..where((t) => t.nextAttempt.isSmallerOrEqualValue(nowMs))
            ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
          .get();

  Future<void> deleteWakefulness(int id) =>
      (delete(wakefulnessQueue)..where((t) => t.id.equals(id))).go();

  /// Gate-only (no attempt increment) — see [gateGps].
  Future<void> gateWakefulness(int id, int nextAttemptMs) =>
      (update(wakefulnessQueue)..where((t) => t.id.equals(id)))
          .write(WakefulnessQueueCompanion(nextAttempt: Value(nextAttemptMs)));

  Future<void> bumpWakefulness(int id, int nextAttemptMs) => customUpdate(
        'UPDATE wakefulness_queue SET attempts = attempts + 1, next_attempt = ? WHERE id = ?',
        variables: [Variable.withInt(nextAttemptMs), Variable.withInt(id)],
        updates: {wakefulnessQueue},
      );

  // ---- Photos --------------------------------------------------------------

  Future<int> enqueuePhoto(PhotoQueueCompanion photo) async {
    final id = await into(photoQueue).insert(photo);
    if (!_importantEnqueue.isClosed) _importantEnqueue.add(null);
    return id;
  }

  Future<List<PhotoQueueData>> duePhotos(String shiftId, int nowMs) =>
      (select(photoQueue)
            ..where((t) => t.shiftId.equals(shiftId) & t.nextAttempt.isSmallerOrEqualValue(nowMs))
            ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
          .get();

  /// Photo rows across **all** shifts whose backoff gate has elapsed, oldest
  /// first. Grouped by each row's own `shiftId` by the flush engine so proof
  /// photos still drain after the shift ends (H1).
  Future<List<PhotoQueueData>> duePhotosAll(int nowMs) =>
      (select(photoQueue)
            ..where((t) => t.nextAttempt.isSmallerOrEqualValue(nowMs))
            ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
          .get();

  Future<void> deletePhoto(int id) =>
      (delete(photoQueue)..where((t) => t.id.equals(id))).go();

  /// Gate-only (no attempt increment) — see [gateGps].
  Future<void> gatePhoto(int id, int nextAttemptMs) =>
      (update(photoQueue)..where((t) => t.id.equals(id)))
          .write(PhotoQueueCompanion(nextAttempt: Value(nextAttemptMs)));

  Future<void> bumpPhoto(int id, int nextAttemptMs) => customUpdate(
        'UPDATE photo_queue SET attempts = attempts + 1, next_attempt = ? WHERE id = ?',
        variables: [Variable.withInt(nextAttemptMs), Variable.withInt(id)],
        updates: {photoQueue},
      );

  // ---- Nonce pool ----------------------------------------------------------

  Future<void> insertNonces(List<NoncePoolCompanion> nonces) =>
      batch((b) => b.insertAll(noncePool, nonces, mode: InsertMode.insertOrReplace));

  /// Count of usable (unexpired, undrawn) pool nonces for [shiftId].
  Future<int> availableNonceCount(String shiftId, int nowMs) async {
    final rows = await (select(noncePool)
          ..where((t) =>
              t.shiftId.equals(shiftId) &
              t.drawn.equals(false) &
              t.expiresAt.isBiggerThanValue(nowMs)))
        .get();
    return rows.length;
  }

  /// Atomically claim one unexpired, undrawn nonce for [shiftId] (marks it
  /// `drawn`), or null if the pool is dry. Runs in a transaction so two
  /// concurrent captures can't claim the same nonce.
  Future<String?> drawNonce(String shiftId, int nowMs) {
    return transaction(() async {
      final row = await (select(noncePool)
            ..where((t) =>
                t.shiftId.equals(shiftId) &
                t.drawn.equals(false) &
                t.expiresAt.isBiggerThanValue(nowMs))
            ..orderBy([(t) => OrderingTerm.asc(t.expiresAt)])
            ..limit(1))
          .getSingleOrNull();
      if (row == null) return null;
      await (update(noncePool)..where((t) => t.nonceValue.equals(row.nonceValue)))
          .write(const NoncePoolCompanion(drawn: Value(true)));
      return row.nonceValue;
    });
  }

  Future<void> purgeExpiredNonces(int nowMs) =>
      (delete(noncePool)..where((t) => t.expiresAt.isSmallerOrEqualValue(nowMs))).go();

  /// Returns a drawn nonce to the pool (marks it un-`drawn`) so it can be reused.
  /// Called when an offline capture that already claimed a nonce fails to persist
  /// or enqueue (M3) — without this the single-use nonce would be lost forever,
  /// silently shrinking the pool.
  Future<void> releaseNonce(String nonceValue) =>
      (update(noncePool)..where((t) => t.nonceValue.equals(nonceValue)))
          .write(const NoncePoolCompanion(drawn: Value(false)));

  /// Total buffered rows still waiting to reach the server, across GPS +
  /// wakefulness + photos (ignores the backoff gate — this is everything queued).
  /// Drives the on-screen "N pending sync" indicator so offline flushing is
  /// visible on-device without a debugger attached.
  ///
  /// Uses COUNT() aggregates rather than materialising every row: this runs on
  /// the 20 s poll + pull-to-refresh, and GPS accumulates ~1 row/15 s offline,
  /// so loading whole tables just to count them would churn memory on a long
  /// offline stretch (M1).
  Future<int> totalPending() async {
    final row = await customSelect(
      'SELECT '
      '(SELECT COUNT(*) FROM gps_queue) + '
      '(SELECT COUNT(*) FROM wakefulness_queue) + '
      '(SELECT COUNT(*) FROM photo_queue) AS c',
    ).getSingle();
    return row.read<int>('c');
  }

  // ---- Teardown ------------------------------------------------------------

  /// Empties every table. Called on sign-out (the cipher key is wiped too, so
  /// the file becomes undecryptable and is dropped on next open).
  Future<void> clearAll() => transaction(() async {
        await delete(gpsQueue).go();
        await delete(wakefulnessQueue).go();
        await delete(photoQueue).go();
        await delete(noncePool).go();
      });
}

/// Opens the queue DB over SQLCipher. The `sqlite3` engine is already the
/// SQLCipher build (selected by the `hooks.user_defines.sqlite3.source:
/// sqlcipher` define in pubspec.yaml), so opening is the same as plain sqlite3 —
/// we just apply the key. Generates/loads the passphrase from secure storage,
/// drops a stale (undecryptable) file, and verifies the cipher is actually
/// active (`PRAGMA cipher_version` non-empty).
LazyDatabase _openConnection() {
  return LazyDatabase(() async {
    final key = await SecureStorageService.getOrCreateDbCipherKey();
    final dir = await getApplicationDocumentsDirectory();
    final file = File(p.join(dir.path, 'offline_queue.db'));

    // A leftover file we can't decrypt (key wiped on a previous sign-out, then
    // regenerated) is stale — drop it and start clean rather than crash.
    if (await file.exists()) {
      try {
        final probe = sqlite3.open(file.path);
        probe.execute("PRAGMA key = '$key';");
        probe.select('SELECT count(*) FROM sqlite_master;');
        probe.close();
      } catch (_) {
        await file.delete();
      }
    }

    return NativeDatabase(
      file,
      setup: (rawDb) {
        // The key PRAGMA must run before any other statement on an encrypted DB.
        rawDb.execute("PRAGMA key = '$key';");
        if (rawDb.select('PRAGMA cipher_version;').isEmpty) {
          throw StateError(
            'SQLCipher engine not available — offline queue cannot be encrypted',
          );
        }
      },
    );
  });
}

/// App-wide single instance. Disposed with the container.
final offlineQueueDbProvider = Provider<OfflineQueueDb>((ref) {
  final db = OfflineQueueDb();
  ref.onDispose(db.close);
  return db;
});

/// Progress of the offline→online sync: how many captures are still waiting
/// ([pending]) out of the batch's high-watermark ([total]). Lets the UI show a
/// real progress bar that fills as rows drain, instead of just "syncing…".
class SyncProgress {
  const SyncProgress({this.pending = 0, this.total = 0, this.completed = false});

  /// Items still buffered and waiting to upload.
  final int pending;

  /// Denominator for the bar: the most items seen queued at once since the queue
  /// was last empty. Held through a batch, reset when the next one starts.
  final int total;

  /// Just drained to zero — show a brief "all synced ✓" at 100% before hiding,
  /// so a fast flush doesn't just blink the bar out.
  final bool completed;

  /// Items already uploaded in this batch.
  int get done => (total - pending).clamp(0, total);

  /// Fraction complete, 0.0–1.0.
  double get fraction =>
      completed ? 1.0 : (total == 0 ? 0 : done / total);

  bool get active => pending > 0;

  /// Whether the chip should be shown at all (syncing, or the brief done state).
  bool get visible => active || completed;
}

/// Tracks the offline-sync backlog + progress. Refreshed by the home poll,
/// pull-to-refresh, and a fast tick while actively syncing so the bar animates.
class PendingSyncNotifier extends Notifier<SyncProgress> {
  Timer? _clearTimer;

  @override
  SyncProgress build() {
    ref.onDispose(() => _clearTimer?.cancel());
    return const SyncProgress();
  }

  Future<void> refresh() async {
    try {
      final pending = await ref.read(offlineQueueDbProvider).totalPending();
      if (pending == 0) {
        // Drained. If we were mid-sync (had a backlog), linger at 100% "synced ✓"
        // for a moment, then clear — otherwise a quick flush would flash by.
        if (state.total > 0 && !state.completed) {
          state = SyncProgress(pending: 0, total: state.total, completed: true);
          _clearTimer?.cancel();
          _clearTimer = Timer(const Duration(seconds: 3),
              () => state = const SyncProgress());
        } else if (state.total != 0 && !state.completed) {
          state = const SyncProgress();
        }
        return;
      }
      // Active backlog — cancel any pending "synced" clear and track the
      // high-watermark. A fresh batch (we were idle/just-completed) starts over.
      _clearTimer?.cancel();
      final base = state.active ? state.total : 0;
      final total = pending > base ? pending : base;
      state = SyncProgress(pending: pending, total: total);
    } catch (_) {
      // Queue unavailable — leave the last known state.
    }
  }
}

final pendingSyncProvider =
    NotifierProvider<PendingSyncNotifier, SyncProgress>(PendingSyncNotifier.new);
