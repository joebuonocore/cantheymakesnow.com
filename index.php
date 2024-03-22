<!DOCTYPE html>
<head>
    <title>Can they blow snow?</title>
</head>
<html>
<body>

<?php

$has_coords = false;

// make sure we have coordinates
if (isset($_GET['lat']) && isset($_GET['lon'])) {

    $has_coords = true;

    // Specify the location for which you want to get the weather (latitude and longitude)
    $latitude = number_format($_GET['lat'], 2, '.', ',');
    $longitude = number_format($_GET['lon'], 2, '.', ',');

    // Make the request to the API
    $curl = curl_init("https://api.weather.gov/points/{$latitude},{$longitude}");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0']);
    $response = curl_exec($curl);
    $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    // Check if the request was successful (status code 200)
    if ($response_code === 200) {
        $data = json_decode($response, true);

        $gridId = $data['properties']['gridId'];
        $gridX = $data['properties']['gridX'];
        $gridY = $data['properties']['gridY'];

        // Make the request to the API
        $curl = curl_init("https://api.weather.gov/gridpoints/{$gridId}/{$gridX},{$gridY}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0']);
        $response = curl_exec($curl);
        $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        // Check if the request was successful (status code 200)
        if ($response_code === 200) {
            $data = json_decode($response, true);

            // Get temperatures for the current date
            $temperatures = $data['properties']['temperature']['values'];
            $currentTime = date('Y-m-d\T');
            $matchedTimes = [];
            foreach ($temperatures as $item) {
                if (strpos($item['validTime'], $currentTime) !== false) {
                    $matchedTimes[] = $item;
                }
            }

            // Get current UTC time
            $currentTime = new DateTime('now', new DateTimeZone('UTC'));

            // Initialize variables for closest time and difference
            $closestTime = null;
            $minDifference = PHP_INT_MAX;

            // Loop through each validTime
            foreach ($matchedTimes as $item) {
                // Parse validTime string to DateTime object
                $validTime = new DateTime(substr($item['validTime'], 0, 19)); // Extracting only the date and time part

                // Extract duration (in hours)
                $duration = intval(substr($item['validTime'], -4, -2)); // Extracting the duration part (e.g., PT2H)

                // Add duration to the validTime
                $validTime->modify("+" . $duration . " hour");

                // Calculate time difference
                $difference = abs($validTime->getTimestamp() - $currentTime->getTimestamp());

                // Check if the difference is smaller than the current minimum difference
                if ($difference < $minDifference) {
                    // Update closest time and minimum difference
                    $closestTime = $validTime;
                    $minDifference = $difference;
                    // Store the associated value of the closest time
                    $dryBulbTemp = $item['value'];
                }
            }

            // Get humitiy for the current date
            $humidities = $data['properties']['relativeHumidity']['values'];
            $currentTime = date('Y-m-d\TH:');
            $matchedTimes = [];
            foreach ($humidities as $item) {
                if (strpos($item['validTime'], $currentTime) !== false) {
                    $matchedTimes[] = $item;
                }
            }

            // Get current UTC time
            $currentTime = new DateTime('now', new DateTimeZone('UTC'));

            // Initialize variables for closest time and difference
            $closestTime = null;
            $minDifference = PHP_INT_MAX;

            // Loop through each validTime
            foreach ($matchedTimes as $item) {
                // Parse validTime string to DateTime object
                $validTime = new DateTime(substr($item['validTime'], 0, 19)); // Extracting only the date and time part

                // Extract duration (in hours)
                $duration = intval(substr($item['validTime'], -4, -2)); // Extracting the duration part (e.g., PT2H)

                // Add duration to the validTime
                $validTime->modify("+" . $duration . " hour");

                // Calculate time difference
                $difference = abs($validTime->getTimestamp() - $currentTime->getTimestamp());

                // Check if the difference is smaller than the current minimum difference
                if ($difference < $minDifference) {
                    // Update closest time and minimum difference
                    $closestTime = $validTime;
                    $minDifference = $difference;
                    // Store the associated value of the closest time
                    $relativeHumidity = $item['value'];
                }
            }

            // Convert relative humidity to fraction
            $relativeHumidity = $relativeHumidity / 100;

            // Calculate wet-bulb temperature
            $wetBulb = $dryBulbTemp * atan(0.151977 * (sqrt($relativeHumidity + 8.313659)))
                + atan($dryBulbTemp + $relativeHumidity)
                - atan($relativeHumidity - 1.676331)
                + 0.00391838 * pow($relativeHumidity, 3/2) * atan(0.023101 * $relativeHumidity)
                - 4.686035;


            if( !isset($wetBulb)) {
                $answer = 'NO ðŸ˜­';
            }
            else {
                if (number_format($wetBulb, 2, '.', ',') < 0) {
                    $answer = 'YES ðŸŽ‰';
                } else {
                    $answer = 'NO ðŸ˜­';
                }
            }

        } else {
            echo 'Error communicating with NOAA API...';
        }
    } else {
        echo 'Error communicating with NOAA API...';
    }
}
?>

<?php if ($has_coords == true && isset($answer)) { ?>
    <h1 style="text-align: center; margin: 52px auto 0;"><?= $answer ?></h1>
    <p style="text-align: center; max-width: 640px; margin: 40px auto 0;">Wet bulb is currently <?= number_format($wetBulb, 2, '.', ',') ?>Â° C. Snow can be made when the wet bulb temperature is below 0Â° C.</p>
    <?php if ($answer == 'YES ðŸŽ‰') { ?>
        <p style="text-align: center; max-width: 640px; margin: 10px auto 0;">Show this to resort staff if they tell you it's too warm to make snow ðŸ˜‰</p>
    <?php } ?>
<?php } else { ?>
    <p style="text-align: center; max-width: 640px; margin: 40px auto 0;">We need your location to provide an answer...</p>
<?php } ?>
<h3 style="text-align: center; max-width: 560px; margin: 60px auto 0;">How does this page work?</h3>
<p style="text-align: center; max-width: 560px; margin: 10px auto 0;">This webpage looks at your latitude and longitude and retrieves the current temperature and relative humidity from NOAA and uses them to calculate the wet bulb temperature to determine if snow could be made where you stand.</p>
<p style="text-align: center; max-width: 560px; margin: 80px auto 0;"><a href="https://albrightlabs.com" target="_blank">Albright Labs</a> &nbsp;|&nbsp; <a href="https://instagram.com/joebuonocore" target="_blank">Instagram</a> &nbsp;|&nbsp; <a href="https://github.com/joebuonocore" target="_blank">Github</a></p>

<?php if ($has_coords == false) { ?>
    <script>
        function getLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(showPosition);
            } else {
                alert("Geolocation is not supported by this browser.");
            }
        }

        function showPosition(position) {
            var latitude = position.coords.latitude;
            var longitude = position.coords.longitude;

            window.location.href = "?lat=" + latitude + "&lon=" + longitude;
        }

        getLocation();
    </script>
<?php } ?>

</body>
</html>