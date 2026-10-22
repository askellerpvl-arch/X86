package kz.x86.stock.download;

import org.junit.Test;
import static org.junit.Assert.*;

public class DownloadAdmissionTest {
    @Test public void holdsLimitUntilUiSaveCompletesAndDoesNotDoubleRelease() {
        DownloadAdmission admission = new DownloadAdmission(2);
        DownloadAdmission.Permit a = admission.acquire(), b = admission.acquire();
        assertNotNull(a); assertNotNull(b); assertNull(admission.acquire());
        a.close(); a.close();
        DownloadAdmission.Permit c = admission.acquire();
        assertNotNull(c); assertNull(admission.acquire());
        b.close(); c.close();
        assertNotNull(admission.acquire()); assertNotNull(admission.acquire());
        assertNull(admission.acquire());
    }
    @Test public void destroyRejectsNewWorkAndReleasesAllLeasesSafely() {
        DownloadAdmission admission = new DownloadAdmission(1);
        DownloadAdmission.Permit a = admission.acquire();
        admission.close(); a.close(); assertNull(admission.acquire());
    }
}
