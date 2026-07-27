import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/data/offline_queue_db.dart';

void main() {
  // These run against an in-memory executor (no native SQLCipher needed on the
  // host) to prove the schema + CRUD. The SQLCipher engine itself is exercised
  // on-device; here we validate the queue logic.
  late OfflineQueueDb db;

  setUp(() {
    db = OfflineQueueDb.forTesting(NativeDatabase.memory());
  });

  tearDown(() async => db.close());

  test('GPS enqueue → dueGps returns it oldest-first, delete clears it',
      () async {
    await db.enqueueGps(GpsQueueCompanion.insert(
      shiftId: 's1',
      latitude: 51.5,
      longitude: -0.09,
      recordedAt: '2026-06-30T10:00:00Z',
      createdAt: 100,
    ));
    await db.enqueueGps(GpsQueueCompanion.insert(
      shiftId: 's1',
      latitude: 51.6,
      longitude: -0.10,
      recordedAt: '2026-06-30T10:00:15Z',
      createdAt: 50, // older
    ));

    final due = await db.dueGps('s1', 9999999999999);
    expect(due, hasLength(2));
    expect(due.first.createdAt, 50, reason: 'oldest first');

    await db.deleteGps(due.map((e) => e.id));
    expect(await db.dueGps('s1', 9999999999999), isEmpty);
  });

  test('bumpGpsAttempts increments attempts and gates next_attempt', () async {
    final id = await db.enqueueGps(GpsQueueCompanion.insert(
      shiftId: 's1',
      latitude: 1,
      longitude: 2,
      recordedAt: 'x',
      createdAt: 1,
    ));
    await db.bumpGpsAttempts([id], 5000);
    // Not due before the gate.
    expect(await db.dueGps('s1', 4999), isEmpty);
    final due = await db.dueGps('s1', 5000);
    expect(due.single.attempts, 1);
  });

  test('nonce pool: draw never returns expired/used, and is single-use',
      () async {
    await db.insertNonces([
      NoncePoolCompanion.insert(nonceValue: 'a', shiftId: 's1', expiresAt: 1000),
      NoncePoolCompanion.insert(nonceValue: 'b', shiftId: 's1', expiresAt: 9000),
    ]);
    expect(await db.availableNonceCount('s1', 500), 2);
    expect(await db.availableNonceCount('s1', 1500), 1); // 'a' expired

    final drawn = await db.drawNonce('s1', 1500);
    expect(drawn, 'b');
    expect(await db.availableNonceCount('s1', 1500), 0); // 'b' now drawn
    expect(await db.drawNonce('s1', 1500), isNull); // pool dry
  });

  test('clearAll empties every table', () async {
    await db.enqueueGps(GpsQueueCompanion.insert(
        shiftId: 's1', latitude: 1, longitude: 2, recordedAt: 'x', createdAt: 1));
    await db.enqueueWakefulness(WakefulnessQueueCompanion.insert(
        shiftId: 's1',
        checkId: 'c1',
        code: '1234',
        windowReference: 42,
        respondedAt: 'x',
        createdAt: 1));
    await db.clearAll();
    expect(await db.dueGps('s1', 9999999999999), isEmpty);
    expect(await db.dueWakefulness(9999999999999), isEmpty);
  });
}
