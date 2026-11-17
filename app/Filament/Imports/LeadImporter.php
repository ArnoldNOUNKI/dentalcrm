<?php

namespace App\Filament\Imports;

use App\Enums\ActivityType;
use App\Enums\LeadType;
use App\Jobs\ProcessImportedLead;
use App\Models\Lead;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

/**
 * Import CSV des leads avec :
 * - correspondance automatique des colonnes (noms FR/EN usuels) modifiable par l'utilisateur
 * - dédoublonnage (SIRET, téléphone, mobile, email)
 * - normalisation (téléphones E.164, CP → département, type d'établissement deviné)
 * - traitement automatique en file d'attente : attribution, passage « À appeler », scoring IA
 */
class LeadImporter extends Importer
{
    protected static ?string $model = Lead::class;

    protected bool $isNewLead = false;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('company_name')->label('Établissement')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->guess(['raison sociale', 'nom', 'cabinet', 'societe', 'société', 'entreprise', 'company', 'name', 'etablissement', 'établissement', 'denomination', 'dénomination', 'nom du cabinet', 'laboratoire']),
            ImportColumn::make('type')->label('Type')
                ->castStateUsing(fn (?string $state) => $state ? LeadType::guess($state)->value : null)
                ->ignoreBlankState()
                ->guess(['type', 'categorie', 'catégorie', 'activite', 'activité', 'specialite', 'spécialité', 'profession']),
            ImportColumn::make('contact_civility')->label('Civilité')->ignoreBlankState()
                ->castStateUsing(fn (?string $state) => match (mb_strtolower(trim((string) $state, '. '))) {
                    'dr', 'docteur' => 'Dr', 'm', 'mr', 'monsieur' => 'M.', 'mme', 'madame', 'mlle' => 'Mme', default => $state ? mb_substr($state, 0, 10) : null,
                })
                ->guess(['civilite', 'civilité', 'titre', 'civility']),
            ImportColumn::make('contact_first_name')->label('Prénom')->ignoreBlankState()->rules(['nullable', 'max:255'])
                ->guess(['prenom', 'prénom', 'first name', 'firstname', 'prenom contact']),
            ImportColumn::make('contact_last_name')->label('Nom du contact')->ignoreBlankState()->rules(['nullable', 'max:255'])
                ->guess(['nom contact', 'nom du contact', 'nom du praticien', 'praticien', 'last name', 'lastname', 'dentiste', 'contact', 'nom de famille']),
            ImportColumn::make('contact_role')->label('Fonction')->ignoreBlankState()->rules(['nullable', 'max:255'])
                ->guess(['fonction', 'poste', 'role', 'rôle', 'job title']),
            ImportColumn::make('phone')->label('Téléphone')->ignoreBlankState()->rules(['nullable', 'max:40'])
                ->guess(['telephone', 'téléphone', 'tel', 'tél', 'phone', 'fixe', 'numero', 'numéro', 'tel fixe', 'téléphone fixe']),
            ImportColumn::make('mobile')->label('Mobile')->ignoreBlankState()->rules(['nullable', 'max:40'])
                ->guess(['mobile', 'portable', 'gsm', 'cell', 'tel portable', 'téléphone portable']),
            ImportColumn::make('email')->label('Email')->ignoreBlankState()
                ->castStateUsing(fn (?string $state) => $state ? mb_strtolower(trim($state)) : null)
                ->rules(['nullable', 'email:rfc', 'max:255'])
                ->guess(['email', 'e-mail', 'mail', 'courriel', 'adresse mail', 'adresse email']),
            ImportColumn::make('address')->label('Adresse')->ignoreBlankState()->rules(['nullable', 'max:255'])
                ->guess(['adresse', 'address', 'rue', 'adresse 1', 'voie']),
            ImportColumn::make('postal_code')->label('Code postal')->ignoreBlankState()->rules(['nullable', 'max:10'])
                ->guess(['cp', 'code postal', 'zip', 'postal code', 'code_postal']),
            ImportColumn::make('city')->label('Ville')->ignoreBlankState()->rules(['nullable', 'max:255'])
                ->guess(['ville', 'commune', 'city', 'localite', 'localité']),
            ImportColumn::make('siret')->label('SIRET')->ignoreBlankState()->rules(['nullable', 'max:20'])
                ->guess(['siret', 'siren', 'n° siret']),
            ImportColumn::make('website')->label('Site web')->ignoreBlankState()->rules(['nullable', 'max:255'])
                ->guess(['site', 'site web', 'url', 'website', 'site internet']),
            ImportColumn::make('practitioners_count')->label('Nb praticiens')->ignoreBlankState()->numeric()->integer()->rules(['nullable', 'integer', 'min:0', 'max:5000'])
                ->guess(['praticiens', 'nb praticiens', 'nombre de praticiens', 'effectif']),
            ImportColumn::make('source')->label('Source')->ignoreBlankState()->rules(['nullable', 'max:255'])
                ->guess(['source', 'origine', 'provenance']),
            ImportColumn::make('notes')->label('Notes')->ignoreBlankState()
                ->guess(['notes', 'note', 'commentaire', 'commentaires', 'remarques', 'observations']),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            Forms\Components\Select::make('assign_to')
                ->label('Attribuer les nouveaux leads à')
                ->options(fn () => User::salesAgents()->pluck('name', 'id'))
                ->placeholder('Répartition automatique entre les commerciaux'),
            Forms\Components\TextInput::make('source')
                ->label('Source (si absente du fichier)')
                ->placeholder('ex : Fichier Ordre Moselle 2026'),
            Forms\Components\Select::make('duplicates')
                ->label('Si le lead existe déjà (même SIRET, téléphone ou email)')
                ->options([
                    'complete' => 'Compléter les champs vides uniquement',
                    'skip' => 'Ignorer la ligne',
                    'overwrite' => 'Écraser avec les données du fichier',
                ])
                ->default('complete')
                ->required(),
        ];
    }

    public function resolveRecord(): ?Lead
    {
        $existing = $this->findDuplicate();

        if ($existing) {
            Cache::increment($this->counterKey('duplicates'));

            if (($this->options['duplicates'] ?? 'complete') === 'skip') {
                return null;
            }

            $this->isNewLead = false;

            return $existing;
        }

        $this->isNewLead = true;

        return new Lead;
    }

    private function findDuplicate(): ?Lead
    {
        $siret = preg_replace('/\D/', '', (string) ($this->data['siret'] ?? ''));
        $phones = array_merge(PhoneNormalizer::variants($this->data['phone'] ?? null), PhoneNormalizer::variants($this->data['mobile'] ?? null));
        $email = $this->data['email'] ?? null;

        if (! $siret && ! $phones && ! $email) {
            return null;
        }

        return Lead::query()
            ->withTrashed()
            ->where(function ($q) use ($siret, $phones, $email) {
                if (strlen($siret) >= 9) {
                    $q->orWhere('siret', $siret);
                }
                if ($phones) {
                    $q->orWhereIn('phone_normalized', $phones)->orWhereIn('mobile_normalized', $phones);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
            })
            ->first();
    }

    public function fillRecord(): void
    {
        $mode = $this->options['duplicates'] ?? 'complete';

        if ($this->isNewLead || $mode === 'overwrite') {
            parent::fillRecord();
        } else {
            // Mode « compléter » : on ne remplit que les champs vides du lead existant
            foreach ($this->getCachedColumns() as $column) {
                $name = $column->getName();
                if (blank($this->columnMap[$name] ?? null) || ! array_key_exists($name, $this->data) || blank($this->data[$name])) {
                    continue;
                }
                if (blank($this->record->getAttribute($name))) {
                    $column->fillRecord($this->data[$name]);
                }
            }
        }

        if ($this->isNewLead) {
            $this->record->source ??= $this->options['source'] ?? 'Import CSV';
            $this->record->type ??= LeadType::guess($this->data['company_name'] ?? '');
        }
    }

    protected function afterSave(): void
    {
        $lead = $this->record;

        if ($lead->trashed()) {
            $lead->restore();
        }

        if ($this->isNewLead) {
            Cache::increment($this->counterKey('created'));
            $lead->logActivity(ActivityType::Import, 'Importé depuis '.$this->import->file_name, null, ['import_id' => $this->import->id], $this->import->user_id);
        } else {
            $lead->logActivity(ActivityType::Import, 'Mis à jour par l\'import '.$this->import->file_name, null, ['import_id' => $this->import->id], $this->import->user_id);
        }

        ProcessImportedLead::dispatch(
            $lead->id,
            $this->isNewLead && ! empty($this->options['assign_to']) ? (int) $this->options['assign_to'] : null,
            $this->isNewLead,
        );
    }

    private function counterKey(string $what): string
    {
        return "import:{$this->import->id}:{$what}";
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $created = (int) Cache::pull("import:{$import->id}:created", 0);
        $duplicates = (int) Cache::pull("import:{$import->id}:duplicates", 0);

        $body = 'Import terminé : '.Number::format($import->successful_rows).' ligne(s) traitée(s), dont '
            .Number::format($created).' nouveau(x) lead(s) et '.Number::format($duplicates).' doublon(s) détecté(s).';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' ligne(s) en erreur (téléchargez le fichier d\'erreurs).';
        }

        $body .= ' Attribution et qualification IA en cours en arrière-plan.';

        return $body;
    }
}
