<?php namespace AlbrightLabs\CanTheyMakeSnow\Models;

use Model;

/**
 * Resort Model: a curated ski area, searchable by name and shown on the map.
 */
class Resort extends Model
{
    use \October\Rain\Database\Traits\Validation;
    use \October\Rain\Database\Traits\Nullable;

    public $table = 'albrightlabs_cantheymakesnow_resorts';

    public $rules = [
        'name' => 'required',
        'slug' => 'required|alpha_dash|unique:albrightlabs_cantheymakesnow_resorts',
        'state' => 'required|size:2',
        'lat' => 'required|numeric|between:-90,90',
        'lon' => 'required|numeric|between:-180,180',
        'summit_lat' => 'nullable|numeric|between:-90,90|required_with:summit_lon',
        'summit_lon' => 'nullable|numeric|between:-180,180|required_with:summit_lat',
    ];

    /**
     * @var array nullable: blank summit fields in the backend mean "estimate it".
     */
    public $nullable = ['summit_lat', 'summit_lon'];

    public $fillable = [
        'name',
        'slug',
        'state',
        'lat',
        'lon',
        'summit_lat',
        'summit_lon',
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

    public function getStateNameAttribute(): string
    {
        return self::STATE_NAMES[$this->state] ?? $this->state;
    }

    /**
     * The resort's own page, e.g. /resorts/killington-resort.
     */
    public function getUrlAttribute(): string
    {
        return self::urlFor($this->slug);
    }

    public static function urlFor(string $slug): string
    {
        return rtrim(url('/'), '/') . '/resorts/' . $slug;
    }

    const STATE_NAMES = [
        'AK' => 'Alaska', 'AZ' => 'Arizona', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut',
        'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'MA' => 'Massachusetts',
        'MD' => 'Maryland', 'ME' => 'Maine', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MO' => 'Missouri',
        'MT' => 'Montana', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'NH' => 'New Hampshire',
        'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NV' => 'Nevada', 'NY' => 'New York', 'OH' => 'Ohio',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'UT' => 'Utah',
        'VA' => 'Virginia', 'VT' => 'Vermont', 'WA' => 'Washington', 'WI' => 'Wisconsin', 'WV' => 'West Virginia',
        'WY' => 'Wyoming',
    ];
}
