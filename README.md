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

### Téléphonie Ringover
- **Click-to-call** : votre poste Ringover sonne, puis le prospect est composé dès que vous décrochez. Sans clé Ringover, un simple lien `tel:` est utilisé.
- **Webhooks** : chaque appel (sortant, entrant, manqué, messagerie) est rattaché automatiquement au lead (via le numéro) et à l'agent (ID Ringover, email ou numéro). Il est journalisé, compte comme tentative et met à jour le statut.
- **Synchronisation de rattrapage** toutes les 5 minutes via l'API `/calls`, au cas où un webhook serait perdu. Un bouton de synchronisation manuelle est aussi disponible.
- **Enregistrements** lisibles dans la fiche appel. Les **transcriptions** (Ringover Empower) sont exploitées par l'IA si elles sont présentes.
- **Session d'appels** (power dialer) : enchaîne les leads de la file (rappels échus d'abord, puis leads chauds). Affiche le pitch IA, les objections et l'historique, et qualifie l'appel en un clic.

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

Tests : `php artisan test` (27 tests : import, webhooks Ringover, IA simulée, droits, pages, file d'appels, pipeline, valorisation).

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

## Configuration Ringover

1. **Clé API** : Dashboard Ringover → *Développeur* → *API* → créer une clé avec les droits sur les appels et le click-to-call. À mettre dans `RINGOVER_API_KEY`.
   Région US : `RINGOVER_BASE_URL=https://public-api-us.ringover.com/v2`.
2. **Webhooks** : Dashboard → *Développeur* → *Webhooks* → URL :
   ```
   https://crm.votre-domaine.fr/webhooks/ringover/<RINGOVER_WEBHOOK_TOKEN>
   ```
   Activez au minimum les événements d'appels terminés (raccroché), manqués et messagerie. Activez aussi les enregistrements et transcriptions si votre offre les inclut.
3. **Utilisateurs** : dans *Administration → Équipe*, renseignez pour chaque commercial :
   - **Numéro Ringover** (format +33…) : utilisé pour le click-to-call ;
   - **ID utilisateur Ringover** : rattache les appels reçus par webhook. Le CRM essaie aussi l'email puis le numéro de ligne.

> Le parsing des webhooks accepte plusieurs formats (données à plat ou sous `data`, noms de champs alternatifs). Après la mise en service, passez un appel test et vérifiez dans *Téléphonie → Journal d'appels* que l'appel est bien rattaché. Le contenu brut de chaque événement est conservé (`raw_payload`) pour diagnostic.

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
│   ├── Ringover/…            Client API (click-to-call, /calls) + ingestion des appels
│   ├── CallLogger            Qualification d'appel (fiche et file d'appels)
│   ├── LeadAssigner          Distribution équilibrée
│   └── PhoneNormalizer       E.164 sans dépendance
├── Jobs/                     Import, scoring IA, résumé d'appel, événement Ringover
├── Console/Commands/         ringover:sync, crm:notify-due-tasks
├── Http/Controllers/Webhooks/RingoverWebhookController
└── Filament/                 Resources, Pages (Dashboard, Session d'appels, Pipeline), Widgets, Import/Export
```

Tâches planifiées (conteneur `scheduler`) : synchronisation Ringover et notifications des rappels toutes les 5 minutes, nettoyage quotidien des files.

## RGPD et prospection B2B

- Champ **« Ne plus appeler »** : le lead est exclu de la file d'appels. Tracez-y les oppositions.
- Les prospects sont des professionnels (B2B) ; conservez la **source** de chaque fichier importé (champ prévu).
- Les données envoyées à l'IA se limitent aux informations professionnelles du lead et à l'historique commercial.
