# Pixel SM mode map pool

This directory contains mode-specific ShootMania map assets used by first-party local smoke runs.

The bootstrap flow syncs these maps into runtime under:

- `UserData/Maps/PixelControl/siege/`
- `UserData/Maps/PixelControl/battle/`

## Source maps (ManiaExchange)

Siege:

- `10050` - `.SiegeDiversity.`
  - https://sm.mania.exchange/mapshow/10050
  - Download endpoint: https://sm.mania.exchange/mapgbx/10050
- `9242` - `Siege - MoniTor`
  - https://sm.mania.exchange/mapshow/9242
  - Download endpoint: https://sm.mania.exchange/mapgbx/9242

Battle:

- `45349` - `Battle - Space is Fake`
  - https://sm.mania.exchange/mapshow/45349
  - Download endpoint: https://sm.mania.exchange/mapgbx/45349
- `45122` - `Battle - In Plane Sight - v1,00`
  - https://sm.mania.exchange/mapshow/45122
  - Download endpoint: https://sm.mania.exchange/mapgbx/45122

## Refreshing maps

Replace map files with newer versions by downloading from the same `mapgbx/{id}` endpoints and keeping filenames aligned with matchsettings templates.

## Runtime compatibility note

- Siege pool is validated against `ShootMania\SiegeV1.Script.txt` on current runtime.
- Battle pool requires `SMStormBattle@nadeolabs.Title.Pack.gbx` in `pixel-sm-server/TitlePacks/`.
  - Download source: `https://maniaplanet.com/ingame/public/titles/download/SMStormBattle@nadeolabs.Title.Pack.gbx`
  - Helper command: `bash scripts/fetch-titlepack.sh SMStormBattle@nadeolabs`
  - Battle mode script can be provided by that title pack even when not present as a loose runtime script file.
