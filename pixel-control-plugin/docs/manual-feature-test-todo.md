# Pixel Control Plugin - Manual Feature Test Todo

Ce fichier sert de checklist manuelle pour valider les features du plugin Pixel Control.
Chaque item contient le titre de la feature + une User Story (one-liner) + comment faire le test.

## 0.bis) Reperes de verification (copier/coller)

- NDJSON principal: `pixel-sm-server/logs/manual/manual-feature-test-payload.ndjson`
- Logs plugin: `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
- Artefacts simulateur admin: `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/`
- Commande rapide NDJSON: `rg -n '<pattern>' pixel-sm-server/logs/manual/manual-feature-test-payload.ndjson`
- Commande rapide logs: `rg -n '<pattern>' pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`

## 0.ter) Frontiere auto vs manuel

- Le suite runner automatise (`bash pixel-sm-server/scripts/test-automated-suite.sh`) couvre uniquement les verifications deterministes et simulables.
- Les callbacks combat reel restent explicitement hors pass/fail automatique et doivent etre valides en session manuelle:
  - `OnShoot`
  - `OnHit`
  - `OnNearMiss`
  - `OnArmorEmpty`
  - `OnCapture`

## 0) Preparation (a faire une seule fois)

- [x] Stack dev et plugin charges
  - User Story: En tant qu'operateur QA, je veux demarrer la stack complete pour pouvoir tester le plugin dans un environnement reel.
  - Commande: `bash pixel-sm-server/scripts/dev-plugin-sync.sh`
  - Validation: le serveur ShootMania + ManiaControl sont up et le plugin Pixel Control est charge.

- [x] Capture des payloads active
  - User Story: En tant qu'operateur QA, je veux capturer les payloads emis pour verifier objectivement ce que le plugin envoie.
  - Commande 1: `bash pixel-sm-server/scripts/manual-wave5-ack-stub.sh --output "pixel-sm-server/logs/manual/manual-feature-test-payload.ndjson"`
  - Commande 2: `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash pixel-sm-server/scripts/dev-plugin-sync.sh`
  - Validation: le NDJSON contient des lignes avec `"event_name":"pixel_control.` et `"schema_version":"2026-02-20.1"` apres une action en jeu.

- [x] Logs live ouverts
  - User Story: En tant qu'operateur QA, je veux voir les logs en temps reel pour confirmer rapidement les callbacks et actions admin.
  - Commande: `tail -f pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log | rg --line-buffered "\\[Pixel Plugin\\]|\\[PixelControl\\]"`
  - Validation: vous voyez les marqueurs `[Pixel Plugin]` et `[PixelControl]` en temps reel.

- [x] Outil de simulation admin pret
  - User Story: En tant qu'operateur QA, je veux lister les actions admin exposees pour savoir exactement ce qui est testable via payload serveur.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh list-actions`
  - Validation: reponse JSON avec `enabled`, `communication.exec="PixelControl.Admin.ExecuteAction"`, `communication.list="PixelControl.Admin.ListActions"` et `actions` non vide.

## 1) Connectivity + envelope identity

- [x] Plugin registration (`pixel_control.connectivity.plugin_registration`)
  - User Story: En tant que backend Pixel Control, je veux recevoir un event d'enregistrement au demarrage pour savoir que le plugin est disponible.
  - Action: redemarrer/sync le plugin.
  - Verification NDJSON: une ligne contient `"event_name":"pixel_control.connectivity.plugin_registration"`.
  - Verification champs: la meme ligne contient `event_id` prefixe `pc-evt-connectivity-plugin_registration-`, `idempotency_key` prefixe `pc-idem-`, et `source_sequence` numerique.

- [x] Heartbeat (`pixel_control.connectivity.plugin_heartbeat`)
  - User Story: En tant que backend Pixel Control, je veux recevoir des heartbeats periodiques pour surveiller l'etat de sante du plugin.
  - Action: attendre au moins 1 cycle heartbeat (intervalle configure, par defaut 120s).
  - Verification NDJSON: au moins 2 lignes avec `"event_name":"pixel_control.connectivity.plugin_heartbeat"`.
  - Verification ordre: les `source_sequence` des heartbeats augmentent entre les lignes.

- [x] Identite deterministe de l'envelope
  - User Story: En tant que systeme d'ingestion, je veux des identifiants deterministes pour dedupliquer et tracer chaque event de facon fiable.
  - Action: ouvrir quelques payloads recents.
  - Verification envelope: chaque ligne testee contient `event_name`, `event_id`, `idempotency_key`, `source_sequence`, `schema_version`.
  - Verification format: `event_id` commence par `pc-evt-` et `idempotency_key` commence par `pc-idem-`.
  - Verification logs: aucune ligne `[PixelControl][queue][drop_identity_invalid]` pendant le test.

## 2) Lifecycle

Note de lecture importante:

- `payload.variant` est une vue normalisee, donc plusieurs `source_callback` peuvent legitiment produire la meme valeur (ex: plusieurs callbacks pour `match.begin`).
- Pour une validation simple et non ambigue, prenez en reference les events `source_channel=maniaplanet` (`pixel_control.lifecycle.maniaplanet_beginmatch` et `pixel_control.lifecycle.maniaplanet_endmatch`).

- [x] Warmup start/end/status
  - User Story: En tant qu'operateur match, je veux suivre l'etat du warmup pour synchroniser l'orchestration de debut de partie.
  - Action: lancer `warmup.extend` puis `warmup.end` (via chat admin ou simulateur payload).
  - Verification NDJSON: presence de `"event_name":"pixel_control.lifecycle.maniaplanet_warmup_start"` et/ou `...warmup_end` (`...warmup_status` peut aussi apparaitre).
  - Verification champs: `payload.variant` vaut `warmup.start` puis `warmup.end` (ou `warmup.status` selon callback), et `payload.admin_action.action_type="warmup"`.

- [x] Pause start/end/status
  - User Story: En tant qu'operateur match, je veux piloter et observer la pause pour gerer les interruptions sans perdre le contexte.
  - Action: lancer `pause.start`, puis `pause.end`.
  - Verification NDJSON: presence de `"event_name":"pixel_control.lifecycle.maniaplanet_pause_status"` apres `pause.start` puis `pause.end`.
  - Verification champs: `payload.variant="pause.start"` juste apres start puis `payload.variant="pause.end"` apres end; `payload.admin_action.target="pause"`.

- [x] Match begin/end
  - User Story: En tant que backend analytics, je veux connaitre les bornes d'un match pour calculer les agregats sur la bonne fenetre.
  - Note: `match.begin` et `match.end` sont des events lifecycle observes (telemetrie), pas des commandes admin executables via `qa-admin-payload-sim.sh execute`.
  - Action: demarrer une partie puis la terminer naturellement ou via rotation map.
  - Verification NDJSON: au minimum une ligne `"event_name":"pixel_control.lifecycle.maniaplanet_beginmatch"` et une ligne `"event_name":"pixel_control.lifecycle.maniaplanet_endmatch"`.
  - Verification champs: `payload.variant="match.begin"` pour beginmatch et `payload.variant="match.end"` pour endmatch; `payload.source_channel="maniaplanet"`.

- [x] Map begin/end
  - User Story: En tant qu'operateur serveur, je veux tracer les transitions de map pour verifier la rotation et l'orchestration admin.
  - Action: executer un `map.skip`.
  - Verification NDJSON: une ligne `"event_name":"pixel_control.lifecycle.maniaplanet_endmap"` suivie d'une ligne `"event_name":"pixel_control.lifecycle.maniaplanet_beginmap"`.
  - Verification champs: `payload.variant="map.end"` puis `payload.variant="map.begin"` et le `payload.map_rotation.current_map.uid` change apres le skip.

- [x] Round begin/end
  - User Story: En tant qu'engine telemetry, je veux delimiter les rounds pour produire des stats fines par manche.
  - Action: en Elite, jouer un turn complet (debut + fin de turn) puis verifier les payloads lifecycle.
  - Verification NDJSON: au moins une ligne avec `payload.variant="round.begin"` et une ligne avec `payload.variant="round.end"`.
  - Verification callbacks: variantes acceptees via `pixel_control.lifecycle.maniaplanet_beginround`/`...endround` ou via projection Elite `...onelitestartturnstructure`/`...oneliteendturnstructure`.
  - Note: en mode Elite, le plugin projette les turns en bornes `round.*` pour garder une telemetrie lifecycle homogène entre modes.

## 3) Player state

- [x] Player connect
  - User Story: En tant qu'operateur roster, je veux detecter l'arrivee d'un joueur pour maintenir un etat de roster fiable.
  - Action: connecter un joueur au serveur.
  - Verification NDJSON: ligne `"event_name":"pixel_control.player.playermanagercallback_playerconnect"`.
  - Verification champs: `payload.event_kind="player.connect"`, `payload.transition_kind="connectivity"`, `payload.player.login="<login_teste>"`, `payload.player.connectivity_state="connected"`.

- [x] Player disconnect
  - User Story: En tant qu'operateur roster, je veux detecter la sortie d'un joueur pour fermer proprement son contexte de session.
  - Action: deconnecter le meme joueur.
  - Verification NDJSON: ligne `"event_name":"pixel_control.player.playermanagercallback_playerdisconnect"`.
  - Verification champs: `payload.event_kind="player.disconnect"`, `payload.state_delta.connectivity.after="disconnected"`, `payload.reconnect_continuity.transition_state="disconnect"`.

- [x] Player info changed
  - User Story: En tant que systeme de suivi joueur, je veux capter les changements d'etat individuels pour maintenir un profil courant exact.
  - Action: modifier un etat joueur (team/spec/referee si possible).
  - Verification NDJSON: ligne `"event_name":"pixel_control.player.playermanagercallback_playerinfochanged"`.
  - Verification champs: `payload.event_kind="player.info_changed"`, `payload.transition_kind="state_change"`, et au moins un `payload.state_delta.<champ>.changed=true` (ex: `team_id`, `spectator`, `auth_level`).
  - Note: juste apres, un event `playerinfoschanged` peut arriver; il ne remplace pas le delta individuel.

- [x] Player infos changed (batch)
  - User Story: En tant que systeme de synchronisation, je veux un signal de refresh batch pour recalculer rapidement l'etat global des joueurs.
  - Action: provoquer un refresh global (ex: transition map/round, plusieurs joueurs).
  - Verification NDJSON: ligne `"event_name":"pixel_control.player.playermanagercallback_playerinfoschanged"`.
  - Verification champs: `payload.event_kind="player.infos_changed"`, `payload.transition_kind="batch_refresh"`, `payload.batch_scope="server_roster_refresh"`.
  - Note: cet event est un refresh global; `payload.player` peut etre `null` et les `payload.state_delta.<champ>.changed` peuvent tous rester a `false` (comportement attendu).

- [x] Reconnect continuity
  - User Story: En tant qu'analyste gameplay, je veux relier les reconnexions a une meme session logique pour eviter les ruptures de tracking.
  - Action: deconnecter puis reconnecter le meme login.
  - Verification champs: sur l'event de reconnexion (`player.connect`), `payload.reconnect_continuity.transition_state="reconnect"`.
  - Verification identite session: `payload.reconnect_continuity.session_id` commence par `pc-session-`, `session_ordinal` augmente, `reconnect_count >= 1`.

- [x] Side/team transition
  - User Story: En tant qu'operateur competition, je veux tracer les changements d'equipe/camp pour auditer l'equite et les actions admin.
  - Action: changer un joueur de team (ex: `player.force_team`).
  - Verification champs: `payload.side_change.detected=true`, avec `payload.side_change.team_changed=true` ou `payload.side_change.side_changed=true`.
  - Verification coherence: `payload.side_change.previous_team_id` et `current_team_id` reflettent le changement; `transition_kind` vaut `team_change`, `side_change` ou `assignment_change`.

- [x] Admin correlation sur events player
  - User Story: En tant qu'auditeur operationnel, je veux corriger les causalites entre action admin et effet joueur pour expliquer les changements observes.
  - Action: lancer une action admin player (force spec/team), puis observer l'event player suivant.
  - Precondition: l'action admin doit repondre `success=true` (si `native_rejected`/`native_exception`, la correlation positive n'est pas attendue).
  - Verification champs: `payload.admin_correlation.correlated=true` et `payload.admin_correlation.admin_event.action_name` correspond a l'action lancee (ex: `player.force_team`).
  - Verification timing: `payload.admin_correlation.seconds_since_admin_action` est renseigne (pas `null`) et faible (quelques secondes).

- [x] Constraint signals
  - User Story: En tant qu'orchestrateur de match, je veux connaitre les contraintes de policy team/slot pour comprendre pourquoi un joueur peut ou non rejoindre.
  - Action: produire un event `playerinfochanged` (ex: changement team via jeu/admin), puis inspecter cet event (pas le `playerinfoschanged` batch).
  - Verification champs: `payload.constraint_signals.policy_context`, `forced_team_policy`, `slot_policy` sont presents.
  - Verification policy: `payload.constraint_signals.forced_team_policy.policy_state` et `payload.constraint_signals.slot_policy.policy_state` sont renseignes.
  - Valeurs attendues (exemples):
    - `forced_team_policy.policy_state`: `enforced_assignment_changed`, `enforced_assignment_stable`, `enforced_missing_assignment`, `disabled`, ou `unavailable`.
    - `slot_policy.policy_state`: `slot_assigned`, `slot_retained_while_spectating`, `slot_restricted`, `slot_state_unknown`, ou `unavailable`.
  - Fallback acceptable: si le contexte policy n'est pas disponible, verifier `policy_context.available=false` avec `policy_context.unavailable_reason` et metadata `field_availability`/`missing_fields` coherentes.
  - Commande utile: `rg -n 'playermanagercallback_playerinfochanged|constraint_signals|forced_team_policy|slot_policy|policy_state|unavailable_reason' pixel-sm-server/logs/manual/manual-feature-test-payload.ndjson`

## 4) Combat + scores

- [x] Player shoot
  - User Story: En tant que pipeline stats, je veux capter chaque tir pour calculer precision et volume de jeu.
  - Action: en jeu, tirer au moins 1 fois.
  - Verification logs: `ManiaControl.log` contient `[Pixel Plugin]` + `shooted`.
  - Verification NDJSON: ligne `"event_name":"pixel_control.combat.maniacontrol_callbacks_structures_shootmania_onshootstructure"` avec `payload.event_kind="shootmania_event_onshoot"`.

- [x] Player hit
  - User Story: En tant que pipeline stats, je veux capter chaque hit pour mesurer l'efficacite des joueurs.
  - Action: toucher un joueur adverse.
  - Verification logs: `ManiaControl.log` contient `[Pixel Plugin]` + `hit someone`.
  - Verification NDJSON: ligne `"event_name":"pixel_control.combat.maniacontrol_callbacks_structures_shootmania_onhitstructure"` avec `payload.dimensions.damage` non nul.

- [x] Player near miss
  - User Story: En tant que pipeline stats, je veux capter les near miss pour qualifier la pression offensive et la precision fine.
  - Action: tirer proche d'un joueur sans toucher.
  - Verification logs: `ManiaControl.log` contient `[Pixel Plugin]` + `near missed`.
  - Verification NDJSON: ligne `"event_name":"pixel_control.combat.maniacontrol_callbacks_structures_shootmania_onnearmissstructure"` avec `payload.event_kind="shootmania_event_onnearmiss"` et `payload.dimensions.distance` non nul.

- [x] Player dead / armor empty
  - User Story: En tant que pipeline stats, je veux detecter les eliminations pour alimenter les compteurs kills/deaths.
  - Action: tuer un joueur.
  - Verification logs: `ManiaControl.log` contient `[Pixel Plugin]` + `armor got emptied`.
  - Verification NDJSON: ligne `"event_name":"pixel_control.combat.maniacontrol_callbacks_structures_shootmania_onarmoremptystructure"` avec `payload.event_kind="shootmania_event_onarmorempty"`, `payload.dimensions.shooter.login` et `payload.dimensions.victim.login` renseignes.

- [x] Capture
  - User Story: En tant qu'analyste mode objectif, je veux capter les captures pour mesurer la progression objective des equipes.
  - Action: realiser une capture (mode compatible capture).
  - Verification logs: `ManiaControl.log` contient `[Pixel Plugin]` + `captured` (ou message capture equivalent).
  - Verification NDJSON: ligne `"event_name":"pixel_control.combat.maniacontrol_callbacks_structures_shootmania_oncapturestructure"` avec `payload.event_kind="shootmania_event_oncapture"` et `payload.capture_players` tableau non vide.

- [x] Scores snapshot
  - User Story: En tant que backend de resultats, je veux les snapshots de score pour determiner vainqueur, egalite et contexte de fin.
  - Action: terminer un round/map pour forcer un update score.
  - Verification NDJSON: ligne `"event_name":"pixel_control.combat.maniacontrol_callbacks_structures_shootmania_onscoresstructure"` avec `payload.event_kind="shootmania_event_scores"`.
  - Verification champs: `payload.scores_snapshot` present + `payload.scores_result.result_state`, `winning_side`, `winning_reason` renseignes.

- [x] Counters combat par joueur
  - User Story: En tant qu'analyste performance, je veux des compteurs combat par joueur pour suivre la production individuelle sur la fenetre active.
  - Action: enchainez tirs/hits/miss/kills sur une courte session.
  - Verification champs: dans les events combat, `payload.player_counters` contient au moins un login avec les cles `shots`, `hits`, `misses`, `kills`, `deaths`, `accuracy`, `rockets`, `lasers`.
  - Verification evolution: apres plusieurs actions, au moins un de ces compteurs augmente pour le login teste.

## 5) Lifecycle aggregate telemetry

- [x] Aggregate stats round.end
  - User Story: En tant que service analytics, je veux les agregats de fin de round pour produire des deltas fiables par manche.
  - Action: jouer puis finir un round.
  - Verification NDJSON: event lifecycle avec `payload.variant="round.end"`.
  - Verification champs: `payload.aggregate_stats.scope="round"`, `payload.aggregate_stats.player_counters_delta` present, `payload.aggregate_stats.team_counters_delta` et `payload.aggregate_stats.team_summary` presents.

- [x] Aggregate stats map.end
  - User Story: En tant que service analytics, je veux les agregats de fin de map pour consolider les performances sur la map complete.
  - Action: changer de map apres gameplay.
  - Verification NDJSON: event lifecycle avec `payload.variant="map.end"`.
  - Verification champs: `payload.aggregate_stats.scope="map"` et bornes temporelles (`started_at`, `ended_at`) renseignees.

- [x] Win context
  - User Story: En tant que consommateur de resultats, je veux un contexte de victoire normalise pour eviter les interpretations ambigues.
  - Action: forcer un resultat clair (victoire/equite si possible).
  - Verification champs: sur un event `round.end`/`map.end`, `payload.aggregate_stats.win_context.result_state`, `winning_side`, `winning_reason` sont coherents avec le score reel.
  - Verification coherence: en cas d'egalite, `result_state` passe en `tie`/`draw` (pas `win`).

## 6) Map rotation + veto/draft

- [x] Map rotation baseline
  - User Story: En tant qu'operateur map pool, je veux une vue structuree de la rotation pour verifier l'ordre et les transitions.
  - Action: executer un `map.skip`.
  - Verification NDJSON: sur `payload.variant="map.begin"` (ou `map.end`), `payload.map_rotation` est present.
  - Verification champs: `current_map.uid`, `next_maps`, `map_pool_size`, `played_map_order` et `played_map_count` sont renseignes.

- [x] Veto/draft actions (si mode supporte)
  - User Story: En tant qu'operateur competition, je veux suivre chaque action veto/draft pour auditer la phase de selection.
  - Action: realiser des actions veto/pick/pass/lock dans un mode qui les expose.
  - Verification champs: `payload.map_rotation.veto_draft_actions.status` existe (`available`/`partial`/`unavailable`) et `action_count` augmente apres actions veto/draft.
  - Verification details: `payload.map_rotation.veto_draft_actions.last_action_kind` et les details map/acteur sont renseignes si exposes par le mode.
  - Statut campagne courante: non bloqueur valide en N/A (phase veto/draft non exposee dans les scenarios executes).

- [x] Veto result (si mode supporte)
  - User Story: En tant que backend de match setup, je veux un resultat final de veto/draft pour connaitre la map retenue.
  - Action: terminer une sequence veto/draft.
  - Verification champs: `payload.map_rotation.veto_result.status` present (`partial`/`unavailable`/`selected`).
  - Verification selection: quand disponible, `payload.map_rotation.veto_result.final_map.uid` correspond a la map finale en jeu.
  - Statut campagne courante: non bloqueur valide en N/A (resultat veto non expose dans les scenarios executes).

## 7) Mode-specific callbacks

- [x] Elite start/end turn (mode Elite)
  - User Story: En tant qu'analyste Elite, je veux les callbacks de tour pour reconstruire la chronologie du round.
  - Action: lancer une manche Elite jusqu'au changement de tour.
  - Verification NDJSON: lignes `"event_name":"pixel_control.mode.maniacontrol_callbacks_structures_shootmania_onelitestartturnstructure"` et `"event_name":"pixel_control.mode.maniacontrol_callbacks_structures_shootmania_oneliteendturnstructure"`.
  - Verification lifecycle liee: en parallele, presence de `payload.variant="round.begin"` et `payload.variant="round.end"` cote lifecycle.

- [x] Joust callbacks (mode Joust)
  - User Story: En tant qu'analyste Joust, je veux les callbacks specifiques au mode pour produire des indicateurs mode-aware.
  - Action: lancer un scenario Joust (reload, selection joueurs, round result).
  - Verification NDJSON: presence des events `pixel_control.mode.maniacontrol_callbacks_structures_shootmania_onjoustreloadstructure`, `pixel_control.mode.maniacontrol_callbacks_structures_shootmania_onjoustselectedplayersstructure` et `pixel_control.mode.maniacontrol_callbacks_structures_shootmania_onjoustroundresultsstructure`.
  - Verification champs: `source_callback` est coherent avec chaque event (`OnJoustReloadStructure`, `OnJoustSelectedPlayersStructure`, `OnJoustRoundResultsStructure`).

- [x] Royal callbacks (mode Royal)
  - User Story: En tant qu'analyste Royal, je veux les callbacks specifiques au mode pour suivre points, spawns et gagnant de manche.
  - Action: lancer un scenario Royal (points/spawn/round winner).
  - Verification NDJSON: au moins un event parmi `pixel_control.mode.shootmania_royal_points`, `...playerspawn`, `...roundwinner`.
  - Verification champs: `event_name` correspond exactement au callback Royal attendu pour l'action jouee.
  - Statut campagne courante: hors scope explicite de cette passe de recette (validation reportee).

## 8) Admin actions depuis le chat jeu (actor-bound)

- [x] Verification droits chat admin
  - User Story: En tant qu'operateur securite, je veux que seules les personnes autorisees puissent executer des commandes admin via le chat.
  - Action: essayer `//pcadmin map.skip` avec un joueur sans droit puis avec un admin ManiaControl.
  - Verification non-admin: message de refus en chat (permission deny) et absence de skip map.
  - Verification admin: message succes en chat + ligne log `[PixelControl][admin][action_success] action=map.skip`.

- [x] Help command
  - User Story: En tant qu'admin serveur, je veux une aide en chat pour connaitre les commandes disponibles sans quitter le jeu.
  - Action: `//pcadmin help`
  - Verification: le chat affiche `Pixel delegated admin actions (<n>).` puis une ligne par action (`- <action>`), avec affichage des sections `required`/`optional` uniquement si des arguments existent; verifier la presence d'au moins `map.skip`, `pause.start`, `auth.grant`.

## 9) Admin actions via payload serveur (simulateur)

Notes:

- Les commandes ci-dessous passent par `PixelControl.Admin.ExecuteAction`.
- Le script ecrit un dossier artefacts `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/`.
- Les noms d'events lifecycle/combat/player (ex: `match.begin`, `round.end`, `combat.*`) ne sont pas des actions admin executables; ils doivent etre verifies dans les payloads/logs.

- [x] List actions
  - User Story: En tant que futur Pixel Control Server, je veux decouvrir dynamiquement les actions supportees par le plugin.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh list-actions`
  - Verification JSON: `enabled` present, `communication.exec`/`communication.list` corrects, `actions` non vide.
  - Verification action cle: `actions.map.skip`, `actions.pause.start`, `actions.auth.grant`, `actions.map.remove` existent.

- [x] map.skip
  - User Story: En tant qu'orchestrateur serveur, je veux skip la map courante a distance pour gerer les incidents ou transitions rapides.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.skip`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification runtime: map change visible + log `[PixelControl][admin][action_success] action=map.skip`.

- [x] map.restart
  - User Story: En tant qu'orchestrateur serveur, je veux redemarrer la map courante pour relancer proprement la manche.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.restart`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification runtime: la meme map repart au debut + log `action=map.restart` en succes.

- [x] map.jump
  - User Story: En tant qu'orchestrateur serveur, je veux sauter vers une map precise pour appliquer un planning de match controle.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.jump map_uid=<valid_map_uid>`
  - Verification JSON: cas nominal `success=true` et `code="ok"`; en map-pool mono-map, `success=false` et `code="native_exception"` avec message `the next map must be different from the current one.` acceptable.
  - Verification runtime: `current_map.uid` devient `<valid_map_uid>` dans un event lifecycle `map.begin`.

- [x] map.queue
  - User Story: En tant qu'orchestrateur serveur, je veux ajouter une map a la queue pour preparer la rotation suivante.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.queue map_uid=<valid_map_uid>`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification runtime: dans `payload.map_rotation.next_maps`, on retrouve la map cible dans les transitions suivantes.

- [x] map.add (ManiaExchange)
  - User Story: En tant qu'orchestrateur serveur, je veux importer une map depuis ManiaExchange pour enrichir dynamiquement le map pool.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.add mx_id=<valid_mx_id>`
  - Verification JSON: `success=true` et `code="ok"` (submission async acceptee).
  - Verification runtime: la map apparait ensuite dans `payload.map_rotation.map_pool` avec `external_ids.mx_id=<valid_mx_id>`.

- [x] warmup.extend
  - User Story: En tant qu'orchestrateur match, je veux prolonger le warmup a distance pour absorber un retard operationnel.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute warmup.extend seconds=30`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification telemetry: event lifecycle warmup avec `payload.admin_action.action_name="warmup.extend"` (ou `warmup.start` selon callback expose).

- [x] warmup.end
  - User Story: En tant qu'orchestrateur match, je veux stopper le warmup a distance pour passer immediatement en phase de jeu.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute warmup.end`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification telemetry: event lifecycle warmup avec `payload.variant="warmup.end"`.

- [x] pause.start
  - User Story: En tant qu'orchestrateur match, je veux declencher une pause a distance en cas d'incident technique.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute pause.start`
  - Verification JSON: `success=true` et `code="ok"` (ou `capability_unavailable` si le mode ne supporte pas la pause).
  - Verification telemetry: `payload.variant="pause.start"` sur l'event lifecycle pause.

- [x] pause.end
  - User Story: En tant qu'orchestrateur match, je veux reprendre la partie a distance une fois l'incident resolu.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute pause.end`
  - Verification JSON: `success=true` et `code="ok"` (ou `capability_unavailable` si pause non supportee).
  - Verification telemetry: `payload.variant="pause.end"` sur l'event lifecycle pause.

- [x] vote.cancel
  - User Story: En tant qu'operateur serveur, je veux annuler un vote en cours pour garder le controle du flux de match.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute vote.cancel`
  - Verification JSON: si vote actif -> `success=true` + `code="ok"`; sinon `success=false` + `code="native_rejected"` (message `no vote is currently running`) acceptable.
  - Verification logs: presence de `action=vote.cancel` avec statut succes ou echec controle.

- [x] vote.set_ratio
  - User Story: En tant qu'operateur serveur, je veux ajuster le ratio de vote pour calibrer la gouvernance en jeu.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute vote.set_ratio command=nextmap ratio=0.60`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification logs: ligne `action=vote.set_ratio` en succes, avec `parameters` contenant `command=nextmap` et `ratio=0.60`.

- [x] vote.custom_start
  - User Story: En tant qu'operateur serveur, je veux declencher un vote custom pour appliquer un workflow communautaire specifique.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute vote.custom_start vote_index=0`
  - Verification JSON: si plugin custom-vote actif -> `success=true` + `code="ok"`; sinon `success=false` + `code="capability_unavailable"` attendu.
  - Verification logs: ligne `action=vote.custom_start` avec code coherent.

- [x] player.force_team
  - User Story: En tant qu'orchestrateur competition, je veux forcer l'equipe d'un joueur pour corriger un placement.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute player.force_team target_login=<login> team=blue`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification player telemetry: event player suivant avec `payload.player.login="<login>"` et `payload.side_change.team_changed=true`.

- [x] player.force_play
  - User Story: En tant qu'orchestrateur competition, je veux forcer un joueur en jeu pour respecter le roster actif.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute player.force_play target_login=<login>`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification player telemetry: event player suivant avec `payload.player.login="<login>"` et `payload.player.is_spectator=false`.

- [x] player.force_spec
  - User Story: En tant qu'orchestrateur competition, je veux forcer un joueur en spectateur pour appliquer une decision arbitrale.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute player.force_spec target_login=<login>`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification player telemetry: event player suivant avec `payload.player.login="<login>"` et `payload.player.is_spectator=true`.

- [x] auth.grant
  - User Story: En tant qu'operateur serveur, je veux accorder un niveau d'auth a un joueur pour deleguer des responsabilites admin.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute auth.grant target_login=<login> auth_level=admin`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification player telemetry: event player suivant avec `payload.player.login="<login>"` et `payload.state_delta.auth_level.changed=true`.

- [x] auth.revoke
  - User Story: En tant qu'operateur serveur, je veux retirer un niveau d'auth pour restaurer les droits standards d'un joueur.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute auth.revoke target_login=<login>`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification player telemetry: event player suivant avec `payload.player.login="<login>"`, `payload.player.auth_role="player"` (ou `state_delta.auth_role.after="player"`).

- [x] map.remove
  - User Story: En tant qu'operateur map pool, je veux retirer une map de la rotation pour exclure un contenu non desire.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.remove map_uid=<valid_map_uid>`
  - Verification JSON: `success=true` et `code="ok"`.
  - Verification runtime: la map retiree n'apparait plus dans `payload.map_rotation.map_pool` sur les transitions map suivantes.

- [x] Full matrix rapide
  - User Story: En tant qu'operateur QA, je veux executer une campagne complete d'actions admin pour verifier rapidement la non-regression globale.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix target_login=<login> map_uid=<valid_map_uid> mx_id=<valid_mx_id>`
  - Verification artefacts: dossier `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/` cree avec `summary.md`, `list-actions.json` et fichiers `execute-*.json`.
  - Verification resultat: `summary.md` contient une ligne par action avec colonnes `Communication Error`, `Action Success`, `Action Code` coherentes (`ok` en nominal, ou codes de fallback attendus selon contexte: `native_rejected`, `capability_unavailable`, `native_exception`).

## 10) Transport resilience

- [x] Outage enter + retry schedule
  - User Story: En tant qu'operateur plateforme, je veux que le plugin passe en mode outage/retry quand l'API est indisponible.
  - Action: arreter le ACK stub, generer des events (combat/admin), observer les logs plugin.
  - Verification logs: presence de `[PixelControl][queue][outage_entered]` puis plusieurs `[PixelControl][queue][retry_scheduled]`.
  - Verification contexte: les logs de retry mentionnent la meme cible API que `PIXEL_CONTROL_API_BASE_URL`.

- [x] Outage recover + flush complete
  - User Story: En tant qu'operateur plateforme, je veux que le plugin se recupere automatiquement et vide sa queue apres retour API.
  - Action: relancer le ACK stub puis attendre la reprise.
  - Verification logs: presence de `[PixelControl][queue][outage_recovered]` puis `[PixelControl][queue][recovery_flush_complete]`.
  - Verification resultat: plus de spam `retry_scheduled` apres recuperation.

## 11) Completion

- [x] Toutes les cases critiques cochees
  - User Story: En tant que responsable release, je veux confirmer les validations critiques avant de considerer le plugin pret.
  - Critique: connectivity, player/combat basique, lifecycle basique, admin payload (`list-actions`, `map.skip`, `pause.start/end`, `auth.grant/revoke`), outage/recovery.
  - Statut campagne courante: valide.

- [x] Evidence archivee
  - User Story: En tant qu'auditeur technique, je veux conserver les preuves d'execution pour assurer la tracabilite de la recette.
  - A conserver: fichier NDJSON capture, extraits `ManiaControl.log`, artefacts `logs/qa/admin-payload-sim-*`.
  - Statut campagne courante: valide (artefacts NDJSON/logs/admin-sim conserves).
