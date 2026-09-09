import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

/// Abre (y crea si hace falta) la base local. La cola de posiciones vive aquí.
///
/// Solo el isolate del servicio abre esta base (la UI lee el contador por el canal de
/// FlutterForegroundTask). sqflite ya gestiona WAL y el bloqueo por sí solo; NO hay que
/// lanzar `PRAGMA journal_mode=WAL` a mano — en Android devuelve una fila y `execute()`
/// la rechaza ("Queries can be performed using ... query or rawQuery methods only").
Future<Database> openAppDatabase() async {
  final String dir = await getDatabasesPath();
  final String path = p.join(dir, 'servalillo_tracker.db');

  return openDatabase(
    path,
    version: 1,
    onCreate: (Database db, int version) async {
      await db.execute('''
        CREATE TABLE pending_positions (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          lat REAL NOT NULL,
          lng REAL NOT NULL,
          recorded_at TEXT NOT NULL,
          accuracy_m REAL,
          speed_mps REAL,
          heading_deg REAL,
          battery_level INTEGER,
          created_at TEXT NOT NULL
        )
      ''');
    },
  );
}
