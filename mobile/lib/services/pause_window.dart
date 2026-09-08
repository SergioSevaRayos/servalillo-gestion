/// ¿[nowLocal] cae dentro de la ventana de pausa [start, end) en formato "HH:mm"?
///
/// - `start < end`  → intervalo normal, ej. 08:00–12:00 → `[08:00, 12:00)`.
/// - `start > end`  → cruza medianoche, ej. 22:00–05:00 → `n >= 22:00 || n < 05:00`.
/// - `start == end` → sin pausa (siempre `false`).
///
/// Inicio incluido, fin excluido. Usa la hora LOCAL del dispositivo.
bool isWithinPause(DateTime nowLocal, String start, String end) {
  final int n = nowLocal.hour * 60 + nowLocal.minute;
  final int s = minutesOfHhmm(start);
  final int e = minutesOfHhmm(end);

  if (s == e) return false;
  if (s < e) return n >= s && n < e;
  return n >= s || n < e;
}

/// "HH:mm" → minutos desde medianoche. Acepta "2:00" y "02:00".
int minutesOfHhmm(String hhmm) {
  final List<String> parts = hhmm.split(':');
  final int h = int.parse(parts[0]);
  final int m = parts.length > 1 ? int.parse(parts[1]) : 0;
  return (h % 24) * 60 + m;
}
