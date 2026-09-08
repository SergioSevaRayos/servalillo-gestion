import 'package:flutter_test/flutter_test.dart';
import 'package:servalillo_tracker/data/position_queue.dart';
import 'package:servalillo_tracker/models/tracked_position.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/test_db.dart';

TrackedPosition sample(int seconds) => TrackedPosition(
  latitude: 36.5,
  longitude: -2.4,
  recordedAt: DateTime.utc(2026, 9, 8, 10, 0, seconds),
);

void main() {
  late Database db;

  setUp(() async {
    db = await openTestDatabase();
  });

  tearDown(() async {
    await db.close();
  });

  test('enqueue + count + peekBatch en orden FIFO', () async {
    final q = PositionQueue(db);
    for (int i = 0; i < 5; i++) {
      await q.enqueue(sample(i));
    }
    expect(await q.count(), 5);

    final batch = await q.peekBatch(limit: 3);
    expect(batch.length, 3);
    expect(batch.first.recordedAt.second, 0);
    expect(batch.last.recordedAt.second, 2);
    expect(batch.first.id! < batch.last.id!, isTrue);
  });

  test('deleteUpTo borra exactamente el lote enviado', () async {
    final q = PositionQueue(db);
    for (int i = 0; i < 6; i++) {
      await q.enqueue(sample(i));
    }
    final batch = await q.peekBatch(limit: 4);
    final removed = await q.deleteUpTo(batch.last.id!);
    expect(removed, 4);
    expect(await q.count(), 2);

    final rest = await q.peekBatch();
    expect(rest.first.recordedAt.second, 4);
  });

  test('trimOldest deja solo las N más recientes', () async {
    final q = PositionQueue(db);
    for (int i = 0; i < 10; i++) {
      await q.enqueue(sample(i));
    }
    final dropped = await q.trimOldest(keep: 3);
    expect(dropped, 7);
    expect(await q.count(), 3);
    final rest = await q.peekBatch();
    expect(rest.map((p) => p.recordedAt.second), <int>[7, 8, 9]);
  });

  test('enqueue por encima del cap recorta automáticamente', () async {
    final q = PositionQueue(db, cap: 3);
    for (int i = 0; i < 5; i++) {
      await q.enqueue(sample(i));
    }
    expect(await q.count(), 3);
  });
}
