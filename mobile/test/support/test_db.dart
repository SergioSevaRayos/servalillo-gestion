import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Base en memoria con el mismo esquema que `openAppDatabase`, para los tests.
Future<Database> openTestDatabase() {
  sqfliteFfiInit();
  return databaseFactoryFfi.openDatabase(
    inMemoryDatabasePath,
    options: OpenDatabaseOptions(
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
    ),
  );
}
