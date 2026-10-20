FROM mcr.microsoft.com/dotnet/sdk:8.0 AS build
WORKDIR /source
COPY services/cad-converter ./
RUN dotnet publish -c Release -r linux-x64 --self-contained true -o /out

FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends libicu72 libssl3 && rm -rf /var/lib/apt/lists/*
COPY --from=build /out /opt/cad
USER 65534:65534
ENTRYPOINT ["/opt/cad/Nahshon.CadConverter"]
