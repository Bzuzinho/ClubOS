<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use App\Models\Familia;
use App\Models\FamiliaUser;
use App\Models\UserGuardian;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasUuids;


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        // Core Laravel fields (keep in English as per framework convention)
        'name',
        'email',
        'password',
        // All fields in Portuguese
        'numero_socio',
        'perfil',
        'tipo_membro',
        'estado',
        'menor',
        'escalao',
        'ativo_desportivo',
        'data_validade_cc',
        'foto_perfil',
        'encarregado_educacao',
        'educandos',
        'tipo_mensalidade',
        'conta_corrente',
        'centro_custo',
        'numero_pmb',
        'data_inscricao',
        'inscricao',
        'email_utilizador',
        'senha',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'senha',
        'user_password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Dates
            'data_nascimento' => 'date',
            'data_validade_cc' => 'date',
            'data_inscricao' => 'date',
            'data_atestado_medico' => 'date',
            'data_rgpd' => 'date',
            'data_consentimento' => 'date',
            'data_afiliacao' => 'date',
            // Booleans
            'menor' => 'boolean',
            'rgpd' => 'boolean',
            'consentimento' => 'boolean',
            'afiliacao' => 'boolean',
            'declaracao_de_transporte' => 'boolean',
            'ativo_desportivo' => 'boolean',
            // JSON
            'tipo_membro' => 'array',
            'escalao' => 'array',
            'encarregado_educacao' => 'array',
            'educandos' => 'array',
            'centro_custo' => 'array',
            'arquivo_atestado_medico' => 'array',
            // Decimals
            'conta_corrente' => 'decimal:2',
            // Integers
            'numero_irmaos' => 'integer',
        ];
    }

    public function getFotoPerfilAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (str_starts_with($value, 'http') || str_starts_with($value, '/storage/')) {
            return $value;
        }

        return Storage::url($value);
    }

    // Relationships
    public function athleteSportsData(): HasOne
    {
        return $this->hasOne(AthleteSportsData::class, 'user_id');
    }

    public function dadosFinanceiros(): HasOne
    {
        return $this->hasOne(DadosFinanceiros::class, 'user_id');
    }

    public function dadosPessoais(): HasOne
    {
        return $this->hasOne(DadosPessoais::class, 'user_id');
    }

    public function dadosConfiguracao(): HasOne
    {
        return $this->hasOne(DadosConfiguracao::class, 'user_id');
    }

    public function centrosCusto(): BelongsToMany
    {
        return $this->belongsToMany(CostCenter::class, 'centro_custo_user', 'user_id', 'centro_custo_id')
            ->withPivot('peso')
            ->withTimestamps();
    }

    public function createdEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'criado_por');
    }

    public function convocations(): HasMany
    {
        return $this->hasMany(EventConvocation::class, 'user_id');
    }

    public function givenConvocations(): HasMany
    {
        return $this->hasMany(EventConvocation::class, 'called_up_by');
    }

    // Legacy relation (old events architecture; not used by canonical Sports module).
    public function eventAttendances(): HasMany
    {
        return $this->hasMany(EventAttendance::class, 'user_id');
    }

    // Legacy relation (old events architecture; not used by canonical Sports module).
    public function eventResults(): HasMany
    {
        return $this->hasMany(EventResult::class, 'user_id');
    }

    // Legacy relation (old events architecture; not used by canonical Sports module).
    public function resultProvas(): HasMany
    {
        return $this->hasMany(ResultProva::class, 'user_id');
    }

    public function createdTrainings(): HasMany
    {
        return $this->hasMany(Training::class, 'criado_por');
    }

    public function trainingAthletes(): HasMany
    {
        return $this->hasMany(TrainingAthlete::class, 'user_id');
    }

    // Legacy relation (old presences architecture; not used by canonical Sports module).
    public function presences(): HasMany
    {
        return $this->hasMany(Presence::class, 'user_id');
    }

    public function competitionRegistrations(): HasMany
    {
        return $this->hasMany(CompetitionRegistration::class, 'user_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class, 'user_id');
    }

    public function competitionResults(): HasMany
    {
        return $this->hasMany(Result::class, 'user_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'user_id');
    }

    public function convocationMovements(): HasMany
    {
        return $this->hasMany(ConvocationMovement::class, 'user_id');
    }

    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class, 'user_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Sale::class, 'user_id');
    }

    public function salesMade(): HasMany
    {
        return $this->hasMany(Sale::class, 'seller_id');
    }

    public function newsItems(): HasMany
    {
        return $this->hasMany(NewsItem::class, 'author_id');
    }

    public function sentCommunications(): HasMany
    {
        return $this->hasMany(Communication::class, 'sender_id');
    }

    public function internalMessagesSent(): HasMany
    {
        return $this->hasMany(InternalMessage::class, 'sender_id');
    }

    public function internalMessageRecipients(): HasMany
    {
        return $this->hasMany(InternalMessageRecipient::class, 'recipient_id');
    }

    public function communicationCampaigns(): HasMany
    {
        return $this->hasMany(CommunicationCampaign::class, 'author_id');
    }

    public function inAppAlerts(): HasMany
    {
        return $this->hasMany(InAppAlert::class, 'user_id');
    }

    public function createdConvocationGroups(): HasMany
    {
        return $this->hasMany(ConvocationGroup::class, 'criado_por');
    }

    public function convocationAthletes(): HasMany
    {
        return $this->hasMany(ConvocationAthlete::class, 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(UserRelationship::class);
    }

    public function relatedToMe(): HasMany
    {
        return $this->hasMany(UserRelationship::class, 'related_user_id');
    }

    public function userTypes(): BelongsToMany
    {
        return $this->belongsToMany(UserType::class, 'user_user_type', 'user_id', 'user_type_id');
    }

    public function ageGroup(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'age_group_id');
    }

    public function encarregados(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_guardian', 'user_id', 'guardian_id')
            ->using(UserGuardian::class)
            ->withPivot('id')
            ->withTimestamps();
    }

    public function educandos(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_guardian', 'guardian_id', 'user_id')
            ->using(UserGuardian::class)
            ->withPivot('id')
            ->withTimestamps();
    }

    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Familia::class, 'familia_user', 'user_id', 'familia_id')
            ->using(FamiliaUser::class)
            ->withPivot([
                'id',
                'papel_na_familia',
                'pode_editar',
                'pode_ver_financeiro',
                'pode_ver_desportivo',
                'pode_ver_documentos',
                'pode_ver_comunicacoes',
            ])
            ->withTimestamps();
    }

    public function familyMemberships(): HasMany
    {
        return $this->hasMany(FamiliaUser::class, 'user_id');
    }

    public function responsibleFamilies(): HasMany
    {
        return $this->hasMany(Familia::class, 'responsavel_user_id');
    }

    public function eventsCreated(): HasMany
    {
        return $this->hasMany(Event::class, 'criado_por');
    }
}
