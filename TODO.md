# TODO

- [ ] Faire le serveur SM aussi compatible avec les modes :
  - [x] Elite
  - [x] Joust
  - [x] Battle
  - [ ] Siege
  - [ ] DuelElite
  - [ ] SpeedBall

- [x] Ajouter une UI pour faire le veto au lieu d'un simple veto dans le chat

- [ ] Faire un audit de comment les informations seront transmisent entre "Pixel Plugin <-> Pixel Server"

- [ ] Faire l'API
  - [x] Faire la description de toutes les actions executables et envoyables par le plugin
  - [x] Faire la liste de tous les domaines des actions (Whitelist / Veto / Match / Combat / ...)
  - [x] Faire la liste des actions par priorités et status d'implémentation
  - [x] Ajouter endpoint pour avoir la liste des seveurs liés dans la ROADMAP
  - [x] Implémenter les P0 (link, connectivity, server CRUD)
  - [x] Implémenter les P1 — endpoint unique POST /v1/plugin/events pour toutes les catégories + GET status/health
  - [ ] Implémenter les P2 (read API — exposer la télémétrie ingérée : players, combat stats, scores, lifecycle, maps, mode)
  - [ ] Implémenter les P3 (admin essentiel — map management, warmup/pause, match/series config via proxy socket plugin)
  - [ ] Implémenter les P4 (contrôle étendu — veto/draft, force-team/play/spec, team policy & roster)
  - [ ] Implémenter les P5 (low priority — whitelist, vote policy, auth grant/revoke, player history)
  - [ ] Ajouter système de CORS pour qu'uniquement certaines URL puisse utiliser l'API et pas d'autres.

## État actuel (2026-02-27)

### Ce qui fonctionne
- API NestJS (P0 + P1) : endpoint unifié `POST /v1/plugin/events` accepte toutes les catégories (connectivity, lifecycle, combat, player, mode)
- Table `events` unifiée en BDD (+ `connectivity_events` legacy pour backward compat)
- `GET /v1/servers/:serverLogin/status` et `GET /v1/servers/:serverLogin/status/health`
- Pipeline plugin → API live et fonctionnelle (events arrivent en temps réel)
- 96 tests unitaires + 2 smoke test scripts (P0: 43 assertions, P1: 35 assertions)
- Docker: auto-migration Prisma, plugin baked into SM image, bind-mount volumes dans `data/`

### Points d'attention pour reprendre
- **Les fichiers `.env.{mode}`** (`.env.elite`, `.env.joust`) ont été corrigés avec la bonne `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:3000/v1`. Quand on switch de mode avec `dev-mode-compose.sh`, le `.env` est écrasé par le `.env.{mode}` correspondant — il faut que tous les `.env.{mode}` aient la bonne URL.
- **ManiaControl persiste les settings dans MySQL** — si on change une env var dans `.env`, il faut aussi wiper MySQL (`rm -rf pixel-sm-server/data/mysql`) et recréer le conteneur (`docker compose up -d`) pour que la nouvelle valeur s'applique.
- **Les services catégorie P1 sont des placeholders** (lifecycle, combat, player, mode) — ils log en debug mais ne font pas de traitement spécifique. Le vrai travail de "read model" sera en P2.
- **`auth_mode=bearer`** s'active dès que MySQL a une ancienne config. Sur env frais, c'est `auth_mode=none` (correct pour dev).
- **Prochaine étape** : P2 (Read API) — exposer les données ingérées via des endpoints GET typés par catégorie.