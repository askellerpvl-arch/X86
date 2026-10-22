package kz.x86.stock.security;

import org.junit.Test;
import static org.junit.Assert.*;

public class StockOriginPolicyTest {
    @Test public void onlyExactStockPagesAreTrusted() {
        for (String url : new String[]{"https://x86.kz/stock", "https://x86.kz/stock/", "https://x86.kz:443/stock/product?id=2"})
            assertTrue(url, StockOriginPolicy.isTrustedStockPage(url));
    }
    @Test public void deceptiveAndMalformedUrlsAreRejected() {
        for (String url : new String[]{null, "", "not a URL", "https://x86.kz.evil.test/stock/",
                "https://sub.x86.kz/stock/", "https://user@x86.kz/stock/", "http://x86.kz/stock/",
                "https://x86.kz:444/stock/", "https://x86.kz/stockevil", "https://x86.kz/stock/../admin",
                "https://x86.kz/stock/%2e%2e/admin", "https://x86.kz/stock/%252e%252e/admin",
                "https://x86.kz/stock/a%2fb", "https://x86.kz/stock/a\\b"})
            assertFalse(String.valueOf(url), StockOriginPolicy.isTrustedStockPage(url));
    }
    @Test public void cameraRequiresExactOriginAndVideoOnly() {
        assertTrue(StockOriginPolicy.mayGrantVideo("https://x86.kz/", new String[]{"android.webkit.resource.VIDEO_CAPTURE"}));
        assertTrue(StockOriginPolicy.isTrustedCameraOrigin("https://x86.kz:443"));
        for (String origin : new String[]{"https://evil.test", "http://x86.kz", "https://x86.kz:123", "https://x86.kz/stock/"})
            assertFalse(StockOriginPolicy.isTrustedCameraOrigin(origin));
        assertFalse(StockOriginPolicy.mayGrantVideo("https://x86.kz", new String[]{"android.webkit.resource.VIDEO_CAPTURE", "android.webkit.resource.AUDIO_CAPTURE"}));
        assertFalse(StockOriginPolicy.mayGrantVideo("https://x86.kz", new String[]{}));
        assertFalse(StockOriginPolicy.mayGrantVideo("https://x86.kz", null));
    }
    @Test public void onlySafeSchemesMayLaunchExternalApps() {
        for (String url : new String[]{"https://example.org", "http://example.org", "tel:123", "mailto:test@example.org"})
            assertTrue(StockOriginPolicy.isExternalSafeScheme(url));
        for (String url : new String[]{null, "javascript:alert(1)", "intent://x", "file:///tmp/x", "content://x", "data:text/html,test"})
            assertFalse(StockOriginPolicy.isExternalSafeScheme(url));
    }
}
