<?php namespace AlbrightLabs\CanTheyMakeSnow\Models;

use Model;
use Illuminate\Support\Facades\URL;

/**
 * Subscription Model: which alerts one subscriber wants for one resort, or
 * powder alerts for an area (any resort within area_radius_mi of a place;
 * resort_id is null). Each new subscription is confirmed by its own emailed
 * link, and the last_* columns remember what was already sent so alerts
 * aren't repeated.
 */
class Subscription extends Model
{
    use \October\Rain\Database\Traits\Validation;

    const POWDER_THRESHOLDS = [3, 6, 12];
    const SNOWMAKING_TIERS = ['marginal', 'great'];
    const CONFIRM_LINK_DAYS = 7;
    const RADII = [100, 200, 300];
    const MAX_AREAS = 3;

    public $table = 'albrightlabs_cantheymakesnow_subscriptions';

    public $rules = [
        'snowmaking_min_tier' => 'in:marginal,great',
        'powder_threshold_in' => 'in:3,6,12',
        'area_radius_mi' => 'nullable|in:100,200,300',
    ];

    public $fillable = ['snowmaking', 'snowmaking_min_tier', 'powder', 'powder_threshold_in'];

    public $dates = [
        'confirmed_at',
        'last_snowmaking_start', 'last_snowmaking_end', 'last_snowmaking_sent_at',
        'last_powder_start', 'last_powder_end', 'last_powder_sent_at',
    ];

    public $belongsTo = [
        'subscriber' => Subscriber::class,
        'resort' => Resort::class,
    ];

    /**
     * Confirmed, wanting at least one alert type, with an active subscriber
     * and either an enabled resort or an area.
     */
    public function scopeActive($query)
    {
        return $query->whereNotNull('confirmed_at')
            ->where(fn ($q) => $q->where('snowmaking', true)->orWhere('powder', true))
            ->whereHas('subscriber', fn ($q) => $q->active())
            ->where(fn ($q) => $q->whereNull('resort_id')->orWhereHas('resort', fn ($r) => $r->isEnabled()));
    }

    public function scopeAreas($query)
    {
        return $query->whereNull('resort_id');
    }

    public function isArea(): bool
    {
        return $this->resort_id === null;
    }

    /**
     * "Killington Resort, VT" or "resorts within 200 miles of Albany, NY".
     */
    public function placeLabel(): string
    {
        return $this->isArea()
            ? "resorts within {$this->area_radius_mi} miles of {$this->area_label}"
            : (string) $this->resort?->label;
    }

    /**
     * Signed on the relative path (checked with hasValidSignature(false)),
     * so http/https or host differences behind a proxy can't break it.
     */
    public function confirmUrl(): string
    {
        $path = URL::temporarySignedRoute('ctms.alerts.confirm', now()->addDays(self::CONFIRM_LINK_DAYS), ['subscription' => $this->id], false);
        return url($path);
    }

    public function describe(): string
    {
        $parts = [];
        if ($this->snowmaking) {
            $parts[] = $this->snowmaking_min_tier === 'great' ? 'great snowmaking windows' : 'snowmaking windows';
        }
        if ($this->powder) {
            $parts[] = "powder ({$this->powder_threshold_in}\u{2033}+ in 48 hours)";
        }
        return implode(' and ', $parts) ?: 'nothing';
    }
}
