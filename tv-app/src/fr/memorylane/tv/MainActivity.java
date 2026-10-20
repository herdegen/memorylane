package fr.memorylane.tv;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.speech.RecognizerIntent;
import android.view.KeyEvent;
import android.view.WindowManager;
import android.util.Log;
import android.webkit.ConsoleMessage;
import android.webkit.CookieManager;
import android.webkit.HttpAuthHandler;
import android.webkit.JavascriptInterface;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import org.json.JSONObject;

import java.util.ArrayList;

/**
 * Coquille de l'application télévision : l'interface /tv/ecran du site, en
 * plein écran. Le site fait tout (appairage, navigation à la télécommande) ;
 * l'application ajoute ce qu'une page web ne peut pas faire seule :
 *  - la touche Retour (remise à la page, qui dit si l'appli doit se fermer) ;
 *  - la lecture des vidéos et des souvenirs racontés sans geste préalable ;
 *  - la dictée de la recherche (reconnaissance vocale d'Android) ;
 *  - l'écran qui reste allumé (diaporama, cadre photo) ;
 *  - un écran d'attente quand la connexion manque, avec nouvel essai.
 */
public class MainActivity extends Activity {

    /** Adresse de l'interface télévision (Config est générée par build.sh). */
    private static final String HOME = Config.HOME;
    private static final String HOST = Uri.parse(Config.HOME).getHost();
    private static final int VOICE_REQUEST = 42;
    private static final long RETRY_MS = 10000;

    private WebView web;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private boolean offline = false;

    private static final String OFFLINE_PAGE = "<!doctype html><html lang=fr><head><meta charset=utf-8>"
        + "<meta name=viewport content='width=device-width'><style>"
        + "body{margin:0;height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;"
        + "background:#12100c;color:#f7f2ea;font-family:sans-serif;text-align:center}"
        + "h1{font-family:serif;font-size:6vw;margin:0 0 2vw}p{font-size:2.4vw;color:#c9bfb1;margin:0}"
        + "</style></head><body><h1>MemoryLane</h1><p>Pas de connexion à Internet pour l'instant.</p>"
        + "<p>Nouvel essai dans quelques secondes…</p></body></html>";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        web = new WebView(this);
        web.setBackgroundColor(0xFF12100C);
        setContentView(web);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        // Pas de zoom (le clavier de la télévision zoomait sur le champ de recherche).
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);
        s.setUserAgentString(s.getUserAgentString() + " MemoryLaneTV/" + version());

        CookieManager.getInstance().setAcceptCookie(true);

        web.addJavascriptInterface(new Bridge(), "MemoryLaneTV");
        // Variante de test : console de la page dans le journal Android, et inspection à distance.
        final boolean debug = !Config.AUTH_USER.isEmpty();
        WebView.setWebContentsDebuggingEnabled(debug);
        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onConsoleMessage(ConsoleMessage message) {
                if (debug) {
                    Log.i("MemoryLaneTV", message.message() + " @" + message.sourceId() + ":" + message.lineNumber());
                }
                return debug;
            }
        });
        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                // Rester sur MemoryLane : les autres adresses ne s'ouvrent pas ici.
                Uri url = request.getUrl();
                return !HOST.equals(url.getHost());
            }

            /** Variante de test (dev.memory-lane.fr est protégé par mot de passe). */
            @Override
            public void onReceivedHttpAuthRequest(WebView view, HttpAuthHandler auth, String host, String realm) {
                if (Config.AUTH_USER.isEmpty()) {
                    auth.cancel();
                } else {
                    auth.proceed(Config.AUTH_USER, Config.AUTH_PASSWORD);
                }
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (!request.isForMainFrame()) {
                    return;
                }
                offline = true;
                view.loadDataWithBaseURL(null, OFFLINE_PAGE, "text/html", "utf-8", null);
                handler.removeCallbacksAndMessages(null);
                handler.postDelayed(() -> web.loadUrl(HOME), RETRY_MS);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                if (url != null && url.startsWith("https://")) {
                    offline = false;
                }
                CookieManager.getInstance().flush();
            }
        });

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState);
        } else {
            web.loadUrl(startUrl(getIntent()));
        }
        web.requestFocus();
    }

    /** Variante de test : « adb shell am start … -e path /tv/ecran/albums » ouvre cette page. */
    private String startUrl(Intent intent) {
        String path = intent == null ? null : intent.getStringExtra("path");
        if (Config.AUTH_USER.isEmpty() || path == null || !path.startsWith("/")) {
            return HOME;
        }
        Uri home = Uri.parse(HOME);
        return home.getScheme() + "://" + home.getHost() + path;
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        if (!Config.AUTH_USER.isEmpty() && intent.hasExtra("path")) {
            web.loadUrl(startUrl(intent));
        }
    }

    /** Retour : la page décide (fermer une fenêtre, page précédente) ; sinon on quitte. */
    @Override
    public boolean dispatchKeyEvent(KeyEvent event) {
        if (event.getKeyCode() == KeyEvent.KEYCODE_BACK) {
            if (event.getAction() == KeyEvent.ACTION_UP) {
                if (offline) {
                    finish();
                } else {
                    web.evaluateJavascript("window.mlTvBack ? window.mlTvBack() : 'exit'", value -> {
                        if (value == null || value.contains("exit")) {
                            finish();
                        }
                    });
                }
            }
            return true;
        }
        return super.dispatchKeyEvent(event);
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != VOICE_REQUEST) {
            return;
        }
        String text = null;
        if (resultCode == RESULT_OK && data != null) {
            ArrayList<String> results = data.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS);
            if (results != null && !results.isEmpty()) {
                text = results.get(0);
            }
        }
        sendVoice(text);
    }

    private void sendVoice(String text) {
        String arg = text == null ? "null" : JSONObject.quote(text);
        web.evaluateJavascript("window.mlVoiceResult && window.mlVoiceResult(" + arg + ")", null);
    }

    private Intent voiceIntent() {
        Intent intent = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH);
        intent.putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM);
        intent.putExtra(RecognizerIntent.EXTRA_LANGUAGE, "fr-FR");
        intent.putExtra(RecognizerIntent.EXTRA_PROMPT, "Qui ou que cherchez-vous ?");
        return intent;
    }

    private String version() {
        try {
            return getPackageManager().getPackageInfo(getPackageName(), 0).versionName;
        } catch (Exception e) {
            return "?";
        }
    }

    /** Ce que la page peut demander à l'application (window.MemoryLaneTV). */
    private class Bridge {
        @JavascriptInterface
        public boolean canListen() {
            return voiceIntent().resolveActivity(getPackageManager()) != null;
        }

        @JavascriptInterface
        public void listen() {
            runOnUiThread(() -> {
                try {
                    startActivityForResult(voiceIntent(), VOICE_REQUEST);
                } catch (ActivityNotFoundException e) {
                    sendVoice(null);
                }
            });
        }

        @JavascriptInterface
        public String version() {
            return MainActivity.this.version();
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        web.saveState(outState);
    }

    @Override
    protected void onPause() {
        CookieManager.getInstance().flush();
        web.onPause();
        super.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        web.onResume();
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        web.destroy();
        super.onDestroy();
    }
}
