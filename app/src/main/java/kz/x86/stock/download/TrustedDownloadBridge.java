package kz.x86.stock.download;

import android.webkit.WebView;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;
import androidx.webkit.WebMessageCompat;
import java.util.Collections;
import org.json.JSONObject;
import kz.x86.stock.security.StockOriginPolicy;

/** Optional migration path: native object exists only for the exact HTTPS origin. */
public final class TrustedDownloadBridge {
    public interface PayloadHandler { void save(String dataUrl, String name, String mime); }
    private TrustedDownloadBridge() { }
    public static boolean install(WebView view, PayloadHandler handler) {
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) return false;
        WebViewCompat.addWebMessageListener(view, "X86Trusted", Collections.singleton("https://x86.kz"),
                (webView, message, origin, mainFrame, reply) -> {
                    if (message.getType() != WebMessageCompat.TYPE_STRING) {
                        reply.postMessage("{\"status\":\"rejected\"}"); return;
                    }
                    String raw = message.getData();
                    if (!mainFrame || !StockOriginPolicy.isTrustedCameraOrigin(origin.toString())
                            || !StockOriginPolicy.isTrustedStockPage(webView.getUrl())
                            || raw == null || raw.length() > DownloadPayloadPolicy.MAX_ENCODED_CHARS + 2048) {
                        reply.postMessage("{\"status\":\"rejected\"}"); return;
                    }
                    try {
                        JSONObject body = new JSONObject(raw);
                        String id = body.getString("id");
                        String data = body.getString("dataUrl");
                        if (body.getInt("version") != 1 || !"download".equals(body.getString("type"))
                                || id.isEmpty() || id.length() > 64
                                || !DownloadPayloadPolicy.isAcceptedBase64Payload(data, DownloadPayloadPolicy.MAX_ENCODED_CHARS)) {
                            reply.postMessage("{\"status\":\"rejected\"}"); return;
                        }
                        handler.save(data, body.optString("fileName", ""), DownloadPayloadPolicy.mimeFromDataUrl(data));
                        // Receipt only. This does not assert that bytes were saved or a sale succeeded.
                        reply.postMessage(new JSONObject().put("id", id).put("status", "received").toString());
                    } catch (Exception malformed) { reply.postMessage("{\"status\":\"rejected\"}"); }
                });
        return true;
    }
}
