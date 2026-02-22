# TODO

- [x] Initialiser le AGENTS.md local avec le but do projet, les ressources etc... init l'IA sur le projet.
- [x] Regarder comment ManiaControl marche et comment c'est utilisable pour le plugin
- [x] Init l'ia pour faire un serveur ShootMania avec Docker et avec le plugin MC `Pixel` déjà intégré pour le dev.

- [ ] Faire le serveur SM aussi compatible avec les modes :
  - [ ] Siege
  - [ ] DuelElite
  - [ ] SpeedBall

- [ ] Faire la partie Veto (vote des maps dans le jeu) => comment on lance un veto et comment récupérer les maps qui ont été veto pour le match
  - [x] Faire la première version
  - [ ] Faire les tests en réel (SM) et faire les fixs nécessaires pour faire fonctionner le veto MM
    - [x] Il faut que le compte a rebours s'affiche dans le chat, a chaque fois que ca passe 10 secondes on affiche un message (il reste 40 secondes, il reste 30 secondes, ... et quand il reste moins de 5 secondes on affiche un message dans le chat a chaque secondes)
    - [x] Il faut 66% des joueurs présents sur le serveur pour que le veto MM se lance
    - [ ] Faire un test complet pour appliquer le flow MM Veto comme ceci :
  - [ ] Ajouter une UI pour faire le veto au lieu d'un simple veot dans le chat

- [x] Ajouter aussi des fonctionnaltiées au Pixel Control Plugin pour set le BO (BO3, BO5, BO7, ...)

- [x] Ajouter les commandes pour set le nombre de maps et le score pour chaque équipes
- [x] Faire un projet de série de tests E2E "Plugin <-> SM Server"

- [ ] Faire un audit de comment les informations seront transmisent entre "Pixel Plugin <-> Pixel Server"
- [ ] Faire le système d'authentification "Pixel Plugin <-> Pixel Server"
