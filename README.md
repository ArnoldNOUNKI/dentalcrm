# DentalCRM

CRM multi-utilisateurs pour la **prospection et la collecte de métaux dentaires** (or, platine, palladium, argent) auprès des cabinets dentaires, centres et laboratoires de prothèse.
Téléphonie **Ringover** intégrée, **import CSV** avec traitement automatique, **IA Claude** (Anthropic) à chaque étape.

Stack : Laravel 12 · Filament 3 · Livewire 3 · MySQL 8 · Redis · Docker (serversideup/php) · prêt pour Coolify.

---

## Fonctionnalités

### Prospection
- **Leads** : fiche complète (établissement, contact, adresse, qualification collecte), statuts, score IA, étiquettes, opposition au démarchage, corbeille.
- **Onglets** : À appeler · Chauds · Intéressés · Clients · Perdus. Filtres : statut, type, commercial, département, source, score minimum, actions en retard, jamais appelé, non attribué.
- **Pipeline Kanban** : glisser-déposer entre statuts, avec historisation automatique.
- **Historique complet** (timeline) : appels, notes, emails, changements de statut, attributions, collectes, analyses IA, imports.
- **Rappels et tâches** : échéances, priorités, report, notifications dans l'application 10 min avant l'échéance.
- **Recherche globale** (Ctrl+K) : nom, ville, CP, téléphone, email, SIRET, référence.

### Import CSV et traitement automatique
1. Upload CSV (séparateur détecté automatiquement, jusqu'à 50 000 lignes, traitement par lots en file d'attente).
2. **Correspondance automatique des colonnes** (en-têtes FR/EN usuels), modifiable avant validation.
3. **Normalisation** : téléphones au format E.164 (gère le zéro supprimé par Excel, `0033`, `+33 (0)`), email en minuscules, CP → département, type d'établissement deviné.
4. **Dédoublonnage** : SIRET, téléphone, mobile ou email. Trois modes au choix : compléter les champs vides, ignorer la ligne, ou écraser.
5. **Attribution automatique** équilibrée (le commercial qui a le moins de leads ouverts), ou à un commercial imposé.
6. Passage en « À appeler », puis **scoring IA** en arrière-plan (débit limité).
7. Fichier des lignes en erreur téléchargeable, notification de fin d'import.

Fichier d'exemple : `docs/exemple-import-leads.csv`.

### Téléphonie (Ringover, Aircall, Twilio, Asterisk/FreePBX, webhook générique)
- **Plusieurs services connectables en même temps**, chacun avec ses paramètres de connexion **chiffrés en base**, gérés dans *Téléphonie → Intégrations* (administrateurs).
- **Récupération automatique de tous les appels** : émis, reçus, manqués, messagerie, injoignables (sans réponse, occupé, échec), avec durée de conversation, temps de sonnerie, motif de non-réponse, agent, enregistrement, message vocal et transcription.
  - **Temps réel** par webhook (Ringover, Aircall, Twilio, webhook générique), avec vérification de l'authenticité de chaque événement.
  - **Synchronisation périodique** par API ou base CDR (Ringover, Aircall, Twilio, Asterisk), de 1 à 60 minutes selon le service, pour rattraper les webhooks perdus. Un bouton permet de récupérer l'historique sur 90 jours.
  - Traitement idempotent : un appel reçu plusieurs fois (webhook et synchronisation) n'est jamais dupliqué.
- **Rattachement automatique** au lead (numéro du correspondant) et à l'agent (ID, email, ligne ou poste, réglés dans *Intégrations → Agents*).
- **Effets sur le suivi** : historique du lead, tentatives, passage en « Injoignable ». Un **appel entrant manqué** crée un rappel prioritaire et une notification pour le commercial.
- **Click-to-call** par le service par défaut : Ringover (callback), Aircall (dial), Twilio (appel de l'agent puis mise en relation, enregistrement), Asterisk (AMI Originate) ou une URL de votre PBX. Sans service configuré, un lien `tel:` est utilisé.
- **Journal d'appels** avec onglets Émis, Reçus, Manqués, Injoignables, Décrochés et Sans lead, filtres par service et statut, et écoute des enregistrements via le CRM (accès authentifié).
- **Session d'appels** (power dialer) : enchaîne les leads de la file (rappels échus d'abord, puis leads chauds), avec pitch IA, objections et historique, et qualification en un clic.

### IA (Claude)
| Fonction | Déclenchement |
|---|---|
| **Qualification / scoring** 0-100, type d'établissement, accroche d'appel personnalisée, objections probables, meilleur créneau | Automatique à l'import / création, ou bouton |
| **Analyse d'appel** : résumé, issue, sentiment, intérêt, prochaine étape, extraction (décideur, concurrent, volume g/an, email), **rappel auto** si convenu, conseil de coaching | Automatique après chaque appel ayant des notes ou une transcription |
| **Rédaction d'emails** (présentation, relance, kit, offre, réactivation), modifiables avant envoi SMTP | Bouton « Email (IA) » |
| **Assistant** : question libre sur un lead, avec tout son historique | Bouton « Demander à l'IA » |
| **Briefing quotidien** : priorités, ordre d'appel, conseil de pitch | Widget du tableau de bord |

Les sorties structurées sont garanties via *tool use* forcé. Les données du CRM sont isolées dans le prompt et traitées comme des données, jamais comme des instructions. Le scoring en masse utilise le modèle rapide, le reste le modèle principal (configurables).

### Collectes
- Cycle complet : planifiée → kit envoyé → en transit → reçue → analyse/titrage → offre → acceptée → payée (ou refusée / retournée). Un bouton « étape suivante » renseigne les dates automatiquement.
- Contenu (couronnes, bridges, alliages, limailles…), poids brut/net, titres Au/Pt/Pd/Ag.
- **Valorisation automatique** = poids net × Σ(titre × cours €/g) × taux reversé, avec instantané des cours au moment du calcul.
- Le statut du lead suit la collecte (Kit envoyé → Client actif).
- **Cours des métaux** éditables (Collectes > Cours des métaux).

### Multi-utilisateurs et rôles
| Rôle | Droits |
|---|---|
| Administrateur | Tout, y compris gestion des admins |
| Manager | Tous les leads, attribution, équipe (hors admins), statistiques équipe |
| Commercial | **Uniquement ses leads**, ses appels, ses rappels, sa file d'appels |
| Logistique | Tous les leads et collectes (lecture/traitement), cours des métaux |

Tableau de bord : leads à appeler, appels et taux de décroché, intéressés, rappels du jour et retards, collectes en cours et valeur, rachats payés, graphiques, classement de l'équipe. Filtres par période et par commercial.

---

## Installation locale

```bash
composer install
cp .env.example .env
# Pour du local : DB_CONNECTION=sqlite, QUEUE_CONNECTION=sync, CACHE_STORE=database, SESSION_DRIVER=database
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed        # crée l'admin (mot de passe affiché) + cours + données de démo hors production
php artisan serve
```
Comptes de démo (hors production) : `manager@demo.fr`, `karim@demo.fr`, `julie@demo.fr`, `labo@demo.fr`, tous avec le mot de passe `demo-password`.

Tests : `php artisan test` (42 tests : import, téléphonie par service (webhooks, signatures, synchronisation, click-to-call, CDR Asterisk), IA simulée, droits, pages, file d'appels, pipeline, valorisation).

---

## Déploiement sur Coolify

1. Poussez le projet sur un dépôt Git (GitHub/GitLab/Gitea).
2. Dans Coolify : **New Resource → Docker Compose** → sélectionnez le dépôt (fichier `docker-compose.yml`).
3. Dans le service `app` : déclarez votre domaine sur **`app` port `8080`**, par exemple `https://crm.votre-domaine.fr:8080` (Coolify/Traefik gère le HTTPS). Aucun port n'est publié sur l'hôte.
4. Onglet **Environment Variables** : reprenez `.env.example`, en particulier :
   - `APP_KEY`, à générer avec `php artisan key:generate --show`
   - `APP_URL=https://crm.votre-domaine.fr`
   - `DB_PASSWORD` et `DB_ROOT_PASSWORD` (mots de passe forts)
   - `ADMIN_EMAIL` et `ADMIN_PASSWORD` : compte admin créé au premier démarrage
   - `ANTHROPIC_API_KEY`, `RINGOVER_API_KEY` et `RINGOVER_WEBHOOK_TOKEN` (chaîne aléatoire longue)
   - `MAIL_*` pour l'envoi des emails
5. Déployez. Au démarrage, le conteneur `app` lance les migrations, met en cache config/routes/vues, optimise Filament et crée l'admin. Les conteneurs `queue` et `scheduler` démarrent ensuite.

Hors Coolify : `docker compose up -d --build` avec un `.env` complet. Le fichier `docker-compose.override.yml`, chargé automatiquement en local, publie le CRM sur le port `APP_PORT` (8080 par défaut).

Volumes persistants : `mysql`, `redis` et `storage` (fichiers d'import/export).

---

## Configuration de la téléphonie

Tout se fait dans le CRM : **Téléphonie → Intégrations → Connecter un service**. Après l'enregistrement, la fiche affiche l'**URL du webhook** à déclarer chez le fournisseur, et le bouton **Tester la connexion** vérifie les accès.

| Service | Paramètres | Webhook à déclarer | Récupération auto |
|---|---|---|---|
| **Ringover** | Clé API (Dashboard → Développeur → API), région EU/US | Développeur → Webhooks : événements d'appels, enregistrements, transcriptions | API `/calls` |
| **Aircall** | API ID + API Token (Intégrations & API) ; jeton du webhook | Intégrations & API → Webhooks : `call.created`, `call.answered`, `call.ended`, `call.voicemail_left` | API `/calls` |
| **Twilio** | Account SID, Auth Token, numéro Twilio | Numéro → Voice → *Call status changes* (POST) ; automatique pour les appels lancés du CRM | API `Calls` |
| **Asterisk / FreePBX** | Base CDR (MySQL `asteriskcdrdb`, table `cdr`) ; AMI (hôte, port 5038, utilisateur, secret) ; URL des enregistrements | — | Lecture du CDR (regroupé par `linkedid`) |
| **Webhook générique** | Vérification : aucune, secret en en-tête ou HMAC-SHA256 ; correspondance des champs ; URL de click-to-call | Webhook « fin d'appel » vers l'URL fournie | — |

**Agents** : sur chaque service, associez les utilisateurs du CRM à leur ID chez le fournisseur (Ringover, Aircall), à leur ligne ou téléphone (Ringover, Twilio) ou à leur poste (Asterisk). Les appels leur sont attribués automatiquement et le click-to-call fait sonner leur poste. Cochez **Click-to-call par défaut** sur le service à utiliser pour le bouton « Appeler ».

**Asterisk** : créez un utilisateur MySQL en **lecture seule** sur `asteriskcdrdb` et ouvrez le port MySQL au seul serveur du CRM (pare-feu, VPN ou tunnel). Pour l'AMI, utilisez un utilisateur dédié limité à `originate` (`permit=` sur l'IP du CRM).

**Compatibilité** : les anciennes variables `RINGOVER_API_KEY` / `RINGOVER_WEBHOOK_TOKEN` créent automatiquement le service Ringover, et l'ancienne URL `/webhooks/ringover/{token}` reste active.

## Configuration de l'IA

- `ANTHROPIC_API_KEY` : clé API Anthropic (console.anthropic.com).
- `ANTHROPIC_MODEL` (par défaut `claude-sonnet-5-5`) et `ANTHROPIC_MODEL_FAST` (par défaut `claude-haiku-5-5`, pour le scoring en masse).
- `AI_COMPANY_CONTEXT` : présentation de votre société (offre, conditions, zone), injectée dans tous les prompts. **À personnaliser** pour des pitchs et des emails pertinents.
- `AI_AUTO_SCORE_IMPORTS` et `AI_AUTO_SUMMARIZE_CALLS` : activer/désactiver les traitements automatiques.
- `AI_JOBS_PER_MINUTE` : débit maximum des analyses en tâche de fond.

Sans clé, le CRM fonctionne entièrement et les boutons IA sont simplement masqués.

---

## Architecture

```
app/
├── Enums/                    Statuts lead, collecte, issue d'appel, rôles, types…
├── Models/                   Lead, Call, Activity, Task, Collection, MetalPrice, User
├── Observers/LeadObserver    Normalisation téléphone/CP/SIRET/email, référence
├── Services/
│   ├── Ai/ClaudeClient       API Messages Anthropic (texte + sortie structurée, retries)
│   ├── Ai/LeadIntelligence   Scoring, analyse d'appel, emails, assistant, briefing
│   ├── Telephony/            Connecteurs (Ringover, Aircall, Twilio, Asterisk, générique),
│   │                         ingestion idempotente, synchronisation, client AMI
│   ├── CallLogger            Qualification d'appel (fiche et file d'appels)
│   ├── LeadAssigner          Distribution équilibrée
│   └── PhoneNormalizer       E.164 sans dépendance
├── Jobs/                     Import, scoring IA, résumé d'appel, événement Ringover
├── Console/Commands/         ringover:sync, crm:notify-due-tasks
├── Http/Controllers/Webhooks/RingoverWebhookController
└── Filament/                 Resources, Pages (Dashboard, Session d'appels, Pipeline), Widgets, Import/Export
```

Tâches planifiées (conteneur `scheduler`) : récupération des appels de chaque service (selon sa fréquence), notifications des rappels toutes les 5 minutes, nettoyage quotidien des files.

## RGPD et prospection B2B

- Champ **« Ne plus appeler »** : le lead est exclu de la file d'appels. Tracez-y les oppositions.
- Les prospects sont des professionnels (B2B) ; conservez la **source** de chaque fichier importé (champ prévu).
- Les données envoyées à l'IA se limitent aux informations professionnelles du lead et à l'historique commercial.
