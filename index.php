<?php

    // Read environment
    $envPath = __DIR__ . '/.env';
    if (!file_exists($envPath)) {
        die(".env file not found.");
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $keyValue = explode('=', $line, 2);
        if (count($keyValue) === 2) {
            $key   = trim($keyValue[0]);
            $value = trim($keyValue[1]);
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }

    // Get page URL
    $this_page_url = $_SERVER['HTTP_HOST'];

    // Default cords false
    $has_coords = false;

    // Check if there are coordinates in the URL
    if (isset($_GET['lat']) && isset($_GET['lon'])) {
        $has_coords = true;

        // Specify the location for which you want to get the weather (latitude and longitude)
        $latitude = number_format($_GET['lat'], 2, '.', ',');
        $longitude = number_format($_GET['lon'], 2, '.', ',');

        function customRound($number) {
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

        $latitude = customRound($latitude);
        $longitude = customRound($longitude);

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
        $current_city = $location_data['properties']['relativeLocation']['properties']['city'];
        $current_state = $location_data['properties']['relativeLocation']['properties']['state'];
        // Output the city and state
        //echo "City: $current_city\n";
        //echo "State: $current_state\n";

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
                $currentTime = new DateTime('now', new DateTimeZone('UTC'));

                // Function to find the closest time
                function findClosestTime($values, $currentTime) {
                    $closestTime = null;
                    $minDifference = PHP_INT_MAX;
                    $valueAtClosestTime = null;

                    foreach ($values as $item) {
                        // Parse validTime string to DateTime object
                        $validTime = new DateTime(substr($item['validTime'], 0, 19)); // Extracting only the date and time part

                        // Extract duration (in hours)
                        if (preg_match('/PT(\d+)H/', $item['validTime'], $matches)) {
                            $duration = (int)$matches[1];
                            $validTime->modify("+" . $duration . " hour");
                        }

                        // Calculate time difference
                        $difference = abs($validTime->getTimestamp() - $currentTime->getTimestamp());

                        // Check if this is the closest time
                        if ($difference < $minDifference) {
                            $closestTime = $validTime;
                            $minDifference = $difference;
                            $valueAtClosestTime = $item['value'];
                        }
                    }

                    return ['time' => $closestTime, 'value' => $valueAtClosestTime];
                }

                // Find closest temperature and humidity
                $closestTemperature = findClosestTime($temperatures, $currentTime);
                $closestHumidity = findClosestTime($humidities, $currentTime);

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
            }
        }
    }

    // Check if there is an address in the URL
    elseif (isset($_GET['address']) && !empty($_GET['address'])) {
        // Replace these with your actual values
        $address = $_GET['address'];
        $address = urlencode($address);
        $apiKey = getenv('GOOGLE_MAPS_API_KEY');

        // Build the Geocoding API URL
        $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$apiKey}";

        // Make the HTTP request to the Google Geocoding API
        $response = file_get_contents($geocodeUrl);
        if ($response === false) {
            die("The Google Maps API seems to be offline, so we can't translate locations names to latitude and longitude right now. Please try again later.");
        }

        // Decode the JSON response
        $jsonData = json_decode($response, true);

        // Check if the request was successful and has any results
        if ($jsonData['status'] === 'OK' && count($jsonData['results']) > 0) {
            $latitude = $jsonData['results'][0]['geometry']['location']['lat'];
            $longitude = $jsonData['results'][0]['geometry']['location']['lng'];

            // Construct the final URL with ?lat= and ?lon=
            $finalUrl = "{$this_page_url}?lat={$latitude}&lon={$longitude}&address={$address}";

            // Redirect to the new URL
            header("Location: {$finalUrl}");
            // exit;
        } else {
            // Handle errors or zero results
            die("The Google Maps API could not convert that location name to latitude and longitude. Please go back and enter a different location.");
        }
    }
?>

<!doctype html>
<html lang="en" data-bs-theme="dark">
    <head>
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-2QX58NHYF6"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());

            gtag('config', 'G-2QX58NHYF6');
        </script>
        <script src="https://getbootstrap.com/docs/5.3/assets/js/color-modes.js"></script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="This webpage uses your latitude and longitude to retrieve the current temperature and relative humidity from NOAA and uses that data to calculate the wet bulb temperature to determine if artificial snow could be made at your location.">
        <meta name="author" content="Joe Buonocore">
        <title>Can they make snow?</title>
        <link rel="canonical" href="https://getbootstrap.com/docs/5.3/examples/starter-template/">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@docsearch/css@3">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <link rel="icon" type="image/x-icon" href="https://fav.farm/%E2%9D%84%EF%B8%8F">
        <style>
            * {
                color: #222;
            }
            @media (prefers-color-scheme: dark) {
                * {
                    color: #fff;
                }
            }
            .snowflake {
                color: rgba(255,255,255,0.5);
                font-size: 0.65em;
                font-family: Arial, sans-serif;
            }
            .snowflake,
            .snowflake .inner {
                animation-iteration-count:infinite;
                animation-play-state:running;
            }
            .rotate {
                animation: rotation 2s infinite linear;
            }
            @keyframes snowflakes-fall {
                0% {
                    transform: translateY(0);
                }
                100% {
                    transform: translateY(110vh);
                }
            }
            @keyframes snowflakes-shake {
                0%, 100% {
                    transform: translateX(0);
                }
                50% {
                    transform: translateX(80px);
                }
            }
            @keyframes rotation {
                from {
                    transform: rotate(0deg);
                }
                to {
                    transform: rotate(359deg);
                }
            }
            .snowflake {
                position: fixed;
                top: -10%;
                z-index: 9999;
                -webkit-user-select: none;
                user-select: none;
                cursor: default;
                animation-name: snowflakes-shake;
                animation-duration: 3s;
                animation-timing-function: ease-in-out;
            }
            .snowflake .inner {
                animation-duration: 10s;
                animation-name: snowflakes-fall;
                animation-timing-function: linear;
            }
            .snowflake:nth-of-type(0) { left: 1%; animation-delay: 0s; }
            .snowflake:first-of-type { left: 10%; animation-delay: 1s; }
            .snowflake:nth-of-type(2) { left: 20%; animation-delay: 0.5s; }
            .snowflake:nth-of-type(3) { left: 30%; animation-delay: 2s; }
            .snowflake:nth-of-type(4) { left: 40%; animation-delay: 2s; }
            .snowflake:nth-of-type(5) { left: 50%; animation-delay: 3s; }
            .snowflake:nth-of-type(6) { left: 60%; animation-delay: 2s; }
            .snowflake:nth-of-type(7) { left: 70%; animation-delay: 1s; }
            .snowflake:nth-of-type(8) { left: 80%; animation-delay: 0s; }
            .snowflake:nth-of-type(9) { left: 90%; animation-delay: 1.5s; }
            .snowflake:nth-of-type(10) { left: 25%; animation-delay: 0s; }
            .snowflake:nth-of-type(11) { left: 65%; animation-delay: 2.5s; }
            .snowflake:nth-of-type(12) { left: 65%; animation-delay: 2.5s; }
            .snowflake:nth-of-type(13) { left: 5%; animation-delay: 1.3s; }
            .snowflake:nth-of-type(14) { left: 15%; animation-delay: 3.6s; }
            .snowflake:nth-of-type(15) { left: 35%; animation-delay: 0.9s; }
            .snowflake:nth-of-type(16) { left: 45%; animation-delay: 2.1s; }
            .snowflake:nth-of-type(17) { left: 55%; animation-delay: 3.1s; }
            .snowflake:nth-of-type(18) { left: 67%; animation-delay: 0.4s; }
            .snowflake:nth-of-type(19) { left: 75%; animation-delay: 2.7s; }
            .snowflake:nth-of-type(20) { left: 85%; animation-delay: 0.5s; }
            .snowflake:nth-of-type(21) { left: 95%; animation-delay: 3.2s; }
            .snowflake:nth-of-type(22) { left: 30%; animation-delay: 1.8s; }
            .snowflake:nth-of-type(23) { left: 40%; animation-delay: 2.9s; }
            .snowflake:nth-of-type(24) { left: 60%; animation-delay: 1.1s; }
            .snowflake:nth-of-type(0) .inner,
            .snowflake:nth-of-type(10) .inner,
            .snowflake:nth-of-type(20) .inner { animation-delay: 0s; }
            .snowflake:first-of-type .inner,
            .snowflake:nth-of-type(11) .inner,
            .snowflake:nth-of-type(21) .inner { animation-delay: 1s; }
            .snowflake:nth-of-type(2) .inner,
            .snowflake:nth-of-type(12) .inner,
            .snowflake:nth-of-type(22) .inner { animation-delay: 6s; }
            .snowflake:nth-of-type(3) .inner,
            .snowflake:nth-of-type(23) .inner,
            .snowflake:nth-of-type(13) .inner { animation-delay: 4s; }
            .snowflake:nth-of-type(4) .inner,
            .snowflake:nth-of-type(24) .inner,
            .snowflake:nth-of-type(14) .inner { animation-delay: 2s; }
            .snowflake:nth-of-type(5) .inner,
            .snowflake:nth-of-type(15) .inner { animation-delay: 8s; }
            .snowflake:nth-of-type(6) .inner,
            .snowflake:nth-of-type(16) .inner { animation-delay: 7s; }
            .snowflake:nth-of-type(7) .inner,
            .snowflake:nth-of-type(17) .inner { animation-delay: 2.5s; }
            .snowflake:nth-of-type(8) .inner,
            .snowflake:nth-of-type(18) .inner { animation-delay: 1s; }
            .snowflake:nth-of-type(9) .inner,
            .snowflake:nth-of-type(19) .inner { animation-delay: 3s; }
            .btn:active,
            .btn:focus,
            .form-control:active,
            .form-control:focus {
                box-shadow: none !important;
            }
        </style>
    </head>
    <body>
        <div class="col-lg-8 mx-auto p-4">
            <header class="d-block align-items-center pb-2 mb-5">
                <a href="/" class="d-block align-items-center text-body-emphasis text-decoration-none">
                    <h1 class="mt-2 mb-0 text-center d-block">Can they make snow?</h1>
                </a>
            </header>
            <main>
                <div class="row">
                    <div class="col">
                        <?php if ($has_coords == true && isset($wetBulb)) { ?>
                            <h2 id="answerText" class="h1 text-center mt-0" style="font-weight:800;">
                                <?php if ($wetBulb < 0) { ?>
                                    YES ❄️🏂⛷️🎉
                                    <div class="snowflakes" aria-hidden="true">
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                        <div class="snowflake">
                                            <div class="inner"><div class="rotate">❄️</div></div>
                                        </div>
                                    </div>
                                <?php } else { ?>
                                    NO 😭😭😭
                                <?php } ?>
                            </h2>
                            <p class="text-center mt-3">Wet bulb is currently <span style="font-weight:600"><?= number_format($wetBulb, 2, '.', ',') ?>&deg; Celsius</span><?php if (isset($current_city) && isset($current_state)) { echo ' in <span style="font-weight:600">'.$current_city.', '.$current_state.'</span>'; } ?>.</p>
                            <p class="text-center mt-2">Snow can be made when the wet bulb temperature is below 0&deg; Celsius.</p>
                        <?php } else { ?>
                            <p class="text-center text-danger">⚠️ &nbsp;We need a location to provide an answer!</p>
                        <?php } ?>

                        <div style="max-width: 460px; margin: 0 auto;">
                            <div class="row mt-4 pt-4 pb-3">
                                <div class="col-12 text-center">
                                    <p class="h5 font-weight-normal mb-0">Want to look up another location?</p>
                                </div>
                            </div>
                            <form action="/" method="GET" class="row justify-content-center mb-2">
                                <div class="col-9 pe-lg-0">
                                    <label class="visually-hidden" for="address">City, State</label>
                                    <input type="text" name="address" id="address" placeholder="City, State" class="form-control rounded-0" value="<?php if (isset($current_city) && isset($current_state)) { echo $current_city.', '.$current_state; } ?>" />
                                </div>
                                <div class="col-3 ps-lg-0">
                                    <button type="submit" class="btn btn-md btn-primary rounded-0 w-100">Submit</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col mt-4 pt-4">
                        <div class="card mx-auto" style="max-width: 560px;">
                            <div class="card-body">
                                <h5 class="card-title"><span style="font-size:150%; line-height:1; position:relative; top:4px;">👉</span> &nbsp;How does this page work?</h5>
                                <p class="card-text">We retrieve the temperature and relative humidity from NOAA and use that data to calculate the wet bulb temperature.</p>
                                <a href="https://github.com/joebuonocore/cantheymakesnow.com" target="_blank" class="card-link text-decoration-none">View the source code on GitHub</a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <footer class="mt-4 pt-4 mb-2 text-body-secondary text-center">
                <p class="mt-0 mb-0"><a href="https://instagram.com/joebuonocore" target="_blank" class="text-decoration-none">Instagram</a> &nbsp;|&nbsp; <a href="https://github.com/joebuonocore" target="_blank" class="text-decoration-none">Github</a></p>
                <p class="mt-2">Made with&nbsp; ❤️ &nbsp;in the Pocono Mountains</p>
                <p class="mt-4" style="font-size:80%;"><a href="https://albrightlabs.com" target="_blank" style="color:inherit; text-decoration:none;">&copy; <?= date('Y') ?> <u>Albright Labs LLC</u>. All Rights Reserved.</a></p>
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

                    // Construct the new URL with query parameters
                    var newUrl = `${window.location.origin}${window.location.pathname}?lat=${encodeURIComponent(latitude)}&lon=${encodeURIComponent(longitude)}`;

                    // Redirect to the new URL
                    window.location.href = newUrl;
                }

                // Call the function to get location and redirect
                getLocation();

                document.addEventListener("DOMContentLoaded", function () {
                    const answerElement = document.getElementById("answerText");
                    if (answerElement) {
                        const answerText = answerElement.textContent || answerElement.innerText;
                        if (!answerText.includes("YES") && !answerText.includes("NO")) {
                            // If neither "YES" nor "NO" is found, refresh the page
                            setTimeout(() => {
                                window.location.reload();
                            }, 5000); // Optional: 5-second delay before refreshing
                        }
                    }
                });
            </script>
        <?php } ?>
    </body>
</html>