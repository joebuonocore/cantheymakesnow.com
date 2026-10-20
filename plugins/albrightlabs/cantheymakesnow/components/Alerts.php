<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

use Carbon\Carbon;
use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use AlbrightLabs\CanTheyMakeSnow\Classes\Throttle;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;
use AlbrightLabs\CanTheyMakeSnow\Models\Subscriber;
use AlbrightLabs\CanTheyMakeSnow\Models\Subscription;

/**
 * Alerts Component
 *
 * Email alert signup on resort results (onSubscribe) and the /alerts page:
 * manage subscriptions by token, and confirm before unsubscribing.
 * Every new resort subscription is confirmed by its own emailed link, so
 * nobody can be signed up without their say-so.
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
        $this->page['confirmedResort'] = $subscriber && get('confirmed')
            ? $subscriber->subscriptions()->whereHas('resort', fn ($q) => $q->where('slug', get('confirmed')))->with('resort')->first()?->resort
            : null;
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
        $this->page['subscriptions'] = $subscriber
            ? $subscriber->subscriptions()->whereNotNull('confirmed_at')->with('resort')->get()->sortBy('resort.name')->values()
            : collect();
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
