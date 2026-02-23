# Checklist validation serveur (Pixel SM)

Objectif: verifier rapidement que la stack `shootmania + maniacontrol + plugin PixelControl` fonctionne correctement.

> Tous les commandes ci-dessous sont a lancer depuis `pixel-sm-server/`.

## 1) Demarrage et sanity check

```bash
cp .env.example .env
bash scripts/import-reference-runtime.sh
docker compose up -d --build
docker compose ps
docker compose logs --tail=120 shootmania
```

Resultat attendu:
- Le service `shootmania` est `healthy`.
- Les logs montrent le lancement de ManiaControl.
- Le plugin PixelControl est charge (marker dans `runtime/server/ManiaControl/ManiaControl.log`).

## 2) Tests automatiques (sans client de jeu)

```bash
bash scripts/validate-dev-stack-launch.sh
bash scripts/validate-mode-launch-matrix.sh
bash scripts/simulate-admin-control-payloads.sh matrix
bash scripts/test-automated-suite.sh --modes elite,joust
```

Resultat attendu:
- Chaque script sort avec code `0`.
- Pas d'erreur bloquante dans le resume final (`suite-summary.json` pour la suite automatee).

## 3) Preparation des policies a tester en manuel

Remplace `<LOGIN_TEST>` par le login reel du joueur non-admin que tu veux tester.

```bash
bash scripts/simulate-admin-control-payloads.sh execute whitelist.clean
bash scripts/simulate-admin-control-payloads.sh execute whitelist.enable
bash scripts/simulate-admin-control-payloads.sh execute vote.policy.set mode=cancel_non_admin_vote_on_callback
bash scripts/simulate-admin-control-payloads.sh execute team.policy.set policy_enabled=true switch_lock_enabled=true
bash scripts/simulate-admin-control-payloads.sh execute team.roster.assign target_login=<LOGIN_TEST> team=blue
```

## 4) Tests manuels obligatoires (avec vrais comptes)

### T1 - Whitelist deny / allow
1. Garde `<LOGIN_TEST>` hors whitelist.
2. Depuis le client `<LOGIN_TEST>`, tente de rejoindre le serveur.
3. Attendu: login refuse ou kick.
4. Ajoute ensuite ce login dans la whitelist:

```bash
bash scripts/simulate-admin-control-payloads.sh execute whitelist.add target_login=<LOGIN_TEST>
```

5. Rejoin avec `<LOGIN_TEST>`.
6. Attendu: connexion autorisee et stable.

### T2 - Vote policy non-admin
1. Depuis un compte non-admin, lance un vote en jeu (UI/chat vote).
2. Attendu: le vote est annule rapidement (non-admin ne peut pas maintenir le vote).

### T3 - Team lock en Elite
1. Avec `<LOGIN_TEST>` assigne a `blue`, essaye de switch sur l'autre equipe.
2. Attendu: correction automatique / switch bloque, le joueur reste sur l'equipe assignee.

### T4 - Team lock sur un 2eme mode team
1. Refaire T3 sur un autre mode team disponible (Joust, Siege ou Battle).
2. Attendu: meme comportement de verrouillage d'equipe.

## 5) Collecte des preuves (recommande)

```bash
mkdir -p logs/manual/latest-server-check
docker compose logs --no-color shootmania > logs/manual/latest-server-check/shootmania.log
cp runtime/server/ManiaControl/ManiaControl.log logs/manual/latest-server-check/maniacontrol.log
```

Optionnel: utiliser les templates deja prets dans
`logs/manual/team-vote-whitelist-20260223-145834/` pour remplir une preuve detaillee scenario par scenario.

## 6) Nettoyage / retour a l'etat neutre

```bash
bash scripts/simulate-admin-control-payloads.sh execute whitelist.disable
bash scripts/simulate-admin-control-payloads.sh execute whitelist.clean
bash scripts/simulate-admin-control-payloads.sh execute team.roster.unassign target_login=<LOGIN_TEST>
bash scripts/simulate-admin-control-payloads.sh execute team.policy.set policy_enabled=false switch_lock_enabled=false
bash scripts/simulate-admin-control-payloads.sh execute vote.policy.set mode=disable_callvotes_and_use_admin_actions
```

Si tu veux arreter la stack:

```bash
docker compose down
```
