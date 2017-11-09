#!/usr/bin/env php
<?php
/*
Copyright 2016-2017 Daniil Gentili
(https://daniil.it)
This file is part of MadelineProto.
MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Affero General Public License for more details.
You should have received a copy of the GNU General Public License along with MadelineProto.
If not, see <http://www.gnu.org/licenses/>.
*/
set_include_path(get_include_path().':'.realpath(dirname(__FILE__).'/MadelineProto/'));

require 'vendor/autoload.php';
$settings = ['app_info' => ['api_id' => 6, 'api_hash' => 'eb06d4abfb49dc3eeb1aeb98ae0f581e']];

try {
    $MadelineProto = new \danog\MadelineProto\API('bot.madeline');
} catch (\danog\MadelineProto\Exception $e) {
    var_dump($e->getMessage());
    $MadelineProto = new \danog\MadelineProto\API($settings);
    $authorization = $MadelineProto->bot_login(readline('Enter a bot token: '));
    \danog\MadelineProto\Logger::log([$authorization], \danog\MadelineProto\Logger::NOTICE);
}
//var_dump($MadelineProto->API->get_config([], ['datacenter' => $MadelineProto->API->datacenter->curdc]));
//var_dump($MadelineProto->API->settings['connection']);
$MadelineProto->session = 'bot.madeline';
echo 'Wrote '.\danog\MadelineProto\Serialization::serialize('bot.madeline', $MadelineProto).' bytes'.PHP_EOL;
$offset = 0;
while (true) {
    $updates = $MadelineProto->get_updates(['offset' => $offset, 'limit' => 50, 'timeout' => 0]); // Just like in the bot API, you can specify an offset, a limit and a timeout
var_dump($updates);
    \danog\MadelineProto\Logger::log([$updates]);
    foreach ($updates as $update) {
        $offset = $update['update_id'] + 1; // Just like in the bot API, the offset must be set to the last update_id
    }
    echo 'Wrote '.\danog\MadelineProto\Serialization::serialize('bot.madeline', $MadelineProto).' bytes'.PHP_EOL;
}
