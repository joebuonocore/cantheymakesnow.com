<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

use Carbon\Carbon;
use AlbrightLabs\CanTheyMakeSnow\Models\Location;

use Cms\Classes\ComponentBase;

/**
 * WeatherData Component
 *
 * @link https://docs.octobercms.com/3.x/extend/cms-components.html
 */
class WeatherData extends ComponentBase
{
    public $lat;
    public $lon;
    public $snow;

    public $address;

    public $city;
    public $state;

    public $data;

    public function componentDetails()
    {
        return [
            'name' => 'Weather Data Component',
            'description' => 'Retrieves weather data based on coordinates or addresses, and calculates the wet-bulb temperature.'
        ];
    }

    /**
     * @link https://docs.octobercms.com/3.x/element/inspector-types.html
     */
    public function defineProperties()
    {
        return [];
    }

    public function onRun()
    {
        if(get('address')) {
            $this->setLatLon(get('address'));
        }

        if(get('lat') && get('lon')) {
            $this->lat = get('lat');
            $this->lon = get('lon');
        }

        if(!is_null($this->lat) && !is_null($this->lon)) {
            $this->data = $this->getData();
            $this->page['data'] = $this->data;
        }

        $this->page['lat'] = $this->lat;
        $this->page['lon'] = $this->lon;
        $this->page['snow'] = $this->snow;
        $this->page['city'] = $this->city;
        $this->page['state'] = $this->state;
        $this->page['address'] = $this->address;

        if($this->state && $this->city) {
            $location = Location::firstOrCreate([
                'city' => $this->city,
                'state' => $this->state,
            ]);

            $location->increment('lookups');
        }
    }

    public function onSubmit()
    {
        $address = urlencode(post('address'));
        return redirect()->to(url('/')."?address={$address}");
    }

    public function setLatLon($address)
    {
        // Get api Key
        $apiKey = env('GOOGLE_MAPS_API_KEY');

        // Replace these with your actual values
        $address = urlencode($address);

        // Build the Geocoding API URL
        $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$apiKey}";

        // Make the HTTP request to the Google Geocoding API
        $response = file_get_contents($geocodeUrl);
        
        if (!$response) {
            return [
                'error' => die("The Google Maps API seems to be offline, so we can't translate locations names to latitude and longitude right now. Please try again later.")
            ];
        }

        // Decode the JSON response
        $jsonData = json_decode($response, true);

        // Check if the request was successful and has any results
        if ($jsonData['status'] === 'OK' && count($jsonData['results']) > 0) {
            
            $this->lat = $jsonData['results'][0]['geometry']['location']['lat'];
            $this->lon = $jsonData['results'][0]['geometry']['location']['lng'];

        } else {    
            // Handle errors or zero results
            return [
                'error' => die("The Google Maps API could not convert that location name to latitude and longitude. Please go back and enter a different location.")
            ];
        }
    }

    public function getData()
    {
        // Get page URL
        $this_page_url = url('/');
        $apiKey = env('GOOGLE_MAPS_API_KEY');

        // Default cords false
        $has_coords = false;

        // Check if there are coordinates in the URL
        if ($this->lat && $this->lon) {

            $has_coords = true;

            // Specify the location for which you want to get the weather (latitude and longitude)
            $latitude = number_format($this->lat, 2, '.', ',');
            $longitude = number_format($this->lon, 2, '.', ',');

            $latitude = $this->customRound($latitude);
            $longitude = $this->customRound($longitude);

            // Make the request to the API
            $curl = curl_init("https://api.weather.gov/points/{$latitude},{$longitude}");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0']);
            $response = curl_exec($curl);
            $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            // Decode JSON into PHP array
            $location_data = json_decode($response, true);

            // Extract city and state
            $this->city = @$location_data['properties']['relativeLocation']['properties']['city'];
            $this->state = @$location_data['properties']['relativeLocation']['properties']['state'];
            
            if($this->city && $this->state) {
                $this->address = "{$this->city}, {$this->state}";
            }

            // Check if the request was successful (status code 200, 301)
            if (in_array($response_code, [200, 301])) {
                $data = json_decode($response, true);

                $gridId = $data['properties']['gridId'];
                $gridX = $data['properties']['gridX'];
                $gridY = $data['properties']['gridY'];

                // Make the request to the gridpoints API
                $curl = curl_init("https://api.weather.gov/gridpoints/{$gridId}/{$gridX},{$gridY}");
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0']);
                $response = curl_exec($curl);
                $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);

                // Check if the request was successful (status code 200, 301)
                if (in_array($response_code, [200, 301])) {
                    
                    $data = json_decode($response, true);

                    $temperatures = $data['properties']['temperature']['values'];
                    $humidities = $data['properties']['relativeHumidity']['values'];

                    // Get current UTC time
                    $currentTime = Carbon::now('UTC');

                    // Find closest temperature and humidity
                    $closestTemperature = $this->findClosestTime($temperatures, $currentTime);
                    $closestHumidity = $this->findClosestTime($humidities, $currentTime);

                    $wetBulb = null;
                    if ($closestTemperature['value'] !== null && $closestHumidity['value'] !== null) {
                        $dryBulbTemp = $closestTemperature['value'];
                        $relativeHumidity = $closestHumidity['value'] / 100;

                        // Calculate wet-bulb temperature
                        $wetBulb = $dryBulbTemp * atan(0.151977 * (sqrt($relativeHumidity + 8.313659)))
                            + atan($dryBulbTemp + $relativeHumidity)
                            - atan($relativeHumidity - 1.676331)
                            + 0.00391838 * pow($relativeHumidity, 3 / 2) * atan(0.023101 * $relativeHumidity)
                            - 4.686035;
                    }

                    $this->snow = ($wetBulb < 0) ? true : false;

                    return [
                        'lat' => $this->lat,
                        'lon' => $this->lon,
                        'closestTemperature' => $closestTemperature,
                        'closestHumidity' => $closestHumidity,
                        'wetBulb' => $wetBulb,
                    ];
                }
            }
        }
    }

    public function customRound($number) {
        // Round to 2 decimal places
        $rounded = round($number, 2);

        // Convert to string to manipulate the decimal part
        $roundedString = number_format($rounded, 2, '.', '');

        // Check if the last digit is 0
        if (substr($roundedString, -1) === '0') {
            // If the number is positive, add 0.01; if negative, subtract 0.01
            $adjusted = $rounded > 0 ? $rounded + 0.01 : $rounded - 0.01;
            // Round again to ensure it stays at 2 decimal places
            return round($adjusted, 2);
        }

        // If the last digit is not 0, return as is
        return $rounded;
    }

    // Function to find the closest time
    public function findClosestTime($values, $currentTime) {
        $closestTime = null;
        $minDifference = PHP_INT_MAX;
        $valueAtClosestTime = null;

        foreach ($values as $item) {
            // Parse validTime string to Carbon object
            $validTime = Carbon::parse(substr($item['validTime'], 0, 19), 'UTC');

            // Extract duration (in hours)
            if (preg_match('/PT(\d+)H/', $item['validTime'], $matches)) {
                $duration = (int)$matches[1];
                $validTime->addHours($duration);
            }

            // Calculate time difference
            $difference = abs($validTime->timestamp - $currentTime->timestamp);

            // Check if this is the closest time
            if ($difference < $minDifference) {
                $closestTime = $validTime;
                $minDifference = $difference;
                $valueAtClosestTime = $item['value'];
            }
        }

        return ['time' => $closestTime, 'value' => $valueAtClosestTime];
    }
}
