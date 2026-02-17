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

     return json_decode($responsejson, TRUE);
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
    $im->readImageBlob(get_cached_weather_icon($info['current_icon']));
    $im_w = $im->getImageWidth();
    $im_h = $im->getImageHeight();
    $im_x = ($width - $im_w) / 2;

    $metrics = $canvas->queryFontMetrics($draw, 'X');
    $y = ($height - ((($metrics['textHeight'] + 1) * 3) + $im_h)) / 2;

    $str = $info['name'];
    $metrics = $canvas->queryFontMetrics($draw, $str);
    $x = $im_x + (intval($im_w - $metrics['textWidth']) / 2);
    $canvas->annotateImage($draw, $x, $y, 0, $str);
    $y += $metrics['textHeight'] + 1;

    $canvas->compositeImage($im, imagick::COMPOSITE_OVER, $im_x, $y);
    $y += $im_h + 1;

    $str = 'Currently: ' . $info['current_description'];
    $metrics = $canvas->queryFontMetrics($draw, $str);
    $x = $im_x + (intval($im_w - $metrics['textWidth']) / 2);
    $canvas->annotateImage($draw, $x, $y, 0, $str);
    $y += $metrics['textHeight'] + 1;

    $str = intval(round($info['current_temp'])) . 'F  (Feels like ' . intval(round($info['current_feelslike'])) . 'F)';
    $metrics = $canvas->queryFontMetrics($draw, $str);
    $x = $im_x + (intval($im_w - $metrics['textWidth']) / 2);
    $canvas->annotateImage($draw, $x, $y, 0, $str);
    //$y += $metrics['textHeight'] + 1;

    header('Content-Type: image/png');
    $canvas->setImageFormat('png');
    return $canvas;
}


function get_cached_zipcode($db, $zipcode)
{
    $stmt = $db->prepare('select * from zipcode_cache foo where zipcode=:zipcode limit 1');
    $stmt->bindValue(':zipcode', $zipcode, SQLITE3_TEXT);
    $rows = $stmt->execute();
    $row = $rows->fetchArray();
    $rows->finalize();
    $stmt->close();
    return $row;
}


$zipcode = $default_zipcode;  // !!! FIXME: allow client to specify this.
$db = load_database_or_fail();
$now = time();

$cached_zipcode = get_cached_zipcode($db, $zipcode);

if (($cached_zipcode === false) || ($cached_zipcode['last_updated'] > $now) || (($cached_zipcode['last_updated'] - $now) > (60 * 15))) {
    if ($cached_zipcode === false) {  // we don't even have latitude and longitude yet!
        $json = openweathermap_apicall('geo/1.0/zip', [ 'zip' => $zipcode ]);
        if ($json == NULL) {
            fail503("Failed to get zipcode latitude/longitude, please try again later.");
        }

        $stmt = $db->prepare('insert into zipcode_cache ( zipcode, name, country, latitude, longitude ) values ( :zipcode, :name, :country, :latitude, :longitude )');
        $stmt->bindValue(':zipcode', $zipcode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $json['name'], SQLITE3_TEXT);
        $stmt->bindValue(':country', $json['country'], SQLITE3_TEXT);
        $stmt->bindValue(':latitude', $json['lat'], SQLITE3_FLOAT);
        $stmt->bindValue(':longitude', $json['lon'], SQLITE3_FLOAT);
        $stmt->execute();
        $stmt->close();

        $cached_zipcode = get_cached_zipcode($db, $zipcode);
        if ($cached_zipcode === false) {
            fail503("Having trouble caching zipcode information, please check your zipcode and/or try again later.");
        }
    }

    // okay, update the cached weather for this zipcode!
    $json = openweathermap_apicall('data/3.0/onecall', [ 'lat' => $cached_zipcode['latitude'], 'lon' => $cached_zipcode['longitude'], 'units' => 'imperial', 'exclude' => 'hourly,daily,minutely,alerts' ]);
    if ($json == NULL) {
        fail503("Failed to get zipcode latitude/longitude, please try again later.");
    }

    $current = $json['current'];
    $weather = $current['weather'][0];
    $cached_zipcode['current_desciption'] = $weather['main']; //$weather['description'];
    $cached_zipcode['current_icon'] = $weather['icon'];
    $cached_zipcode['current_temp'] = $current['temp'];
    $cached_zipcode['current_feelslike'] = $current['feels_like'];

    $stmt = $db->prepare('update zipcode_cache set current_description=:current_desc,current_icon=:current_icon,current_temp=:current_temp,current_feelslike=:current_feelslike,last_updated=:now where id=:id limit 1');
    $stmt->bindValue(':current_desc', $cached_zipcode['current_description'], SQLITE3_TEXT);
    $stmt->bindValue(':current_icon', $cached_zipcode['current_icon'], SQLITE3_TEXT);
    $stmt->bindValue(':current_temp', $cached_zipcode['current_temp'], SQLITE3_FLOAT);
    $stmt->bindValue(':current_feelslike', $cached_zipcode['current_feelslike'], SQLITE3_FLOAT);
    $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $cached_zipcode['id'], SQLITE3_INTEGER);
    $stmt->execute();
    $stmt->close();
}

close_database($db);

echo render_current_weather($cached_zipcode, 480, 280);  // !!! FIXME: allow different sizes.

exit(0);
