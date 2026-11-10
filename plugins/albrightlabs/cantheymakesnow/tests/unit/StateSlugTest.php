<?php

use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

// The model only needs October's base Model class here, not the app.
if (!class_exists('Model')) {
    class_alias(\October\Rain\Database\Model::class, 'Model');
}
require_once __DIR__ . '/../../classes/WeatherService.php';
require_once __DIR__ . '/../../models/Resort.php';

class StateSlugTest extends TestCase
{
    public function testStateSlugsRoundTrip()
    {
        $this->assertSame('vermont', Resort::stateSlug('VT'));
        $this->assertSame('new-york', Resort::stateSlug('NY'));
        $this->assertSame('NY', Resort::stateCodeForSlug('new-york'));
        $this->assertSame('WV', Resort::stateCodeForSlug('west-virginia'));
        $this->assertNull(Resort::stateCodeForSlug('killington-resort'));
        foreach (array_keys(Resort::STATE_NAMES) as $code) {
            $this->assertSame($code, Resort::stateCodeForSlug(Resort::stateSlug($code)));
        }
    }

    public function testNoSeededResortUsesAStateSlug()
    {
        $slugs = array_column(json_decode(file_get_contents(__DIR__ . '/../../updates/seeds/resorts.json'), true), 'slug');
        $states = array_map([Resort::class, 'stateSlug'], array_keys(Resort::STATE_NAMES));

        $this->assertNotEmpty($slugs);
        $this->assertSame([], array_values(array_intersect($slugs, $states)));
    }
}
