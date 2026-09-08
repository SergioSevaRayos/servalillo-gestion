/// Una posición GPS pendiente de enviar. `toJson()` produce EXACTAMENTE lo que espera
/// `StoreGpsBatchRequest` del servidor.
class TrackedPosition {
  const TrackedPosition({
    this.id,
    required this.latitude,
    required this.longitude,
    required this.recordedAt,
    this.accuracyM,
    this.speedMps,
    this.headingDeg,
    this.batteryLevel,
  });

  /// id de la fila en SQLite (null antes de encolar).
  final int? id;
  final double latitude;
  final double longitude;
  final DateTime recordedAt;
  final double? accuracyM;
  final double? speedMps;
  final double? headingDeg;
  final int? batteryLevel;

  static double _round7(double v) => double.parse(v.toStringAsFixed(7));

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> json = <String, dynamic>{
      'lat': _round7(latitude),
      'lng': _round7(longitude),
      'recorded_at': recordedAt.toUtc().toIso8601String(),
    };

    if (accuracyM != null && accuracyM! >= 0) {
      json['accuracy_m'] = _round7(accuracyM!);
    }
    // geolocator devuelve speed negativa cuando no la sabe.
    if (speedMps != null && speedMps! >= 0) {
      json['speed_mps'] = _round7(speedMps!);
    }
    // geolocator devuelve heading -1 cuando no la sabe.
    if (headingDeg != null && headingDeg! >= 0) {
      json['heading_deg'] = _round7(headingDeg!.clamp(0, 360).toDouble());
    }
    if (batteryLevel != null) {
      json['battery_level'] = batteryLevel!.clamp(0, 100);
    }

    return json;
  }

  Map<String, Object?> toRow() => <String, Object?>{
    'lat': latitude,
    'lng': longitude,
    'recorded_at': recordedAt.toUtc().toIso8601String(),
    'accuracy_m': accuracyM,
    'speed_mps': speedMps,
    'heading_deg': headingDeg,
    'battery_level': batteryLevel,
    'created_at': DateTime.now().toUtc().toIso8601String(),
  };

  factory TrackedPosition.fromRow(Map<String, Object?> row) => TrackedPosition(
    id: row['id'] as int?,
    latitude: (row['lat'] as num).toDouble(),
    longitude: (row['lng'] as num).toDouble(),
    recordedAt: DateTime.parse(row['recorded_at'] as String),
    accuracyM: (row['accuracy_m'] as num?)?.toDouble(),
    speedMps: (row['speed_mps'] as num?)?.toDouble(),
    headingDeg: (row['heading_deg'] as num?)?.toDouble(),
    batteryLevel: (row['battery_level'] as num?)?.toInt(),
  );
}
