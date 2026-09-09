package nl.kvt.soteria

import android.app.Application
import org.osmdroid.config.Configuration
import java.io.File

class SoteriaApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Prefs.init(this)
        Configuration.getInstance().userAgentValue = packageName
        Configuration.getInstance().osmdroidBasePath = File(cacheDir, "osmdroid")
        Configuration.getInstance().osmdroidTileCache = File(cacheDir, "osmdroid/tiles")
    }
}
