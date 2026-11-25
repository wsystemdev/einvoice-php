#!/bin/sh
# Fresh-directory composer require smoke. Usage:
#   tools/ci/packagist-smoke.sh wsystem/einvoice:^0.9
#   tools/ci/packagist-smoke.sh wsystem/einvoice:dev-main
# Exits non-zero if Packagist does not serve the package yet.
set -eu
constraint=${1:-wsystem/einvoice:dev-main}
dir=$(mktemp -d)
trap 'rm -rf "$dir"' EXIT
cd "$dir"
composer require "$constraint" --no-interaction --no-progress
test -x vendor/bin/wsystem-einvoice
vendor/bin/wsystem-einvoice --version
php -r 'require "vendor/autoload.php"; echo (new ReflectionClass("WSystem\\EInvoice\\Builder"))->getName(), "\n";'
echo "packagist smoke ok: $constraint"
