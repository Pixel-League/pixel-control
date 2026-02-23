# Checklist QA - Systeme Veto/Draft Pixel Control (par role)

Objectif: tester la feature veto/draft en separant clairement le plan de tests entre (1) le cote admin/server et (2) le cote joueur general.

## 0) Preparation commune

- [ ] Stack locale up et plugin charge (`bash pixel-sm-server/scripts/dev-plugin-sync.sh`).
- [ ] Feature veto active (`PIXEL_CONTROL_VETO_DRAFT_ENABLED=1`).
- [ ] Socket communication actif (`PIXEL_SM_COMMUNICATION_SOCKET_ENABLED=1`, port par defaut `31501`).
- [ ] Pool map valide (>=2 maps pour matchmaking, >= best_of maps pour tournament).
- [ ] Capture payload active (`bash pixel-sm-server/scripts/manual-wave5-ack-stub.sh --output "pixel-sm-server/logs/manual/veto-test-payload.ndjson"`).
- [ ] Plugin pointe vers le stub ACK (`PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash pixel-sm-server/scripts/dev-plugin-sync.sh`).

## 1) Matrice des actions par role

| Action | Admin / Server | Joueur general |
| --- | --- | --- |
| Configurer le mode par defaut (`mode`) | Oui | Non |
| Configurer la duree matchmaking (`duration`) | Oui | Non |
| Configurer le seuil auto-start matchmaking (`min_players`) | Oui | Non |
| Armer le cycle matchmaking (`ready`) | Oui | Non |
| Lancer manuellement une session (`start`) | Oui | Non |
| Lancer matchmaking sans `ready` (gate attendu) | Oui | Oui |
| Demarrer un matchmaking sans admin (auto-start via `vote`) | Oui | Oui |
| Auto-start matchmaking sur seuil joueurs connectes | Oui | Oui |
| Annuler une session veto active | Oui | Non |
| Verifier que `pcveto bo` est retiree (commande inconnue) | Oui | Oui |
| Modifier le BO runtime (`match.bo.set`) | Oui | Non |
| Lire le BO runtime (`match.bo.get`) | Oui | Non |
| Lire/mettre a jour maps_score (`match.maps.get/set`) | Oui | Non |
| Lire/mettre a jour current_map_score (`match.score.get/set`) | Oui | Non |
| Lire status global (`status`) | Oui | Oui |
| Lister les maps (`maps`) | Oui | Oui |
| Voter en mode matchmaking | Oui | Oui |
| Jouer une action tournament (ban/pick) | Oui (override possible) | Oui (si capitaine et si son tour) |
| Forcer un override (`force`/`allow_override`) | Oui | Non |

## 2) Cote Admin / Server (actions exclusives)

### 2.1 Controle chat admin (`//pcveto`, `//pcadmin`)

- [ ] A-00 - `//pcveto help` (admin) n affiche que la doc du mode effectif.
  - Attendu (matchmaking effectif): aide inclut `start matchmaking` / `mode` / `duration` / `min_players` / `cancel`, sans doc tournament action-only.
  - Attendu (tournament effectif): aide inclut `start tournament` / `mode` / `cancel`, sans doc matchmaking vote-only et sans commande `bo` sous `pcveto`.
- [ ] A-01 - Configurer le mode par defaut (`//pcveto mode matchmaking`).
  - Attendu: succes, mode runtime = `matchmaking_vote`.
- [ ] A-02 - Configurer la duree matchmaking (`//pcveto duration 45`).
  - Attendu: succes, duree runtime = `45s`.
- [ ] A-02b - Configurer le seuil auto-start matchmaking (`//pcveto min_players 2`).
  - Attendu: succes, `matchmaking_autostart_min_players=2` dans `status` et `config`.
- [ ] A-02c - Armer le prochain cycle matchmaking (`//pcveto ready`).
  - Attendu: succes, `matchmaking_ready_armed=true` dans `status`.
- [ ] A-03 - Lancer un matchmaking manuellement (`//pcveto start matchmaking duration=30`) (optionnel).
  - Attendu: sans `ready` prealable -> echec `matchmaking_ready_required`; apres `ready` -> succes, session `running`, mode `matchmaking_vote`.
- [ ] A-04 - Lancer un tournament via admin (`//pcveto start tournament captain_a=<CAPTAIN_A> captain_b=<CAPTAIN_B> bo=3 starter=team_a timeout=45`).
  - Attendu: succes, session `running`, mode `tournament_draft`.
- [ ] A-05 - Annuler une session active (`//pcveto cancel`).
  - Attendu: `session_cancelled`.
- [ ] A-06 - Verifier que la commande retiree (`//pcveto bo 7`) n est plus supportee.
  - Attendu: erreur `Unknown veto command` (aucune mutation `best_of`).
- [ ] A-07 - Lire la policy serie runtime (`//pcveto config`).
	- Attendu: snapshot complet (`best_of`, `maps_score`, `current_map_score`, metadata update).
- [ ] A-08 - Mettre a jour le BO runtime (`//pcadmin match.bo.set best_of=5`).
	- Attendu: success.
- [ ] A-09 - Lire le BO runtime (`//pcadmin match.bo.get`).
	- Attendu: `best_of=5`.
- [ ] A-10 - Mettre a jour le score maps (`//pcadmin match.maps.set target_team=0 maps_score=2`).
	- Attendu: success.
- [ ] A-11 - Lire le score maps (`//pcadmin match.maps.get`).
	- Attendu: snapshot `maps_score.team_a=2` et `maps_score.team_b` coherent.
- [ ] A-12 - Mettre a jour le score rounds map courante (`//pcadmin match.score.set target_team=1 score=3`).
	- Attendu: success.
- [ ] A-13 - Lire le score rounds map courante (`//pcadmin match.score.get`).
	- Attendu: snapshot `current_map_score.team_b=3` et `current_map_score.team_a` coherent.

### 2.2 Orchestration server (communication `PixelControl.VetoDraft.*`)

- [ ] A-08 - Status API (`bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status`).
  - Attendu: payload avec `enabled`, `command`, `default_mode`, `matchmaking_autostart_min_players`, `communication`, `status`, `series_targets`.
- [ ] A-09 - Start matchmaking API (`bash pixel-sm-server/scripts/qa-veto-payload-sim.sh start mode=matchmaking_vote duration_seconds=20`).
  - Attendu: sans armement -> `success=false`, `code=matchmaking_ready_required`; apres `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh ready` -> `success=true`, `code=matchmaking_started`.
- [ ] A-09b - Ready API (`bash pixel-sm-server/scripts/qa-veto-payload-sim.sh ready`).
  - Attendu: `success=true`, `code=matchmaking_ready_armed|matchmaking_ready_already_armed`.
- [ ] A-10 - Start tournament API (`bash pixel-sm-server/scripts/qa-veto-payload-sim.sh start mode=tournament_draft captain_a=<CAPTAIN_A> captain_b=<CAPTAIN_B> best_of=3 starter=random action_timeout_seconds=45`).
  - Attendu: `success=true`, `code=tournament_started`.
- [ ] A-11 - Cancel API (`bash pixel-sm-server/scripts/qa-veto-payload-sim.sh cancel reason=qa_admin_cancel`).
  - Attendu: session active -> `success=true`, `code=session_cancelled`; sans session -> `success=false`, `code=session_not_running`.
- [ ] A-12 - Matrix server complete (`bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`).
  - Attendu: `summary.md` present + `matrix-validation.json` with `overall_passed=true` et `required_failed_checks=[]`.
  - Attendu: erreurs de communication negatives (`error=true`) uniquement sur steps attendus (`session_not_running`, `session_active`, `captain_missing`, `captain_conflict`, `actor_not_allowed`), pas sur les paths de succes.
  - Attendu: actions tournament applicables retournent `tournament_action_applied`; status final `completed`.

### 2.3 Regles admin exclusives

- [ ] A-13 - Start pendant session deja active refuse.
  - Attendu: `success=false`, `code=session_active`.
- [ ] A-14 - Tournament avec capitaine manquant refuse.
  - Attendu: `success=false`, `code=captain_missing`.
- [ ] A-15 - Tournament avec meme capitaine A/B refuse.
  - Attendu: `success=false`, `code=captain_conflict`.
- [ ] A-16 - Tournament avec `best_of` > pool maps refuse.
  - Attendu: `success=false`, `code=map_pool_too_small_for_bo`.
- [ ] A-17 - Matchmaking avec pool < 2 maps refuse.
  - Attendu: `success=false`, `code=map_pool_too_small`.

### 2.4 Override admin

- [ ] A-18 - Action tournament hors tour avec `allow_override=1` (communication) ou `force=1` (chat) par admin.
  - Attendu: action acceptee (pas `actor_not_allowed`) si droit override present.
- [ ] A-19 - Meme action override sans droit override.
  - Attendu: refuse (chat deny permission / communication echec attendu selon contexte).

## 3) Cote Joueur general (veto/draft jouable)

### 3.1 Surface accessible a tous

- [ ] P-01 - `/pcveto help` fonctionne.
  - Attendu: aide role-aware (pas de docs admin-only: `start|cancel|mode|duration|config|force`).
  - Attendu: aide mode-aware (matchmaking -> `vote` seulement, tournament -> `action` seulement).
- [ ] P-02 - `/pcveto maps` fonctionne.
  - Attendu (joueur non-admin): liste map numerotee sans suffixe UID (`#<index> <name>`).
- [ ] P-02b - `//pcveto maps` (admin) conserve les UID.
  - Attendu (admin): liste map numerotee avec UID (`#<index> <name> [<uid>]`).
- [ ] P-03 - `/pcveto status` fonctionne.
  - Attendu (joueur non-admin): sortie veto-result uniquement (`Veto result: ...` + resultat final si disponible), sans lignes operationnelles (`Map draft/veto status`, `No active session`, `Series config`).
- [ ] P-03b - `//pcveto status` (admin) conserve les details operationnels.
  - Attendu (admin): `Map draft/veto status`, details de session/votes/tours, et `Series config` visibles.
- [ ] P-04 - `/pcveto config` refuse pour joueur non-admin.
	- Attendu: deny permission (pas d exposition de config runtime).

### 3.2 Matchmaking vote (joueur)

- [ ] P-05 - Joueur vote par index (`/pcveto vote 1`) sans session active pre-lancee.
  - Attendu: session matchmaking demarree automatiquement + message chat `Matchmaking veto launched` + vote enregistre.
- [ ] P-06 - Joueur vote par UID (`/pcveto vote <MAP_UID>`).
  - Attendu: vote enregistre.
- [ ] P-07 - Joueur remplace son vote (2 votes differents).
  - Attendu: ancien compteur decremente, nouveau incremente.
- [ ] P-08 - Fenetre close -> resolution automatique.
  - Attendu: `completed` avec winner, ou fallback `no_votes_random` si aucun vote.
- [ ] P-09 - Cas egalite.
  - Attendu: `tie_break_applied=true` et `resolution_reason=top_vote_tiebreak_random`.

### 3.3 Tournament draft (joueur capitaine)

- [ ] P-10 - Admin lance tournament avec 2 joueurs non-admin comme capitaines.
  - Attendu: capitaines reconnus dans `session.captains`.
- [ ] P-11 - Capitaine du tour joue une action (`/pcveto action <map_uid|index>`).
  - Attendu: succes `tournament_action_applied`.
- [ ] P-12 - Joueur non-capitaine tente une action.
  - Attendu: echec `actor_not_allowed`.
- [ ] P-13 - Capitaine hors tour tente une action.
  - Attendu: echec `actor_not_allowed`.
- [ ] P-14 - Sequence de draft complete jusqu au lock final.
  - Attendu: action finale `lock` par `system`, `session.status=completed`, `series_map_order` taille `best_of`.

### 3.4 Actions interdites au joueur general

- [ ] P-15 - Joueur non-admin ne peut pas lancer `start`.
  - Attendu: deny permission.
- [ ] P-16 - Joueur non-admin ne peut pas `cancel`.
  - Attendu: deny permission.
- [ ] P-17 - Joueur non-admin tente la commande retiree `/pcveto bo 7`.
  - Attendu: erreur `Unknown veto command` (pas de mutation `best_of`).
- [ ] P-18 - Joueur non-admin ne peut pas utiliser `force=1` pour bypasser le tour.
  - Attendu: echec (pas d override).

## 4) Verifs transverses (admin + joueur)

### 4.1 Application map order

- [ ] X-01 - A la completion, message global present: `Draft/Veto completed and map order applied`.
- [ ] X-02 - Message ordre serie admin-only present: `Series order: uid1 -> uid2 -> ...`.
- [ ] X-02b - Message explicite des maps queuees present: `Queued maps:` + liste numerotee.
- [ ] X-02c - Session start role-aware:
  - non-admin voit `Available maps` / `Available veto maps` sans UID,
  - admin voit `Map vote IDs` / `Available veto IDs` avec UID.
- [ ] X-02d - Completion diagnostics admin-only: `Completion branch` et `Opener jump` visibles admin, non visibles joueur non-admin.
- [ ] X-03 - Queue map appliquee dans l ordre attendu.
- [ ] X-04 - Branche `opener_differs`: queue complete + `skip` applique vers opener.
- [ ] X-05 - Branche `opener_already_current`: opener non redemarre, queue limitee aux maps restantes.

### 4.5 Countdown + auto-start threshold

- [ ] X-20 - Countdown matchmaking verifie: annonces strictes `N, N-10, ..., 10, 5/4/3/2/1` (N = duree configuree), sans doublons par seconde/session.
- [ ] X-21 - Auto-start threshold pre-start verifie: passage au seuil avec `ready` arme -> annonce unique `[PixelControl] Matchmaking veto starts in 15s.` puis lancement automatique apres ~15s si conditions stables.
- [ ] X-21b - Annulation pre-start verifiee: pendant la fenetre 15s, si `ready` retombe ou si joueurs < `min_players`, lancement differe annule et aucune session ne demarre apres deadline obsolete.
- [ ] X-21c - Anti-loop auto-start verifie: passage sous seuil -> `armed`, passage au seuil -> `triggered` (apres pre-start), apres lancement -> `suppressed` tant que le count reste >= seuil.
- [ ] X-22 - Persistance seuil auto-start: valeur `min_players` survive hot-restart plugin et restart conteneur (hors override env).
- [ ] X-22b - Ready gate matchmaking verifie: sans `ready`, aucun nouveau cycle matchmaking ne peut demarrer (`matchmaking_ready_required`) meme apres completion + transitions map.
- [ ] X-22c - Rearm explicite verifie: apres completion d un cycle, `ready` doit etre rejoue pour autoriser un nouveau cycle.

### 4.6 Matchmaking lifecycle post-veto (mode matchmaking uniquement)

- [ ] X-23 - `PixelControl.VetoDraft.Status.matchmaking_lifecycle` expose le snapshot attendu apres completion matchmaking.
  - Attendu: `status`, `stage`, `ready_for_next_players`, `actions`, `history` presents et coherents.
- [ ] X-24 - Sequence des stages verifiee dans l ordre:
  - `veto_completed -> selected_map_loaded -> match_started -> selected_map_finished -> players_removed -> map_changed -> match_ended -> ready_for_next_players`.
- [ ] X-25 - Fin de cycle matchmaking verifiee:
  - Attendu: `status=completed`, `stage=ready_for_next_players`, `ready_for_next_players=true`.
- [ ] X-26 - Telemetry map lifecycle verifiee:
  - Attendu: `payload.map_rotation.matchmaking_lifecycle` present sur `map.begin|map.end` avec progression additive (pas de rupture schema).
- [ ] X-27 - Guard tournament verifie:
  - Attendu: aucune execution automation matchmaking (`kick_all_players` / `map_change` / `match_end_mark`) en session `tournament_draft`.
- [ ] X-28 - Marqueurs runtime verifie:
  - Attendu: logs `[PixelControl][veto][matchmaking_lifecycle][stage]`, `[action]`, `[ready]` sans erreur fatale.

### 4.2 Telemetry payload

- [ ] X-06 - `payload.map_rotation.veto_draft_mode` et `veto_draft_session_status` coherents avec la session.
- [ ] X-07 - `payload.map_rotation.veto_draft_actions.action_count` augmente apres chaque vote/ban/pick/lock.
- [ ] X-08 - `payload.map_rotation.veto_result.status` suit `running -> completed|cancelled`.
- [ ] X-09 - Matchmaking: `veto_result.final_map.uid` correspond au winner.
- [ ] X-10 - Tournament: `veto_result.series_map_order` et `decider_map` coherents.
- [ ] X-11 - `series_targets` present dans `PixelControl.VetoDraft.Status` et dans `map_rotation`.

### 4.3 Non-regression auto

- [ ] X-12 - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix` passe.
  - Attendu: verifier `matrix-validation.json` (`overall_passed=true`, `required_failed_checks=[]`).
- [ ] X-13 - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix` passe.
- [ ] X-14 - `bash pixel-sm-server/scripts/test-automated-suite.sh` passe avec `required_failures=0`.

### 4.4 Persistance BO/maps/score a travers restart

- [ ] X-15 - Definir une valeur sentinelle via admin payload/chat:
  - `match.bo.set best_of=9`
  - `match.maps.set target_team=0 maps_score=8`
  - `match.maps.set target_team=1 maps_score=9`
  - `match.score.set target_team=0 score=10`
  - `match.score.set target_team=1 score=11`
- [ ] X-16 - Verifier avant restart (`match.bo.get`, `match.maps.get`, `match.score.get`).
  - Attendu: valeurs sentinelles visibles dans `series_targets`.
- [ ] X-17 - Redemarrer plugin (hot-restart) puis re-verifier les memes getters.
  - Attendu: valeurs conservees, `update_source=setting` et `updated_by=plugin_bootstrap`.
- [ ] X-18 - Redemarrer conteneur `shootmania` puis re-verifier les memes getters.
  - Attendu: valeurs conservees a nouveau.
- [ ] X-19 - Verifier que les overrides env BO/mode/duration sont vides ou alignes pendant le test de persistance.
  - Attendu: sinon, la precedence env masque les valeurs persistees en base setting.

## 5) Evidence a archiver

- [ ] E-01 - Dossier matrix veto: `pixel-sm-server/logs/qa/veto-payload-sim-<timestamp>/`.
- [ ] E-02 - Dossier suite auto: `pixel-sm-server/logs/qa/automated-suite-<timestamp>/`.
- [ ] E-03 - Capture payload manuelle: `pixel-sm-server/logs/manual/veto-test-payload.ndjson`.
- [ ] E-04 - Logs plugin: `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`.

## 6) Signature recette

- [ ] Date de recette renseignee.
- [ ] Environnement renseigne (compose files, bridge/host, mode teste).
- [ ] Responsable QA renseigne.
- [ ] Decision finale `GO` / `NO-GO` renseignee.
