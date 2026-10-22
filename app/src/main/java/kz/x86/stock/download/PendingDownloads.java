package kz.x86.stock.download;

import java.util.ArrayDeque;
import java.util.HashSet;
import java.util.Set;

/** Activity-scoped bookkeeping. Never retries a server request or stores credentials. */
public final class PendingDownloads {
    public static final int MAX_PENDING_BYTES = 16 * 1024 * 1024;
    private final Set<Long> pdfIds = new HashSet<>();
    private final ArrayDeque<FileData> files = new ArrayDeque<>();
    private int pendingBytes;
    public static final class FileData {
        public final byte[] bytes;
        public final String name;
        public final String mime;
        FileData(byte[] bytes, String name, String mime) { this.bytes = bytes; this.name = name; this.mime = mime; }
    }
    public void trackPdf(long id) { if (id >= 0) pdfIds.add(id); }
    public boolean consumePdf(long id) { return pdfIds.remove(id); }
    public boolean enqueue(byte[] bytes, String name, String mime) {
        if (bytes == null || bytes.length == 0 || files.size() >= 8 || bytes.length > MAX_PENDING_BYTES - pendingBytes) return false;
        files.add(new FileData(bytes, name, mime)); pendingBytes += bytes.length; return true;
    }
    public FileData poll() {
        FileData file = files.poll();
        if (file != null) pendingBytes -= file.bytes.length;
        return file;
    }
    public boolean isEmpty() { return files.isEmpty(); }
    public void clear() { pdfIds.clear(); files.clear(); pendingBytes = 0; }
}
