package kz.x86.stock.download;

import org.junit.Test;
import static org.junit.Assert.*;

public class PendingDownloadsTest {
    @Test public void tracksMultiplePdfIdsIndependentlyAndConsumesEachOnce() {
        PendingDownloads pending = new PendingDownloads();
        pending.trackPdf(10); pending.trackPdf(11);
        assertFalse(pending.consumePdf(99));
        assertTrue(pending.consumePdf(11)); assertFalse(pending.consumePdf(11));
        assertTrue(pending.consumePdf(10));
    }
    @Test public void boundedQueuePreservesFirstDownloadInsteadOfOverwritingIt() {
        PendingDownloads pending = new PendingDownloads();
        assertTrue(pending.enqueue(new byte[]{1}, "first.pdf", "application/pdf"));
        assertTrue(pending.enqueue(new byte[]{2}, "second.pdf", "application/pdf"));
        assertEquals("first.pdf", pending.poll().name);
        assertEquals("second.pdf", pending.poll().name);
        assertNull(pending.poll());
        assertFalse(pending.enqueue(new byte[PendingDownloads.MAX_PENDING_BYTES + 1], "huge.pdf", "application/pdf"));
    }
    @Test public void clearReleasesMemoryAndPdfTracking() {
        PendingDownloads pending = new PendingDownloads();
        pending.trackPdf(1); pending.enqueue(new byte[]{1}, "a.pdf", "application/pdf");
        pending.clear(); assertNull(pending.poll()); assertFalse(pending.consumePdf(1));
    }
}
