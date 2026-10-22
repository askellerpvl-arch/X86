package kz.x86.stock;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.ext.junit.rules.ActivityScenarioRule;
import org.junit.Rule;
import org.junit.Test;
import org.junit.runner.RunWith;
import static org.junit.Assert.*;
import static org.junit.Assume.assumeTrue;
import static androidx.test.platform.app.InstrumentationRegistry.getInstrumentation;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import kz.x86.stock.download.TrustedDownloadBridge;
import androidx.webkit.WebViewFeature;

@RunWith(AndroidJUnit4.class)
public class TrustedDownloadBridgeTest {
    @Rule public ActivityScenarioRule<WebViewFixtureActivity> activity = new ActivityScenarioRule<>(WebViewFixtureActivity.class);
    private WebView view;
    private final AtomicInteger accepted = new AtomicInteger();
    private void load(String html, String origin) throws Exception {
        activity.getScenario().onActivity(host -> view = host.webView);
        CountDownLatch loaded = new CountDownLatch(1);
        getInstrumentation().runOnMainSync(() -> {
            assumeTrue(TrustedDownloadBridge.install(view, (data, name, mime) -> accepted.incrementAndGet()));
            view.setWebViewClient(new WebViewClient() {
                @Override public void onPageFinished(WebView v, String url) { loaded.countDown(); }
            });
            view.loadDataWithBaseURL(origin, html, "text/html", "UTF-8", origin);
        });
        assertTrue(loaded.await(15, TimeUnit.SECONDS));
        AtomicReference<String> loadedUrl = new AtomicReference<>();
        getInstrumentation().runOnMainSync(() -> loadedUrl.set(view.getUrl()));
        assertEquals("fixture history URL must model a real loaded page", origin, loadedUrl.get());
    }
    private String evaluate(String script) throws Exception {
        CountDownLatch done = new CountDownLatch(1); AtomicReference<String> result = new AtomicReference<>();
        getInstrumentation().runOnMainSync(() -> view.evaluateJavascript(script, value -> { result.set(value); done.countDown(); }));
        assertTrue(done.await(10, TimeUnit.SECONDS)); return result.get();
    }
    private void awaitStatus(String expression, String status) throws Exception {
        String expected = "\"" + status + "\"";
        for (int i = 0; i < 40; i++) if (expected.equals(evaluate(expression))) return;
        assertEquals(expected, evaluate(expression));
    }
    private static final String MESSAGE = "JSON.stringify({version:1,type:'download',id:'test-1',dataUrl:'data:application/pdf;base64,JVBERg==',fileName:'label.pdf'})";
    @Test public void acceptsOnlyTopFramePdfAndAcknowledgesReceipt() throws Exception {
        load("<p>PDF fixture</p>", "https://x86.kz/stock/");
        evaluate("X86Trusted.onmessage=function(e){window.ack=e.data};X86Trusted.postMessage(" + MESSAGE + ")");
        getInstrumentation().waitForIdleSync();
        // A renderer round-trip drains the earlier posted message without network access.
        awaitStatus("JSON.parse(window.ack || '{}').status", "received");
        assertEquals(1, accepted.get());
    }
    @Test public void rejectsSameOriginIframeMessages() throws Exception {
        load("<iframe id='child' srcdoc='<p>Child</p>'></iframe>", "https://x86.kz/stock/");
        evaluate("var child=document.getElementById('child').contentWindow; child.X86Trusted.onmessage=function(e){child.ack=e.data};child.X86Trusted.postMessage(" + MESSAGE + ")");
        awaitStatus("JSON.parse(document.getElementById('child').contentWindow.ack || '{}').status", "rejected");
        assertEquals(0, accepted.get());
    }
    @Test public void foreignOriginNeverReceivesNativeObject() throws Exception {
        load("<p>Foreign fixture</p>", "https://foreign.example/");
        assertEquals("\"undefined\"", evaluate("typeof X86Trusted"));
        assertEquals(0, accepted.get());
    }
    @Test public void rejectsMalformedOrUnsupportedEnvelope() throws Exception {
        load("<p>PDF fixture</p>", "https://x86.kz/stock/");
        evaluate("X86Trusted.onmessage=function(e){window.ack=e.data};X86Trusted.postMessage('not JSON');X86Trusted.postMessage(JSON.stringify({version:2,type:'download',id:'x',dataUrl:'data:application/pdf;base64,JVBERg=='}));");
        awaitStatus("JSON.parse(window.ack || '{}').status", "rejected"); assertEquals(0, accepted.get());
    }

    @Test public void existingBase64AdapterSupportsBlobPdfWithoutLegacyNativeInterface() throws Exception {
        load("<p>Blob PDF fixture</p>", "https://x86.kz/stock/");
        String adapter;
        try (java.io.InputStream in = getInstrumentation().getTargetContext().getAssets().open("trusted-download-adapter.js");
             java.io.ByteArrayOutputStream out = new java.io.ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096]; int n;
            while ((n = in.read(buffer)) != -1) out.write(buffer, 0, n);
            adapter = new String(out.toByteArray(), java.nio.charset.StandardCharsets.UTF_8);
        }
        assertEquals("\"undefined\"", evaluate("typeof X86Android"));
        assertEquals("true", evaluate(adapter));
        evaluate("X86Trusted.onmessage=function(e){window.ack=e.data};var reader=new FileReader();reader.onloadend=function(){X86Android.saveBase64(reader.result,'label.pdf','application/pdf')};reader.readAsDataURL(new Blob(['%PDF-1.4 fixture'],{type:'application/pdf'}));");
        awaitStatus("JSON.parse(window.ack || '{}').status", "received");
        assertEquals(1, accepted.get());
    }
    @Test public void rejectsArrayBufferWithoutCrashing() throws Exception {
        assumeTrue(WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_ARRAY_BUFFER));
        load("<p>Binary message fixture</p>", "https://x86.kz/stock/");
        evaluate("X86Trusted.onmessage=function(e){window.ack=e.data};X86Trusted.postMessage(new ArrayBuffer(1));");
        awaitStatus("JSON.parse(window.ack || '{}').status", "rejected");
        assertEquals(0, accepted.get());
    }
}
