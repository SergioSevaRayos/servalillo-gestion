import 'dart:convert';

/// Config de tracking que manda el servidor en el enrolamiento y en `GET /api/device`.
class TrackingConfig {
  const TrackingConfig({
    required this.pingIntervalSeconds,
    required this.pingDistanceMeters,
    required this.pauseStart,
    required this.pauseEnd,
  });

  final int pingIntervalSeconds;
  final int pingDistanceMeters;
  final String pauseStart; // "HH:mm"
  final String pauseEnd; // "HH:mm"

  static const TrackingConfig fallback = TrackingConfig(
    pingIntervalSeconds: 45,
    pingDistanceMeters: 75,
    pauseStart: '22:00',
    pauseEnd: '05:00',
  );

  Duration get pingInterval => Duration(seconds: pingIntervalSeconds);

  factory TrackingConfig.fromJson(Map<String, dynamic> json) {
    int intOr(String key, int fallbackValue) {
      final dynamic v = json[key];
      if (v is int) return v;
      if (v is num) return v.toInt();
      if (v is String) return int.tryParse(v) ?? fallbackValue;
      return fallbackValue;
    }

    String hhmmOr(String key, String fallbackValue) {
      final dynamic v = json[key];
      if (v is String && _isHhmm(v)) return _normalizeHhmm(v);
      return fallbackValue;
    }

    return TrackingConfig(
      pingIntervalSeconds: intOr(
        'ping_interval_seconds',
        fallback.pingIntervalSeconds,
      ).clamp(5, 3600),
      pingDistanceMeters: intOr(
        'ping_distance_meters',
        fallback.pingDistanceMeters,
      ).clamp(0, 100000),
      pauseStart: hhmmOr('pause_start', fallback.pauseStart),
      pauseEnd: hhmmOr('pause_end', fallback.pauseEnd),
    );
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
    'ping_interval_seconds': pingIntervalSeconds,
    'ping_distance_meters': pingDistanceMeters,
    'pause_start': pauseStart,
    'pause_end': pauseEnd,
  };

  String encode() => jsonEncode(toJson());

  static TrackingConfig decode(String? raw) {
    if (raw == null || raw.isEmpty) return fallback;
    try {
      return TrackingConfig.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return fallback;
    }
  }

  static bool _isHhmm(String v) => RegExp(r'^\d{1,2}:\d{2}$').hasMatch(v);

  static String _normalizeHhmm(String v) {
    final List<String> parts = v.split(':');
    return '${parts[0].padLeft(2, '0')}:${parts[1]}';
  }
}
