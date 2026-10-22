# Supplied source reference, not a deployment

Source: user-supplied `x86stock-update-1.9.3.ocmod.zip`.
Archive SHA-256: `40a66a91e2f7d9c18c8312dd56e5131f16444392ceda2af63ccfc8fa00d4fe2e`.
`lib.php` is byte-identical to `upload/stock/lib.php`. Module DDL is extracted
from the supplied admin controller; no production config or data is included.
OpenCart base tables in `../base-schema.sql` are minimal synthetic test fixtures.

`../run.sh` creates a disposable, internal Docker network and synthetic MariaDB.
It never loads the production `config.php`, publishes no ports and destroys its DB.
Do not deploy any test file to the production server.

Frontend source fixtures are under `app/src/debug/assets/server-fixture/` and are
excluded from release APKs. The HTML removes PHP category generation and external
resources; JavaScript/CSS are the supplied source files, with hashes recorded here.
