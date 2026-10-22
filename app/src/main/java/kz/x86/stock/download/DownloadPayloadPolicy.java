package kz.x86.stock.download;

import java.util.Arrays;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;

public final class DownloadPayloadPolicy {
    public static final int MAX_ENCODED_CHARS = 16 * 1024 * 1024;
    private static final Set<String> TYPES = new HashSet<>(Arrays.asList(
            "application/pdf", "application/octet-stream", "text/csv", "text/plain",
            "application/zip", "image/png", "image/jpeg", "application/vnd.ms-excel",
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"));
    private DownloadPayloadPolicy() { }
    public static boolean isAcceptedBase64Payload(String value, int maxEncodedChars) {
        if (value == null || maxEncodedChars <= 0 || value.length() > (long) maxEncodedChars + 160) return false;
        int comma = value.indexOf(',');
        if (comma < 0 || comma > 160 || !value.startsWith("data:")) return false;
        String header = value.substring(5, comma);
        int suffix = header.indexOf(';');
        if (suffix <= 0 || !header.substring(suffix).equals(";base64") || !TYPES.contains(header.substring(0, suffix))) return false;
        int length = value.length() - comma - 1;
        if (length == 0 || length > maxEncodedChars || length % 4 != 0) return false;
        int padding = 0;
        for (int i = comma + 1; i < value.length(); i++) {
            char c = value.charAt(i);
            if (c == '=') { if (++padding > 2 || i < value.length() - 2) return false; }
            else if (padding != 0 || !((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z')
                    || (c >= '0' && c <= '9') || c == '+' || c == '/')) return false;
        }
        return true;
    }
    public static String mimeFromDataUrl(String value) { return value.substring(5, value.indexOf(';')); }
    public static String sanitizedDownloadName(String requested, String mime) {
        String name = requested == null ? "" : requested.trim();
        name = name.replaceAll("[\\\\/:*?\"<>|\\p{Cntrl}]", "_").replaceAll("^\\.+", "");
        if (name.length() > 120) name = name.substring(0, 100) + (name.toLowerCase(Locale.ROOT).endsWith(".pdf") ? ".pdf" : "");
        if (name.isEmpty() || name.equalsIgnoreCase("download") || !name.contains(".")) {
            if ("application/pdf".equalsIgnoreCase(mime)) return "X86-etiketki.pdf";
            if (name.isEmpty()) return "X86-file";
        }
        return name;
    }
}
