import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

/// Abre (y crea si hace falta) la base local. La cola de posiciones vive aquí.
///
/// Todas las ESCRITURAS se hacen desde el isolate del servicio; la UI solo lee el contador
/// vía el canal de FlutterForegroundTask, no abre la base. Aun así, WAL + busy_timeout por
/// si dos isolates coinciden.
Future<Database> openAppDatabase() async {
  final String dir = await getDatabasesPath();
  final String path = p.join(dir, 'servalillo_tracker.db');

  return openDatabase(
    path,
    version: 1,
    onConfigure: (Database db) async {
      await db.execute('PRAGMA journal_mode=WAL');
      await db.execute('PRAGMA busy_timeout=5000');
    },
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
