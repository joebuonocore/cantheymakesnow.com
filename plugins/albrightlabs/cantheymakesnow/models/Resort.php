<?php namespace AlbrightLabs\CanTheyMakeSnow\Models;

use Model;

/**
 * Resort Model: a curated ski area, searchable by name and shown on the map.
 */
class Resort extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $table = 'albrightlabs_cantheymakesnow_resorts';

    public $rules = [
        'name' => 'required',
        'slug' => 'required|alpha_dash|unique:albrightlabs_cantheymakesnow_resorts',
        'state' => 'required|size:2',
        'lat' => 'required|numeric|between:-90,90',
        'lon' => 'required|numeric|between:-180,180',
    ];

    public $fillable = [
        'name',
        'slug',
        'state',
        'lat',
        'lon',
        'base_elev_ft',
        'summit_elev_ft',
        'is_enabled',
    ];

    public $dates = ['last_checked_at'];

    public function scopeIsEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function getLabelAttribute(): string
    {
        return "{$this->name}, {$this->state}";
    }
}
