<?php

$starttime = microtime(true);

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/clients.php';

Log::append("Начато переименование раздач...");

$cfg = get_settings();

$allowedClients = ['qbittorrent'];
$usedClients = 0;

// Перебираем доступные клиенты и продолжаем только для допустимых
foreach ($cfg['clients'] as $torrentClientData) {
    if (!in_array($torrentClientData['cl'], $allowedClients)) {
        continue;
    }
    $usedClients++;

    $client = new $torrentClientData['cl'](
        $torrentClientData['ssl'],
        $torrentClientData['ht'],
        $torrentClientData['pt'],
        $torrentClientData['lg'],
        $torrentClientData['pw']
    );
    if ($client->isOnline() === false) {
        throw new Exception('Не подключения к торрент-клиенту');
    }

    $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);
    $clientTags = $client->getAllTags();

    $params = array('filter' => 'completed', 'tag' => '', 'limit' => 100);
    // $params = array('hashes' => 'd0ff54c19605dc63253d4ce36e0a392bbc26fdb3');
    $torrents = $client->getAllTorrents( $params );

    // Ищем названия раздач в БД
    $topicsHashes = array_keys($torrents);
    $placeholders = str_repeat('?,', count($topicsHashes) - 1) . '?';
    $response = Db::query_database(
        'SELECT DISTINCT hs, na AS topic_title, id AS topic_id FROM Topics WHERE hs IN (' . $placeholders . ')',
        $topicsHashes,
        true,
        PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
    );
    $torrents = array_replace_recursive($torrents, $response);

    // echo "<pre>". print_r($torrents,1) ."</pre>";

    $tagged = [];
    foreach ($torrents as $torrentHash => $torrent) {
        if ($torrent['done'] < 1) continue;

        $result = EditTorrent::processTorrent($torrentHash, $torrent, $clientTags);
        if (!$result || !count($result)) continue;

        // Переименование.
        if ($result["nameAft"] != $result["nameBef"]) {
            Log::append("Раздача переименована в клиенте $torrentHash => ".$result["nameAft"]);
            $client->renameTorrent($torrent['client_hash'], $result["nameAft"]);
        }

        // Расстановка меток.
        if (count($result["tags"]) > 0) {
            foreach ($result["tags"] as $tag) {
                $tagged[$tag][]= $torrent['client_hash'];
            }
        }
    }

    if (count($tagged)) {
        foreach ($tagged as $tag => $torrentHashes) {
            Log::append("Проставлены метки $tag " . count($torrentHashes) . " шт");
            $client->addTags($torrentHashes, array($tag));
        }
    }
}
Log::append("Использовано клиентов: ".$usedClients);
Log::append("Переименование раздач завершено за " . convert_seconds(microtime(true) - $starttime));


class EditTorrent {
    const GROUPS = ['anime', 'video', 'serials', 'linux', 'games', 'soft'];

    public static function processTorrent( $torrentHash, $torrent, $clientTags ) {
        if (!isset($torrent['topic_title'])) {
            Log::append("Нет торрента в БД $torrentHash => ".$torrent['topic_id']);
            return;
        }

        $clientTags = array_unique( array_merge( $clientTags, array_map('ucfirst', self::GROUPS) ) );

        $topicName = self::getTopicName($torrent['topic_title'], $torrent['category']);
        $topicTags = self::getTopicTags($clientTags, $topicName);
        $topicOldID = preg_replace('/[^0-9]*/', '$1', $torrent['location']);

        if (strtolower($torrent['client_hash']) !== strtolower($torrentHash)) {
            $topicTags[]= 'hash-V2';
        }
        $result = array(
            'hash'         => $torrentHash,
            'nameBef'      => $torrent['name'],
            'nameAft'      => $topicName,
            'nameTit'      => $torrent['topic_title'],
            'category'     => $torrent['category'],
            'tags'         => $topicTags,
        );

        return $result;
    }

    private static function getTopicTags($clientTags, $topicName)
    {
        $topicTags = [];
        foreach ($clientTags as $tag) {
            if (strpos($topicName, '['. $tag .']') !== false) {
                $topicTags[]= $tag;
            }
        }
        if (empty($topicTags)) {
            $topicTags[]= 'Other';
        }
        return $topicTags;
    }

    private static function getTopicName($title, $category = '')
    {
        foreach ( self::GROUPS as $key ) {
            if (strpos($category, $key) !== false) {
                $group = $key;
                break;
            }
        }

        if ($group == 'games') {
            $title = preg_replace('|(\([^\[\]]+\)\s?)|U', '', $title); // Для игр вырезаем любую дичь в скобках
            $title = preg_replace('|&#\d+;|', "", $title);             // Стираем азиатские символы.
            $title = str_replace(' &amp;', ',', $title);               // << Заменяем символ &.
            $title = trim(preg_replace('|\s+|', ' ', $title));         // << Убираем лишние пробелы.
        }
        else if($group == 'video') {
            $ql  = self::getRegexMatch('|\[\d+p\]|', $title);
            $rip = self::getRegexMatch('/\w+(Rip|mux)/', $title);
            $ln  = self::getRegexMatch('|\[\d+(\+\d+)?\s.+\s\d+(\+\d+)?\]|', $title);
            $arr = explode('/', preg_replace('|\[.*\]|', '', $title));
            $nm  = implode('/', array_slice($arr, 0, 2));
            // Название / кол-во серий / рип / качество.
            $title = '[' . ucfirst($group) . '] ' . $nm . $ln;
            if ($rip) $title .= '[' . $rip . ']';
            if ($ql)  $title .= $ql;
        } else if ($group != ''){
            $title = '[' . ucfirst($group) . '] ' . $title;
        }
        return $title;
    }

    private static function getRegexMatch($pattern, $string)
    {
        preg_match($pattern, $string, $matches);
        return isset($matches[0]) ? $matches[0] : '';
    }
}

