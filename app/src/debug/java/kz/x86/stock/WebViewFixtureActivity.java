package kz.x86.stock;

import android.app.Activity;
import android.os.Bundle;
import android.webkit.WebView;

/** Offline fixture host, excluded from every release build. */
public class WebViewFixtureActivity extends Activity {
    public WebView webView;
    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        webView = new WebView(this);
        webView.getSettings().setJavaScriptEnabled(true);
        setContentView(webView);
    }
    @Override public void onDestroy() {
        webView.destroy();
        super.onDestroy();
    }
}
