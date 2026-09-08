/// Errores de la API mapeados a la acción que debe tomar la app.
sealed class TrackerApiException implements Exception {
  const TrackerApiException(this.message);
  final String message;

  @override
  String toString() => '$runtimeType: $message';
}

/// 403 en `/device/register`: el secreto de enrolamiento no vale. No reintentar.
class EnrolmentRejected extends TrackerApiException {
  const EnrolmentRejected([
    super.message = 'Secreto de enrolamiento no válido.',
  ]);
}

/// 401 en `/gps/batch` o `/device`: el técnico revocó el token. Re-enrolar.
class TokenRevoked extends TrackerApiException {
  const TokenRevoked([super.message = 'Acceso revocado.']);
}

/// 403 en `/gps/batch` o `/device`: el dispositivo está desactivado en el panel.
class DeviceDeactivated extends TrackerApiException {
  const DeviceDeactivated([super.message = 'Dispositivo desactivado.']);
}

/// 422 en `/gps/batch`: el lote no valida. Descartarlo y seguir.
class BatchRejected extends TrackerApiException {
  const BatchRejected([super.message = 'Lote rechazado por el servidor.']);
}

/// Red caída / timeout / 5xx / 429: conservar cola, reintentar con backoff.
class TransientNetworkError extends TrackerApiException {
  const TransientNetworkError([
    super.message = 'Fallo de red temporal.',
    this.retryAfter,
  ]);
  final Duration? retryAfter;
}
