# TODO

- [ ] Faire le serveur SM aussi compatible avec les modes :
  - [x] Elite
  - [ ] Joust
  - [ ] Battle
  - [ ] Siege
  - [ ] DuelElite
  - [ ] SpeedBall

- [ ] Ajouter une UI pour faire le veto au lieu d'un simple veto dans le chat, dans le jeu (pas dans pixel-control-ui)

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
  - [x] Implémenter les P2.6 (historique combat joueur par map + stats dérivées : kd_ratio, win_rate, rocket/laser accuracy + Elite attack/defense win rates)
  - [ ] **Vérifier les P2, P2.5 et P2.6 avec le serveur SM réel** :
    - [ ] Rebuild le serveur SM avec le plugin mis à jour (`docker compose up -d --build`)
    - [ ] Lancer le serveur SM en mode Elite, jouer quelques rounds, changer de map, finir un match
    - [ ] Vérifier que `GET .../stats/combat/players/:login` retourne `kd_ratio`, `hits_rocket`, `hits_laser`, `rocket_accuracy`, `laser_accuracy`
    - [ ] Vérifier que `GET .../stats/combat/players/:login` retourne `attack_win_rate`, `defense_win_rate` (Elite)
    - [ ] Vérifier que `GET .../stats/combat/players/:login/maps` retourne l'historique par map avec `won`, `win_rate`, `maps_won`
    - [ ] Vérifier que `GET .../stats/combat/maps` retourne les stats par map avec les nouveaux compteurs
    - [ ] Vérifier que `GET .../stats/combat/series` retourne un BO complet avec breakdown par map
    - [ ] Vérifier que les champs `hits_rocket`/`hits_laser` sont bien remplis (pas null) après mise à jour du plugin
    - [ ] Vérifier que la logique de défense Elite fonctionne (1+ hit sans mourir OU 2+ rocket hits même mort)
    - [ ] Vérifier les endpoints P2 de base (players, lifecycle, mode, scores, capabilities) avec des données réelles
  - [ ] Merger `feat/p2-read-api` dans `main` une fois la validation réelle OK
  - [ ] Implémenter les P3 (admin essentiel — map management, warmup/pause, match/series config via proxy socket plugin)
  - [ ] Implémenter les P4 (contrôle étendu — veto/draft, force-team/play/spec, team policy & roster)
  - [ ] Implémenter les P5 (low priority — whitelist, vote policy, auth grant/revoke, player history)
  - [ ] Ajouter système de CORS pour qu'uniquement certaines URL puisse utiliser l'API et pas d'autres.

## État actuel (2026-03-01)

### Ce qui fonctionne
- API NestJS (P0 + P1 + P2 + P2.5 + P2.6) : 25 endpoints au total
- Ingestion unifiée `POST /v1/plugin/events` (connectivity, lifecycle, combat, player, mode, batch)
- Read API P2 : players, combat stats, scores, lifecycle, map-rotation, aggregate-stats, capabilities, maps, mode
- Read API P2.5 : stats combat par map + par série
- Read API P2.6 : historique combat joueur par map, stats dérivées (kd_ratio, win_rate, rocket/laser accuracy), Elite attack/defense win rates
- Plugin PHP mis à jour : tracking `hits_rocket`/`hits_laser` par arme + `EliteRoundTrackingTrait` pour attack/defense
- 240 tests unitaires + 6 smoke test scripts (P0: 43, P1: 35, P2: 94, P2.5: 59, P2.6: 29, P2.6-Elite: 21 assertions)
- Docker: auto-migration Prisma, plugin baked into SM image, bind-mount volumes dans `data/`

### Points d'attention pour reprendre
- **Branche `feat/p2-read-api`** contient P2 + P2.5 + P2.6 + Elite win rates (pas encore mergé dans `main`)
- **Tests uniquement avec fixtures curl** — validation avec le serveur SM réel requise (voir checklist ci-dessus)
- **Plugin PHP modifié** — le serveur SM doit être rebuild (`docker compose up -d --build`) pour prendre en compte `hits_rocket`/`hits_laser` et le tracking Elite
- **Les fichiers `.env.{mode}`** sont corrigés avec la bonne URL API
- **ManiaControl persiste les settings dans MySQL** — wiper MySQL si changement d'env var
- **Prochaine étape** : Validation réelle P2/P2.5/P2.6 avec le serveur SM, merger dans `main`, puis P3 (admin essentiel)