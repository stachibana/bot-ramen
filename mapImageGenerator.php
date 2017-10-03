<?php

// テーブル名を定義
define('TABLE_NAME_USERS', 'users');
//require_once __DIR__ . '/vendor/autoload.php';

//$lat = $_REQUEST['lat'];// '35.658034';
//$lon = $_REQUEST['lon'];// '139.701636';

$userArray = Array();

$userId = $_REQUEST['userId'];
$dbh = dbConnection::getConnection();
$sql = 'select * from ' . TABLE_NAME_USERS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
$sth = $dbh->prepare($sql);
$sth->execute(array($userId));
if (!($row = $sth->fetch())) {
  return PDO::PARAM_NULL;

  return;
} else {
  //return $row['location'];
  $userArray = $row;
  //error_log('user ::: ' . var_export($userArray, true));
}

$lat = explode(",", $userArray['location'])[0];
$lon = explode(",", $userArray['location'])[1];

$mapImageUrl = 'https://maps.googleapis.com/maps/api/staticmap?center=' . $lat . ',' . $lon . '&zoom=16&size=1280x1280&scale=2&maptype=roadmap&key=' . getenv('GOOGLE_API_KEY');

/*
$mapImageUrl = $mapImageUrl . '&markers=black:white%7C' . $lat . ',' . $lon;

$placesUrl = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=" . $lat . ',' . $lon . "&keyword=" . urlencode('ラーメン') . "&radius=500&key=" . getenv('GOOGLE_API_KEY');
error_log($placesUrl);
$json_string = file_get_contents($placesUrl);
*/
$json_string = $userArray['jsonstring'];
$json = json_decode($json_string, true)["results"];

$array = Array();
$cnt = 0;
foreach($json as $shop) {
  //array_push($array, [$shop['geometry']['location']['lat'], $shop['geometry']['location']['lng']]);

  $markerColor = 'red';
  if($shop['rating'] > 3.8) {
    $markerColor = 'red';
  } else if($shop['rating'] > 3.4) {
    $markerColor = 'orange';
  } else if($shop['rating'] > 3.0) {
    $markerColor = 'green';
  } else {
    $markerColor = 'blue';
  }

  //$mapImageUrl = $mapImageUrl . '&markers=color:' . $markerColor . '%7Clabel:' . $cnt . '%7C' . $shop['geometry']['location']['lat'] . ',' . $shop['geometry']['location']['lng'];
  $mapImageUrl = $mapImageUrl . '&markers=color:' . $markerColor . '%7C' . $shop['geometry']['location']['lat'] . ',' . $shop['geometry']['location']['lng'];
  array_push($array, [($shop['geometry']['location']['lat'] - $lat) * -1.0, $shop['geometry']['location']['lng'] - $lon]);

  $cnt++;
  // TODO DELETE THIS
  /*
  $cnt++;
  if($cnt > 10) {
    break; // only a part of results
  }*/
}

$map = imagecreatefrompng($mapImageUrl);

$multiple = 131072.0; // 2^16

/*
foreach($array as $diff) {

  $overlay = imagecreatefrompng('marker_overlay.png');
  error_log('marker');
  error_log($diff[0] * $multiple);
  error_log($diff[1] * $multiple);

  $xDiff = $diff[1] * $multiple * 0.71;
  $yDiff = $diff[0] * $multiple * 0.88;

  imagecopy($map, $overlay, 640 + $xDiff, 640 + $yDiff, 0, 0, 30, 42);
  imagedestroy($overlay);
}*/

// リクエストされているサイズを取得
$size = $_REQUEST['size'];
$out = imagecreatetruecolor($size ,$size);
imagecopyresampled($out, $map, 0, 0, 0, 0, $size, $size, 1280, 1280);

header('Content-type: image/png');
imagepng($out);

// データベースへの接続を管理するクラス
class dbConnection {
  // インスタンス
  protected static $db;
  // コンストラクタ
  private function __construct() {

    try {
      // 環境変数からデータベースへの接続情報を取得し
      $url = parse_url(getenv('DATABASE_URL'));
      // データソース
      $dsn = sprintf('pgsql:host=%s;dbname=%s', $url['host'], substr($url['path'], 1));
      // 接続を確立
      self::$db = new PDO($dsn, $url['user'], $url['pass']);
      // エラー時例外を投げるように設定
      self::$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    }
    catch (PDOException $e) {
      error_log('Connection Error: ' . $e->getMessage());
    }
  }

  // シングルトン。存在しない場合のみインスタンス化
  public static function getConnection() {
    if (!self::$db) {
      new dbConnection();
    }
    return self::$db;
  }
}

?>
