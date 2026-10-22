package kz.x86.stock.download;

/** Bounds accepted encoded/decoded work until the UI has saved or queued its bytes. */
public final class DownloadAdmission implements AutoCloseable {
    private final int limit;
    private int active;
    private boolean closed;
    public DownloadAdmission(int limit) { this.limit = limit; }
    public synchronized Permit acquire() {
        if (closed || active >= limit) return null;
        active++; return new Permit(this);
    }
    private synchronized void release() { if (active > 0) active--; }
    @Override public synchronized void close() { closed = true; active = 0; }
    public static final class Permit implements AutoCloseable {
        private DownloadAdmission owner;
        private Permit(DownloadAdmission owner) { this.owner = owner; }
        @Override public synchronized void close() {
            if (owner != null) { owner.release(); owner = null; }
        }
    }
}
