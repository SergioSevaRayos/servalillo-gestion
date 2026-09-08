import 'package:sqflite/sqflite.dart';

import '../models/tracked_position.dart';

/// Cola FIFO de posiciones pendientes de enviar (tabla `pending_positions`).
///
/// Garantía anti-duplicado: `sendBatch` OK → `deleteUpTo(último id del lote)`. Los ids son
/// contiguos y crecientes, así que borrar `<= maxId` borra exactamente el lote enviado.
class PositionQueue {
  PositionQueue(this._db, {this.cap = 50000});

  final Database _db;
  final int cap;

  Future<void> enqueue(TrackedPosition position) async {
    await _db.insert('pending_positions', position.toRow());

    if (await count() > cap) {
      await trimOldest(keep: cap);
    }
  }

  Future<List<TrackedPosition>> peekBatch({int limit = 500}) async {
    final List<Map<String, Object?>> rows = await _db.query(
      'pending_positions',
      orderBy: 'id ASC',
      limit: limit,
    );
    return rows.map(TrackedPosition.fromRow).toList();
  }

  Future<int> deleteUpTo(int maxId) => _db.delete(
    'pending_positions',
    where: 'id <= ?',
    whereArgs: <Object?>[maxId],
  );

  Future<int> count() async {
    final Object? c = (await _db.rawQuery(
      'SELECT COUNT(*) AS c FROM pending_positions',
    )).first['c'];
    return (c as num).toInt();
  }

  /// Deja solo las [keep] filas más recientes. Devuelve cuántas descartó.
  Future<int> trimOldest({int keep = 50000}) => _db.rawDelete(
    'DELETE FROM pending_positions WHERE id NOT IN '
    '(SELECT id FROM pending_positions ORDER BY id DESC LIMIT ?)',
    <Object?>[keep],
  );
}
