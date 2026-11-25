package es.servalillo.device_policy

import android.app.Activity
import android.os.Bundle

/**
 * Alta por QR (docs/24), Android 10+: último paso del asistente. Las restricciones se aplican
 * después, cuando la app sincroniza con la web; aquí solo confirmamos. Sin interfaz.
 */
class PolicyComplianceActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setResult(RESULT_OK)
        finish()
    }
}
