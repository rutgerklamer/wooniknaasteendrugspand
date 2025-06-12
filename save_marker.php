<?php
// save_marker.php
header('Content-Type: application/json');
$folder = __DIR__ . '/markers';

// 1) Ontvang en valideer
$lat = filter_input(INPUT_POST, 'lat', FILTER_VALIDATE_FLOAT);
$lng = filter_input(INPUT_POST, 'lng', FILTER_VALIDATE_FLOAT);
$centerLat = filter_input(INPUT_POST, 'centerLat', FILTER_VALIDATE_FLOAT);
$centerLng = filter_input(INPUT_POST, 'centerLng', FILTER_VALIDATE_FLOAT);
if ($lat===false || $lng===false || !$centerLat || !$centerLng) {
    http_response_code(400);
    echo json_encode(['error'=>'Onjuiste parameters']);
    exit;
}

// 2) Check binnen 25 km
function haversine($lat1,$lng1,$lat2,$lng2){
  $R=6371;
  $dLat=deg2rad($lat2-$lat1);
  $dLon=deg2rad($lng2-$lng1);
  $a=sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
  $c=2*asin(min(1,sqrt($a)));
  return $R*$c;
}
if (haversine($lat,$lng,$centerLat,$centerLng) > 250000) {
    http_response_code(403);
    echo json_encode(['error'=>'Buiten straal van 250000 km']);
    exit;
}

// 3) Schrijf bestand
if (!is_dir($folder)) mkdir($folder,0755,true);
$id = uniqid('marker_', true);
$data = [
  'lat'=>$lat,
  'lng'=>$lng,
  'timestamp'=>(new DateTime())->format(DateTime::ATOM)
];
file_put_contents("$folder/$id.json", json_encode($data, JSON_PRETTY_PRINT));

// 4) Succes
echo json_encode(['ok'=>true,'id'=>$id]);
