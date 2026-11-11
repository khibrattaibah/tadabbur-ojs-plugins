#!/usr/bin/env bash
# Builds installable archives (standard OJS layout: one top-level folder with version.xml) + checksums into dist/.
set -euo pipefail
cd "$(dirname "$0")/.."
ver() { grep -o '<release>[^<]*' "$1/version.xml" | sed 's/<release>//; s/\.0$//'; }
rm -rf dist && mkdir dist
SV=$(ver plugins/generic/tadabburSuite); TV=$(ver plugins/themes/tadabbur)
(cd plugins/generic && zip -qrX "../../dist/tadabburSuite-$SV.zip" tadabburSuite -x '*/__pycache__/*')
(cd plugins/themes  && zip -qrX "../../dist/tadabbur-theme-$TV.zip" tadabbur -x '*/__pycache__/*')
(cd dist && sha256sum *.zip > SHA256SUMS.txt && cat SHA256SUMS.txt)
