<?php

// Composerでインストールしたライブラリを一括読み込み
require_once __DIR__ . '/vendor/autoload.php';
// テーブル名を定義
define('TABLE_NAME_USERS', 'users');

// アクセストークンを使いCurlHTTPClientをインスタンス化
$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
// CurlHTTPClientとシークレットを使いLINEBotをインスタンス化
$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
// LINE Messaging APIがリクエストに付与した署名を取得
$signature = $_SERVER['HTTP_' . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];

// 署名が正当かチェック。正当であればリクエストをパースし配列へ
// 不正であれば例外の内容を出力
try {
  $events = $bot->parseEventRequest(file_get_contents('php://input'), $signature);
} catch(\LINE\LINEBot\Exception\InvalidSignatureException $e) {
  error_log('parseEventRequest failed. InvalidSignatureException => '.var_export($e, true));
} catch(\LINE\LINEBot\Exception\UnknownEventTypeException $e) {
  error_log('parseEventRequest failed. UnknownEventTypeException => '.var_export($e, true));
} catch(\LINE\LINEBot\Exception\UnknownMessageTypeException $e) {
  error_log('parseEventRequest failed. UnknownMessageTypeException => '.var_export($e, true));
} catch(\LINE\LINEBot\Exception\InvalidEventRequestException $e) {
  error_log('parseEventRequest failed. InvalidEventRequestException => '.var_export($e, true));
}

// 配列に格納された各イベントをループで処理
foreach ($events as $event) {
  // MessageEventクラスのインスタンスでなければ処理をスキップ

  registerUser($event->getUserId());

  if (!($event instanceof \LINE\LINEBot\Event\MessageEvent)) {
    error_log('Non message event has come');
    continue;
  }
  // TextMessageクラスのインスタンスでなければ処理をスキップ
  /*
  if (!($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage)) {
    error_log('Non text message has come');
    continue;
  }*/
  // オウム返し
  //$bot->replyText($event->getReplyToken(), $event->getText());
  //$lat = '35.658034';
  //$lon = '139.701636';
  if ($event instanceof \LINE\LINEBot\Event\MessageEvent\LocationMessage) {
    //error_log('Non text message has come');
    $lat =  $event->getLatitude();
    $lon =  $event->getLongitude();

    updateLocation($event->getUserId(), $lat . ',' . $lon);

    $markers = getMarkerPosArray($event->getUserId(), false);

  } else if ($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage) {
    if ($event->getText() == "次") {
      if(getLastToken($event->getUserId()) == '') {
        replyTextMessage($bot, $event->getReplyToken(), '該当する検索結果は全て表示済みです。');
        continue;
      }
      $markers = getMarkerPosArray($event->getUserId(), true);
    }
    else if(ctype_digit($event->getText()) && $event->getText() < 20) {

      $json_string = getJsonString($event->getUserId());
      $json = json_decode($json_string, true)["results"];

      $shopName = $json[$event->getText()]['name'];
      $message1 = ($json[$event->getText()]['opening_hours']['open_now']) ? '【営業中】': '【営業時間外】';
      $message2 = 'Googleプレイス検索におけるユーザー評価：' . $json[$event->getText()]['rating'];
      //replyTextMessage($bot, $event->getReplyToken(), $json[$event->getText()]['name']);
      replyButtonsTemplate($bot,
        $event->getReplyToken(),
        $shopName,
        null,
        $shopName,
        $message1 . $message2,
        new LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder (
          '詳細を見る', '_' . $event->getText()
        )
      );
      continue;
    }
    else if(ctype_digit(substr($event->getText(), 1)) && substr($event->getText(), 1) < 20) {
      //replyTextMessage($bot, $event->getReplyToken(), '詳細');
      $json_string = getJsonString($event->getUserId());
      $json = json_decode($json_string, true)["results"];

      $shopDetailString = file_get_contents('https://maps.googleapis.com/maps/api/place/details/json?language=ja&placeid=' . $json[substr($event->getText(), 1)]['place_id'] . '&key='. getenv('GOOGLE_API_KEY'));
      $shopJson = json_decode($shopDetailString, true)["result"];

      updateShopJsonString($event->getUserId(), $shopDetailString);

      $opening = '';
      foreach($shopJson['opening_hours']['weekday_text'] as $date) {
        $opening = $opening . PHP_EOL . $date;
      }
      $opening = $opening . PHP_EOL . $shopJson['formatted_phone_number'];

      $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
      $builder->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('【営業時間】' . $opening));
      $builder->add(new \LINE\LINEBot\MessageBuilder\LocationMessageBuilder($shopJson['name'], $shopJson['formatted_address'],
        $shopJson['geometry']['location']['lat'],
        $shopJson['geometry']['location']['lng']));

      $actionArray = Array();
      array_push($actionArray, new LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder (
        '画像', '_' . $event->getText()
      ));
      array_push($actionArray, new LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder (
        'レビュー', '__' . $event->getText()
      ));
      // TemplateMessageBuilderの引数は代替テキスト、ButtonTemplateBuilder
      $builder->add(new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
        '画像＆レビュー',
        new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder (null, 'オプション', null, $actionArray)
      ));

      $response = $bot->replyMessage($event->getReplyToken(), $builder);
      if (!$response->isSucceeded()) {
        error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
      }
      continue;
    }
    else if(ctype_digit(substr($event->getText(), 2)) && substr($event->getText(), 2) < 20) {

      $json_string = getShopJsonString($event->getUserId());
      $json = json_decode($json_string, true)["result"];

      $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
      $cnt = 0;
      foreach($json['photos'] as $photo) {
        $builder->add(new \LINE\LINEBot\MessageBuilder\ImageMessageBuilder(
          'https://maps.googleapis.com/maps/api/place/photo?key=' . getenv('GOOGLE_API_KEY') . '&maxwidth=1000&maxheight=1000&photoreference=' . $photo['photo_reference'],
          'https://maps.googleapis.com/maps/api/place/photo?key=' . getenv('GOOGLE_API_KEY') . '&maxwidth=240&maxheight=240&photoreference=' . $photo['photo_reference']));

        $cnt++;
        if($cnt >= 5)
          break;
      }
      $response = $bot->replyMessage($event->getReplyToken(), $builder);
      if (!$response->isSucceeded()) {
        error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
      }
      //"https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photoreference=CnRtAAAATLZNl354RwP_9UKbQ_5Psy40texXePv4oAlgP4qNEkdIrkyse7rPXYGd9D_Uj1rVsQdWT4oRz4QrYAJNpFX7rzqqMlZw2h2E2y5IKMUZ7ouD_SlcHxYq1yL4KbKUv3qtWgTK0A6QbGh87GB3sscrHRIQiG2RrmU_jF4tENr9wGS_YxoUSSDrYjWmrNfeEHSGSc3FyhNLlBU&key=YOUR_API_KEY"

    }
    else if(ctype_digit(substr($event->getText(), 3)) && substr($event->getText(), 3) < 20) {
      $json_string = getShopJsonString($event->getUserId());
      $json = json_decode($json_string, true)["result"];

      $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
      $cnt = 0;
      foreach($json['reviews'] as $review) {
        $stars = '';
        for($i = 1; $i <= 5; $i++) {
          $stars = $stars . (($review['rating'] < $i) ? '☆': '★');
        }
        $text = "【"  . $stars . '】' . PHP_EOL . $review['text'] . PHP_EOL . $review['relative_time_description'];

        $builder->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($text));

        $cnt++;
        if($cnt >= 5)
          break;
      }
      $response = $bot->replyMessage($event->getReplyToken(), $builder);
      if (!$response->isSucceeded()) {
        error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
      }
    }
    else {
      continue;
    }
  } else {
    continue;
  }

  $actionsArray = array();
  array_push($actionsArray, new LINE\LINEBot\ImagemapActionBuilder\ImagemapMessageActionBuilder(
    '-',
    new LINE\LINEBot\ImagemapActionBuilder\AreaBuilder(0, 0, 1, 1)));

  foreach($markers as $marker) {
    array_push($actionsArray, $marker);
  }

  $imagemapMessageBuilder = new \LINE\LINEBot\MessageBuilder\ImagemapMessageBuilder (
    //'https://' . $_SERVER['HTTP_HOST'] .  '/map/' . urlencode($lat) . '/' . urlencode($lon) . '/' . uniqid(),
    'https://' . $_SERVER['HTTP_HOST'] .  '/map/' . $event->getUserId() . '/' . uniqid(),
    'シート',
    new LINE\LINEBot\MessageBuilder\Imagemap\BaseSizeBuilder(1040, 1040),
    $actionsArray
  );
  $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
  //$builder->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder("結果です"));
  $builder->add($imagemapMessageBuilder);
  if(getLastToken($event->getUserId()) != '') {
    $builder->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder("次の20件を表示するには「次」と送ってね！"));
  }

  $response = $bot->replyMessage($event->getReplyToken(), $builder);
  if(!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}


function getMarkerPosArray($userId, $isUseLastToken) {
  if($isUseLastToken) {
    $lat = explode(",", getLatlon($userId))[0];
    $lon = explode(",", getLatlon($userId))[1];
    $placesUrl = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?pagetoken=" . getLastToken($userId) . "&key=" . getenv('GOOGLE_API_KEY');
  } else {
    $lat = explode(",", getLatlon($userId))[0];
    $lon = explode(",", getLatlon($userId))[1];
    $placesUrl = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=" . $lat . ',' . $lon . "&keyword=" . urlencode('ラーメン') . "&radius=500&key=" . getenv('GOOGLE_API_KEY');
  }
  error_log("places ::: " . $placesUrl);

  $json_string = file_get_contents($placesUrl);
  $json = json_decode($json_string, true)["results"];

  //error_log(var_export(json_decode($json_string, true), true));

  updateLastToken($userId, json_decode($json_string, true)['next_page_token']);
  updateJsonString($userId, $json_string);

  $cnt = 0;
  $multiple = 131072.0; // 2^16

  $result = Array();
  $scale = 1040.0 / 1280.0;

  foreach($json as $shop) {

    $marker = [($shop['geometry']['location']['lat'] - $lat) * -1.0, $shop['geometry']['location']['lng'] - $lon];

    $xDiff = $marker[1] * $multiple * 0.71;
    $yDiff = $marker[0] * $multiple * 0.88;

    //error_log('adding area. x' . (640 + $xDiff) * $scale);
    //error_log('adding area. y' . (640 + $yDiff) * $scale);

    $x = (640 + $xDiff) * $scale;
    $y = (640 + $yDiff) * $scale;


    $areaWidth = 60 * 1.5;
    $areaHeight = 84 * 1.5;

    if($x - $areaWidth / 2 < 0 || $y - $areaHeight / 2 < 0) {
      continue;
    }

    array_push($result,
    new LINE\LINEBot\ImagemapActionBuilder\ImagemapMessageActionBuilder(
      $cnt,
      new LINE\LINEBot\ImagemapActionBuilder\AreaBuilder($x - $areaWidth / 2, $y - $areaHeight / 2, $areaWidth, $areaHeight)));
    $cnt++;
  }

  return $result;
}

function registerUser($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select jsonstring from ' . TABLE_NAME_USERS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));

  if (!($row = $sth->fetch())) {
    //return PDO::PARAM_NULL;
    $sqlRegister = 'insert into '. TABLE_NAME_USERS .' (userid, lasttoken, jsonstring, location, shopjsonstring) values (pgp_sym_encrypt(?, \'' . getenv('DB_ENCRYPT_PASS') . '\'), ?, ?, ?, ?) ';
    $sthRegister = $dbh->prepare($sqlRegister);
    $sthRegister->execute(array($userId, '', '', '', ''));
  }
}

function updateLocation($userId, $latlonString) {
  $dbh = dbConnection::getConnection();
  $sql = 'update ' . TABLE_NAME_USERS . ' set location = ? where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($latlonString, $userId));
}

function getLatlon($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select location from ' . TABLE_NAME_USERS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));

  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['location'];
  }
}

function updateLastToken($userId, $lastToken) {
  error_log('updateLastToken : ' . $lastToken);
  $dbh = dbConnection::getConnection();
  $sql = 'update ' . TABLE_NAME_USERS . ' set lasttoken = ? where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($lastToken, $userId));
}

function getLastToken($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select lasttoken from ' . TABLE_NAME_USERS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['lasttoken'];
  }
}

function updateJsonString($userId, $jsonString) {
  $dbh = dbConnection::getConnection();
  $sql = 'update ' . TABLE_NAME_USERS . ' set jsonstring = ? where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($jsonString, $userId));
}

function getJsonString($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select jsonstring from ' . TABLE_NAME_USERS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['jsonstring'];
  }
}

function updateShopJsonString($userId, $shopJsonString) {
  $dbh = dbConnection::getConnection();
  $sql = 'update ' . TABLE_NAME_USERS . ' set shopjsonstring = ? where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($shopJsonString, $userId));
}

function getShopJsonString($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select shopjsonstring from ' . TABLE_NAME_USERS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['shopjsonstring'];
  }
}


// テキストを返信。引数はLINEBot、返信先、テキスト
function replyTextMessage($bot, $replyToken, $text) {
  // 返信を行いレスポンスを取得
  // TextMessageBuilderの引数はテキスト
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($text));
  // レスポンスが異常な場合
  if (!$response->isSucceeded()) {
    // エラー内容を出力
    error_log('Failed! '. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// 画像を返信。引数はLINEBot、返信先、画像URL、サムネイルURL
function replyImageMessage($bot, $replyToken, $originalImageUrl, $previewImageUrl) {
  // ImageMessageBuilderの引数は画像URL、サムネイルURL
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\ImageMessageBuilder($originalImageUrl, $previewImageUrl));
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// 位置情報を返信。引数はLINEBot、返信先、タイトル、住所、
// 緯度、経度
function replyLocationMessage($bot, $replyToken, $title, $address, $lat, $lon) {
  // LocationMessageBuilderの引数はダイアログのタイトル、住所、緯度、経度
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\LocationMessageBuilder($title, $address, $lat, $lon));
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// スタンプを返信。引数はLINEBot、返信先、
// スタンプのパッケージID、スタンプID
function replyStickerMessage($bot, $replyToken, $packageId, $stickerId) {
  // StickerMessageBuilderの引数はスタンプのパッケージID、スタンプID
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder($packageId, $stickerId));
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// 動画を返信。引数はLINEBot、返信先、動画URL、サムネイルURL
function replyVideoMessage($bot, $replyToken, $originalContentUrl, $previewImageUrl) {
  // VideoMessageBuilderの引数は動画URL、サムネイルURL
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\VideoMessageBuilder($originalContentUrl, $previewImageUrl));
  if (!$response->isSucceeded()) {
    error_log('Failed! '. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// オーディオファイルを返信。引数はLINEBot、返信先、
// ファイルのURL、ファイルの再生時間
function replyAudioMessage($bot, $replyToken, $originalContentUrl, $audioLength) {
  // AudioMessageBuilderの引数はファイルのURL、ファイルの再生時間
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\AudioMessageBuilder($originalContentUrl, $audioLength));
  if (!$response->isSucceeded()) {
    error_log('Failed! '. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// 複数のメッセージをまとめて返信。引数はLINEBot、
// 返信先、メッセージ(可変長引数)
function replyMultiMessage($bot, $replyToken, ...$msgs) {
  // MultiMessageBuilderをインスタンス化
  $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
  // ビルダーにメッセージを全て追加
  foreach($msgs as $value) {
    $builder->add($value);
  }
  $response = $bot->replyMessage($replyToken, $builder);
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// Buttonsテンプレートを返信。引数はLINEBot、返信先、代替テキスト、
// 画像URL、タイトル、本文、アクション(可変長引数)
function replyButtonsTemplate($bot, $replyToken, $alternativeText, $imageUrl, $title, $text, ...$actions) {
  // アクションを格納する配列
  $actionArray = array();
  // アクションを全て追加
  foreach($actions as $value) {
    array_push($actionArray, $value);
  }
  // TemplateMessageBuilderの引数は代替テキスト、ButtonTemplateBuilder
  $builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
    $alternativeText,
    // ButtonTemplateBuilderの引数はタイトル、本文、
    // 画像URL、アクションの配列
    new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder ($title, $text, $imageUrl, $actionArray)
  );
  $response = $bot->replyMessage($replyToken, $builder);
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// Confirmテンプレートを返信。引数はLINEBot、返信先、代替テキスト、
// 本文、アクション(可変長引数)
function replyConfirmTemplate($bot, $replyToken, $alternativeText, $text, ...$actions) {
  $actionArray = array();
  foreach($actions as $value) {
    array_push($actionArray, $value);
  }
  $builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
    $alternativeText,
    // Confirmテンプレートの引数はテキスト、アクションの配列
    new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder ($text, $actionArray)
  );
  $response = $bot->replyMessage($replyToken, $builder);
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// Carouselテンプレートを返信。引数はLINEBot、返信先、代替テキスト、
// ダイアログの配列
function replyCarouselTemplate($bot, $replyToken, $alternativeText, $columnArray) {
  $builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
  $alternativeText,
  // Carouselテンプレートの引数はダイアログの配列
  new \LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselTemplateBuilder (
   $columnArray)
  );
  $response = $bot->replyMessage($replyToken, $builder);
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

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
