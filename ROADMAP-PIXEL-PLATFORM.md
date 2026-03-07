# Pixel MatchMaking — Roadmap des fonctionnalités

Document de référence pour la plateforme web de matchmaking compétitif ShootMania Elite 3v3. Chaque fonctionnalité est rattachée à un domaine, priorisée, et débouchera sur un fichier de PLAN dédié.

## Stack technique

| Couche | Technologie |
|---|---|
| Framework | Next.js 16.1 (App Router, Turbopack), TypeScript strict, React 19.2 |
| UI | `@pixel-series/design-system-neumorphic` (import local monorepo) |
| Styling | Tailwind CSS v3 (aligné sur les tokens du design system) |
| API client | SDK auto-généré via Swagger CodeGen depuis le Swagger NestJS de `pixel-control-server` |
| Auth | SSO ManiaPlanet (OAuth2 Authorization Code) via NextAuth.js, custom provider |
| Base de données | PostgreSQL (Prisma ORM) — **base dédiée**, séparée de `pixel-control-server` |
| Temps réel | WebSocket (Socket.io) pour matchmaking, veto, scores live |
| i18n | `next-intl` — FR (par défaut) + EN |
| Déploiement | VPS / Docker (WebSocket incompatible serverless Vercel) |
| Cible | Desktop uniquement (pas de responsive mobile) |

## Légende

### Priorités

| Priorité | Signification |
|---|---|
| **P0** | Fondation — rien ne fonctionne sans ça |
| **P1** | Coeur de l'expérience — le produit minimum jouable |
| **P2** | Expérience complète — ce qui rend le site utile au quotidien |
| **P3** | Administration et outils — gestion, modération, ops |
| **P4** | Social et communauté — ce qui fait revenir les joueurs |
| **P5** | Polish et extras — confort, optimisations, nice-to-have |

### Statuts

| Statut | Signification |
|---|---|
| `Not Started` | Pas encore commencé |
| `In Progress` | En cours de développement |
| `Done` | Terminé et testé |
| `Blocked` | Bloqué par une dépendance |

---

## Décisions architecturales actées

Ces choix ont été validés et s'appliquent à l'ensemble du projet.

| Sujet | Décision | Justification |
|---|---|---|
| **Format de match** | **BO1 uniquement** (3v3 Elite) | Matchmaking rapide, une seule map par match, pas de transition multi-map |
| **Base de données** | **PostgreSQL dédiée** (séparée de pixel-control-server) | Découplage propre — comptes, ELO, matchs, stats sont du domaine de la plateforme, pas du serveur de télémétrie |
| **Communication avec pixel-control-server** | **Polling régulier** | La plateforme poll les endpoints GET de pixel-control-server pour récupérer les résultats de match et l'état des serveurs |
| **Logique de veto** | **Hybride** | La plateforme gère sa propre logique de veto (vote majoritaire, timer). Le résultat est ensuite appliqué au serveur SM via les endpoints admin existants (POST /maps/queue, PUT /match/best-of, etc.) |
| **Sélection de map (BO1)** | **Vote majoritaire** | Les 6 joueurs votent pour une map (30-45s). Majorité simple gagne. En cas d'égalité, tirage au sort parmi les maps à égalité |
| **Algorithme ELO** | **Elo classique** avec K-factor adaptatif | K-factor élevé pour les nouveaux joueurs (convergence rapide), bas pour les vétérans. Simple et éprouvé |
| **Composition d'équipes** | **Auto-balance par la plateforme** | L'algorithme répartit les 6 joueurs en 2 équipes de 3 pour que l'ELO moyen soit le plus équilibré possible. Les groupes pré-formés restent ensemble |
| **Pénalités dodge/abandon** | **Cooldown progressif** | 5min → 15min → 30min → 1h → 24h. Reset après X matchs joués sans dodge. Pas de perte ELO pour un dodge, seulement pour un abandon |
| **Pool de maps** | **Géré par l'admin plateforme** | L'admin définit manuellement le pool (nom, UID). Il s'assure que les maps sont installées sur tous les serveurs |
| **Images des maps** | **Auto depuis ManiaExchange** | Récupération automatique de la thumbnail via le MX ID. Placeholder si indisponible |
| **Accès au site** | **Compte ManiaPlanet obligatoire** | Pas de visiteurs sans compte. SSO MP est le seul moyen de se connecter |
| **Team-up** | **Alliance mutuelle par login** | Un joueur entre le login MP d'un allié dans un input sur /play. L'alliance ne s'active que quand les deux joueurs se sont ajoutés mutuellement. Statut "pending" (orange) tant que l'alliance est unilatérale. Groupes de 1, 2 ou 3 |
| **Queue** | **Unique et mixte** | Solos et teams dans la même queue. Un solo peut affronter un trio pré-formé. L'ELO équilibre |
| **Team-up fill** | **Solo comble le slot** | Un joueur solo de la queue est ajouté au duo pour former l'équipe de 3 |
| **Serveurs au lancement** | **1-3 serveurs** (même datacenter EU) | Alpha/beta. Pas de multi-région au lancement. Le modèle de données prévoit un champ `region` pour le futur |
| **Rejoindre le serveur** | **Lien `maniaplanet://` cliquable** | Bouton "Rejoindre le serveur" qui ouvre directement le jeu sur le bon serveur |
| **Reconnexion mid-match** | **Autorisée avec timer** | Le joueur a 3 minutes pour revenir. Le match continue en 2v3 en attendant. Timeout → abandon + pénalité |
| **Spectateurs** | **Page match publique** | N'importe quel utilisateur connecté peut voir le score live d'un match en cours sur `/match/:id` |
| **Surrender** | **Pas de surrender** | Le match se joue jusqu'au bout. ShootMania Elite en BO1 est court |
| **Provisioning serveurs** | **Manuel** | Les serveurs sont provisionnés manuellement. L'admin les ajoute/retire du pool via le panel admin |
| **Langue** | **FR + EN** | Français par défaut, anglais disponible via i18n |
| **Saisons** | **Post-launch (P3+)** | Classement permanent au lancement. Les saisons arrivent quand la base de joueurs est stable |
| **Tournois** | **Nice-to-have (P4+)** | Le composant Bracket du DS existe, mais les tournois ne sont pas prioritaires |
| **Post-match** | **Écran résultat dédié** | Après la fin du match, redirection vers une page de résultat : Victoire/Défaite, ELO delta, stats, bouton "Rejouer" |
| **Reports/Signalement** | **Pas prévu** | Communauté petite, modération manuelle via Discord/admin |
| **Discord** | **Nice-to-have (P5)** | Webhook Discord pour poster les résultats de match. Pas prioritaire |
| **Cohérence visuelle** | **Tout suit le style du design system** | Les composants du DS (`@pixel-series/design-system-neumorphic`) sont utilisés en priorité. Tout composant custom créé pour la plateforme doit respecter les mêmes conventions visuelles : ombres neumorphiques, 0px border-radius, palette du DS (primary `#2C12D9`), typographies Karantina (titres) / Poppins (corps), thème sombre par défaut. On ne mélange pas les styles. |

---

## Table des domaines

| # | Domaine | Priorité principale | Nb fonctionnalités |
|---|---|---|---|
| D0 | [Fondation technique](#d0--fondation-technique) | P0 | 9 |
| D1 | [Authentification & Identité](#d1--authentification--identité) | P0 | 8 |
| D2 | [Profil joueur](#d2--profil-joueur) | P1–P4 | 7 |
| D3 | [Système ELO & Classement](#d3--système-elo--classement) | P1–P4 | 9 |
| D4 | [Matchmaking](#d4--matchmaking) | P0–P3 | 12 |
| D5 | [Veto & Sélection de maps](#d5--veto--sélection-de-maps) | P1 | 7 |
| D6 | [Orchestration serveur](#d6--orchestration-serveur) | P1–P2 | 9 |
| D7 | [Match — suivi & résultats](#d7--match--suivi--résultats) | P1–P4 | 11 |
| D8 | [Statistiques & Analytics](#d8--statistiques--analytics) | P2–P5 | 10 |
| D9 | [Social & Team-up](#d9--social--team-up) | P1–P4 | 8 |
| D10 | [Notifications & Temps réel](#d10--notifications--temps-réel) | P0–P5 | 8 |
| D11 | [Administration](#d11--administration) | P3–P5 | 12 |
| D12 | [Saisons & Compétitions](#d12--saisons--compétitions) | P3–P4 | 8 |
| D13 | [Pages publiques & SEO](#d13--pages-publiques--seo) | P2–P3 | 5 |
| D14 | [Infrastructure & DevOps](#d14--infrastructure--devops) | P3 | 7 |
| D15 | [Intégrations externes](#d15--intégrations-externes) | P5 | 3 |

---

# D0 — Fondation technique

Ce qu'on pose en premier pour que tout le reste puisse s'y brancher.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D0.1 | Scaffold Next.js 15 + TypeScript + App Router | P0 | `Not Started` | Créer le projet `pixel-matchmaking/` à la racine du monorepo. Structure `app/`, `src/lib/`, `src/components/`. |
| D0.2 | Intégration du design system | P0 | `Not Started` | Import local de `@pixel-series/design-system-neumorphic`. Configurer Tailwind v3 avec les tokens du DS (couleurs, ombres, typographie Karantina/Poppins). ThemeProvider à la racine du layout. Thème sombre par défaut. |
| D0.3 | Génération du SDK API via Swagger CodeGen | P0 | `Not Started` | Script npm qui récupère le `swagger.json` du serveur NestJS et génère un client TypeScript typé. Outil à évaluer : `orval` (hooks TanStack Query auto-générés) ou `openapi-typescript-codegen`. |
| D0.4 | Couche API client (wrapper SDK) | P0 | `Not Started` | Wrapper autour du SDK généré : injection automatique des headers d'auth, gestion d'erreurs centralisée, retry basique, base URL configurable par env. |
| D0.5 | Configuration environnement | P0 | `Not Started` | Variables d'env : `NEXT_PUBLIC_API_URL`, `MANIAPLANET_CLIENT_ID`, `MANIAPLANET_CLIENT_SECRET`, `NEXTAUTH_SECRET`, `DATABASE_URL`. Fichiers `.env.local` / `.env.example`. |
| D0.6 | Schéma Prisma (base plateforme) | P0 | `Not Started` | Base PostgreSQL dédiée. Tables : `User`, `Match`, `MatchPlayer`, `EloHistory`, `MapPool`, `Server`, `Alliance`, `DodgePenalty`. Séparé du schéma `pixel-control-server`. |
| D0.7 | Layout global et navigation | P0 | `Not Started` | Layout racine avec le `TopNav` du design system. Navigation : Accueil, Jouer, Classement, Profil, Admin (si rôle). Thème sombre par défaut. |
| D0.8 | Internationalisation (i18n) | P0 | `Not Started` | Setup `next-intl` : français par défaut, anglais disponible. Fichiers de traduction `messages/fr.json` / `messages/en.json`. Sélecteur de langue dans le layout. |
| D0.9 | Pages d'erreur et fallbacks | P1 | `Not Started` | Pages 404, 500, loading states avec `Skeleton` du DS. Error boundaries React. |

---

# D1 — Authentification & Identité

La connexion passe par ManiaPlanet. Pas de système de compte maison — on s'appuie entièrement sur le SSO.

**Référence** : `ressources/oauth2-maniaplanet/` (provider PHP League OAuth2).

**Endpoints ManiaPlanet** :
- Autorisation : `https://www.maniaplanet.com/login/oauth2/authorize`
- Token : `https://www.maniaplanet.com/login/oauth2/access_token`
- Profil : `https://www.maniaplanet.com/webservices/me`
- Scopes : `basic` (login, nickname, path). Scope `maps` disponible mais pas utilisé au lancement.
- Séparateur de scopes : espace

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D1.1 | Provider NextAuth.js custom ManiaPlanet | P0 | `Not Started` | Provider OAuth2 custom pour NextAuth.js reproduisant le flow Authorization Code. Scope : `basic`. À évaluer : NextAuth v5 (Auth.js) pour meilleur support App Router. |
| D1.2 | Flow de connexion complet | P0 | `Not Started` | Bouton "Se connecter avec ManiaPlanet" → redirect OAuth2 → callback → création/mise à jour du user en base → session active. Page `/auth/signin`. |
| D1.3 | Gestion de session (JWT + DB) | P0 | `Not Started` | Session NextAuth avec strategy JWT + stockage user en base (Prisma). Cookie sécurisé httpOnly. |
| D1.4 | Refresh token automatique | P1 | `Not Started` | Renouvellement automatique du token ManiaPlanet avant expiration. Token expiré + refresh échoué → re-login. |
| D1.5 | Middleware de routes protégées | P0 | `Not Started` | Middleware Next.js qui redirige vers `/auth/signin` pour les routes protégées (`/play`, `/me`, `/admin`). Pages publiques : landing, leaderboard, profils publics. |
| D1.6 | Synchronisation du profil ManiaPlanet | P1 | `Not Started` | À chaque connexion, récupérer et mettre à jour `login`, `nickname`, `path` depuis `/webservices/me`. Stocker en base. |
| D1.7 | Déconnexion propre | P1 | `Not Started` | Suppression de session côté serveur + côté client. Redirect vers la landing. |
| D1.8 | Rôles et permissions utilisateur | P1 | `Not Started` | Rôles : `player` (défaut), `moderator`, `admin`, `superadmin`. Stocké en base, vérifié côté serveur sur les routes sensibles. Distinct des auth levels ManiaControl (per-server). |

---

# D2 — Profil joueur

La vitrine du joueur. ELO, stats, historique, et info ManiaPlanet.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D2.1 | Page profil public (`/player/:login`) | P1 | `Not Started` | Accessible à tout utilisateur connecté. Nickname, zone, rang ELO, stats résumées, matchs récents. Composants `Card`, `Avatar`, `Badge` du DS. |
| D2.2 | Page "Mon profil" (`/me`) | P1 | `Not Started` | Version enrichie du profil pour le joueur connecté. Accès aux paramètres, stats détaillées, historique complet, alliances actives. |
| D2.3 | Carte de joueur visuelle | P2 | `Not Started` | Composant réutilisable "player card" : rang, ELO, win rate, stats clés. Utilisé dans le leaderboard, les résultats de match, les alliances. |
| D2.4 | Paramètres utilisateur (`/me/settings`) | P2 | `Not Started` | Préférences de langue (FR/EN), visibilité du profil. Pas de préférences de matchmaking (un seul mode BO1 3v3). |
| D2.5 | Zone géographique et drapeau | P2 | `Not Started` | Extraire le pays depuis le champ `path` de ManiaPlanet (ex: `World|Europe|France|Île-de-France`). Afficher le drapeau. |
| D2.6 | Badges et récompenses | P4 | `Not Started` | Système de badges affichés sur le profil : "Top 10 saison X", "100 victoires", "First blood king", etc. |
| D2.7 | Lien vers le profil ManiaPlanet | P5 | `Not Started` | Lien externe vers le profil officiel ManiaPlanet du joueur. |

---

# D3 — Système ELO & Classement

On attribue un score de compétence à chaque joueur et on classe tout le monde. ELO individuel uniquement (pas d'ELO d'équipe au lancement — les équipes sont éphémères en matchmaking).

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D3.1 | Modèle de données ELO | P1 | `Not Started` | Table `EloHistory` : `userId`, `matchId`, `eloBefore`, `eloAfter`, `delta`, `timestamp`. Champ `currentElo` sur le user. ELO initial : 1000. |
| D3.2 | Algorithme Elo classique | P1 | `Not Started` | Formule Elo standard. K-factor adaptatif : K=40 pendant le placement, K=20 après, K=10 pour les joueurs à haut ELO (>1800). Facteurs : résultat (win/loss), écart d'ELO moyen entre les deux équipes. |
| D3.3 | Matchs de placement | P1 | `Not Started` | Les 10 premiers matchs sont des matchs de calibration (K=40). Badge "Non classé" pendant le placement. Pas de rang visible tant que le placement n'est pas terminé. |
| D3.4 | Calcul post-match automatique | P1 | `Not Started` | Quand le résultat arrive (via polling de pixel-control-server), recalculer l'ELO des 6 joueurs et persister les deltas. L'ELO moyen de l'équipe adverse détermine le gain/perte. |
| D3.5 | Rangs visuels (divisions) | P2 | `Not Started` | Mapping ELO → rang visuel. Ex : Bronze (0–999), Silver (1000–1199), Gold (1200–1399), Platinum (1400–1599), Diamond (1600–1799), Champion (1800+). Icônes/couleurs par rang. |
| D3.6 | Page leaderboard (`/leaderboard`) | P1 | `Not Started` | Classement global paginé. Filtres : zone géographique. Colonnes : rang, joueur, ELO, win rate, matchs joués. Composants `Table` + `Pagination` du DS. |
| D3.7 | Graphique d'évolution ELO | P2 | `Not Started` | Courbe d'évolution de l'ELO dans le temps sur le profil joueur. Librairie de graphiques (Recharts ou Tremor). |
| D3.8 | Decay d'inactivité | P4 | `Not Started` | Perte progressive d'ELO après 14 jours sans match (ex: -15 ELO/semaine). Plafond de decay (pas en dessous du rang initial). Notification avant decay. |
| D3.9 | Reset saisonnier | P3 | `Not Started` | Compression soft de l'ELO vers la médiane à chaque nouvelle saison. Lié au domaine D12. |

---

# D4 — Matchmaking

Le flux principal : un joueur clique "Jouer", on lui trouve un match, il vote pour une map, rejoint le serveur, et joue.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D4.1 | Page "Jouer" (`/play`) | P0 | `Not Started` | Interface principale. En haut : input d'alliance (team-up D9.1). Bouton "Rechercher un match". Statut de la queue, temps d'attente, nombre de joueurs en file. Tous les alliés doivent être "prêts" pour lancer. |
| D4.2 | File d'attente (queue unique) | P0 | `Not Started` | Queue mixte : solos et teams dans la même file. Backend maintient la file ordonnée par ELO et temps d'attente. WebSocket pour les mises à jour de statut en temps réel. |
| D4.3 | Algorithme de matching | P1 | `Not Started` | Trouver 6 joueurs de niveau proche. Fenêtre ELO qui s'élargit avec le temps d'attente. Les groupes pré-formés (alliances) sont placés dans la même équipe. Le matching cherche une équipe adverse dont l'ELO moyen est proche. |
| D4.4 | Composition d'équipes (auto-balance) | P1 | `Not Started` | Répartir les 6 joueurs en 2 équipes de 3 pour que l'ELO moyen soit le plus équilibré possible. Contrainte : les alliés restent ensemble dans la même équipe. |
| D4.5 | Popup de confirmation (accept/decline) | P0 | `Not Started` | Match trouvé → chaque joueur a 30 secondes pour accepter. Si un joueur decline ou timeout → retour en queue pour les autres, pénalité pour le joueur qui a refusé. Compteur d'acceptations affiché en temps réel. Modal du DS. |
| D4.6 | Pénalités de dodge | P1 | `Not Started` | Cooldown progressif : 5min → 15min → 30min → 1h → 24h de ban de queue. Reset après X matchs joués sans dodge. Pas de perte d'ELO pour un dodge (seulement le cooldown). |
| D4.7 | Pénalités d'abandon | P1 | `Not Started` | Si un joueur quitte un match en cours et ne revient pas dans les 3 minutes (D7.9) : comptabilisé comme défaite pour l'ELO + cooldown progressif. Compteur d'abandons visible par l'admin. |
| D4.8 | Estimation du temps d'attente | P2 | `Not Started` | Calcul basé sur le nombre de joueurs en queue dans la tranche ELO. Affiché en temps réel sur `/play`. |
| D4.9 | Anti-snipe / délai de re-queue | P3 | `Not Started` | Cooldown de re-match contre le même adversaire (ex: 5 min). |
| D4.10 | Heures creuses : fenêtre ELO élargie | P4 | `Not Started` | Quand peu de joueurs sont en queue, élargir la fenêtre ELO plus agressivement. Seuil configurable par l'admin. |
| D4.11 | Statistiques de queue (admin) | P3 | `Not Started` | Métriques : temps d'attente moyen, taux d'acceptation, nombre de matchs/heure, distribution ELO de la queue. Dashboard admin D11. |
| D4.12 | Vérification de ban avant queue | P1 | `Not Started` | Vérifier que le joueur n'a pas de cooldown de dodge actif ni de ban admin avant de l'autoriser à entrer en queue. Message d'erreur explicite avec temps restant. |

### Flux matchmaking complet (BO1)

```
1. Joueur configure ses alliances (team-up) sur /play
2. Joueur (et ses alliés) cliquent "Rechercher"
3. → Entrée en queue (WebSocket: queue_joined)
4. → Algorithme cherche 6 joueurs compatibles (ELO + alliances)
5. → Match trouvé (WebSocket: match_found) → popup accept/decline (30s)
6. → Tous acceptent → phase de vote map (D5) (30-45s)
7. → Map sélectionnée → assignation serveur (D6)
8. → Serveur configuré + whitelist → bouton "Rejoindre" affiché (maniaplanet://)
9. → Match joué sur le serveur SM (BO1)
10. → Match terminé → écran résultat (D7) → calcul ELO (D3)
11. → Budget temps total : ~2-3 minutes entre "match trouvé" et "en jeu"
```

---

# D5 — Veto & Sélection de maps

Le veto se fait directement sur la plateforme, indépendamment du serveur SM. C'est un vote majoritaire simple en BO1.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D5.1 | Interface de vote intégrée | P1 | `Not Started` | Après l'acceptation du match, les 6 joueurs arrivent sur un écran de vote. Pool de maps affiché avec images (MX thumbnails). Timer visible (30-45s). Chaque joueur clique sur une map pour voter. |
| D5.2 | Logique de vote (backend plateforme) | P1 | `Not Started` | La plateforme gère elle-même la logique de vote : collecte des votes, timer, résolution (majorité simple, random en cas d'égalité). Indépendant de `pixel-control-server` / ManiaControl. |
| D5.3 | Pool de maps (admin-managed) | P1 | `Not Started` | L'admin définit le pool de maps actif dans la DB plateforme : nom, UID, MX ID (pour les images). Table `MapPool` avec `name`, `uid`, `mxId`, `active`, `addedAt`. |
| D5.4 | Images de maps (ManiaExchange) | P1 | `Not Started` | Récupération automatique des thumbnails depuis ManiaExchange via le MX ID. URL pattern : `https://sm.mania.exchange/maps/screenshot/normal/{mxId}` (à vérifier). Placeholder si indisponible. |
| D5.5 | Résultat du vote → config serveur | P1 | `Not Started` | La map gagnante est envoyée au serveur SM assigné via les endpoints admin existants (`POST /servers/:s/maps/jump` ou `POST /servers/:s/maps/queue`). |
| D5.6 | Timeout : vote aléatoire | P1 | `Not Started` | Si un joueur ne vote pas dans le temps imparti : vote aléatoire automatique. Le match n'est jamais annulé pour un timeout de veto. |
| D5.7 | Vote en temps réel (WebSocket) | P1 | `Not Started` | Chaque vote est diffusé en temps réel aux 6 joueurs : compteur par map mis à jour instantanément. Le votant voit sa sélection confirmée, les autres voient le décompte (sans savoir qui a voté quoi, ou avec — à décider). |

---

# D6 — Orchestration serveur

La plateforme connecte les joueurs aux serveurs ShootMania physiques. Les serveurs sont provisionnés manuellement et gérés via `pixel-control-server`.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D6.1 | Pool de serveurs (DB plateforme) | P1 | `Not Started` | Table `Server` : `id`, `serverLogin`, `displayName`, `address` (IP/hostname), `port`, `region` (pour le futur), `status` (available/in_match/offline/maintenance), `currentMatchId`. Serveurs ajoutés manuellement par l'admin. |
| D6.2 | Assignation automatique | P1 | `Not Started` | Quand le vote est terminé, la plateforme choisit un serveur dont le statut est `available`. Si aucun serveur libre → match annulé, joueurs remis en queue. |
| D6.3 | Configuration serveur pré-match | P1 | `Not Started` | Via l'API pixel-control-server : `PUT /match/best-of` (best_of=1), `POST /maps/jump` (map issue du vote). Reset des scores si nécessaire. |
| D6.4 | Whitelisting automatique | P1 | `Not Started` | `POST /whitelist/enable` → `POST /whitelist` × 6 (un par joueur, login MP). Seuls les 6 joueurs du match peuvent rejoindre le serveur. |
| D6.5 | Lien de connexion serveur | P1 | `Not Started` | Bouton "Rejoindre le serveur" qui ouvre `maniaplanet://#join=<server_address>`. Affiché sur la page match après configuration. |
| D6.6 | Timeout d'entrée sur serveur | P2 | `Not Started` | Les joueurs ont 3 minutes pour rejoindre le serveur. Détection via polling du heartbeat serveur (présence des joueurs). Timeout → pénalité d'abandon. |
| D6.7 | Libération de serveur post-match | P1 | `Not Started` | Match terminé → `DELETE /whitelist` (clean all) → `POST /whitelist/disable` → statut serveur remis à `available`. |
| D6.8 | Health check des serveurs | P2 | `Not Started` | Polling périodique du heartbeat de chaque serveur via `pixel-control-server`. Serveurs qui ne répondent plus → statut `offline`. |
| D6.9 | Multi-région (préparation) | P3 | `Not Started` | Ajouter le champ `region` au modèle serveur. Pas de logique de matching par région au lancement, mais le modèle est prêt. À terme : prioriser les serveurs proches des joueurs du match. |

---

# D7 — Match — suivi & résultats

Ce qui se passe pendant et après un match côté plateforme.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D7.1 | Page de match (`/match/:id`) | P1 | `Not Started` | Page dédiée à chaque match (publique pour tout utilisateur connecté). Avant : composition des équipes, vote, serveur. Pendant : score live. Après : résultat final, stats, ELO delta. |
| D7.2 | Score en temps réel | P2 | `Not Started` | Pendant le match, afficher le score des rounds en direct. Source : polling des événements lifecycle de pixel-control-server (round_end). Mis à jour toutes les 5-10 secondes. |
| D7.3 | Détection de fin de match | P1 | `Not Started` | Polling des événements lifecycle pour détecter le match_end. Déclenche automatiquement : calcul ELO, libération serveur, nettoyage whitelist. |
| D7.4 | Résultat post-match | P1 | `Not Started` | Récupérer le résultat final : équipe gagnante, score. Déclencher le calcul ELO (D3.4). Persister le résultat en base. |
| D7.5 | Écran de résultat dédié | P1 | `Not Started` | Après la fin du match, les joueurs voient : "Victoire" / "Défaite", score final, ELO delta (+25 / -18), stats du match, MVP (meilleur joueur). Bouton "Rechercher un nouveau match" pour relancer depuis /play. |
| D7.6 | Stats post-match par joueur | P2 | `Not Started` | Tableau des stats individuelles sur l'écran résultat : kills, deaths, K/D, damage, accuracy. Source : polling de `GET /servers/:s/stats/players`. |
| D7.7 | Historique des matchs (`/matches`) | P1 | `Not Started` | Liste paginée de tous les matchs terminés. Filtres : joueur, date, résultat. Composants `Table` + `Pagination` du DS. |
| D7.8 | Mes matchs (`/me/matches`) | P1 | `Not Started` | Historique des matchs du joueur connecté. Vue rapide : win/loss, ELO delta, adversaires/alliés, map jouée. |
| D7.9 | Reconnexion mid-match | P2 | `Not Started` | Si un joueur quitte le serveur, il a 3 minutes pour revenir (la whitelist reste active). Le match continue en 2v3. Le timer de reconnexion est géré côté plateforme. Timeout → abandon + pénalité (D4.7). |
| D7.10 | Gestion des matchs annulés | P2 | `Not Started` | Si le serveur crash ou si tous les joueurs d'une équipe quittent : match annulé, pas d'impact ELO, serveur libéré. L'admin peut aussi annuler manuellement (D11.5). |
| D7.11 | Replay / timeline du match | P4 | `Not Started` | Timeline détaillée des événements (kills, rounds) affichée chronologiquement. Basé sur les raw events de pixel-control-server. |

---

# D8 — Statistiques & Analytics

Les stats détaillées que les joueurs compétitifs consultent régulièrement.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D8.1 | Dashboard stats personnel (`/me/stats`) | P2 | `Not Started` | Win rate, ELO actuel + courbe, matchs joués, streak actuelle (victoires/défaites consécutives), temps de jeu total. |
| D8.2 | Stats globales détaillées | P2 | `Not Started` | Kills, deaths, K/D ratio, damage infligé/reçu, accuracy globale. Moyenne par match et totaux cumulés. |
| D8.3 | Stats par arme | P2 | `Not Started` | Breakdown laser / rocket / nucleus : kills, accuracy, damage, usage rate. Source : `weapon_id` dans les combat events de pixel-control-server. |
| D8.4 | Stats par map | P2 | `Not Started` | Win rate par map, performance relative. Aide le joueur à voir ses forces/faiblesses par map. |
| D8.5 | Comparaison entre joueurs (`/compare`) | P3 | `Not Started` | Sélectionner deux joueurs et voir leurs stats côte à côte. |
| D8.6 | Évolution temporelle | P3 | `Not Started` | Graphiques d'évolution : ELO, win rate, K/D — par semaine/mois. |
| D8.7 | Records personnels | P3 | `Not Started` | Meilleur K/D en un match, plus grosse série de victoires, meilleure accuracy. "Personal bests" sur le profil. |
| D8.8 | Stats de la communauté | P4 | `Not Started` | Stats agrégées globales : nombre total de matchs, joueur le plus actif, map la plus jouée, arme la plus mortelle. Page publique. |
| D8.9 | Export des stats | P5 | `Not Started` | Permettre aux joueurs d'exporter leurs stats en CSV/JSON. |
| D8.10 | API publique de stats | P5 | `Not Started` | Endpoints publics avec rate limiting pour que des outils tiers récupèrent les stats d'un joueur. |

---

# D9 — Social & Team-up

Le team-up est intégré directement dans la page `/play`. Pas de lobby séparé — c'est un système d'alliance mutuelle simple.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D9.1 | Système d'alliance (team-up) | P1 | `Not Started` | Sur la page `/play`, un input permet d'entrer 1 ou 2 logins ManiaPlanet de joueurs alliés. L'alliance ne s'active que quand les deux joueurs se sont mutuellement ajoutés. Statut : `pending` (orange, unilatéral) → `active` (vert, mutuel). Le joueur allié doit avoir un compte sur la plateforme. |
| D9.2 | Validation d'alliance | P1 | `Not Started` | Vérifier que le login entré correspond à un utilisateur existant sur la plateforme. Si le login n'existe pas → erreur "Joueur introuvable". Vérifier la réciprocité : l'alliance ne fonctionne que si les deux côtés ont ajouté l'autre. |
| D9.3 | Matchmaking en groupe | P1 | `Not Started` | Les 2 ou 3 joueurs alliés doivent tous cliquer "Rechercher" (être "prêts") pour que le matchmaking se lance. Le groupe entre en queue ensemble. L'ELO moyen du groupe est utilisé pour le matching. Le groupe reste dans la même équipe. |
| D9.4 | Statut en ligne des alliés | P2 | `Not Started` | Indicateur de statut pour les logins entrés dans l'input : hors ligne, en ligne, en queue, en match. Aide à savoir si son allié est prêt. |
| D9.5 | Recherche de joueurs | P3 | `Not Started` | Barre de recherche pour trouver un joueur par login/nickname. Utilisé pour entrer les logins dans le team-up et pour la navigation. |
| D9.6 | Bloquer un joueur | P3 | `Not Started` | Empêcher un joueur de vous ajouter en alliance. Le matchmaking n'est pas impacté (anti-exploit). |
| D9.7 | Historique de matchs communs | P4 | `Not Started` | Voir les matchs joués avec ou contre un joueur spécifique. Win rate ensemble. |
| D9.8 | Équipes/Clans permanents | P4 | `Not Started` | Créer une équipe avec un nom, un tag, des membres fixes. ELO d'équipe séparé. Page équipe (`/team/:tag`). |

---

# D10 — Notifications & Temps réel

L'infrastructure WebSocket et les notifications qui alimentent le matchmaking, le veto, et les scores live.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D10.1 | Infrastructure WebSocket (Socket.io) | P0 | `Not Started` | Serveur Socket.io intégré au Next.js (ou service dédié). Channels : par match, par utilisateur. Reconnexion automatique. Auth via session NextAuth. |
| D10.2 | Événements matchmaking | P0 | `Not Started` | `queue_joined`, `queue_left`, `match_found`, `player_accepted`, `player_declined`, `match_confirmed`, `match_cancelled`. Poussés en temps réel à chaque joueur concerné. |
| D10.3 | Événements veto/vote | P1 | `Not Started` | `vote_started`, `vote_cast`, `vote_timeout`, `vote_result`. Synchronisation temps réel entre les 6 joueurs du match. |
| D10.4 | Événements match live | P2 | `Not Started` | `match_started`, `round_end` (scores), `match_end` (résultat). Source : polling de pixel-control-server, re-diffusé via WebSocket aux spectateurs et joueurs. |
| D10.5 | Événements d'alliance | P2 | `Not Started` | `alliance_requested`, `alliance_confirmed`, `alliance_removed`. Notification quand quelqu'un vous ajoute en allié. |
| D10.6 | Centre de notifications (`/me/notifications`) | P3 | `Not Started` | Historique : matchs terminés, alliances, résultats, annonces admin. Marquage lu/non lu. |
| D10.7 | Son de notification | P4 | `Not Started` | Son audio quand un match est trouvé (même si le tab est en arrière-plan). Configurable dans les paramètres. |
| D10.8 | Push notifications navigateur | P5 | `Not Started` | Notifications push via l'API Notifications du navigateur. Utile si le joueur est sur un autre onglet. |

---

# D11 — Administration

Le panneau d'admin pour gérer la plateforme. Réservé aux rôles `admin` et `superadmin`.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D11.1 | Dashboard admin (`/admin`) | P3 | `Not Started` | Vue d'ensemble : joueurs en ligne, matchs en cours, serveurs actifs, queue stats. |
| D11.2 | Gestion des utilisateurs (`/admin/users`) | P3 | `Not Started` | Liste, recherche, détail. Actions : ban temporaire/permanent, reset ELO, changer le rôle. |
| D11.3 | Gestion des serveurs (`/admin/servers`) | P3 | `Not Started` | Pool de serveurs, statut, match en cours. Ajouter/retirer un serveur du pool. Forcer le statut (maintenance). Champs : login, adresse, port, région. |
| D11.4 | Gestion du pool de maps (`/admin/maps`) | P3 | `Not Started` | Définir les maps actives pour le matchmaking. Ajouter : nom + UID + MX ID (optionnel). Activer/désactiver. |
| D11.5 | Gestion des matchs (`/admin/matches`) | P3 | `Not Started` | Voir les matchs en cours et passés. Annuler un match en cours, invalider un match terminé (retirer l'impact ELO). |
| D11.6 | Modération et sanctions (`/admin/moderation`) | P3 | `Not Started` | Historique des sanctions. Formulaire : type (ban/warning), durée, raison. Pas de signalement joueur (pas prévu), mais sanction manuelle. |
| D11.7 | Configuration matchmaking (`/admin/config`) | P3 | `Not Started` | Paramètres : fenêtre ELO initiale, taux d'élargissement, K-factor, durée du vote map, timeout accept, cooldowns de dodge. |
| D11.8 | Gestion des saisons (`/admin/seasons`) | P3 | `Not Started` | Créer/clôturer une saison. Définir le pool de maps de la saison. Déclencher le reset ELO saisonnier. |
| D11.9 | Logs et audit trail | P3 | `Not Started` | Journal de toutes les actions admin : qui a fait quoi, quand. Filtrable. |
| D11.10 | Annonces et messages système | P4 | `Not Started` | Publier des annonces visibles par tous (bandeau). Maintenance planifiée, patch notes. |
| D11.11 | Configuration des rangs | P4 | `Not Started` | Personnaliser les seuils ELO des rangs, les noms, les icônes. |
| D11.12 | Export de données admin | P5 | `Not Started` | Export CSV/JSON des joueurs, matchs, stats pour analyse externe. |

---

# D12 — Saisons & Compétitions

Structurer le temps compétitif en saisons. Les tournois sont un nice-to-have.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D12.1 | Modèle de saison | P3 | `Not Started` | Table `Season` : nom, dates début/fin, pool de maps, statut (upcoming/active/ended). Une seule saison active à la fois. |
| D12.2 | Transition de saison | P3 | `Not Started` | Fin de saison : archiver le classement, appliquer le reset ELO (D3.9), activer le nouveau pool de maps, remettre les compteurs de placement à zéro. |
| D12.3 | Classement saisonnier | P3 | `Not Started` | Leaderboard spécifique à la saison. Archivé et consultable après la fin. `/leaderboard?season=S1`. |
| D12.4 | Récompenses de fin de saison | P4 | `Not Started` | Badges selon le rang final : "Gold S1", "Top 10 S1", etc. Affichés sur le profil (D2.6). |
| D12.5 | Page d'archives des saisons (`/seasons`) | P4 | `Not Started` | Historique des saisons passées avec classement final et stats globales. |
| D12.6 | Tournois communautaires | P4 | `Not Started` | Créer un tournoi avec bracket (composant `Bracket` du DS), inscriptions, arbre éliminatoire. Double élimination. |
| D12.7 | Page tournoi (`/tournament/:id`) | P4 | `Not Started` | Bracket interactif, résultats en direct, inscriptions. |
| D12.8 | Inscriptions et check-in tournoi | P4 | `Not Started` | Inscription, check-in obligatoire avant le début, remplacement des no-shows. |

---

# D13 — Pages publiques & SEO

Les pages accessibles à tout utilisateur connecté (pas de visiteurs sans compte MP).

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D13.1 | Landing page (`/`) | P2 | `Not Started` | Page d'accueil pour les non-connectés : hero section, features clés (matchmaking, ELO, stats), bouton "Se connecter avec ManiaPlanet". Design neumorphique DS. |
| D13.2 | Page "Comment ça marche" | P2 | `Not Started` | Explication du flux : connexion → matchmaking → vote → match → résultats. Schémas visuels. |
| D13.3 | SEO metadata | P2 | `Not Started` | Balises OpenGraph et Twitter Cards. Titre dynamique par page. Meta description. |
| D13.4 | Profils publics SEO-friendly | P3 | `Not Started` | Les pages `/player/:login` sont SSR pour le contenu statique (rang, stats). Indexables. |
| D13.5 | Page de maintenance | P3 | `Not Started` | Page dédiée quand la plateforme est en maintenance. |

---

# D14 — Infrastructure & DevOps

Ce qui fait tourner la plateforme en production.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D14.1 | CI/CD pipeline | P3 | `Not Started` | Build + lint + tests automatiques sur chaque push. Déploiement auto sur staging. |
| D14.2 | Stratégie de déploiement | P3 | `Not Started` | VPS ou Docker (WebSocket exclut Vercel serverless). Docker Compose pour la plateforme + sa DB PostgreSQL. |
| D14.3 | Monitoring et alerting | P3 | `Not Started` | Uptime monitoring, error tracking (Sentry), métriques applicatives. |
| D14.4 | Rate limiting et protection | P3 | `Not Started` | Rate limiting sur les routes. Protection CSRF. Headers de sécurité. |
| D14.5 | Logging structuré | P3 | `Not Started` | Logs JSON structurés. Correlation IDs. Rotation et rétention. |
| D14.6 | Backups base de données | P3 | `Not Started` | Backup automatique quotidien PostgreSQL. Rétention 30 jours. |
| D14.7 | Environnements (dev/staging/prod) | P3 | `Not Started` | Variables d'env par environnement. Base séparée par environnement. |

---

# D15 — Intégrations externes

Nice-to-have pour plus tard.

| # | Fonctionnalité | Priorité | Statut | Détail |
|---|---|---|---|---|
| D15.1 | Webhook Discord | P5 | `Not Started` | Poster les résultats de match, les classements, les annonces dans un channel Discord configuré par l'admin. |
| D15.2 | Bot Discord | P5 | `Not Started` | Commandes Discord (!stats, !rank, !leaderboard) pour consulter les stats et le classement depuis Discord. |
| D15.3 | ManiaExchange API | P5 | `Not Started` | Intégration poussée avec MX : recherche de maps, import direct dans le pool, stats de maps. |

---

## Ordre d'implémentation recommandé

D'abord le squelette technique et l'auth, puis le flux matchmaking complet de bout en bout, puis tout le reste autour.

### Phase 1 — Fondations (P0)
> **Objectif** : un site qui se lance, avec connexion ManiaPlanet, SDK API fonctionnel, et infra WebSocket.

- D0.1 → D0.8 (scaffold, DS, SDK, layout, i18n)
- D1.1 → D1.3, D1.5 (auth SSO + session + middleware)
- D10.1 (infra Socket.io)

### Phase 2 — Flux matchmaking de bout en bout (P0–P1)
> **Objectif** : un joueur peut cliquer "Jouer", trouver un match, voter une map, rejoindre le serveur, et voir le résultat avec son ELO mis à jour.

- D4.1, D4.2, D4.5 (page /play, queue, accept/decline)
- D10.2 (événements matchmaking WebSocket)
- D5.1 → D5.7 (vote map complet)
- D10.3 (événements vote WebSocket)
- D6.1 → D6.5, D6.7 (pool serveurs, assignation, whitelist, lien maniaplanet://)
- D7.1, D7.3 → D7.5 (page match, détection fin, résultat, écran résultat)
- D3.1, D3.2, D3.4 (ELO : modèle + calcul + post-match auto)
- D9.1 → D9.3 (alliances / team-up)

### Phase 3 — Expérience joueur complète (P1)
> **Objectif** : le joueur a un profil, voit ses stats et le classement, et le matchmaking est affiné.

- D2.1, D2.2 (profil public + mon profil)
- D3.3, D3.6 (placement + leaderboard)
- D7.7, D7.8 (historique matchs)
- D4.3, D4.4, D4.6, D4.7, D4.12 (matching ELO, composition, pénalités, vérif ban)
- D1.4, D1.6 → D1.8 (refresh token, sync profil, logout, rôles)
- D0.9 (pages d'erreur)

### Phase 4 — Enrichissement (P2)
> **Objectif** : stats détaillées, score live, social enrichi, landing page.

- D8.1 → D8.4 (stats dashboard, armes, maps)
- D7.2, D7.6, D7.9, D7.10 (score live, stats post-match, reconnexion, annulation)
- D9.4 (statut en ligne des alliés)
- D10.4, D10.5 (événements match live + alliance)
- D4.8 (estimation temps d'attente)
- D2.3 → D2.5 (carte joueur, paramètres, zone/drapeau)
- D3.5, D3.7 (rangs visuels, graphique ELO)
- D6.6, D6.8 (timeout entrée serveur, health check)
- D13.1 → D13.3 (landing, "comment ça marche", SEO)

### Phase 5 — Admin et saisons (P3)
> **Objectif** : la plateforme est opérable et structurée dans le temps.

- D11.1 → D11.9 (panneau admin complet)
- D12.1 → D12.3 (saisons)
- D14.1 → D14.7 (infra, CI/CD, monitoring)
- D4.9 (anti-snipe)
- D6.9 (multi-région : préparation)
- D8.5 → D8.7 (comparaison, évolution, records)
- D9.5, D9.6 (recherche joueurs, bloquer)
- D3.9 (reset saisonnier)
- D10.6 (centre de notifications)
- D13.4, D13.5 (profils SEO, maintenance)

### Phase 6 — Communauté et polish (P4–P5)
> **Objectif** : tournois, clans, badges, intégrations, extras.

- D12.4 → D12.8 (récompenses, tournois)
- D9.7, D9.8 (historique matchs communs, clans)
- D8.8 → D8.10 (stats communauté, export, API publique)
- D3.8 (decay ELO)
- D11.10 → D11.12 (annonces, config rangs, export admin)
- D10.7, D10.8 (sons, push notifications)
- D4.10 (heures creuses)
- D2.6, D2.7 (badges, lien MP)
- D7.11 (timeline match)
- D15.1 → D15.3 (Discord, ManiaExchange)

---

## Dépendances externes

| Dépendance | Rôle | Statut |
|---|---|---|
| `pixel-control-server` (NestJS API) | Backend télémétrie + commandes admin + state sync. La plateforme poll ses endpoints et proxie les commandes serveur | Done (60+ endpoints, P0–P5) |
| `pixel-control-plugin` (PHP ManiaControl) | Plugin sur chaque serveur SM — exécute les commandes, envoie les events lifecycle/combat | Done (38 admin actions, 5 VetoDraft methods) |
| `pixel-design-system` | Bibliothèque de composants React neumorphique | Done (26 composants, Storybook) |
| `ressources/oauth2-maniaplanet` | Référence pour le flow OAuth2 ManiaPlanet | Référence only (PHP) — à réimplémenter en TS/NextAuth |
| API ManiaPlanet OAuth2 | Service externe (maniaplanet.com) | Disponible — nécessite `client_id` + `client_secret` |
| API ManiaExchange | Service externe (sm.mania.exchange) | Disponible — utilisé pour les thumbnails de maps |
| Serveurs ShootMania | Machines physiques avec dedicated server + ManiaControl + plugin | À provisionner manuellement |

---

## Décisions ouvertes restantes

| Sujet | Options | Impact |
|---|---|---|
| **Swagger CodeGen tool** | `orval` (hooks TanStack Query auto-générés) vs `openapi-typescript-codegen` (client vanilla) | D0.3 — choix avant le scaffold |
| **NextAuth v4 vs v5** | v4 (stable) vs v5/Auth.js (meilleur App Router mais beta) | D1.1 — choix avant l'auth |
| **Visibilité des votes** | Les joueurs voient-ils qui a voté quoi pendant le vote map, ou seulement le décompte anonyme ? | D5.7 — UX du veto |
| **ELO initial** | 1000 vs 1200 — le choix impacte la distribution des rangs et les seuils de divisions | D3.1 — calibrage |
| **K-factor après placement** | K=20 (standard) vs K=16 (CS:GO-like) vs variable selon le rang | D3.2 — tuning |
| **Nombre de matchs de placement** | 10 (standard) vs 5 (plus rapide) vs 15 (plus précis) | D3.3 — calibrage |
| **ManiaExchange thumbnail URL** | Pattern exact de l'URL des thumbnails SM (à vérifier sur sm.mania.exchange) | D5.4 — vérification technique |
| **Lien maniaplanet://** | Format exact du deep link pour rejoindre un serveur (à vérifier dans la doc MP) | D6.5 — vérification technique |
