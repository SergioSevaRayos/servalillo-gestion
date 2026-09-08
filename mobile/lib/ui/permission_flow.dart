import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';

/// Estado de cada permiso que el tracker necesita.
class PermissionsState {
  const PermissionsState({
    required this.locationWhileInUse,
    required this.locationAlways,
    required this.notifications,
    required this.batteryUnrestricted,
  });

  final bool locationWhileInUse;
  final bool locationAlways;
  final bool notifications;
  final bool batteryUnrestricted;

  bool get allGranted => locationAlways && notifications && batteryUnrestricted;

  /// Texto del botón: el siguiente permiso que falta.
  String get nextActionLabel {
    if (!locationWhileInUse) return 'Permitir ubicación';
    if (!locationAlways) return 'Permitir ubicación "todo el tiempo"';
    if (!notifications) return 'Permitir notificaciones';
    if (!batteryUnrestricted) return 'Quitar restricción de batería';
    return 'Todo listo';
  }
}

class PermissionFlow {
  const PermissionFlow();

  Future<PermissionsState> check() async {
    final LocationPermission loc = await Geolocator.checkPermission();
    final bool whileInUse =
        loc == LocationPermission.whileInUse ||
        loc == LocationPermission.always;
    final bool always = loc == LocationPermission.always;

    final NotificationPermission notif =
        await FlutterForegroundTask.checkNotificationPermission();

    final bool battery =
        await FlutterForegroundTask.isIgnoringBatteryOptimizations;

    return PermissionsState(
      locationWhileInUse: whileInUse,
      locationAlways: always,
      notifications: notif == NotificationPermission.granted,
      batteryUnrestricted: battery,
    );
  }

  /// Pide el siguiente permiso que falte. Devuelve el estado tras el intento.
  /// `openedSettings` = true si mandó al usuario a Ajustes (el flujo debe re-chequear al volver).
  Future<({PermissionsState state, bool openedSettings})> requestNext() async {
    final PermissionsState state = await check();

    if (!state.locationWhileInUse) {
      final LocationPermission result = await Geolocator.requestPermission();
      if (result == LocationPermission.deniedForever) {
        await Geolocator.openAppSettings();
        return (state: await check(), openedSettings: true);
      }
      return (state: await check(), openedSettings: false);
    }

    if (!state.locationAlways) {
      // Android 11+: request() no muestra diálogo → hay que ir a Ajustes.
      final PermissionStatus status = await Permission.locationAlways.request();
      if (!status.isGranted) {
        await openAppSettings();
        return (state: await check(), openedSettings: true);
      }
      return (state: await check(), openedSettings: false);
    }

    if (!state.notifications) {
      await FlutterForegroundTask.requestNotificationPermission();
      return (state: await check(), openedSettings: false);
    }

    if (!state.batteryUnrestricted) {
      await FlutterForegroundTask.requestIgnoreBatteryOptimization();
      return (state: await check(), openedSettings: false);
    }

    return (state: state, openedSettings: false);
  }
}
