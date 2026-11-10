<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

use Carbon\Carbon;
use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use AlbrightLabs\CanTheyMakeSnow\Classes\NoaaClient;
use AlbrightLabs\CanTheyMakeSnow\Classes\SnowReport;
use AlbrightLabs\CanTheyMakeSnow\Classes\Throttle;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;
use AlbrightLabs\CanTheyMakeSnow\Models\Subscriber;
use AlbrightLabs\CanTheyMakeSnow\Models\Subscription;

/**
 * Alerts Component
 *
 * Email alert signup on resort results (onSubscribe), area powder alerts
 * (onSubscribeArea) and the /alerts page: manage subscriptions by token, and
 * confirm before unsubscribing. Every new subscription is confirmed by its
 * own emailed link, so nobody can be signed up without their say-so.
 */
class Alerts extends ComponentBase
{
    const RESEND_SECONDS = 600;

    public function componentDetails()
    {
        return [
            'name' => 'Alerts',
            'description' => 'Snowmaking and powder email alert signup and management.',
        ];
    }

    public function defineProperties()
    {
        return [];
    }

    public function onRun()
    {
        $action = $this->param('action');
        $this->page['linkExpired'] = (bool) get('expired');
        if (!in_array($action, ['manage', 'unsubscribe'], true)) {
            return;
        }

        $subscriber = Subscriber::findByToken($this->param('token'));
        $this->page['alertsAction'] = $subscriber ? $action : 'invalid';
        $this->page['subscriber'] = $subscriber;
        $this->page['confirmedLabel'] = $subscriber && is_string(get('confirmed')) ? $this->confirmedLabel($subscriber, get('confirmed')) : null;
        $this->prepareManage($subscriber);
        $this->page->meta_title = 'Your alerts · Can They Make Snow?';
    }

    /**
     * Signup from a resort result. Always answers with a message for the
     * form; never reveals whether an address is already subscribed beyond
     * "check your inbox".
     */
    public function onSubscribe()
    {
        $data = [
            'email' => trim((string) post('email')),
            'resort' => (string) post('resort'),
            'snowmaking' => (bool) post('snowmaking'),
            'snowmaking_min_tier' => post('snowmaking_min_tier', 'marginal'),
            'powder' => (bool) post('powder'),
            'powder_threshold_in' => (int) post('powder_threshold_in', 6),
            'units' => post('units') === 'c' ? 'c' : 'f',
        ];

        $validator = Validator::make($data, [
            'email' => 'required|email|max:254',
            'resort' => 'required',
            'snowmaking_min_tier' => 'in:' . implode(',', Subscription::SNOWMAKING_TIERS),
            'powder_threshold_in' => 'in:' . implode(',', Subscription::POWDER_THRESHOLDS),
        ]);
        if ($validator->fails()) {
            return $this->reply(false, 'Please enter a valid email address.');
        }
        if (!$data['snowmaking'] && !$data['powder']) {
            return $this->reply(false, 'Pick at least one kind of alert.');
        }

        $resort = Resort::isEnabled()->where('slug', $data['resort'])->first();
        if (!$resort) {
            return $this->reply(false, 'Alerts are only available for ski resorts.');
        }
        if (!Throttle::attempt('subscribe')) {
            return $this->reply(false, 'Too many signups from your connection. Please try again in a minute.');
        }

        $subscriber = Subscriber::firstOrNew(['email' => strtolower($data['email'])]);
        if (!$subscriber->exists) {
            $subscriber->units = $data['units'];
            $subscriber->save();
        }

        $subscription = Subscription::where(['subscriber_id' => $subscriber->id, 'resort_id' => $resort->id])->first() ?: new Subscription();
        $subscription->subscriber_id = $subscriber->id;
        $subscription->resort_id = $resort->id;
        $inbox = "Check {$subscriber->email} for a link to confirm.";

        // Already confirmed: don't let anyone change someone else's settings;
        // send the owner their manage link instead.
        if ($subscription->exists && $subscription->confirmed_at && !$subscriber->unsubscribed_at) {
            $sent = $this->sendOnce("manage:{$subscriber->id}", 'albrightlabs.cantheymakesnow::mail.manage', $subscriber, [
                'resort' => $resort->label,
                'manageUrl' => $subscriber->manageUrl(),
            ]);
            if (!$sent) {
                return $this->reply(false, "We couldn't send email just now. Please try again in a few minutes.");
            }
            return $this->reply(true, "You're already getting alerts for {$resort->name}. We've emailed a link to change them. {$inbox}");
        }

        $subscription->fill(array_intersect_key($data, array_flip(['snowmaking', 'snowmaking_min_tier', 'powder', 'powder_threshold_in'])));
        $subscription->confirmed_at = null;
        $subscription->save();

        $sent = $this->sendOnce("confirm:{$subscription->id}", 'albrightlabs.cantheymakesnow::mail.confirm', $subscriber, [
            'resort' => $resort->label,
            'what' => $subscription->describe(),
            'confirmUrl' => $subscription->confirmUrl(),
            'days' => Subscription::CONFIRM_LINK_DAYS,
        ]);
        if (!$sent) {
            return $this->reply(false, "We couldn't send the confirmation email just now. Please try again in a few minutes.");
        }

        return $this->reply(true, "Almost done. {$inbox}");
    }

    /**
     * Area powder alert signup: "email me when any resort within 200 miles
     * of this place is forecast for 6 inches". The place is a resort name,
     * the visitor's coordinates (rounded) or a town we geocode.
     */
    public function onSubscribeArea()
    {
        $data = [
            'email' => trim((string) post('email')),
            'place' => trim((string) post('place')),
            'area_radius_mi' => (int) post('radius', 200),
            'powder_threshold_in' => (int) post('powder_threshold_in', 6),
            'units' => post('units') === 'c' ? 'c' : 'f',
        ];
        $validator = Validator::make($data, [
            'email' => 'required|email|max:254',
            'place' => 'max:120',
            'area_radius_mi' => 'in:' . implode(',', Subscription::RADII),
            'powder_threshold_in' => 'in:' . implode(',', Subscription::POWDER_THRESHOLDS),
        ]);
        if ($validator->fails()) {
            return $this->reply(false, 'Please enter a valid email address.');
        }
        $hasCoords = is_numeric(post('lat')) && is_numeric(post('lon'));
        if (!$data['place'] && !$hasCoords) {
            return $this->reply(false, 'Enter a town or use your location.');
        }
        if (!Throttle::attempt('subscribe')) {
            return $this->reply(false, 'Too many signups from your connection. Please try again in a minute.');
        }

        $area = $this->resolveArea($data['place'], $hasCoords ? (float) post('lat') : null, $hasCoords ? (float) post('lon') : null);
        if (isset($area['error'])) {
            return $this->reply(false, $area['error']);
        }

        $subscriber = Subscriber::firstOrNew(['email' => strtolower($data['email'])]);
        if (!$subscriber->exists) {
            $subscriber->units = $data['units'];
            $subscriber->save();
        }
        $inbox = "Check {$subscriber->email} for a link to confirm.";

        $subscription = Subscription::areas()->where([
            'subscriber_id' => $subscriber->id,
            'area_lat' => $area['lat'],
            'area_lon' => $area['lon'],
        ])->first();
        $confirmedAreas = $subscriber->subscriptions()->areas()->whereNotNull('confirmed_at')->count();

        // Already confirmed, or at the limit: send the owner their manage link instead.
        $active = $subscription && $subscription->confirmed_at && !$subscriber->unsubscribed_at;
        if ($active || (!$subscription && $confirmedAreas >= Subscription::MAX_AREAS)) {
            $sent = $this->sendOnce("manage:{$subscriber->id}", 'albrightlabs.cantheymakesnow::mail.manage', $subscriber, [
                'resort' => $active ? $subscription->placeLabel() : $area['label'],
                'limit' => $active ? null : Subscription::MAX_AREAS,
                'manageUrl' => $subscriber->manageUrl(),
            ]);
            if (!$sent) {
                return $this->reply(false, "We couldn't send email just now. Please try again in a few minutes.");
            }
            return $this->reply(true, $active
                ? "You already have alerts near {$area['label']}. We've emailed a link to change them. {$inbox}"
                : 'You can have up to ' . Subscription::MAX_AREAS . " areas. We've emailed a link to manage them. {$inbox}");
        }

        $subscription = $subscription ?: new Subscription();
        $subscription->subscriber_id = $subscriber->id;
        $subscription->resort_id = null;
        $subscription->area_label = $area['label'];
        $subscription->area_lat = $area['lat'];
        $subscription->area_lon = $area['lon'];
        $subscription->area_radius_mi = $data['area_radius_mi'];
        $subscription->snowmaking = false;
        $subscription->powder = true;
        $subscription->powder_threshold_in = $data['powder_threshold_in'];
        $subscription->confirmed_at = null;
        $subscription->save();

        $sent = $this->sendOnce("confirm:{$subscription->id}", 'albrightlabs.cantheymakesnow::mail.confirm', $subscriber, [
            'resort' => $subscription->placeLabel(),
            'what' => $subscription->describe(),
            'confirmUrl' => $subscription->confirmUrl(),
            'days' => Subscription::CONFIRM_LINK_DAYS,
        ]);
        if (!$sent) {
            return $this->reply(false, "We couldn't send the confirmation email just now. Please try again in a few minutes.");
        }

        return $this->reply(true, "Almost done. {$inbox}");
    }

    /**
     * An area's centre and name: ['label', 'lat', 'lon'] rounded to two
     * decimals (about a kilometre), or ['error' => message].
     */
    protected function resolveArea(string $place, ?float $lat, ?float $lon): array
    {
        $report = new SnowReport();
        if ($lat !== null && $lon !== null) {
            if (!$report->isValidCoordinate($lat, $lon)) {
                return ['error' => "Those coordinates don't look right. Try typing your town instead."];
            }
        } elseif ($resort = $report->matchResortName($place)) {
            return ['label' => $resort->label, 'lat' => round((float) $resort->lat, 2), 'lon' => round((float) $resort->lon, 2)];
        } else {
            $geocode = $report->geocode($place);
            if (isset($geocode['error'])) {
                return ['error' => SnowReport::errorMessage($geocode['error'])];
            }
            [$lat, $lon] = [$geocode['lat'], $geocode['lon']];
        }

        // NOAA names the nearest town for free, and confirms the point is in the US.
        $points = (new NoaaClient())->points(round($lat, 2), round($lon, 2));
        if (!$points['ok']) {
            return ['error' => SnowReport::errorMessage($points['error'] === 'not_found' ? 'outside_us' : 'weather_unavailable')];
        }
        $town = $points['data']['properties']['relativeLocation']['properties'] ?? [];
        $label = isset($town['city'], $town['state']) ? "{$town['city']}, {$town['state']}" : mb_substr($place ?: 'your location', 0, 120);

        return ['label' => $label, 'lat' => round($lat, 2), 'lon' => round($lon, 2)];
    }

    /**
     * What the confirm link just turned on, for the manage page notice:
     * a resort slug, or "area-{id}" for an area alert.
     */
    protected function confirmedLabel(Subscriber $subscriber, string $confirmed): ?string
    {
        $query = $subscriber->subscriptions()->with('resort');
        $subscription = preg_match('/^area-(\d+)$/', $confirmed, $m)
            ? $query->areas()->whereKey((int) $m[1])->first()
            : $query->whereHas('resort', fn ($q) => $q->where('slug', $confirmed))->first();
        return $subscription?->placeLabel();
    }

    /**
     * Save the manage form: per-resort alert types and thresholds, units.
     */
    public function onSave()
    {
        $subscriber = $this->subscriberFromPost();
        $subscriber->units = post('units') === 'c' ? 'c' : 'f';
        $subscriber->save();

        foreach ($subscriber->subscriptions as $subscription) {
            $fields = (array) post("subs.{$subscription->id}", []);
            $subscription->snowmaking = !empty($fields['snowmaking']);
            $subscription->powder = !empty($fields['powder']);
            if (in_array($fields['snowmaking_min_tier'] ?? null, Subscription::SNOWMAKING_TIERS, true)) {
                $subscription->snowmaking_min_tier = $fields['snowmaking_min_tier'];
            }
            if (in_array((int) ($fields['powder_threshold_in'] ?? 0), Subscription::POWDER_THRESHOLDS, true)) {
                $subscription->powder_threshold_in = (int) $fields['powder_threshold_in'];
            }
            if ($subscription->isArea() && in_array((int) ($fields['radius'] ?? 0), Subscription::RADII, true)) {
                $subscription->area_radius_mi = (int) $fields['radius'];
            }
            $subscription->save();
        }

        return $this->refreshManage($subscriber, 'Saved.');
    }

    public function onRemove()
    {
        $subscriber = $this->subscriberFromPost();
        $subscriber->subscriptions()->where('id', (int) post('id'))->delete();
        return $this->refreshManage($subscriber, 'Removed.');
    }

    public function onUnsubscribeAll()
    {
        $subscriber = $this->subscriberFromPost();
        $subscriber->unsubscribed_at = Carbon::now();
        $subscriber->save();
        return $this->refreshManage($subscriber, "You're unsubscribed. You won't get any more alerts.");
    }

    public function onResubscribe()
    {
        $subscriber = $this->subscriberFromPost();
        $subscriber->unsubscribed_at = null;
        $subscriber->save();
        return $this->refreshManage($subscriber, 'Welcome back. Your alerts are on again.');
    }

    protected function subscriberFromPost(): Subscriber
    {
        $subscriber = Subscriber::findByToken(post('token'));
        if (!$subscriber) {
            throw new \ApplicationException('That link is no longer valid.');
        }
        return $subscriber;
    }

    protected function prepareManage(?Subscriber $subscriber): void
    {
        $this->page['subscriber'] = $subscriber;
        $confirmed = $subscriber ? $subscriber->subscriptions()->whereNotNull('confirmed_at')->with('resort')->get() : collect();
        $this->page['subscriptions'] = $confirmed->reject(fn ($s) => $s->isArea())->sortBy('resort.name')->values();
        $this->page['areaSubscriptions'] = $confirmed->filter(fn ($s) => $s->isArea())->sortBy('area_label')->values();
    }

    protected function refreshManage(Subscriber $subscriber, string $message): array
    {
        $this->prepareManage($subscriber->fresh());
        $this->page['alertsAction'] = 'manage';
        $this->page['alertsMessage'] = $message;
        return ['#alertsManage' => $this->controller->renderPartial('alerts-manage')];
    }

    /**
     * Send a transactional email unless the same one went out recently
     * (double clicks, repeated submits). Returns false if sending failed,
     * so the visitor can be told and retry.
     */
    protected function sendOnce(string $key, string $view, Subscriber $subscriber, array $vars): bool
    {
        $cacheKey = "ctms:mail:{$key}";
        if (!Cache::add($cacheKey, 1, self::RESEND_SECONDS)) {
            return true;
        }
        try {
            Mail::send($view, $vars + ['manageUrl' => $subscriber->manageUrl()], function ($message) use ($subscriber) {
                $message->to($subscriber->email);
            });
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            Log::error('Alert signup email failed', ['view' => $view, 'subscriber' => $subscriber->id, 'error' => $e->getMessage()]);
            return false;
        }
        return true;
    }

    protected function reply(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => $message];
    }
}
