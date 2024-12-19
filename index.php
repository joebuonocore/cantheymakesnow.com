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
        <meta name="meta_description" content="This webpage uses your latitude and longitude to retrieve the current temperature and relative humidity from NOAA and uses that data to calculate the wet bulb temperature to determine if artificial snow could be made at your location.">
        <meta name="author" content="Joe Buonocore">
        <title>Can they make snow?</title>
        <meta name="meta_title" content="Can they make snow?">
        <link rel="canonical" href="https://getbootstrap.com/docs/5.3/examples/starter-template/">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@docsearch/css@3">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <link rel="icon" type="image/x-icon" href="https://fav.farm/%E2%9D%84%EF%B8%8F">
        <style>
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
                            <p class="text-center mt-3">Wet bulb is currently <span style="font-weight:600"><?= number_format($wetBulb, 2, '.', ',') ?>&deg; Celsius</span>.</p>
                            <p class="text-center mt-2">Snow can be made when the wet bulb temperature is below 0&deg; Celsius.</p>
                        <?php } else { ?>
                            <p class="text-center mt-3">We need your location to provide an answer...</p>
                        <?php } ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col mt-4 pt-4">
                        <div class="card mx-auto" style="max-width: 560px;">
                            <div class="card-body">
                                <h5 class="card-title"><span style="font-size:150%; line-height:1; position:relative; top:4px;">👉</span> &nbsp;How does this page work?</h5>
                                <p class="card-text">We retrieve the temperature and relative humidity in your location from NOAA and use that data to calculate the wet bulb temperature.</p>
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