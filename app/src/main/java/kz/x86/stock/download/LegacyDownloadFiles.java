package kz.x86.stock.download;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;

public final class LegacyDownloadFiles {
    private LegacyDownloadFiles() { }
    /** Reserve a new name atomically, never truncate another exported document. */
    public static File save(File directory, String requested, byte[] bytes) throws IOException {
        if (!directory.exists() && !directory.mkdirs()) throw new IOException("Downloads unavailable");
        String name = DownloadPayloadPolicy.sanitizedDownloadName(requested, "application/octet-stream");
        int dot = name.lastIndexOf('.');
        String stem = dot > 0 ? name.substring(0, dot) : name;
        String extension = dot > 0 ? name.substring(dot) : "";
        for (int index = 0; index < 1000; index++) {
            File file = new File(directory, index == 0 ? name : stem + "-" + index + extension);
            if (!file.createNewFile()) continue;
            try (FileOutputStream out = new FileOutputStream(file)) {
                out.write(bytes); out.flush();
            } catch (IOException failure) {
                if (!file.delete()) file.deleteOnExit();
                throw failure;
            }
            return file;
        }
        throw new IOException("Too many matching download names");
    }
}
