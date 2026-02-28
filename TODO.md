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
  - [x] Implémenter les P2 (read API — 12 endpoints GET : players, combat stats, scores, lifecycle, maps, mode, capabilities)
  - [x] Implémenter les P2.5 (stats combat par map et par série — 4 endpoints GET supplémentaires)
  - [ ] **Vérifier les P2 et P2.5 avec le serveur SM réel** — lancer une session de jeu, générer des events live, et valider que tous les endpoints retournent des données cohérentes (pas juste des fixtures injectées via curl)
  - [ ] Implémenter les P3 (admin essentiel — map management, warmup/pause, match/series config via proxy socket plugin)
  - [ ] Implémenter les P4 (contrôle étendu — veto/draft, force-team/play/spec, team policy & roster)
  - [ ] Implémenter les P5 (low priority — whitelist, vote policy, auth grant/revoke, player history)
  - [ ] Ajouter système de CORS pour qu'uniquement certaines URL puisse utiliser l'API et pas d'autres.

## État actuel (2026-02-28)

### Ce qui fonctionne
- API NestJS (P0 + P1 + P2 + P2.5) : 22 endpoints au total
- Ingestion unifiée `POST /v1/plugin/events` (connectivity, lifecycle, combat, player, mode, batch)
- Read API P2 : players, combat stats, scores, lifecycle, map-rotation, aggregate-stats, capabilities, maps, mode
- Read API P2.5 : stats combat par map (`/stats/combat/maps`, `/maps/:mapUid`, `/maps/:mapUid/players/:login`) + par série (`/stats/combat/series`)
- 199 tests unitaires + 4 smoke test scripts (P0: 43, P1: 35, P2: 94, P2.5: 59 assertions)
- Docker: auto-migration Prisma, plugin baked into SM image, bind-mount volumes dans `data/`

### Points d'attention pour reprendre
- **Branche `feat/p2-read-api`** contient les P2 + P2.5 (pas encore mergé dans `main`)
- **Les P2/P2.5 ont été testés uniquement avec des fixtures curl** — il faut valider avec des events réels venant du plugin SM en jeu (combats Elite, changements de map, fin de match)
- **Les fichiers `.env.{mode}`** (`.env.elite`, `.env.joust`) sont corrigés avec la bonne URL API
- **ManiaControl persiste les settings dans MySQL** — si on change une env var, wiper MySQL (`rm -rf pixel-sm-server/data/mysql`) + recreate
- **`auth_mode=bearer`** s'activate dès que MySQL a une ancienne config. Sur env frais, c'est `auth_mode=none` (correct pour dev)
- **Prochaine étape** : Valider les P2/P2.5 avec le serveur SM réel, puis passer aux P3 (admin essentiel)