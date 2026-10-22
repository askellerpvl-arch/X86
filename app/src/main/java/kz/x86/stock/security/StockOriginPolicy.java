package kz.x86.stock.security;

import java.net.URI;
import java.util.Locale;

/** Decisions only; does not perform network requests or grant permissions. */
public final class StockOriginPolicy {
    private StockOriginPolicy() { }
    private static URI parse(String value) {
        try { return value == null ? null : new URI(value); }
        catch (Exception invalid) { return null; }
    }
    private static boolean trustedAuthority(URI uri) {
        return uri != null && "https".equalsIgnoreCase(uri.getScheme())
                && "x86.kz".equalsIgnoreCase(uri.getHost()) && uri.getRawUserInfo() == null
                && (uri.getPort() == -1 || uri.getPort() == 443);
    }
    public static boolean isTrustedStockPage(String value) {
        URI uri = parse(value);
        if (!trustedAuthority(uri)) return false;
        String path = uri.getRawPath();
        if (path == null || !(path.equals("/stock") || path.startsWith("/stock/"))) return false;
        String lower = path.toLowerCase(Locale.ROOT);
        if (lower.contains("%2e") || lower.contains("%2f") || lower.contains("%5c")
                || lower.contains("%25") || lower.contains("//")) return false;
        return uri.normalize().getRawPath().equals(path);
    }
    public static boolean isTrustedCameraOrigin(String value) {
        URI uri = parse(value);
        return trustedAuthority(uri) && (uri.getRawPath().isEmpty() || "/".equals(uri.getRawPath()))
                && uri.getRawQuery() == null && uri.getRawFragment() == null;
    }
    public static boolean mayGrantVideo(String origin, String[] resources) {
        return isTrustedCameraOrigin(origin) && resources != null && resources.length == 1
                && "android.webkit.resource.VIDEO_CAPTURE".equals(resources[0]);
    }
    public static boolean isExternalSafeScheme(String value) {
        URI uri = parse(value);
        if (uri == null || uri.getScheme() == null) return false;
        String scheme = uri.getScheme().toLowerCase(Locale.ROOT);
        return scheme.equals("https") || scheme.equals("http") || scheme.equals("tel") || scheme.equals("mailto");
    }
}
