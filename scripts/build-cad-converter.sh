#!/usr/bin/env bash
set -euo pipefail

# Optional Laravel Cloud build step. The SDK is temporary; only the self-contained engine ships.
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cad_sdk_version=8.0.426
cad_sdk_sha512=8141d01bdc53f604aed3bb661b604707a64209f8a947cd81bf95b4f518951593d77864069191f54ea5e84362a409da6b0f69074bf893b283d7e0cf93e2bf20a8
cad_temp="$(mktemp -d)"
trap 'rm -rf -- "$cad_temp"' EXIT
export DOTNET_CLI_TELEMETRY_OPTOUT=1 DOTNET_SKIP_FIRST_TIME_EXPERIENCE=1
export DOTNET_CLI_HOME="$cad_temp/home"
if command -v dotnet >/dev/null 2>&1; then
    cad_dotnet="$(command -v dotnet)"
else
    curl --fail --location --retry 2 --max-time 240 \
        "https://builds.dotnet.microsoft.com/dotnet/Sdk/$cad_sdk_version/dotnet-sdk-$cad_sdk_version-linux-x64.tar.gz" \
        --output "$cad_temp/sdk.tar.gz"
    printf '%s  %s\n' "$cad_sdk_sha512" "$cad_temp/sdk.tar.gz" | sha512sum --check --status
    mkdir "$cad_temp/dotnet"
    tar -xzf "$cad_temp/sdk.tar.gz" -C "$cad_temp/dotnet"
    cad_dotnet="$cad_temp/dotnet/dotnet"
fi
"$cad_dotnet" restore "$repo_root/services/cad-converter/CadConverter.csproj" --locked-mode
"$cad_dotnet" publish "$repo_root/services/cad-converter/CadConverter.csproj" -c Release \
    -r linux-x64 --self-contained true -o "$repo_root/services/cad-converter/publish"
chmod 755 "$repo_root/services/cad-converter/publish/Nahshon.CadConverter"
"$repo_root/services/cad-converter/publish/Nahshon.CadConverter" capabilities
