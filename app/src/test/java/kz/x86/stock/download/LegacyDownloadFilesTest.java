package kz.x86.stock.download;

import org.junit.Test;
import org.junit.Rule;
import org.junit.rules.TemporaryFolder;
import java.io.File;
import java.nio.file.Files;
import static org.junit.Assert.*;

public class LegacyDownloadFilesTest {
    @Rule public TemporaryFolder temporary = new TemporaryFolder();
    @Test public void identicalPdfNamesPreserveBothFilesAndExistingDocuments() throws Exception {
        File dir = temporary.newFolder();
        File existing = new File(dir, "X86-etiketki.pdf");
        Files.write(existing.toPath(), new byte[]{9});
        PendingDownloads queue = new PendingDownloads();
        queue.enqueue(new byte[]{1}, "X86-etiketki.pdf", "application/pdf");
        queue.enqueue(new byte[]{2}, "X86-etiketki.pdf", "application/pdf");
        PendingDownloads.FileData first = queue.poll(), second = queue.poll();
        File a = LegacyDownloadFiles.save(dir, first.name, first.bytes);
        File b = LegacyDownloadFiles.save(dir, second.name, second.bytes);
        assertNotEquals(a, b);
        assertArrayEquals(new byte[]{9}, Files.readAllBytes(existing.toPath()));
        assertArrayEquals(new byte[]{1}, Files.readAllBytes(a.toPath()));
        assertArrayEquals(new byte[]{2}, Files.readAllBytes(b.toPath()));
    }
}
