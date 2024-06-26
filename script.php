<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$config = json_decode(file_get_contents("config.json"), true);

$username = $config['traduora']['username'];
$password = $config['traduora']['password'];
$projects = $config['traduora']['projects'];
$url = $config['traduora']['url'];
$deeplApiKey = $config['deepl']['apiKey'];
$sourceLangDeepl = $config['sourceLang']['deepl'];
$sourceLang = $config['sourceLang']['traduora'];


sendMessage("");
sendMessage("Traduora-Deepl-Translator 1.0");
sendMessage("");

$managedLang = $config['langs'];

define("url", $url);
define("deepl", $deeplApiKey);
define("sourceLangDeepl", $sourceLangDeepl);
define("sourceLang", $sourceLang);

$postField = ["grant_type" => "password", "username" => $username, "password" => $password];

$ch = curl_init();
curl_setopt_array($ch, [CURLOPT_URL => url . "/api/v1/auth/token", CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => "POST", CURLOPT_POSTFIELDS => json_encode($postField), CURLOPT_HTTPHEADER => ["content-type: application/json"]]);
$auth = curl_exec($ch);


$response = json_decode($auth, true);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if(is_null($response)) throw new Exception("auth request failed (null)");
if (isset($auth['error'])) throw new Exception("auth request failed");

define("accessToken", $response['access_token']);

function traduoraRequest($path, $method, $data = [], $timeout = 10): array
{
  $ch = curl_init();
  curl_setopt_array($ch, [CURLOPT_URL => url . "/api/v1/" . $path, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_FOLLOWLOCATION => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ["Authorization: Bearer " . accessToken, "content-type: application/json"]]);
  $response = curl_exec($ch);
  curl_close($ch);
  $response = json_decode($response, true);
  if ($response === false) throw new Exception("traduora request failed");
  if (isset($response['error'])) {
    throw new Exception("traduora request failed: " . $response['error']['message'] . " (" . $response['error']['code'] . ")");
  }
  if(is_null($response)) throw new Exception("traduora request failed: " . $response."(null)");

  return $response;
}

function deeplRequest($sourceLang, $outputLang, $message): string
{
  $curl = curl_init();
  curl_setopt_array($curl, [
    CURLOPT_URL => 'https://api-free.deepl.com/v2/translate',
    CURLOPT_POSTFIELDS => [
      'auth_key' => deepl,
      'text' => $message,
      'target_lang' => $outputLang,
      'source_lang' => $sourceLang,
      'ignore_tags' => 'keep',
      'preserve_formatting' => true,
      'tag_handling' => 'xml'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CUSTOMREQUEST => 'POST'
  ]);

  $response = curl_exec($curl);
  $info = curl_getinfo($curl);
  curl_close($curl);
  if ($response === false) {
    throw new Exception("deepl request failed, ".$info["http_code"]);
  }
  $array = json_decode($response, true);
  if (is_null($array) or $array === false) {
    throw new Exception("deepl request failed, ".$info["http_code"]);
  }

  return $array['translations'][0]['text'] ?? throw new Exception("deepl request failed, ".$info["http_code"]);
}

/**
 * @throws Exception
 */
function getTerms($project, $lang): array
{
  $terms = [];

  $r = traduoraRequest("projects/" . $project . "/translations/" . $lang, "GET");
  foreach ($r['data'] as $term) {
    $terms[$term['termId']] = $term['value'];
  }

  return $terms;
}

/**
 * @throws Exception
 */
function getLangs($project): array
{
  $r = traduoraRequest("projects/" . $project . "/translations/", "GET");
  $langs = [];
  foreach ($r['data'] as $lang) {
    $langs[$lang['locale']['code']] = $lang['locale']['language'];
  }

  return $langs;
}


foreach ($projects as $project => $name) {
  sendMessage("get project ".$name);
  try {
    sendMessage("get terms in ".sourceLang." in ".$name);
    $terms = getTerms($project, sourceLang);
  } catch (Exception $e) {
    sendMessage("error in get Projects terms in ".$name.": ".$e->getMessage());
    continue;
  }

  try {
    sendMessage("get langs in ".$name);
    $langs = getLangs($project);
  } catch (Exception $e) {
    sendMessage("error in get Projects langs in ".$name.": ".$e->getMessage());
    continue;
  }

  foreach ($managedLang as $lang => $deeplLang) {
    try {
      if (!isset($langs[$lang])) {
        sendMessage("create lang ".$lang." in ".$name);
        traduoraRequest("projects/" . $project . "/translations", "POST", ["code" => $lang], 100);
        sleep(3);
      }
      sendMessage("get terms in ".$lang." in ".$name);
      $termsLang = getTerms($project, $lang);

      $countTerms = count($terms);
      $currentTerm = 0;
      foreach ($terms as $key => $value) {
        $currentTerm++;
        if (empty($termsLang[$key]) and !empty($value)) {
          usleep(250000);
          try {
            sendMessage("translate " . $key . " in " . $lang . " in " . $name." (".$currentTerm."/".$countTerms.")");
            $termsLang[$key] = removeMessagePlaceholder(deeplRequest(sourceLangDeepl, $deeplLang, addMessagePlaceholder($value)));
            traduoraRequest("projects/" . $project . "/translations/" . $lang, "PATCH", ["termId" => $key, "value" => $termsLang[$key]]);
          } catch (Exception $e) {
            sendMessage("error in lang " . $lang . " for term " . $key . " with value " . $value." error: ".$e->getMessage(). " (".$currentTerm."/".$countTerms.")");
          }
        } else {
          if(!hasMessagePlaceholder($termsLang[$key],$value)){
            sendMessage("patch translation (no placeholder found) " . $key . " in " . $lang . " in " . $name." (".$currentTerm."/".$countTerms.")");

            echo "old:".$termsLang[$key].PHP_EOL;
            $termsLang[$key] = removeMessagePlaceholder(deeplRequest(sourceLangDeepl, $deeplLang, addMessagePlaceholder($value)));
            echo "new:".$termsLang[$key].PHP_EOL;

            traduoraRequest("projects/" . $project . "/translations/" . $lang, "PATCH", ["termId" => $key, "value" => $termsLang[$key]]);
          } else {
            sendMessage("skip " . $key . " in " . $lang . " in " . $name . " (" . $currentTerm . "/" . $countTerms . ")");
          }
        }
      }
    } catch (Exception $e) {
      sendMessage("error in lang " . $lang . ":" . $e->getMessage());
    }

  }

}

function sendMessage(string $message): void
{
  echo "[".date("d.m.Y H:i:s")."] ".$message.PHP_EOL;
}

function hasMessagePlaceholder(string $message, string $original): bool
{
  $allPlaceholders = [];
  $split = explode(" ", $original);
  foreach ($split as $word){
    if (str_starts_with($word, "@")){
      $allPlaceholders[] = $word;
    }
  }

  if(count($allPlaceholders) === 0){
    return true;
  }


  foreach ($allPlaceholders as $allPlaceholder) {
    if (!str_contains($message, $allPlaceholder)) {
      return false;
    }
  }

  return true;
}

function addMessagePlaceholder(string $message): string
{
  $split = explode(" ", $message);
  $newMessage = "";
  foreach ($split as $word){
    if (str_starts_with($word, "@")){
      $newMessage .= " <keep>".$word."</keep>";
    } else {
      $newMessage .= " ".$word;
    }
  }

  return removeFirstEmptySpace($newMessage);
}

function removeFirstEmptySpace(string $message): string
{
  $new = $message;
  if (str_starts_with($message, " ")){
    $new = substr($message, 1);
  }
  if (str_starts_with($new, " ")){
    return removeFirstEmptySpace($new);
  }


  return $new;
}

function removeMessagePlaceholder(string $message): string
{
  $message = str_replace("</keep>", "", str_replace("<keep>", "", $message));

  return removeFirstEmptySpace($message);
}