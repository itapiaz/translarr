<?php
// html/includes/MediaServerFactory.php

require_once 'MediaServerInterface.php';

class MediaServerFactory {
    public static function getAPI($type = null, $url = null, $apiKey = null) {
        if ($type === null) {
            $type = defined('MEDIA_SERVER_TYPE') ? MEDIA_SERVER_TYPE : 'bazarr';
            $url = defined('MEDIA_SERVER_URL') ? MEDIA_SERVER_URL : '';
            $apiKey = defined('MEDIA_SERVER_API_KEY') ? MEDIA_SERVER_API_KEY : '';
        }

        if ($type === 'emby' || $type === 'jellyfin') {
            require_once 'EmbyJellyfinAPI.php';
            return new EmbyJellyfinAPI($url, $apiKey, $type);
        } else {
            require_once 'BazarrAPI.php';
            return new BazarrAPI($url, $apiKey);
        }
    }
}
?>
