/// Configuración horneada en el build con `--dart-define` (o `--dart-define-from-file`).
///
/// `SERVER_URL` tiene un default (el dev tunnel actual) para poder compilar sin argumentos
/// durante el desarrollo. `ENROLMENT_SECRET` NO tiene default: si falta, la pantalla avisa y
/// no se enrola.
class AppConfig {
  const AppConfig._();

  static const String serverUrl = String.fromEnvironment(
    'SERVER_URL',
    defaultValue: 'https://8m5qlpx8-8000.euw.devtunnels.ms',
  );

  static const String enrolmentSecret = String.fromEnvironment(
    'ENROLMENT_SECRET',
  );

  static const String appVersion = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: '1.0.0',
  );

  static bool get isConfigured =>
      serverUrl.isNotEmpty && enrolmentSecret.isNotEmpty;

  /// Tope de filas en la cola local (a 45 s son ~26 días sin red).
  static const int queueCap = int.fromEnvironment(
    'QUEUE_CAP',
    defaultValue: 50000,
  );
}
