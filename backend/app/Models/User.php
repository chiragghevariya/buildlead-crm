<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'regions',
        'project_types',
        'max_open_leads',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'regions' => 'array',
            'project_types' => 'array',
            'max_open_leads' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ---- Relations -------------------------------------------------------

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function openLeads(): HasMany
    {
        return $this->leads()->open();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'created_by');
    }

    public function managedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'manager_id');
    }

    // ---- Scopes ----------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSalesReps(Builder $query): Builder
    {
        return $query->where('role', UserRole::Sales->value);
    }

    // ---- Helpers ---------------------------------------------------------

    public function hasRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $value = $role instanceof UserRole ? $role : UserRole::tryFrom($role);
            if ($value === $this->role) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    public function isSales(): bool
    {
        return $this->role === UserRole::Sales;
    }

    /** Admins and managers see every lead; sales only their own. */
    public function canSeeAllLeads(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function hasCapacity(): bool
    {
        return $this->leads()->whereIn('status', LeadStatus::open())->count() < $this->max_open_leads;
    }

    /** Postcode prefix match against the rep's regions (e.g. "10" matches "10115"). */
    public function coversPostcode(?string $postcode): bool
    {
        if (blank($postcode)) {
            return false;
        }

        foreach ($this->regions ?? [] as $prefix) {
            if (str_starts_with($postcode, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))
            ->filter()
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');
    }
}
