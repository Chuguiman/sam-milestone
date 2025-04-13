<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoredCountry extends Model
{
    use HasFactory;

    /**
     * Los atributos que son asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'country_id',
        'is_active',
        'settings',
    ];

    /**
     * Los atributos que deben convertirse a tipos nativos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'json',
    ];

    /**
     * Obtiene la organización a la que pertenece este país monitoreado.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Obtiene el país asociado.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Devuelve un valor específico de la configuración.
     *
     * @param string $key Clave de configuración
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    public function getSetting(string $key, $default = null)
    {
        $settings = $this->settings;
        
        if (!$settings || !isset($settings[$key])) {
            return $default;
        }
        
        return $settings[$key];
    }

    /**
     * Establece un valor en la configuración.
     *
     * @param string $key Clave de configuración
     * @param mixed $value Valor a establecer
     * @return $this
     */
    public function setSetting(string $key, $value)
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
        
        return $this;
    }

    /**
     * Scope para obtener solo los países activos.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para países de una organización específica.
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}