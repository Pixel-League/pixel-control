# Playbook de tests manuels — P3, P4, P5

Ce document decrit chaque test a realiser manuellement pour verifier que toutes les fonctionnalites admin (P3 a P5) fonctionnent correctement avec un vrai serveur ShootMania Elite, le plugin Pixel Control, l'API NestJS, et l'interface Pixel Control UI.

---

## Comment lire ce document

Chaque test suit le meme format :

- **Ou** : quelle page de l'UI ouvrir (ou quelle commande lancer)
- **Quoi faire** : les etapes detaillees, bouton par bouton, champ par champ
- **Resultat attendu** : ce qu'on doit voir a l'ecran ou dans le jeu
- **Verification cote serveur** : comment confirmer que l'action a bien ete executee sur le serveur de jeu
- **Validation** : une checkbox a cocher une fois le test realise

Les codes de reponse (`success`, `code`, `message`) apparaissent dans le panneau "Response History" a droite de chaque page admin.

---

## Prerequis : mettre en place l'environnement de test

### 1. Demarrer le stack de jeu

```bash
cd pixel-sm-server
cp .env.example .env         # premiere fois uniquement
# Editer .env : verifier MYSQL_ROOT_PASSWORD, MC_SOCKET_PASSWORD, etc.
docker compose up -d --build
docker compose logs -f shootmania   # attendre "ManiaControl fully loaded"
```

Verifier que ManiaControl demarre correctement et que le plugin Pixel Control est charge :
```
[PixelControl] Plugin loaded — Elite mode
```

### 2. Demarrer l'API

```bash
cd pixel-control-server
npm install
npm run start:dev
```

Verifier que l'API tourne sur le port 3000 :
```
[Nest] application successfully started on port 3000
```

### 3. Demarrer l'UI

```bash
cd pixel-control-ui
npm install
npm run dev
```

Ouvrir Chrome a l'adresse `http://localhost:5173`.

### 4. Enregistrer le serveur dans l'UI

1. Dans la sidebar, cliquer sur **Register Server**.
2. Remplir le champ `Server Login` avec le login du serveur dedie (celui configure dans `pixel-sm-server/.env`, variable `PIXEL_SM_SERVER_LOGIN`).
3. Cliquer sur **Register**.
4. Le serveur apparait dans le selecteur "Active Server" de la sidebar.
5. Selectionner ce serveur dans le dropdown.

A partir de la, toutes les pages admin utilisent ce serveur.

### 5. Connecter des joueurs

Pour tester les commandes qui agissent sur des joueurs (force team, force play, force spec, auth, whitelist...), il faut au moins 2 joueurs connectes au serveur. Utiliser le client ManiaPlanet pour se connecter, ou un second compte si disponible. Noter les logins des joueurs connectes (visibles dans la page **Player List** de l'UI).

### Validation des prerequis

- [x] Stack de jeu demarre, plugin charge
- [x] API NestJS sur port 3000
- [x] UI sur port 5173
- [x] Serveur enregistre et selectionne dans l'UI
- [x] Au moins 1 joueur connecte au serveur de jeu

---

## Partie 1 — P3 : Map Control (6 tests)

Page UI : **Admin > Map Control** (`/admin/maps`)

### Test 1.1 — Skip Map

1. Ouvrir la page **Map Control**.
2. Verifier qu'une map est en cours de jeu (visible dans la sidebar : serveur en ligne).
3. Cliquer sur le bouton **Skip to Next Map**.
4. **Resultat attendu** :
   - Le panneau Response History affiche un badge vert "Success".
   - Le code de reponse est `map_skipped`.
   - Dans le jeu, la map change immediatement vers la suivante dans la rotation.
5. **Verification in-game** : la map courante change. Les joueurs voient le chargement de la nouvelle map.

- [x] **Test 1.1 realise et fonctionnel**

### Test 1.2 — Restart Map

1. Attendre que la nouvelle map soit chargee apres le test 1.1.
2. Cliquer sur le bouton **Restart Current Map**.
3. **Resultat attendu** :
   - Badge vert "Success" dans l'historique.
   - Code : `map_restarted`.
   - In-game : la map courante redemarre depuis le debut (scores remis a zero pour la map en cours).

- [x] **Test 1.2 realise et fonctionnel**

### Test 1.3 — Jump to Map

1. Recuperer l'UID d'une map dans la rotation. Pour ca, aller dans la page **Map List** (`/maps`) et copier l'UID d'une map qui n'est PAS la map courante.
2. Revenir sur **Map Control**.
3. Coller l'UID dans le champ "Map UID" de la section **Jump to Map**.
4. Cliquer sur **Jump to Map**.
5. **Resultat attendu** :
   - Badge vert, code `map_jumped` ou `map_jump_queued`.
   - In-game : le serveur passe directement a la map demandee.
6. **Test d'erreur** : entrer un UID bidon (`xxxxxxxxxxxxxxxxxxxxxxx`), cliquer. Attendu : badge rouge, code d'erreur (par exemple `map_not_found`).

- [x] **Test 1.3 realise et fonctionnel**
- [x] **Test 1.3 cas d'erreur verifie**

### Test 1.4 — Queue Map

1. Copier l'UID d'une map differente de la map courante (depuis **Map List**).
2. Coller dans le champ "Map UID" de la section **Queue Map**.
3. Cliquer sur **Queue as Next**.
4. **Resultat attendu** :
   - Badge vert, code `map_queued`.
   - La map est planifiee comme prochaine. Quand la map courante se termine (ou apres un skip), c'est cette map qui charge.
5. **Verification** : faire un Skip (test 1.1) et verifier que la map qui charge est bien celle qu'on a mise en file.

- [x] **Test 1.4 realise et fonctionnel**

### Test 1.5 — Add Map from ManiaExchange

1. Aller sur [ManiaExchange](https://sm.mania.exchange/) et trouver un ID de map ShootMania (par exemple `12345` — ou un ID reel si possible).
2. Entrer cet ID dans le champ de la section **Add Map from MX**.
3. Cliquer sur **Add Map**.
4. **Resultat attendu** :
   - Si l'ID est valide : badge vert, code `map_added`. La map est telechargee et ajoutee a la rotation.
   - Si l'ID est invalide : badge rouge avec un message d'erreur (par exemple `mx_download_failed`).
5. **Verification** : aller dans **Map List** et verifier que la nouvelle map apparait dans la liste.

- [x] **Test 1.5 realise et fonctionnel**

### Test 1.6 — Remove Map

1. Choisir une map a supprimer (de preference celle ajoutee au test 1.5). Copier son UID depuis **Map List**.
2. Coller l'UID dans le champ de la section **Remove Map** (section a bordure rouge).
3. Cliquer sur **Remove Map**.
4. Une modale de confirmation apparait : "Remove map '...' from the server map pool? This cannot be undone."
5. Cliquer sur **Remove** dans la modale.
6. **Resultat attendu** :
   - Badge vert, code `map_removed`.
   - La map disparait de la rotation.
7. **Verification** : rafraichir **Map List** — la map n'est plus la.
8. **Test de la modale** : entrer un UID, cliquer sur Remove, puis cliquer **Cancel** dans la modale. Verifier qu'aucune action n'est executee.

- [x] **Test 1.6 realise et fonctionnel**
- [x] **Test 1.6 modale Cancel verifiee**

---

## Partie 2 — P3 : Warmup & Pause (4 tests)

Page UI : **Admin > Warmup / Pause** (`/admin/warmup-pause`)

### Test 2.1 — Extend Warmup

1. Ouvrir la page **Warmup / Pause**.
2. Attendre d'etre en phase de warmup dans le jeu (au debut d'une map par exemple). Si le serveur est en match, faire un Skip Map pour passer a la map suivante et etre en warmup.
3. Le champ "Extend by (seconds)" est pre-rempli a 30.
4. Changer la valeur a `60`.
5. Cliquer sur **Extend**.
6. **Resultat attendu** :
   - Badge vert, code `warmup_extended`.
   - In-game : la duree du warmup est prolongee de 60 secondes.
7. **Test d'erreur** : tester avec une valeur de `0` ou un nombre negatif — le bouton Extend devrait etre desactive (grise).

- [x] **Test 2.1 realise et fonctionnel**
- [x] **Test 2.1 validation d'entree verifiee (bouton grise si valeur invalide)**

### Test 2.2 — End Warmup

1. S'assurer d'etre en phase de warmup in-game.
2. Cliquer sur le bouton rouge **End Warmup Now**.
3. Une modale de confirmation apparait.
4. Cliquer sur **End Warmup**.
5. **Resultat attendu** :
   - Badge vert, code `warmup_ended`.
   - In-game : le warmup se termine immediatement et le match commence.
6. **Test de la modale** : cliquer sur Cancel dans la modale — rien ne se passe.

- [x] **Test 2.2 realise et fonctionnel**
- [x] **Test 2.2 modale Cancel verifiee**

### Test 2.3 — Pause Match

1. S'assurer qu'un match est en cours (pas en warmup). Si besoin, terminer le warmup d'abord (test 2.2).
2. Cliquer sur **Pause Match**.
3. **Resultat attendu** :
   - Badge vert, code `pause_started` ou `match_paused`.
   - In-game : le match est en pause, les joueurs ne peuvent plus agir.

- [x] **Test 2.3 realise et fonctionnel**

### Test 2.4 — Resume Match

1. Apres avoir mis en pause (test 2.3).
2. Cliquer sur **Resume Match**.
3. **Resultat attendu** :
   - Badge vert, code `pause_ended` ou `match_resumed`.
   - In-game : le match reprend.
4. **Test d'erreur** : cliquer sur Resume sans avoir pause avant — attendu : un code d'erreur indiquant qu'il n'y a pas de pause active.

- [x] **Test 2.4 realise et fonctionnel**
- [x] **Test 2.4 cas d'erreur verifie (resume sans pause)**

---

## Partie 3 — P3 : Match Config (6 tests)

Page UI : **Admin > Match Config** (`/admin/match`)

### Test 3.1 — Lire le Best-of actuel

1. Ouvrir la page **Match Config**.
2. La section "Best-of Configuration" charge automatiquement la valeur actuelle depuis le serveur.
3. **Resultat attendu** :
   - Si le socket est accessible : un bloc vert avec les details JSON (`best_of: 3` par exemple).
   - Si le socket est inaccessible : un badge rouge "Socket Unavailable" avec un bouton Retry.
4. Cliquer sur **Retry** si necessaire pour retenter.

- [x] **Test 3.1 realise et fonctionnel**

### Test 3.2 — Modifier le Best-of

1. Dans le champ "Best-of target", entrer `5`.
2. Cliquer sur **Set**.
3. **Resultat attendu** :
   - Badge vert "Success" sous la section, code `best_of_set`.
   - La valeur lue en haut se met a jour automatiquement a `5`.
4. Remettre a `3` et re-tester.
5. **Test d'erreur** : entrer un nombre pair (ex: `4`) — le plugin devrait rejeter avec un code d'erreur (le best-of doit etre impair).

- [x] **Test 3.2 realise et fonctionnel**
- [x] **Test 3.2 cas d'erreur verifie (nombre pair)**

### Test 3.3 — Lire le Maps Score

1. La section "Maps Score" charge automatiquement le score des maps.
2. **Resultat attendu** : affichage JSON des scores de maps par equipe (ex: `{"team_a": 0, "team_b": 0}`).

- [x] **Test 3.3 realise et fonctionnel**

### Test 3.4 — Modifier le Maps Score

1. Selectionner "Team A" dans le dropdown.
2. Entrer `2` dans le champ "Maps won".
3. Cliquer sur **Set**.
4. **Resultat attendu** :
   - Badge vert, code `maps_score_set`.
   - La valeur lue se met a jour : Team A a maintenant 2 maps gagnees.
5. Faire la meme chose pour Team B avec `1`.
6. Verifier la lecture : Team A = 2, Team B = 1.

- [x] **Test 3.4 realise et fonctionnel**

### Test 3.5 — Lire le Round Score

1. La section "Round Score" charge automatiquement.
2. **Resultat attendu** : affichage JSON du score du round en cours par equipe.

- [x] **Test 3.5 realise et fonctionnel**

### Test 3.6 — Modifier le Round Score

1. Selectionner "Team B".
2. Entrer `3` dans le champ "Score".
3. Cliquer sur **Set**.
4. **Resultat attendu** :
   - Badge vert, code `round_score_set`.
   - La lecture se met a jour : Team B a un score de 3.
5. In-game : verifier que le tableau de score reflete les valeurs modifiees.

- [x] **Test 3.6 realise et fonctionnel**

---

## Partie 4 — P4 : Veto / Draft (5 tests)

Page UI : **Admin > Veto / Draft** (`/admin/veto`)

C'est l'interface la plus complexe. Elle a 3 zones : Configuration, Map Pool, et Timeline.

### Test 4.1 — Consulter le statut du veto

1. Ouvrir la page **Veto / Draft**.
2. Le badge en haut a droite de la zone Config affiche le statut : "Idle" (gris) si aucune session n'est active.
3. Cliquer sur **Refresh** pour forcer un rechargement.
4. **Resultat attendu** :
   - Badge "Idle" gris.
   - Zone Map Pool : message "No map pool available".
   - Zone Timeline : "No actions yet".
5. En bas de la zone Timeline, cliquer sur **Raw veto status** pour derouler le JSON brut. Verifier qu'il contient les champs `status`, `communication`, etc.

- [x] **Test 4.1 realise et fonctionnel**

### Test 4.2 — Demarrer une session Tournament Draft (BO3)

1. Dans la zone Config :
   - **Mode** : s'assurer que "Tournament Draft" est selectionne (bouton orange).
   - **Series Format** : cliquer sur **BO3**.
   - **First Team** : laisser "Random" ou choisir "Team A".
   - **Action Timeout** : laisser `30` (secondes).
   - **Team A Captain Login** : entrer le login d'un joueur connecte au serveur (ex: `player1`).
   - **Team B Captain Login** : entrer le login d'un second joueur (ex: `player2`).
2. Cliquer sur **Arm Ready** d'abord.
   - **Resultat attendu** : dans l'Action Log (zone en bas a droite), badge vert "OK" avec le message "matchmaking_ready_armed" ou similaire.
3. Cliquer sur **Start Session**.
   - **Resultat attendu** :
     - Badge du statut passe a "Running" (orange).
     - La zone Map Pool se remplit avec les cartes de la map pool du serveur (grille de cartes visuelles).
     - L'indicateur de tour affiche quelque chose comme "Team A's turn — BAN".
     - L'auto-refresh s'active automatiquement (checkbox cochee).
     - In-game : un message de chat apparait indiquant le debut du veto.
4. **Test d'erreur** : tenter de Start une seconde session sans annuler la premiere — attendu : message d'erreur `session_active`.
5. **Test d'erreur** : mettre le meme login pour captain A et captain B — attendu : erreur `captain_conflict`.

- [x] **Test 4.2 realise et fonctionnel**
- [x] **Test 4.2 erreur session_active verifiee**
- [x] **Test 4.2 erreur captain_conflict verifiee**

### Test 4.3 — Realiser des actions de veto (ban/pick)

Prerequis : une session Tournament Draft est en cours (test 4.2).

1. Dans la zone Map Pool, un champ "Actor Login" apparait en haut a droite. Entrer le login du capitaine dont c'est le tour (affiche dans l'indicateur de tour).
2. Cliquer sur une carte de map qui a le statut "Available" (fond gris, bord gris).
3. **Resultat attendu** :
   - La carte change d'aspect :
     - Si c'etait un ban : la carte devient rouge/bleue barree (selon l'equipe), avec le label "BANNED — A" ou "BANNED — B".
     - Si c'etait un pick : la carte prend une couleur orange (Team A) ou cyan (Team B) avec le label "PICK — A/B" et un badge numerote en haut a droite (1, 2...).
   - L'indicateur de tour passe a l'equipe suivante.
   - La timeline (zone en bas a gauche) ajoute une nouvelle etape avec un point colore et les details de l'action.
   - L'Action Log affiche un badge vert.
4. Repeter en alternant les capitaines :
   - Changer l'Actor Login pour le capitaine de l'autre equipe.
   - Cliquer sur une autre carte disponible.
5. Continuer jusqu'a ce que le veto soit termine (toutes les bans et picks sont faits).
6. **Resultat final** :
   - Le badge statut passe a "Completed" (vert).
   - L'indicateur de tour affiche "Veto complete".
   - La timeline montre le recap complet. En haut de la timeline, un bloc dore "Final Map Order" liste les maps pickees dans l'ordre.
   - La derniere carte restante (si applicable) affiche le statut "DECIDER" (dore).
7. **Test d'erreur** : pendant la session, essayer de soumettre une action avec un login qui n'est pas le capitaine du tour — attendu : erreur `actor_not_allowed`.

- [x] **Test 4.3 realise — bans fonctionnels**
- [x] **Test 4.3 realise — picks fonctionnels**
- [x] **Test 4.3 realise — session completee**
- [x] **Test 4.3 timeline et Final Map Order corrects**
- [x] **Test 4.3 erreur actor_not_allowed verifiee**

### Test 4.4 — Annuler une session

1. Demarrer une nouvelle session (refaire test 4.2).
2. Faire 1-2 actions de ban.
3. Cliquer sur le bouton rouge **Cancel Session** (visible quand une session est en cours).
4. Une modale de confirmation apparait : "This will cancel the current veto session. All progress will be lost."
5. Cliquer sur **Cancel Session** dans la modale.
6. **Resultat attendu** :
   - Badge statut passe a "Cancelled" (rouge).
   - Indicateur : "Session cancelled".
   - Action Log : badge vert avec message `session_cancelled`.
   - L'auto-refresh se desactive.
7. **Test de la modale** : relancer, cliquer Cancel Session, puis cliquer sur le bouton "Cancel" (pas "Cancel Session") dans la modale — rien ne se passe.

- [x] **Test 4.4 realise et fonctionnel**
- [x] **Test 4.4 modale Cancel verifiee**

### Test 4.5 — Tester le mode Matchmaking Vote

1. Dans la zone Config :
   - **Mode** : cliquer sur "Matchmaking Vote".
   - Le champ "Action Timeout" est remplace par "Vote Duration (seconds)".
   - Les champs Captain A/B disparaissent.
   - Mettre la duree a `60`.
2. Cliquer sur **Arm Ready**.
3. Cliquer sur **Start Session**.
4. **Resultat attendu** :
   - Session demarre en mode matchmaking.
   - Les joueurs in-game peuvent voter via le chat (`/pcveto vote <map>`).
   - Dans l'UI, le statut passe a "Running".
5. Soumettre un vote depuis l'UI :
   - Entrer l'Actor Login d'un joueur.
   - Cliquer sur une carte de map.
   - Attendu : badge vert, code `vote_recorded`.
6. Annuler la session pour nettoyer.

- [x] **Test 4.5 realise et fonctionnel**
- [x] **Test 4.5 vote soumis avec succes**

---

## Partie 5 — P4 : Player Management (3 tests)

Page UI : **Admin > Player Mgmt** (`/admin/players`)

Prerequis : au moins 1 joueur connecte au serveur de jeu. Noter son login (visible dans **Players > Player List**).

### Test 5.1 — Force Player Team

1. Ouvrir la page **Player Management**.
2. Dans la section "Force Player Team" :
   - Entrer le login du joueur dans le champ "Player Login".
   - Selectionner "Team B" (bouton cyan).
3. Cliquer sur **Force to Team**.
4. **Resultat attendu** :
   - Badge vert, code `player_forced_to_team` ou equivalent.
   - In-game : le joueur passe dans l'equipe B (bleue).
5. Refaire le test avec "Team A" — le joueur repasse dans l'equipe A (rouge).
6. **Test d'erreur** : entrer un login qui n'existe pas (ex: `fake_player_xyz`). Attendu : badge rouge avec code d'erreur.

- [x] **Test 5.1 realise et fonctionnel (Team B)**
- [x] **Test 5.1 realise et fonctionnel (Team A)**
- [x] **Test 5.1 cas d'erreur verifie (login invalide)**

### Test 5.2 — Force Player to Spectator

1. Dans la section "Force Player to Spectator" :
   - Entrer le login du joueur.
2. Cliquer sur **Force to Spectator**.
3. **Resultat attendu** :
   - Badge vert, code `player_forced_to_spec` ou equivalent.
   - In-game : le joueur passe en mode spectateur.

- [x] **Test 5.2 realise et fonctionnel**

### Test 5.3 — Force Player to Play

1. Apres le test 5.2 (le joueur est en spectateur).
2. Dans la section "Force Player to Play" :
   - Entrer le login du joueur.
3. Cliquer sur **Force to Play**.
4. **Resultat attendu** :
   - Badge vert, code `player_forced_to_play` ou equivalent.
   - In-game : le joueur repasse en mode joueur actif.

- [x] **Test 5.3 realise et fonctionnel**

---

## Partie 6 — P4 : Team Control (5 tests)

Page UI : **Admin > Team Control** (`/admin/teams`)

### Test 6.1 — Lire la Team Policy

1. Ouvrir la page **Team Control**.
2. Dans la section "Team Policy", cliquer sur **Get Policy**.
3. **Resultat attendu** :
   - Badge vert dans l'historique, code `team_policy_status` ou equivalent.
   - Les toggles "Policy Enabled" et "Switch Lock" se synchronisent avec les valeurs du serveur.
   - Le JSON de reponse apparait sous la section.

- [x] **Test 6.1 realise et fonctionnel**

### Test 6.2 — Modifier la Team Policy

1. Activer le toggle **Policy Enabled** (le mettre sur On).
2. Activer le toggle **Switch Lock** (le mettre sur Locked).
3. Cliquer sur **Set Policy**.
4. **Resultat attendu** :
   - Badge vert, code `team_policy_set`.
   - In-game : les joueurs ne peuvent plus changer d'equipe librement.
5. Desactiver les deux toggles et re-cliquer Set Policy. Verifier que la policy est desactivee.

- [x] **Test 6.2 realise et fonctionnel (activation)**
- [x] **Test 6.2 realise et fonctionnel (desactivation)**

### Test 6.3 — Assigner un joueur au roster

1. Dans la section "Assign to Roster" :
   - Entrer le login d'un joueur connecte.
   - Selectionner "Team A" (bouton orange).
2. Cliquer sur **Assign to Roster**.
3. **Resultat attendu** :
   - Badge vert, code `team_roster_assigned`.
   - Le joueur est assigne au roster de la Team A.
4. Assigner un second joueur a "Team B".

- [x] **Test 6.3 realise et fonctionnel (Team A)**
- [x] **Test 6.3 realise et fonctionnel (Team B)**

### Test 6.4 — Lister le roster

1. Dans la section "Team Roster", cliquer sur **Fetch Roster**.
2. **Resultat attendu** :
   - Un tableau s'affiche sous le bouton montrant chaque joueur avec son equipe (badges orange/cyan).
   - Le JSON brut est accessible via le toggle "Raw roster response".
   - Les joueurs assignes aux tests 6.3 apparaissent avec la bonne equipe.

- [x] **Test 6.4 realise et fonctionnel**

### Test 6.5 — Retirer un joueur du roster

1. Dans la section "Unassign from Roster" :
   - Entrer le login d'un joueur assigne au roster.
2. Cliquer sur le bouton rouge **Unassign from Roster**.
3. Une modale de confirmation apparait.
4. Cliquer sur **Unassign**.
5. **Resultat attendu** :
   - Badge vert, code `team_roster_unassigned`.
6. Re-cliquer **Fetch Roster** — le joueur n'apparait plus dans la liste.
7. **Test de la modale** : entrer un login, cliquer Unassign, puis Cancel dans la modale — rien ne se passe.

- [x] **Test 6.5 realise et fonctionnel**
- [x] **Test 6.5 modale Cancel verifiee**

---

## Partie 7 — P5 : Auth Management (2 tests)

Page UI : **Admin > Auth Mgmt** (`/admin/auth`)

### Test 7.1 — Accorder un niveau d'auth

1. Ouvrir la page **Auth Management**.
2. Dans la section "Grant Auth" :
   - Entrer le login d'un joueur connecte dans le champ "Player Login".
   - Cliquer sur le bouton **moderator** dans la grille de niveaux (il devient cyan).
   - Verifier que le badge "Selected: moderator" s'affiche en dessous.
3. Cliquer sur **Grant Auth**.
4. **Resultat attendu** :
   - Badge vert, code `auth_granted`.
   - In-game : le joueur a maintenant les droits de moderateur dans ManiaControl.
5. Tester avec **admin** : selectionner "admin" (bouton orange), cliquer Grant Auth.
   - Attendu : badge vert, code `auth_granted`.
6. Tester avec **superadmin** : meme chose.
7. **Test de chaque niveau** : chaque niveau doit etre accepte par le serveur (`player`, `moderator`, `admin`, `superadmin`).

- [x] **Test 7.1 — niveau player accorde**
- [x] **Test 7.1 — niveau moderator accorde**
- [x] **Test 7.1 — niveau admin accorde**
- [x] **Test 7.1 — niveau superadmin accorde**

### Test 7.2 — Revoquer un niveau d'auth

1. Dans la section "Revoke Auth" (bordure rouge) :
   - Entrer le login du joueur a qui on a accorde un auth au test 7.1.
2. Cliquer sur le bouton rouge **Revoke Auth**.
3. Une modale de confirmation apparait.
4. Cliquer sur **Revoke**.
5. **Resultat attendu** :
   - Badge vert, code `auth_revoked`.
   - In-game : le joueur revient au niveau d'acces par defaut.
6. **Test de la modale** : cliquer Revoke Auth, puis Cancel dans la modale — rien ne se passe.

- [x] **Test 7.2 realise et fonctionnel**
- [x] **Test 7.2 modale Cancel verifiee**

---

## Partie 8 — P5 : Whitelist Management (7 tests)

Page UI : **Admin > Whitelist** (`/admin/whitelist`)

### Test 8.1 — Activer la whitelist

1. Ouvrir la page **Whitelist Management**.
2. Dans la section "Status & Controls", cliquer sur **Enable**.
3. **Resultat attendu** :
   - Badge vert, code `whitelist_enabled`.
   - La whitelist est maintenant active sur le serveur.

- [x] **Test 8.1 realise et fonctionnel**

### Test 8.2 — Ajouter un joueur a la whitelist

1. Dans la section "Add Player" :
   - Entrer le login d'un joueur connecte (ou un login quelconque).
2. Cliquer sur **Add to Whitelist**.
3. **Resultat attendu** :
   - Badge vert, code `whitelist_player_added`.
4. Ajouter un second joueur pour avoir au moins 2 entrees.

- [x] **Test 8.2 realise et fonctionnel (joueur 1)**
- [x] **Test 8.2 realise et fonctionnel (joueur 2)**

### Test 8.3 — Lister la whitelist

1. Dans la section "Whitelist", cliquer sur **Load List**.
2. **Resultat attendu** :
   - Un tableau s'affiche montrant chaque joueur whiteliste avec un badge vert "Allowed".
   - Le compteur affiche "Whitelisted (2 players)" (ou le nombre correct).
   - Le JSON brut est accessible via "Raw whitelist response".
   - Les joueurs ajoutes au test 8.2 apparaissent dans la liste.

- [x] **Test 8.3 realise et fonctionnel**

### Test 8.4 — Retirer un joueur de la whitelist

1. Dans la section "Remove Player" :
   - Entrer le login d'un joueur ajoute au test 8.2.
2. Cliquer sur le bouton rouge **Remove from Whitelist**.
3. Modale de confirmation.
4. Cliquer sur **Remove**.
5. **Resultat attendu** :
   - Badge vert, code `whitelist_player_removed`.
6. Re-cliquer **Load List** : le joueur n'apparait plus.
7. **Test d'erreur** : essayer de retirer un joueur qui n'est pas dans la whitelist — attendu : code `player_not_in_whitelist`.

- [x] **Test 8.4 realise et fonctionnel**
- [x] **Test 8.4 cas d'erreur verifie (joueur absent)**

### Test 8.5 — Synchroniser la whitelist

1. Cliquer sur **Sync Whitelist** dans la section "Status & Controls".
2. **Resultat attendu** :
   - Badge vert, code `whitelist_synced`.
   - La whitelist est synchronisee avec le runtime de ManiaControl.

- [x] **Test 8.5 realise et fonctionnel**

### Test 8.6 — Vider la whitelist

1. Dans la section "Clear All" (bordure rouge), cliquer sur **Clear Whitelist**.
2. Modale de confirmation : "Remove ALL players from the whitelist? This cannot be undone."
3. Cliquer sur **Clear All**.
4. **Resultat attendu** :
   - Badge vert, code `whitelist_cleaned`.
5. Re-cliquer **Load List** : la liste est vide ("Whitelist is empty.").

- [x] **Test 8.6 realise et fonctionnel**

### Test 8.7 — Desactiver la whitelist

1. Cliquer sur **Disable** dans la section "Status & Controls".
2. **Resultat attendu** :
   - Badge vert, code `whitelist_disabled`.
   - La whitelist n'est plus appliquee sur le serveur.

- [x] **Test 8.7 realise et fonctionnel**

---

## Partie 9 — P5 : Vote Management (5 tests)

Page UI : **Admin > Vote Policy** (`/admin/votes`)

### Test 9.1 — Annuler un vote actif

1. Ouvrir la page **Vote Management**.
2. Cliquer sur le bouton rouge **Cancel Active Vote**.
3. **Resultat attendu** :
   - S'il y a un vote en cours : badge vert, code `vote_cancelled`.
   - S'il n'y a pas de vote en cours : badge rouge avec un code comme `no_active_vote`.
4. Pour un test positif : lancer un vote in-game d'abord (un joueur tape `/skip` par exemple), puis cliquer Cancel avant la fin du vote.

- [x] **Test 9.1 realise — cas avec vote actif**
- [x] **Test 9.1 realise — cas sans vote actif (erreur attendue)**

### Test 9.2 — Modifier le ratio d'un vote

1. Dans la section "Set Vote Ratio" :
   - Entrer un nom de commande dans le champ "Command" (ex: `skip`).
   - Deplacer le slider "Ratio" vers `0.75`.
   - Verifier que la valeur affichee en orange est bien `0.75`.
2. Cliquer sur **Set Ratio**.
3. **Resultat attendu** :
   - Badge vert, code `vote_ratio_set`.
   - A partir de maintenant, 75% des joueurs doivent voter pour que la commande `/skip` passe.
4. Tester avec d'autres valeurs :
   - Ratio `0.00` : le vote passe toujours.
   - Ratio `1.00` : 100% des joueurs doivent voter.
   - Ratio `0.50` : majorite simple.
5. **Test d'erreur** : entrer une commande vide — le bouton est desactive.

- [x] **Test 9.2 realise et fonctionnel**
- [x] **Test 9.2 validation d'entree verifiee (bouton grise si commande vide)**

### Test 9.3 — Lancer un vote personnalise

1. Dans la section "Start Custom Vote" :
   - Entrer un index de vote (ex: `0`).
2. Cliquer sur **Start Custom Vote**.
3. **Resultat attendu** :
   - Badge vert si l'index est valide et qu'un vote personnalise est configure : code `custom_vote_started`.
   - Badge rouge si l'index n'existe pas : code d'erreur.
4. In-game : un vote apparait pour les joueurs.

- [x] **Test 9.3 realise et fonctionnel**

### Test 9.4 — Lire la politique de vote

1. Dans la section "Vote Policy", cliquer sur **Get Policy**.
2. **Resultat attendu** :
   - Badge vert dans l'historique, code comme `vote_policy_status`.
   - Le bouton de mode correspondant a la politique actuelle se selectionne automatiquement (default, strict, lenient, ou disabled).
   - Le JSON de reponse apparait sous la section.
   - Le badge "Selected:" affiche la politique en cours.

- [x] **Test 9.4 realise et fonctionnel**

### Test 9.5 — Modifier la politique de vote

1. Cliquer sur le bouton **strict** dans la grille de modes (il devient orange).
2. Cliquer sur **Set Policy**.
3. **Resultat attendu** :
   - Badge vert, code `vote_policy_set`.
   - La politique est maintenant "strict" sur le serveur.
4. Changer pour **lenient** (cyan), cliquer Set Policy. Verifier que ca passe.
5. Changer pour **disabled** (rouge), cliquer Set Policy. Verifier.
6. Remettre sur **default** (gris) pour nettoyer. Verifier.
7. Re-cliquer **Get Policy** pour confirmer que la derniere politique sauvee est bien celle attendue.

- [x] **Test 9.5 — mode strict applique**
- [x] **Test 9.5 — mode lenient applique**
- [x] **Test 9.5 — mode disabled applique**
- [x] **Test 9.5 — mode default applique**
- [x] **Test 9.5 — Get Policy confirme la derniere valeur**

---

## Partie 10 — Tests transversaux

### Test 10.1 — Toutes les pages sans serveur selectionne

1. Dans la sidebar, choisir "— Select server —" (pas de serveur selectionne).
2. Visiter chaque page admin une par une :
   - `/admin/maps`
   - `/admin/warmup-pause`
   - `/admin/match`
   - `/admin/veto`
   - `/admin/players`
   - `/admin/teams`
   - `/admin/auth`
   - `/admin/whitelist`
   - `/admin/votes`
3. **Resultat attendu pour chaque page** : un bloc "Empty State" avec le message "No server selected" et le sous-titre "Select a server from the sidebar to use admin controls."

- [ ] **Test 10.1 — /admin/maps affiche Empty State**
- [ ] **Test 10.1 — /admin/warmup-pause affiche Empty State**
- [ ] **Test 10.1 — /admin/match affiche Empty State**
- [ ] **Test 10.1 — /admin/veto affiche Empty State**
- [ ] **Test 10.1 — /admin/players affiche Empty State**
- [ ] **Test 10.1 — /admin/teams affiche Empty State**
- [ ] **Test 10.1 — /admin/auth affiche Empty State**
- [ ] **Test 10.1 — /admin/whitelist affiche Empty State**
- [ ] **Test 10.1 — /admin/votes affiche Empty State**

### Test 10.2 — Reponse quand le socket est inaccessible

Si le serveur de jeu est eteint mais que l'API et l'UI tournent :

1. Selectionner un serveur enregistre.
2. Aller sur **Map Control** et cliquer **Skip to Next Map**.
3. **Resultat attendu** : badge rouge dans l'historique, avec un message d'erreur de type "502" ou "Socket connection failed" ou "Bad Gateway".
4. Repeter sur quelques autres pages admin (Warmup, Match Config, etc.).
5. Sur **Match Config**, les sections de lecture (Best-of, Maps Score, Round Score) affichent un badge rouge "Socket Unavailable" avec un bouton "Retry".

- [ ] **Test 10.2 — Map Control retourne une erreur propre**
- [ ] **Test 10.2 — Warmup/Pause retourne une erreur propre**
- [ ] **Test 10.2 — Match Config affiche "Socket Unavailable"**

### Test 10.3 — Verification de l'historique des reponses

Sur n'importe quelle page admin :

1. Effectuer 3-4 actions differentes.
2. Verifier que le panneau "Response History" (colonne de droite) :
   - Affiche les entrees dans l'ordre chronologique inverse (la plus recente en haut).
   - Chaque entree montre : un badge Success/Failed, le nom de l'action, l'heure, le code de reponse en orange, et le message.
   - Le toggle "Raw response" sous chaque entree deroule le JSON complet.
3. Apres 10 actions, verifier que seules les 10 dernieres sont affichees (pas de scroll infini).

- [ ] **Test 10.3 — ordre chronologique inverse correct**
- [ ] **Test 10.3 — badge, code, message affiches**
- [ ] **Test 10.3 — JSON brut accessible**
- [ ] **Test 10.3 — limite a 10 entrees**

### Test 10.4 — Verification du selecteur de serveur

1. Enregistrer un second serveur (via **Register Server**) avec un login different.
2. Selectionner le premier serveur, aller sur **Map Control**, faire une action.
3. Changer pour le second serveur dans le dropdown.
4. Verifier que :
   - Le nom du serveur en haut de la page change.
   - L'historique des reponses est vide (reset par le changement de serveur ou conserve selon l'implementation).
   - Les actions sont maintenant envoyees au nouveau serveur.

- [ ] **Test 10.4 realise et fonctionnel**

---

## Recap : matrice de couverture

| # | Fonctionnalite | Page UI | Priorite | Tests | Valide |
|---|---|---|---|---|---|
| 1.1-1.6 | Map Control (skip, restart, jump, queue, add, remove) | `/admin/maps` | P3 | 6 | - [x] |
| 2.1-2.4 | Warmup & Pause (extend, end warmup, pause, resume) | `/admin/warmup-pause` | P3 | 4 | - [x] |
| 3.1-3.6 | Match Config (best-of, maps score, round score — lecture + ecriture) | `/admin/match` | P3 | 6 | - [x] |
| 4.1-4.5 | Veto / Draft (status, start, action, cancel, matchmaking) | `/admin/veto` | P4 | 5 | - [x] |
| 5.1-5.3 | Player Management (force team, spec, play) | `/admin/players` | P4 | 3 | - [x] |
| 6.1-6.5 | Team Control (policy get/set, roster assign/unassign/list) | `/admin/teams` | P4 | 5 | - [x] |
| 7.1-7.2 | Auth Management (grant, revoke) | `/admin/auth` | P5 | 2 | - [x] |
| 8.1-8.7 | Whitelist Management (enable, add, list, remove, sync, clean, disable) | `/admin/whitelist` | P5 | 7 | - [x] |
| 9.1-9.5 | Vote Management (cancel, ratio, custom, policy get/set) | `/admin/votes` | P5 | 5 | - [x] |
| 10.1-10.4 | Tests transversaux (empty state, socket down, historique, multi-serveur) | Toutes | — | 4 | - [ ] |
| **Total** | | | | **47 tests** | |

---

## Checklist finale

- [ ] Les 9 pages admin s'affichent sans erreur de console JS
- [ ] Le serveur de jeu reagit a chaque commande (verifier in-game)
- [ ] Les reponses JSON contiennent les bons codes (`success: true/false`, codes coherents)
- [ ] Les modales de confirmation fonctionnent (confirmer + annuler)
- [ ] Les champs de saisie valident les entrees (boutons desactives si champs vides, valeurs invalides)
- [ ] Le panneau Response History affiche les resultats dans le bon ordre
- [ ] Le veto timeline (page Veto/Draft) trace correctement les bans et picks avec les bonnes couleurs
- [ ] Les tests d'erreur retournent des codes d'erreur propres (pas de crash, pas de page blanche)
- [ ] **Toutes les parties (1-10) sont validees dans la matrice ci-dessus**
