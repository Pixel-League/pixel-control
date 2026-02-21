# Pixel Control Plugin - Manual Feature Test Todo

Ce fichier sert de checklist manuelle pour valider les features du plugin Pixel Control.
Chaque item contient le titre de la feature + une User Story (one-liner) + comment faire le test.

## 0) Preparation (a faire une seule fois)

- [x] Stack dev et plugin charges
  - User Story: En tant qu'operateur QA, je veux demarrer la stack complete pour pouvoir tester le plugin dans un environnement reel.
  - Commande: `bash pixel-sm-server/scripts/dev-plugin-sync.sh`
  - Validation: le serveur ShootMania + ManiaControl sont up et le plugin Pixel Control est charge.

- [x] Capture des payloads active
  - User Story: En tant qu'operateur QA, je veux capturer les payloads emis pour verifier objectivement ce que le plugin envoie.
  - Commande 1: `bash pixel-sm-server/scripts/manual-wave5-ack-stub.sh --output "pixel-sm-server/logs/manual/manual-feature-test-payload.ndjson"`
  - Commande 2: `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash pixel-sm-server/scripts/dev-plugin-sync.sh`
  - Validation: le fichier NDJSON se remplit quand des events plugin sont emis.

- [x] Logs live ouverts
  - User Story: En tant qu'operateur QA, je veux voir les logs en temps reel pour confirmer rapidement les callbacks et actions admin.
  - Commande: `tail -f pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log | rg --line-buffered "\\[Pixel Plugin\\]|\\[PixelControl\\]"`
  - Validation: vous voyez les marqueurs `[Pixel Plugin]` et `[PixelControl]` en temps reel.

- [x] Outil de simulation admin pret
  - User Story: En tant qu'operateur QA, je veux lister les actions admin exposees pour savoir exactement ce qui est testable via payload serveur.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh list-actions`
  - Validation: reponse JSON avec `PixelControl.Admin.ListActions` + liste des actions disponibles.

## 1) Connectivity + envelope identity

- [x] Plugin registration (`pixel_control.connectivity.plugin_registration`)
  - User Story: En tant que backend Pixel Control, je veux recevoir un event d'enregistrement au demarrage pour savoir que le plugin est disponible.
  - Action: redemarrer/sync le plugin.
  - Verification: trouver `pixel_control.connectivity.plugin_registration` dans le fichier NDJSON.

- [x] Heartbeat (`pixel_control.connectivity.plugin_heartbeat`)
  - User Story: En tant que backend Pixel Control, je veux recevoir des heartbeats periodiques pour surveiller l'etat de sante du plugin.
  - Action: attendre au moins 1 cycle heartbeat (intervalle configure, par defaut 15s).
  - Verification: trouver des events heartbeat successifs dans le NDJSON.

- [x] Identite deterministe de l'envelope
  - User Story: En tant que systeme d'ingestion, je veux des identifiants deterministes pour dedupliquer et tracer chaque event de facon fiable.
  - Action: ouvrir quelques payloads recents.
  - Verification: chaque envelope contient `event_name`, `event_id`, `idempotency_key`, `source_sequence`, `schema_version`.
  - Verification supplementaire: aucune erreur `drop_identity_invalid` inattendue dans `ManiaControl.log`.

## 2) Lifecycle

- [x] Warmup start/end/status
  - User Story: En tant qu'operateur match, je veux suivre l'etat du warmup pour synchroniser l'orchestration de debut de partie.
  - Action: lancer `warmup.extend` puis `warmup.end` (via chat admin ou simulateur payload).
  - Verification: events lifecycle warmup emis + contexte `admin_action` coherent.

- [ ] Pause start/end/status
  - User Story: En tant qu'operateur match, je veux piloter et observer la pause pour gerer les interruptions sans perdre le contexte.
  - Action: lancer `pause.start`, puis `pause.end`.
  - Verification: map/match se met bien en pause puis reprend, et payload `pause.status` coherent.

- [ ] Match begin/end
  - User Story: En tant que backend analytics, je veux connaitre les bornes d'un match pour calculer les agregats sur la bonne fenetre.
  - Action: demarrer une partie puis la terminer naturellement ou via rotation map.
  - Verification: events `match.begin` et `match.end` presentes.

- [ ] Map begin/end
  - User Story: En tant qu'operateur serveur, je veux tracer les transitions de map pour verifier la rotation et l'orchestration admin.
  - Action: executer un `map.skip`.
  - Verification: events `map.end` puis `map.begin` + changement visible de map en jeu.

- [ ] Round begin/end
  - User Story: En tant qu'engine telemetry, je veux delimiter les rounds pour produire des stats fines par manche.
  - Action: jouer au moins un round complet.
  - Verification: `round.begin` et `round.end` presents dans les payloads.

## 3) Player state

- [ ] Player connect
  - User Story: En tant qu'operateur roster, je veux detecter l'arrivee d'un joueur pour maintenir un etat de roster fiable.
  - Action: connecter un joueur au serveur.
  - Verification: event player connect + snapshot joueur present.

- [ ] Player disconnect
  - User Story: En tant qu'operateur roster, je veux detecter la sortie d'un joueur pour fermer proprement son contexte de session.
  - Action: deconnecter le meme joueur.
  - Verification: event player disconnect + etat connecte -> deconnecte.

- [ ] Player info changed
  - User Story: En tant que systeme de suivi joueur, je veux capter les changements d'etat individuels pour maintenir un profil courant exact.
  - Action: modifier un etat joueur (team/spec/referee si possible).
  - Verification: event `playerinfochanged` + `state_delta` coherent.

- [ ] Player infos changed (batch)
  - User Story: En tant que systeme de synchronisation, je veux un signal de refresh batch pour recalculer rapidement l'etat global des joueurs.
  - Action: provoquer un refresh global (ex: transition map/round, plusieurs joueurs).
  - Verification: event `playerinfoschanged` et `transition_kind=batch_refresh`.

- [ ] Reconnect continuity
  - User Story: En tant qu'analyste gameplay, je veux relier les reconnexions a une meme session logique pour eviter les ruptures de tracking.
  - Action: deconnecter puis reconnecter le meme login.
  - Verification: `reconnect_continuity` rempli (`session_id`, `session_ordinal`, `reconnect_count`).

- [ ] Side/team transition
  - User Story: En tant qu'operateur competition, je veux tracer les changements d'equipe/camp pour auditer l'equite et les actions admin.
  - Action: changer un joueur de team (ex: `player.force_team`).
  - Verification: objet `side_change` coherent (`previous/current`, `team_changed`/`side_changed`).

- [ ] Admin correlation sur events player
  - User Story: En tant qu'auditeur operationnel, je veux corriger les causalites entre action admin et effet joueur pour expliquer les changements observes.
  - Action: lancer une action admin player (force spec/team), puis observer l'event player suivant.
  - Verification: `admin_correlation` present avec correlation plausible.

- [ ] Constraint signals
  - User Story: En tant qu'orchestrateur de match, je veux connaitre les contraintes de policy team/slot pour comprendre pourquoi un joueur peut ou non rejoindre.
  - Action: produire un event player avec changement de slot/team.
  - Verification: `constraint_signals` present (`policy_context`, `forced_team_policy`, `slot_policy`).

## 4) Combat + scores

- [ ] Player shoot
  - User Story: En tant que pipeline stats, je veux capter chaque tir pour calculer precision et volume de jeu.
  - Action: en jeu, tirer au moins 1 fois.
  - Verification: log `[Pixel Plugin] ... shoot` + event `pixel_control.combat.shootmania_event_onshoot`.

- [ ] Player hit
  - User Story: En tant que pipeline stats, je veux capter chaque hit pour mesurer l'efficacite des joueurs.
  - Action: toucher un joueur adverse.
  - Verification: log `[Pixel Plugin] ... hit` + event `..._onhit`.

- [ ] Player near miss
  - User Story: En tant que pipeline stats, je veux capter les near miss pour qualifier la pression offensive et la precision fine.
  - Action: tirer proche d'un joueur sans toucher.
  - Verification: log `[Pixel Plugin] ... near-miss` + event `..._onnearmiss`.

- [ ] Player dead / armor empty
  - User Story: En tant que pipeline stats, je veux detecter les eliminations pour alimenter les compteurs kills/deaths.
  - Action: tuer un joueur.
  - Verification: log `[Pixel Plugin] ... armor-empty` + event `..._onarmorempty`.

- [ ] Capture
  - User Story: En tant qu'analyste mode objectif, je veux capter les captures pour mesurer la progression objective des equipes.
  - Action: realiser une capture (mode compatible capture).
  - Verification: log capture + event `..._oncapture`.

- [ ] Scores snapshot
  - User Story: En tant que backend de resultats, je veux les snapshots de score pour determiner vainqueur, egalite et contexte de fin.
  - Action: terminer un round/map pour forcer un update score.
  - Verification: event `..._scores` avec `scores_snapshot` et `scores_result`.

- [ ] Counters combat par joueur
  - User Story: En tant qu'analyste performance, je veux des compteurs combat par joueur pour suivre la production individuelle sur la fenetre active.
  - Action: enchainez tirs/hits/miss/kills sur une courte session.
  - Verification: `player_counters` evolue (shots/hits/misses/kills/deaths/accuracy/rockets/lasers).

## 5) Lifecycle aggregate telemetry

- [ ] Aggregate stats round.end
  - User Story: En tant que service analytics, je veux les agregats de fin de round pour produire des deltas fiables par manche.
  - Action: jouer puis finir un round.
  - Verification: `aggregate_stats` present sur `round.end` avec deltas joueurs + teams.

- [ ] Aggregate stats map.end
  - User Story: En tant que service analytics, je veux les agregats de fin de map pour consolider les performances sur la map complete.
  - Action: changer de map apres gameplay.
  - Verification: `aggregate_stats` present sur `map.end`.

- [ ] Win context
  - User Story: En tant que consommateur de resultats, je veux un contexte de victoire normalise pour eviter les interpretations ambigues.
  - Action: forcer un resultat clair (victoire/equite si possible).
  - Verification: `win_context` coherent (`result_state`, `winning_side`, `winning_reason`).

## 6) Map rotation + veto/draft

- [ ] Map rotation baseline
  - User Story: En tant qu'operateur map pool, je veux une vue structuree de la rotation pour verifier l'ordre et les transitions.
  - Action: executer un `map.skip`.
  - Verification: `map_rotation` present avec `current_map`, `next_maps`, `map_pool_size`, `played_map_order`.

- [ ] Veto/draft actions (si mode supporte)
  - User Story: En tant qu'operateur competition, je veux suivre chaque action veto/draft pour auditer la phase de selection.
  - Action: realiser des actions veto/pick/pass/lock dans un mode qui les expose.
  - Verification: `veto_draft_actions` alimente avec ordre + acteur + map.

- [ ] Veto result (si mode supporte)
  - User Story: En tant que backend de match setup, je veux un resultat final de veto/draft pour connaitre la map retenue.
  - Action: terminer une sequence veto/draft.
  - Verification: `veto_result` present (status `partial`/`unavailable`/selection).

## 7) Mode-specific callbacks

- [ ] Elite start/end turn (mode Elite)
  - User Story: En tant qu'analyste Elite, je veux les callbacks de tour pour reconstruire la chronologie du round.
  - Action: lancer une manche Elite jusqu'au changement de tour.
  - Verification: events mode Elite start/end turn emis.

- [ ] Joust callbacks (mode Joust)
  - User Story: En tant qu'analyste Joust, je veux les callbacks specifiques au mode pour produire des indicateurs mode-aware.
  - Action: lancer un scenario Joust (reload, selection joueurs, round result).
  - Verification: events Joust emis.

- [ ] Royal callbacks (mode Royal)
  - User Story: En tant qu'analyste Royal, je veux les callbacks specifiques au mode pour suivre points, spawns et gagnant de manche.
  - Action: lancer un scenario Royal (points/spawn/round winner).
  - Verification: events Royal emis.

## 8) Admin actions depuis le chat jeu (actor-bound)

- [ ] Verification droits chat admin
  - User Story: En tant qu'operateur securite, je veux que seules les personnes autorisees puissent executer des commandes admin via le chat.
  - Action: essayer `//pcadmin map.skip` avec un joueur sans droit puis avec un admin ManiaControl.
  - Verification: refus pour non-admin, succes pour admin.

- [ ] Help command
  - User Story: En tant qu'admin serveur, je veux une aide en chat pour connaitre les commandes disponibles sans quitter le jeu.
  - Action: `//pcadmin help`
  - Verification: la liste des actions apparait en chat.

## 9) Admin actions via payload serveur (simulateur)

Notes:

- Les commandes ci-dessous passent par `PixelControl.Admin.ExecuteAction`.
- Le script ecrit un dossier artefacts `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/`.

- [ ] List actions
  - User Story: En tant que futur Pixel Control Server, je veux decouvrir dynamiquement les actions supportees par le plugin.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh list-actions`
  - Verification: JSON de reponse contient le catalogue d'actions.

- [ ] map.skip
  - User Story: En tant qu'orchestrateur serveur, je veux skip la map courante a distance pour gerer les incidents ou transitions rapides.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.skip`
  - Verification: la map est skip in-game + `action_success` dans la reponse.

- [ ] map.restart
  - User Story: En tant qu'orchestrateur serveur, je veux redemarrer la map courante pour relancer proprement la manche.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.restart`
  - Verification: la map redemarre in-game + succes en reponse.

- [ ] map.jump
  - User Story: En tant qu'orchestrateur serveur, je veux sauter vers une map precise pour appliquer un planning de match controle.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.jump map_uid=<valid_map_uid>`
  - Verification: saut vers la map demandee + succes en reponse.

- [ ] map.queue
  - User Story: En tant qu'orchestrateur serveur, je veux ajouter une map a la queue pour preparer la rotation suivante.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.queue map_uid=<valid_map_uid>`
  - Verification: map ajoutee a la queue + succes en reponse.

- [ ] map.add (ManiaExchange)
  - User Story: En tant qu'orchestrateur serveur, je veux importer une map depuis ManiaExchange pour enrichir dynamiquement le map pool.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.add mx_id=<valid_mx_id>`
  - Verification: import map lance via ManiaExchange + reponse succes (submission async).

- [ ] warmup.extend
  - User Story: En tant qu'orchestrateur match, je veux prolonger le warmup a distance pour absorber un retard operationnel.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute warmup.extend seconds=30`
  - Verification: warmup allonge + succes en reponse.

- [ ] warmup.end
  - User Story: En tant qu'orchestrateur match, je veux stopper le warmup a distance pour passer immediatement en phase de jeu.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute warmup.end`
  - Verification: warmup stoppe + succes en reponse.

- [ ] pause.start
  - User Story: En tant qu'orchestrateur match, je veux declencher une pause a distance en cas d'incident technique.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute pause.start`
  - Verification: partie en pause + succes en reponse.

- [ ] pause.end
  - User Story: En tant qu'orchestrateur match, je veux reprendre la partie a distance une fois l'incident resolu.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute pause.end`
  - Verification: reprise de la partie + succes en reponse.

- [ ] vote.cancel
  - User Story: En tant qu'operateur serveur, je veux annuler un vote en cours pour garder le controle du flux de match.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute vote.cancel`
  - Verification: si vote en cours, il est annule; sinon code d'echec controle (`native_rejected`) acceptable.

- [ ] vote.set_ratio
  - User Story: En tant qu'operateur serveur, je veux ajuster le ratio de vote pour calibrer la gouvernance en jeu.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute vote.set_ratio command=nextmap ratio=0.60`
  - Verification: ratio mis a jour + succes en reponse.

- [ ] vote.custom_start
  - User Story: En tant qu'operateur serveur, je veux declencher un vote custom pour appliquer un workflow communautaire specifique.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute vote.custom_start vote_index=0`
  - Verification: vote custom demarre si plugin support actif; sinon `capability_unavailable` attendu.

- [ ] player.force_team
  - User Story: En tant qu'orchestrateur competition, je veux forcer l'equipe d'un joueur pour corriger un placement.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute player.force_team target_login=<login> team=blue`
  - Verification: joueur bascule d'equipe + succes en reponse.

- [ ] player.force_play
  - User Story: En tant qu'orchestrateur competition, je veux forcer un joueur en jeu pour respecter le roster actif.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute player.force_play target_login=<login>`
  - Verification: joueur force en jeu + succes en reponse.

- [ ] player.force_spec
  - User Story: En tant qu'orchestrateur competition, je veux forcer un joueur en spectateur pour appliquer une decision arbitrale.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute player.force_spec target_login=<login>`
  - Verification: joueur force spectateur + succes en reponse.

- [ ] auth.grant
  - User Story: En tant qu'operateur serveur, je veux accorder un niveau d'auth a un joueur pour deleguer des responsabilites admin.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute auth.grant target_login=<login> auth_level=admin`
  - Verification: niveau auth augmente + succes en reponse.

- [ ] auth.revoke
  - User Story: En tant qu'operateur serveur, je veux retirer un niveau d'auth pour restaurer les droits standards d'un joueur.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute auth.revoke target_login=<login>`
  - Verification: auth revient a player + succes en reponse.

- [ ] map.remove
  - User Story: En tant qu'operateur map pool, je veux retirer une map de la rotation pour exclure un contenu non desire.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute map.remove map_uid=<valid_map_uid>`
  - Verification: map supprimee de la rotation + succes en reponse.

- [ ] Full matrix rapide
  - User Story: En tant qu'operateur QA, je veux executer une campagne complete d'actions admin pour verifier rapidement la non-regression globale.
  - Commande: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix target_login=<login> map_uid=<valid_map_uid> mx_id=<valid_mx_id>`
  - Verification: `summary.md` genere avec une ligne par action et un code de resultat explicite.

## 10) Transport resilience

- [ ] Outage enter + retry schedule
  - User Story: En tant qu'operateur plateforme, je veux que le plugin passe en mode outage/retry quand l'API est indisponible.
  - Action: arreter le ACK stub, generer des events (combat/admin), observer les logs plugin.
  - Verification: marqueurs `outage_entered` puis `retry_scheduled`.

- [ ] Outage recover + flush complete
  - User Story: En tant qu'operateur plateforme, je veux que le plugin se recupere automatiquement et vide sa queue apres retour API.
  - Action: relancer le ACK stub puis attendre la reprise.
  - Verification: marqueurs `outage_recovered` puis `recovery_flush_complete`.

## 11) Completion

- [ ] Toutes les cases critiques cochees
  - User Story: En tant que responsable release, je veux confirmer les validations critiques avant de considerer le plugin pret.
  - Critique: connectivity, player/combat basique, lifecycle basique, admin payload (`list-actions`, `map.skip`, `pause.start/end`, `auth.grant/revoke`), outage/recovery.

- [ ] Evidence archivee
  - User Story: En tant qu'auditeur technique, je veux conserver les preuves d'execution pour assurer la tracabilite de la recette.
  - A conserver: fichier NDJSON capture, extraits `ManiaControl.log`, artefacts `logs/qa/admin-payload-sim-*`.
