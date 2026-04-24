<?php namespace AlbrightLabs\CanTheyMakeSnow\Models;

use Model;

/**
 * Location Model
 *
 * @link https://docs.octobercms.com/3.x/extend/system/models.html
 */
class Location extends Model
{
    use \October\Rain\Database\Traits\Validation;

    /**
     * @var string table name
     */
    public $table = 'albrightlabs_cantheymakesnow_locations';

    /**
     * @var array rules for validation
     */
    public $rules = [];

    public $fillable = [
        'city',
        'state',
        'lat',
        'lon',
        'lookups',
        'last_looked_up_at',
    ];

    public $dates = ['last_looked_up_at'];
}
