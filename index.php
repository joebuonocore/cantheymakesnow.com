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
            }
        }
    }
?>

<!doctype html>
<html lang="en" data-bs-theme="auto">
    <head><script src="https://getbootstrap.com/docs/5.3/assets/js/color-modes.js"></script>

        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="">
        <meta name="author" content="Joe Buonocore">
        <title>Can they make snow?</title>
        <link rel="canonical" href="https://getbootstrap.com/docs/5.3/examples/starter-template/">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@docsearch/css@3">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    </head>
    <body>
        <div class="col-lg-8 mx-auto p-4">
            <header class="d-block align-items-center pb-3 mb-5">
                <a href="/" class="d-block align-items-center text-body-emphasis text-decoration-none">
                    <h1 class="text-center d-block">Can they make snow?</h1>
                </a>
            </header>
            <main>
                <div class="row">
                    <div class="col">
                        <?php if ($has_coords == true && isset($wetBulb)) { ?>
                            <h2 class="text-center">
                                <?php if ($wetBulb < 0) { ?>
                                    YES 🎉🎉🎉
                                <?php } else { ?>
                                    NO 😭
                                <?php } ?>
                            </h2>
                            <p class="text-center mt-4">Wet bulb is currently <?= number_format($wetBulb, 2, '.', ',') ?>&deg; C. Snow can be made when the wet bulb temperature is below 0&deg; C.</p>
                            <?php if ($wetBulb < 0) { ?>
                                <p class="text-center mt-2">Show this to resort staff if they tell you it's too warm to make snow 😉</p>
                            <?php } ?>
                        <?php } else { ?>
                            <p class="text-center mt-3">We need your location to provide an answer...</p>
                        <?php } ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <div class="card mx-auto my-5" style="max-width: 560px;">
                            <div class="card-body">
                                <h4 class="card-title">How does this page work?</h4>
                                <p class="card-text">This webpage looks at your latitude and longitude and retrieves the current temperature and relative humidity from NOAA and uses them to calculate the wet bulb temperature to determine if snow could be made where you stand.</p>
                                <a href="https://github.com/joebuonocore/cantheymakesnow.com" target="_blank" class="card-link text-decoration-none">View code on GitHub</a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <footer class="pt-4 my-4 text-body-secondary text-center">
                <p><a href="https://instagram.com/joebuonocore" target="_blank" class="text-decoration-none">Instagram</a> &nbsp;|&nbsp; <a href="https://github.com/joebuonocore" target="_blank" class="text-decoration-none">Github</a></p>
                <p class="mt-4">Created by ✋🏼 in the Pocono Mountains</p>
            </footer>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
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
