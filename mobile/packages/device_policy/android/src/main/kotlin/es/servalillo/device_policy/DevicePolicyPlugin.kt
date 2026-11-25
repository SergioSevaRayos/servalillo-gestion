package es.servalillo.device_policy

import android.app.PendingIntent
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.os.Build
import android.os.UserManager
import java.io.File
import io.flutter.embedding.engine.plugins.FlutterPlugin
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel

/**
 * Restricciones del terminal del chofer (docs/24). Solo funcionan si la APK es Device Owner
 * (alta por adb); si no lo es, todo es no-op y el estado devuelto es todo `false`.
 */
class DevicePolicyPlugin : FlutterPlugin, MethodChannel.MethodCallHandler {
    private lateinit var channel: MethodChannel
    private lateinit var context: Context

    override fun onAttachedToEngine(binding: FlutterPlugin.FlutterPluginBinding) {
        context = binding.applicationContext
        channel = MethodChannel(binding.binaryMessenger, "es.servalillo/device_policy")
        channel.setMethodCallHandler(this)
    }

    override fun onDetachedFromEngine(binding: FlutterPlugin.FlutterPluginBinding) {
        channel.setMethodCallHandler(null)
    }

    private val dpm: DevicePolicyManager
        get() = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager

    private val admin: ComponentName
        get() = ComponentName(context, AdminReceiver::class.java)

    private fun isOwner(): Boolean = dpm.isDeviceOwnerApp(context.packageName)

    override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
        try {
            when (call.method) {
                "state" -> result.success(state())
                "apply" -> {
                    if (isOwner()) {
                        apply(
                            screenCapture = call.argument<Boolean>("block_screen_capture") ?: false,
                            uninstall = call.argument<Boolean>("block_uninstall") ?: false,
                            factoryReset = call.argument<Boolean>("block_factory_reset") ?: false,
                            safeBoot = call.argument<Boolean>("block_safe_boot") ?: false,
                        )
                    }
                    result.success(state())
                }
                "release" -> {
                    if (isOwner()) {
                        apply(screenCapture = false, uninstall = false, factoryReset = false, safeBoot = false)
                        @Suppress("DEPRECATION")
                        dpm.clearDeviceOwnerApp(context.packageName)
                    }
                    result.success(state())
                }
                "versionCode" -> result.success(versionCode())
                "installApk" -> result.success(installApk(call.argument<String>("path") ?: ""))
                else -> result.notImplemented()
            }
        } catch (e: SecurityException) {
            result.error("security", e.message, null)
        }
    }

    @Suppress("DEPRECATION")
    private fun versionCode(): Long {
        val info = context.packageManager.getPackageInfo(context.packageName, 0)
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) info.longVersionCode else info.versionCode.toLong()
    }

    /**
     * Actualización automática (docs/24): instala la APK descargada sin preguntar al usuario, cosa
     * que Android solo permite al Device Owner. Al terminar, Android reinicia la app (y el servicio,
     * por `autoRunOnMyPackageReplaced`). Devuelve false si no se pudo lanzar.
     */
    private fun installApk(path: String): Boolean {
        val file = File(path)
        if (!isOwner() || !file.exists()) return false

        val installer = context.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL)
        params.setAppPackageName(context.packageName)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            params.setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
        }

        val sessionId = installer.createSession(params)
        installer.openSession(sessionId).use { session ->
            session.openWrite("tracker.apk", 0, file.length()).use { out ->
                file.inputStream().use { it.copyTo(out) }
                session.fsync(out)
            }
            val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
            } else {
                PendingIntent.FLAG_UPDATE_CURRENT
            }
            val intent = Intent(context, InstallResultReceiver::class.java)
            val pending = PendingIntent.getBroadcast(context, sessionId, intent, flags)
            session.commit(pending.intentSender)
        }
        return true
    }

    private fun apply(screenCapture: Boolean, uninstall: Boolean, factoryReset: Boolean, safeBoot: Boolean) {
        dpm.setScreenCaptureDisabled(admin, screenCapture)
        dpm.setUninstallBlocked(admin, context.packageName, uninstall)
        restriction(UserManager.DISALLOW_FACTORY_RESET, factoryReset)
        restriction(UserManager.DISALLOW_SAFE_BOOT, safeBoot)
    }

    private fun restriction(key: String, enabled: Boolean) {
        if (enabled) dpm.addUserRestriction(admin, key) else dpm.clearUserRestriction(admin, key)
    }

    private fun state(): Map<String, Boolean> {
        if (!isOwner()) {
            return mapOf(
                "device_owner" to false,
                "block_screen_capture" to false,
                "block_uninstall" to false,
                "block_factory_reset" to false,
                "block_safe_boot" to false,
            )
        }
        val restrictions = dpm.getUserRestrictions(admin)
        return mapOf(
            "device_owner" to true,
            "block_screen_capture" to dpm.getScreenCaptureDisabled(admin),
            "block_uninstall" to dpm.isUninstallBlocked(admin, context.packageName),
            "block_factory_reset" to restrictions.getBoolean(UserManager.DISALLOW_FACTORY_RESET),
            "block_safe_boot" to restrictions.getBoolean(UserManager.DISALLOW_SAFE_BOOT),
        )
    }
}
