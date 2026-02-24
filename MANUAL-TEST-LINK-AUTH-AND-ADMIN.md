# Manual Test Plan - Liaison N serveurs ShootMania <-> Pixel Control Server

Ce guide te donne un plan manuel complet pour verifier la liaison plugin<->server, les droits chat admin/non-admin, et les scenarios simulateur.

## 1) Objectif de validation

Verifier de bout en bout que:

- un serveur SM peut etre enregistre et lie au backend,
- un token de liaison est emis et valide,
- le plugin accepte la configuration de liaison uniquement via super/master admin,
- les commandes server-scoped sont rejetees si l'auth est absente/invalide,
- les commandes server-scoped passent quand l'auth est valide,
- la separation multi-serveurs (N serveurs) est respectee.

## 2) Prerequis

- Docker Desktop actif.
- Stack locale disponible dans `pixel-sm-server/`.
- Backend NestJS disponible dans `pixel-control-server/`.
- Compte joueur ShootMania avec droits superadmin/masteradmin ManiaControl (pour les tests chat admin).
- Compte joueur ShootMania sans droits admin (pour les tests refus).
- Node 22.18+ recommande pour les commandes backend.

## 3) Variables utilisees dans les commandes

Remplace les valeurs si besoin:

```bash
export API_BASE_URL="http://127.0.0.1:8080"
export SERVER_LOGIN="<dedicated_login_sm_server>"
export SERVER_LOGIN_B="<dedicated_login_sm_server_b>"
```

`SERVER_LOGIN` doit correspondre au login dedie du serveur SM (celui utilise par le runtime/plugin).

## 4) Demarrage des services

### 4.1 Backend Pixel Control Server

Dans un terminal:

```bash
cd pixel-control-server
nvm use 22.18.0
npm run start:dev
```

### 4.2 Stack ShootMania locale

Dans un second terminal:

```bash
cd pixel-sm-server
docker compose up -d --build
```

Depuis la racine du repo, synchronise le plugin:

```bash
bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh
```

Optionnel (mais recommande):

```bash
bash pixel-sm-server/scripts/validate-dev-stack-launch.sh
```

## 5) Verifications API de liaison (register/access/token/auth-state)

### 5.1 Access avant enregistrement

```bash
curl -sS "$API_BASE_URL/v1/servers/$SERVER_LOGIN/link/access"
```

Attendu:

- `data.access.allowed=false`
- `data.access.reason=server_not_registered` (si serveur jamais enregistre)

### 5.2 Enregistrement du serveur

```bash
curl -sS -X PUT "$API_BASE_URL/v1/servers/$SERVER_LOGIN/link/registration" \
  -H "Content-Type: application/json" \
  -d '{"actor":"manual-test","source":"manual"}'
```

Attendu:

- `data.registration_status=registered`
- `data.server_login` normalise en lowercase

### 5.3 Generation/rotation de token

```bash
TOKEN_RESPONSE="$(curl -sS -X POST "$API_BASE_URL/v1/servers/$SERVER_LOGIN/link/token" \
  -H "Content-Type: application/json" \
  -d '{"actor":"manual-test","source":"manual"}')"

echo "$TOKEN_RESPONSE"
export LINK_TOKEN="$(printf '%s' "$TOKEN_RESPONSE" | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["link_token"])')"
```

Attendu:

- `data.link_token` present (retourne une seule fois)
- `data.token_fingerprint_masked` present
- `LINK_TOKEN` exporte pour les tests suivants

### 5.4 Auth-state

```bash
curl -sS "$API_BASE_URL/v1/servers/$SERVER_LOGIN/link/auth-state"
```

Attendu:

- `data.registration_status=registered`
- `data.linked=true`
- metadonnees token (`token_id`, `token_fingerprint_masked`, `issued_at`)

## 6) Tests chat in-game (admin super/master)

Connecte-toi sur le serveur ShootMania avec un compte superadmin/masteradmin ManiaControl.

### 6.1 Verification surface admin

Dans le chat:

```text
//pcadmin help
```

Attendu: la commande repond et liste les actions.

### 6.2 Statut liaison avant config explicite

```text
//pcadmin server.link.status
```

Attendu: statut affiche (`linked` ou `not_linked`) avec fingerprint masque, jamais le token brut.

### 6.3 Configuration liaison depuis le chat admin

```text
//pcadmin server.link.set base_url=http://127.0.0.1:8080 link_token=<TON_LINK_TOKEN>
```

Puis:

```text
//pcadmin server.link.status
```

Attendu:

- commande `server.link.set` reussit,
- `server.link.status` montre `linked`,
- la sortie n'affiche pas le token en clair (fingerprint masque seulement).

## 7) Tests chat in-game (joueur non-admin)

Connecte-toi avec un compte sans droits super/master admin.

Commande test:

```text
//pcadmin server.link.set base_url=http://127.0.0.1:8080 link_token=should-fail
```

Attendu:

- refus deterministe (`not allowed` / unauthorized),
- aucune modification effective de la liaison.

Optionnel:

```text
//pcadmin map.skip
```

Attendu: refus si le joueur n'a pas les droits plugin requis.

## 8) Tests simulateur (matrix link auth)

Depuis la racine du repo:

### 8.1 Cas missing

```bash
bash pixel-sm-server/scripts/simulate-admin-control-payloads.sh matrix link_auth_case=missing
```

Attendu dans les artefacts:

- code `link_auth_missing`

### 8.2 Cas invalid

```bash
bash pixel-sm-server/scripts/simulate-admin-control-payloads.sh matrix \
  link_auth_case=invalid \
  link_server_login="$SERVER_LOGIN" \
  link_token=invalid-token
```

Attendu:

- code `link_auth_invalid`

### 8.3 Cas mismatch

```bash
bash pixel-sm-server/scripts/simulate-admin-control-payloads.sh matrix \
  link_auth_case=mismatch \
  link_server_login="$SERVER_LOGIN" \
  link_token="$LINK_TOKEN"
```

Attendu:

- code `link_server_mismatch`

### 8.4 Cas valid

```bash
bash pixel-sm-server/scripts/simulate-admin-control-payloads.sh matrix \
  link_auth_case=valid \
  link_server_login="$SERVER_LOGIN" \
  link_token="$LINK_TOKEN"
```

Attendu:

- pas de code `link_auth_missing|link_auth_invalid|link_server_mismatch|admin_command_unauthorized`,
- `matrix-validation.json` avec `overall_passed=true`.

## 9) Tests API control write avec headers link_bearer

Endpoint d'exemple:

```bash
export CONTROL_URL="$API_BASE_URL/v1/servers/$SERVER_LOGIN/control/player-eligibility-policy"
```

Payload:

```bash
export CONTROL_PAYLOAD='{"actor":"manual-test","source":"manual","policy_enabled":true,"allowed_player_logins":["alpha"]}'
```

### 9.1 Missing auth

```bash
curl -sS -X PUT "$CONTROL_URL" -H "Content-Type: application/json" -d "$CONTROL_PAYLOAD"
```

Attendu: `error.code=link_auth_missing`.

### 9.2 Invalid mode

```bash
curl -sS -X PUT "$CONTROL_URL" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -H "X-Pixel-Auth-Mode: bearer_static" \
  -H "Authorization: Bearer $LINK_TOKEN" \
  -d "$CONTROL_PAYLOAD"
```

Attendu: `error.code=link_auth_invalid`.

### 9.3 Mismatch server_login

```bash
curl -sS -X PUT "$CONTROL_URL" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: ${SERVER_LOGIN}-other" \
  -H "X-Pixel-Auth-Mode: link_bearer" \
  -H "Authorization: Bearer $LINK_TOKEN" \
  -d "$CONTROL_PAYLOAD"
```

Attendu: `error.code=link_server_mismatch`.

### 9.4 Invalid token

```bash
curl -sS -X PUT "$CONTROL_URL" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -H "X-Pixel-Auth-Mode: link_bearer" \
  -H "Authorization: Bearer invalid-token" \
  -d "$CONTROL_PAYLOAD"
```

Attendu: `error.code=link_auth_invalid`.

### 9.5 Valid auth

```bash
curl -sS -X PUT "$CONTROL_URL" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -H "X-Pixel-Auth-Mode: link_bearer" \
  -H "Authorization: Bearer $LINK_TOKEN" \
  -d "$CONTROL_PAYLOAD"
```

Attendu: succes (`200`) avec mutation `applied` ou `noop`.

## 10) Test multi-serveurs (N) - isolation des tokens

Repete la sequence de liaison pour un deuxieme serveur (`SERVER_LOGIN_B`):

```bash
curl -sS -X PUT "$API_BASE_URL/v1/servers/$SERVER_LOGIN_B/link/registration" \
  -H "Content-Type: application/json" \
  -d '{"actor":"manual-test","source":"manual"}'

TOKEN_B_RESPONSE="$(curl -sS -X POST "$API_BASE_URL/v1/servers/$SERVER_LOGIN_B/link/token" \
  -H "Content-Type: application/json" \
  -d '{"actor":"manual-test","source":"manual"}')"

export LINK_TOKEN_B="$(printf '%s' "$TOKEN_B_RESPONSE" | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["link_token"])')"
```

Verifie l'isolation:

- appel control de `SERVER_LOGIN_B` avec `LINK_TOKEN` (serveur A) => doit echouer (`link_auth_invalid`),
- appel control de `SERVER_LOGIN_B` avec `LINK_TOKEN_B` => doit passer.

## 11) Test simulateur commande unitaire (optionnel mais utile)

```bash
bash pixel-sm-server/scripts/simulate-admin-control-payloads.sh execute map.skip \
  link_auth_case=valid \
  link_server_login="$SERVER_LOGIN" \
  link_token="$LINK_TOKEN"
```

Attendu:

- reponse `success=true` (si l'action est applicable dans le contexte runtime courant).

## 12) Verification logs/artefacts

- Simulateur: `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/`
  - verifier `summary.md` et `matrix-validation.json`.
- Hot sync plugin: `pixel-sm-server/logs/dev/dev-plugin-hot-sync-*.log`
- Backend: verifier les codes de rejet dans les reponses HTTP.

## 13) Checklist finale (a cocher)

- [ ] `link/access` retourne `server_not_registered` avant registration
- [ ] `link/registration` retourne `registered`
- [ ] `link/token` retourne un `link_token` + fingerprint masque
- [ ] `link/auth-state` retourne `linked=true`
- [ ] `//pcadmin server.link.set` reussit en super/master admin
- [ ] `//pcadmin server.link.set` echoue en non-admin
- [ ] Simulateur `missing` => `link_auth_missing`
- [ ] Simulateur `invalid` => `link_auth_invalid`
- [ ] Simulateur `mismatch` => `link_server_mismatch`
- [ ] Simulateur `valid` => pass (`overall_passed=true`)
- [ ] API control write missing/invalid/mismatch/valid retourne les codes attendus
- [ ] Multi-serveurs: token A invalide pour serveur B, token B valide pour B

## 14) Depannage rapide

- Si le simulateur echoue avec socket refusee (`127.0.0.1:31501`):
  - verifier stack `shootmania` up,
  - relancer `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`,
  - rerun du simulateur.
- Si `//pcadmin` ne repond pas:
  - verifier que le plugin est charge,
  - verifier la config runtime admin-control de l'environnement de test,
  - verifier via `simulate-admin-control-payloads.sh list-actions`.
- Si les matrices auth se marchent dessus:
  - eviter les runs paralleles la meme seconde,
  - utiliser des `PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT` distincts.
