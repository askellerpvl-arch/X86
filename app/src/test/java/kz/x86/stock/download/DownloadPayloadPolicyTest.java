package kz.x86.stock.download;

import org.junit.Test;
import static org.junit.Assert.*;

public class DownloadPayloadPolicyTest {
    @Test public void acceptsPdfAndExistingBinaryDownloads() {
        assertTrue(DownloadPayloadPolicy.isAcceptedBase64Payload("data:application/pdf;base64,JVBERg==", 100));
        assertTrue(DownloadPayloadPolicy.isAcceptedBase64Payload("data:application/octet-stream;base64,YQ==", 100));
        assertTrue(DownloadPayloadPolicy.isAcceptedBase64Payload("data:text/csv;base64,YQ==", 100));
    }
    @Test public void rejectsInvalidOversizedAndActiveContent() {
        for (String data : new String[]{null, "", "JVBERg==", "data:application/pdf,hello", "data:application/pdf;base64,",
                "data:text/html;base64,YQ==", "data:image/svg+xml;base64,YQ==", "data:application/pdf;base64,!!!!",
                "data:application/pdf;base64,Y===", "data:application/pdf;base64,YQ=", "data:application/pdf;base64,YQ==more"})
            assertFalse(String.valueOf(data), DownloadPayloadPolicy.isAcceptedBase64Payload(data, 100));
        assertFalse(DownloadPayloadPolicy.isAcceptedBase64Payload("data:application/pdf;base64,JVBERg==", 4));
        assertFalse(DownloadPayloadPolicy.isAcceptedBase64Payload("data:application/pdf;base64,YQ==", 0));
    }
    @Test public void filenameCannotEscapeDownloadsOrBecomeHidden() {
        for (String name : new String[]{"../../invoice.pdf", "..\\..\\label.pdf", ".", "..", "\u0000bad.pdf", "/tmp/a.pdf"}) {
            String safe = DownloadPayloadPolicy.sanitizedDownloadName(name, "application/pdf");
            assertFalse(safe.contains("/")); assertFalse(safe.contains("\\"));
            assertFalse(safe.startsWith(".")); assertFalse(safe.contains("\u0000"));
        }
        assertEquals("label-50x30.pdf", DownloadPayloadPolicy.sanitizedDownloadName("label-50x30.pdf", "application/pdf"));
        assertEquals("X86-etiketki.pdf", DownloadPayloadPolicy.sanitizedDownloadName("", "application/pdf"));
    }
}
