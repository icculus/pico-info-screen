<?php

require('common.php');

function do_apicall($url, $args = NULL)
{
    global $user_agent;

    if (($args != NULL) && !empty($args)) {
        $url .= '?' . http_build_query($args);
    }

    $reqheaders = array(
        "User-Agent: $user_agent",
        'Accept: application/json'
    );

    $curl = curl_init($url);
    $curlopts = array(
        CURLOPT_AUTOREFERER => TRUE,
        CURLOPT_FOLLOWLOCATION => TRUE,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_HTTPHEADER => $reqheaders
    );

     //print("CURLOPTS:\n"); print_r($curlopts);
     //print("REQHEADERS:\n"); print_r($reqheaders);

     curl_setopt_array($curl, $curlopts);
     $responsejson = curl_exec($curl);
     $curlfailed = ($responsejson === false);
     $curlerr = $curlfailed ? curl_error($curl) : NULL;
     $httprc = $curlfailed ? 0 : curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
     curl_close($curl);
     unset($curl);

     //print("RESPONSE:\n"); print_r(json_decode($responsejson, TRUE));

     if ($curlfailed || (($httprc != 200) && ($httprc != 201))) {
         return NULL;
     }

     return $responsejson;
}

function openweathermap_apicall($operation, $args)
{
    global $openweathermap_apikey;
    if ($args == NULL) {
        $args = array();
    }
    $args['appid'] = $openweathermap_apikey;
    return do_apicall("https://api.openweathermap.org/$operation", $args);
}

function get_cached_weather_icon($basename)
{
    $cachedir = 'weather_icon_cache';
    $fname = "$cachedir/$basename.png";
    if (@stat($fname) === false) {
        @mkdir($cachedir);
        $img = file_get_contents("https://openweathermap.org/payload/api/media/file/$basename.png");
        if ($img) {
            file_put_contents($fname, $img);
        }
    } else {
        $img = file_get_contents($fname);
    }
    return $img;
}


function render_current_weather($info, $width, $height)
{
    $metadata = $info['metadata'];
    $current = $info['current'];
    $weather = $current['weather'][0];
    $canvas = new Imagick();
    $canvas->newImage($width, $height, new ImagickPixel('white'));
    $canvas->setImageType(Imagick::IMGTYPE_GRAYSCALE);
    $canvas->setImageDepth(1);

    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('black'));
    $draw->setGravity(Imagick::GRAVITY_NORTHWEST);
    $draw->setFont('Xerxes 10.ttf');
    $draw->setFontSize(30);

    $im = new Imagick();
    $im->readImageBlob(get_cached_weather_icon($weather['icon']));
    $im_w = $im->getImageWidth();
    $im_h = $im->getImageHeight();
    $im_x = ($width - $im_w) / 2;

    $metrics = $canvas->queryFontMetrics($draw, 'X');
    $y = ($height - ((($metrics['textHeight'] + 1) * 3) + $im_h)) / 2;

    $str = $metadata['name'];
    $metrics = $canvas->queryFontMetrics($draw, $str);
    $x = $im_x + (intval($im_w - $metrics['textWidth']) / 2);
    $canvas->annotateImage($draw, $x, $y, 0, $str);
    $y += $metrics['textHeight'] + 1;

    $canvas->compositeImage($im, imagick::COMPOSITE_OVER, $im_x, $y);
    $y += $im_h + 1;

    $str = 'Currently: ' . $weather['main'];
    $metrics = $canvas->queryFontMetrics($draw, $str);
    $x = $im_x + (intval($im_w - $metrics['textWidth']) / 2);
    $canvas->annotateImage($draw, $x, $y, 0, $str);
    $y += $metrics['textHeight'] + 1;

    $str = intval(round($current['temp'])) . "\xB0F  (Feels like " . intval(round($current['feels_like'])) . "\xB0F)";
    $metrics = $canvas->queryFontMetrics($draw, $str);
    $x = $im_x + (intval($im_w - $metrics['textWidth']) / 2);
    $canvas->annotateImage($draw, $x, $y, 0, $str);
    //$y += $metrics['textHeight'] + 1;

    header('Content-Type: image/png');
    $canvas->setImageFormat('png');
    return $canvas;
}


function get_cached_zipcode($zipcode)
{
    $now = time();
    $cachedir = 'weather_zipcode_cache';
    $fname_metadata = "$cachedir/$zipcode-metadata.json";
    $fname = "$cachedir/$zipcode.json";
    $update = false;

    $json = NULL;
    $json_metadata = NULL;

    $jsonstr = @file_get_contents($fname_metadata);
    if ($jsonstr) {
        $json_metadata = json_decode($jsonstr, TRUE);
    }

    $statbuf = @stat($fname);
    if ($statbuf === false) {
        @mkdir($cachedir);
        $update = true;
    } else if (($statbuf['mtime'] > $now) || (($now - $statbuf['mtime']) > (60 * 15))) {
        $update = true;
    }

    if ($update) {
        if ($json_metadata == NULL) {  // we don't even have latitude and longitude yet!
            $jsonstr = openweathermap_apicall('geo/1.0/zip', [ 'zip' => $zipcode ]);
            if ($jsonstr == NULL) {
                fail503("Failed to get zipcode metadata, please try again later.");
            }

            $json_metadata = json_decode($jsonstr, TRUE);
            if ($json_metadata == NULL) {
                fail503("Failed to decode zipcode metadata, please try again later.");
            }

            file_put_contents($fname_metadata, $jsonstr);
        }

        // okay, update the cached weather for this zipcode!
        $jsonstr = openweathermap_apicall('data/3.0/onecall', [ 'lat' => $json_metadata['lat'], 'lon' => $json_metadata['lon'], 'units' => 'imperial' ]);
        if ($jsonstr == NULL) {
            fail503("Failed to get current zipcode weather data, please try again later.");
        }

        file_put_contents($fname, $jsonstr);
    } else {
        $jsonstr = file_get_contents($fname);
    }

    if ($jsonstr === false) {
        fail503("Failed to get current zipcode weather data, please try again later.");
    }

    $retval = json_decode($jsonstr, TRUE);
    if ($retval === false) {
        fail503("Failed to decode current zipcode weather data, please try again later.");
    } else {
        $retval['metadata'] = $json_metadata;
    }

    return $retval;
}


// MAINLINE!

$zipcode = $default_zipcode;  // !!! FIXME: allow client to specify this.
$cached_zipcode = get_cached_zipcode($zipcode);
echo render_current_weather($cached_zipcode, 480, 280);  // !!! FIXME: allow different sizes.

exit(0);

