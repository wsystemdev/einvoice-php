# SaxonC-HE for the official rule sets

Out of the box, `validate()` runs XML Schema validation and a pure-PHP pre-check of the core EN 16931 and XRechnung
rules (engine `php-core`). For the complete official rule sets, install the optional SaxonC-HE PHP extension, as
described here, or point the validator at the W System validator or API.

When the extension is loaded, the validator uses it automatically. Results then say `"engine": "saxonc"` and
`"conformance": "official"`: the bundled, unmodified KoSIT XRechnung 3.0.2 configuration (2026-08-31) and the CEN
EN 16931 XSLT run in Saxon. These are the same files and the same engine family as api.wsystem.dev and the KoSIT validator.

## Linux x86_64 build (PHP 8.1 to 8.4)

SaxonC-HE is MPL-2.0 (https://www.saxonica.com/saxon-c/). Needs `cmake`, a C++ compiler, `unzip` and `phpize` (php-dev).

```sh
curl -fLO https://downloads.saxonica.com/SaxonC/HE/13/SaxonCHE-linux-x86_64-13-1-1.zip
echo "66bdeba467fcfa0be1f78c7ae477524773a5e39821a159884a7fd1aab6301d16  SaxonCHE-linux-x86_64-13-1-1.zip" | sha256sum -c
sudo unzip -q SaxonCHE-linux-x86_64-13-1-1.zip -d /opt/saxonica
S=/opt/saxonica/SaxonCHE-linux-x86_64-13-1-1
cd $S/src && mkdir -p build && cd build \
  && cmake .. -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=$S/SaxonCHE && make -j4 && sudo make install
cd $S/php/src && phpize && ./configure --with-saxonc=$S/SaxonCHE --with-saxonc-core=$S/SaxonCCoreHE && make -j4 && sudo make install
```

Then load it. The libraries must be on the loader path:

```sh
export LD_LIBRARY_PATH=$S/SaxonCCoreHE/lib:$S/SaxonCHE/lib   # or add both directories to /etc/ld.so.conf.d/ + ldconfig
php -d extension=saxon.so -r 'echo (new Saxon\SaxonProcessor(false))->version(), PHP_EOL;'
```

Make it permanent with `extension=saxon.so` in your php.ini (CLI and FPM). The extension registers as `saxonc`
(`php -m`), and its class is `Saxon\SaxonProcessor`.

Check it:

```sh
vendor/bin/wsystem-einvoice validate invoice.xml
# valid [saxonc] EN 16931 + XRechnung 3.0.2: official KoSIT/CEN schematron (KoSIT config 2026-08-31)
```

CI builds exactly this recipe on PHP 8.1 to 8.4 (job `saxonc` in `.github/workflows/ci.yml`). It runs the parity
tests with `WSYSTEM_REQUIRE_SAXONC=1`, so a missing extension fails the build instead of skipping.

## Known SaxonC 13.1 behaviour

- When an official rule hits a dynamic error (for example `XX` where a number is required), Saxon stops the whole run.
  The validator then throws `RuleEvaluationError`, an `InputError` that carries the XML Schema findings. SaxonC also
  prints its own stack trace on stderr, and PHP can't switch that off.
- The validator avoids a SaxonC 13.1 crash, where a stylesheet that raised an error segfaults the process when it is
  freed, by using `applyTemplatesReturningString()`. Don't call `transformToString()` on the cached executables yourself.
