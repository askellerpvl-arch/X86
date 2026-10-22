package kz.x86.stock.ui;

import android.webkit.WebView;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;
import androidx.webkit.WebMessageCompat;
import java.util.Collections;
import org.json.JSONObject;
import kz.x86.stock.security.StockOriginPolicy;

public final class StockEventBridge {
    public interface Listener { void confirmed(String action, long reference); }
    private StockEventBridge() { }
    public static boolean install(WebView view, Listener listener) {
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) return false;
        WebViewCompat.addWebMessageListener(view,"X86Status",Collections.singleton("https://x86.kz"),
                (page,message,origin,mainFrame,reply) -> {
                    try {
                        if (!mainFrame || message.getType() != WebMessageCompat.TYPE_STRING
                                || !StockOriginPolicy.isTrustedStockPage(page.getUrl())) return;
                        String raw=message.getData();
                        if (raw==null || raw.length()>2048) return;
                        JSONObject body=new JSONObject(raw);
                        if (body.optInt("version")==1 && "scan".equals(body.optString("type"))
                                && StockOriginPolicy.isTrustedCameraOrigin(origin.toString())) {
                            listener.confirmed("scan",0); return;
                        }
                        String action=body.getString("action");long reference=body.getLong("reference");
                        if (body.getInt("version")==1 && "operation".equals(body.getString("type"))
                                && OperationNoticePolicy.accepts(mainFrame,origin.toString(),action,reference))
                            listener.confirmed(action,reference);
                    } catch (Exception rejected) { /* A malformed notice never alters a transaction. */ }
                });
        return true;
    }
}
