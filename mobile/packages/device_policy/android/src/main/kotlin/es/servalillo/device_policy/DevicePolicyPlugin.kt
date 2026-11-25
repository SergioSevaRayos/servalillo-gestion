package es.servalillo.device_policy

import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.os.UserManager
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
                else -> result.notImplemented()
            }
        } catch (e: SecurityException) {
            result.error("security", e.message, null)
        }
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
