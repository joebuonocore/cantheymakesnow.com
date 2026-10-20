<?php namespace AlbrightLabs\CanTheyMakeSnow\Models;

use Model;
use Illuminate\Support\Str;

/**
 * Subscriber Model: one email address. The token identifies the subscriber
 * in manage and unsubscribe links, so those links need no login.
 */
class Subscriber extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $table = 'albrightlabs_cantheymakesnow_subscribers';

    public $rules = [
        'email' => 'required|email|max:254',
        'units' => 'in:f,c',
    ];

    public $fillable = ['email', 'units'];

    public $dates = ['confirmed_at', 'unsubscribed_at', 'last_emailed_at'];

    public $hasMany = [
        'subscriptions' => Subscription::class,
    ];

    public function beforeCreate()
    {
        $this->token = $this->token ?: Str::random(40);
    }

    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = strtolower(trim((string) $value));
    }

    public function scopeActive($query)
    {
        return $query->whereNotNull('confirmed_at')->whereNull('unsubscribed_at');
    }

    public static function findByToken(?string $token): ?self
    {
        return $token && strlen($token) === 40 ? static::where('token', $token)->first() : null;
    }

    public function manageUrl(): string
    {
        return url('alerts/manage/' . $this->token);
    }

    public function unsubscribeUrl(): string
    {
        return url('alerts/unsubscribe/' . $this->token);
    }
}
