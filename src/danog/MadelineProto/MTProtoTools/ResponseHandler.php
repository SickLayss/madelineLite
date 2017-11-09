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

namespace danog\MadelineProto\MTProtoTools;

/**
 * Manages responses.
 */
trait ResponseHandler
{
    public function send_msgs_state_info($req_msg_id, $msg_ids, $datacenter)
    {
        $info = '';
        foreach ($msg_ids as $msg_id) {
            $cur_info = 0;
            if (!isset($this->datacenter->sockets[$datacenter]->incoming_messages[$msg_id])) {
                $msg_id = new \phpseclib\Math\BigInteger(strrev($msg_id), 256);
                if ((new \phpseclib\Math\BigInteger(time() + $this->datacenter->sockets[$datacenter]->time_delta + 30))->bitwise_leftShift(32)->compare($msg_id) < 0) {
                    $cur_info |= 3;
                } elseif ((new \phpseclib\Math\BigInteger(time() + $this->datacenter->sockets[$datacenter]->time_delta - 300))->bitwise_leftShift(32)->compare($msg_id) > 0) {
                    $cur_info |= 1;
                } else {
                    $cur_info |= 2;
                }
            } else {
                $cur_info |= 4;
                if ($this->datacenter->sockets[$datacenter]->incoming_messages[$msg_id]['ack']) {
                    $cur_info |= 8;
                }
            }
            $info .= chr($cur_info);
        }
        $this->datacenter->sockets[$datacenter]->outgoing_messages[$this->object_call('msgs_state_info', ['req_msg_id' => $req_msg_id, 'info' => $info], ['datacenter' => $datacenter])]['response'] = $req_msg_id;
    }

    public function handle_messages($datacenter)
    {
        $only_updates = true;
        foreach ($this->datacenter->sockets[$datacenter]->new_incoming as $current_msg_id) {
            $unset = false;
            \danog\MadelineProto\Logger::log(['Received '.$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['_'].'.'], \danog\MadelineProto\Logger::ULTRA_VERBOSE);
            //\danog\MadelineProto\Logger::log([$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']], \danog\MadelineProto\Logger::ULTRA_VERBOSE);
            if (\danog\MadelineProto\Logger::$has_thread && is_object(\Thread::getCurrentThread())) {
                if (!$this->synchronized(function ($zis, $datacenter, $current_msg_id) {
                    if (isset($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['handling'])) {
                        return false;
                    }
                    $this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['handling'] = true;

                    return true;
                }, $this, $datacenter, $current_msg_id)) {
                    \danog\MadelineProto\Logger::log([base64_encode($current_msg_id).$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['_'].' is already being handled'], \danog\MadelineProto\Logger::VERBOSE);
                    continue;
                }
                \danog\MadelineProto\Logger::log(['Handling '.base64_encode($current_msg_id).$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['_'].'.'], \danog\MadelineProto\Logger::VERBOSE);
            }
            switch ($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['_']) {
                case 'msgs_ack':
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    foreach ($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_ids'] as $msg_id) {
                        $this->ack_outgoing_message_id($msg_id, $datacenter); // Acknowledge that the server received my message
                    }
                    break;

                case 'rpc_result':
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    unset($this->datacenter->sockets[$datacenter]->new_outgoing[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['req_msg_id']]);
                    $this->ack_incoming_message_id($current_msg_id, $datacenter); // Acknowledge that I received the server's response
                    $this->ack_outgoing_message_id($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['req_msg_id'], $datacenter); // Acknowledge that the server received my request
                    $this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['req_msg_id']]['response'] = $current_msg_id;
                    $this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content'] = $this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['result'];
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    break;
                case 'future_salts':
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    unset($this->datacenter->sockets[$datacenter]->new_outgoing[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['req_msg_id']]);
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    $this->ack_outgoing_message_id($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['req_msg_id'], $datacenter); // Acknowledge that the server received my request
                    $this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['req_msg_id']]['response'] = $current_msg_id;
                    break;

                case 'bad_server_salt':
                case 'bad_msg_notification':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    $this->ack_outgoing_message_id($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['bad_msg_id'], $datacenter); // Acknowledge that the server received my request
                    $this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['bad_msg_id']]['response'] = $current_msg_id;
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    unset($this->datacenter->sockets[$datacenter]->new_outgoing[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['bad_msg_id']]);
                    break;

                case 'pong':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    unset($this->datacenter->sockets[$datacenter]->new_outgoing[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_id']]);
                    $this->ack_outgoing_message_id($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_id'], $datacenter); // Acknowledge that the server received my request
                    $this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_id']]['response'] = $current_msg_id;
                    break;

                case 'new_session_created':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    $this->datacenter->sockets[$datacenter]->temp_auth_key['server_salt'] = $this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['server_salt'];
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    $this->ack_incoming_message_id($current_msg_id, $datacenter); // Acknowledge that I received the server's response
                    $unset = true;
                    break;
                case 'msg_container':
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    foreach ($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['messages'] as $message) {
                        $this->check_message_id($message['msg_id'], ['outgoing' => false, 'datacenter' => $datacenter, 'container' => true]);
                        $this->datacenter->sockets[$datacenter]->incoming_messages[$message['msg_id']] = ['seq_no' => $message['seqno'], 'content' => $message['body']];
                        $this->datacenter->sockets[$datacenter]->new_incoming[$message['msg_id']] = $message['msg_id'];

                        $this->handle_messages($datacenter);
                    }
                    $unset = true;
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    break;
                case 'msg_copy':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    $this->ack_incoming_message_id($current_msg_id, $datacenter); // Acknowledge that I received the server's response
                    if (isset($this->datacenter->sockets[$datacenter]->incoming_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['orig_message']['msg_id']])) {
                        $this->ack_incoming_message_id($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['orig_message']['msg_id'], $datacenter); // Acknowledge that I received the server's response
                    } else {
                        $this->check_message_id($message['orig_message']['msg_id'], ['outgoing' => false, 'datacenter' => $datacenter, 'container' => true]);
                        $this->datacenter->sockets[$datacenter]->incoming_messages[$message['orig_message']['msg_id']] = ['content' => $this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['orig_message']];
                        $this->datacenter->sockets[$datacenter]->new_incoming[$message['orig_message']['msg_id']] = $message['orig_message']['msg_id'];

                        $this->handle_messages($datacenter);
                    }
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    $unset = true;
                    break;
                case 'http_wait':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;

                    \danog\MadelineProto\Logger::log([$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']], \danog\MadelineProto\Logger::NOTICE);
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    $unset = true;
                    break;
                case 'msgs_state_info':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    $this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['req_msg_id']]['response'] = $current_msg_id;
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    unset($this->datacenter->sockets[$datacenter]->new_outgoing[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['req_msg_id']]);
                    $unset = true;
                    break;
                case 'msgs_state_req':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    $this->send_msgs_state_info($current_msg_id, $this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_ids'], $datacenter);
                    break;
                case 'msgs_all_info':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);

                    foreach ($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_ids'] as $key => $msg_id) {
                        $msg_id = new \phpseclib\Math\BigInteger(strrev($msg_id), 256);
                        $status = 'Status for message id '.$msg_id.': ';
                        if (($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['info'][$key] & 4) !== 0) {
                            $this->ack_outgoing_message_id($msg_id, $datacenter);
                        }
                        foreach (self::MSGS_INFO_FLAGS as $flag => $description) {
                            if (($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['info'][$key] & $flag) !== 0) {
                                $status .= $description;
                            }
                        }
                        \danog\MadelineProto\Logger::log([$status], \danog\MadelineProto\Logger::NOTICE);
                    }
                    break;
                case 'msg_detailed_info':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    if (isset($this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_id']])) {
                        if (isset($this->datacenter->sockets[$datacenter]->incoming_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['answer_msg_id']])) {
                            $this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_id']]['response'] = $this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['answer_msg_id'];
                            unset($this->datacenter->sockets[$datacenter]->new_outgoing[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_id']]);
                        }
                    }
                case 'msg_new_detailed_info':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    if (isset($this->datacenter->sockets[$datacenter]->incoming_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['answer_msg_id']])) {
                        $this->ack_incoming_message_id($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['answer_msg_id'], $datacenter);
                    }
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    break;
                case 'msg_resend_req':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    $ok = true;
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    foreach ($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_ids'] as $msg_id) {
                        if (!isset($this->datacenter->sockets[$datacenter]->outgoing_messages[$msg_id]) || isset($this->datacenter->sockets[$datacenter]->incoming_messages[$msg_id])) {
                            $ok = false;
                        }
                    }
                    if ($ok) {
                        foreach ($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_ids'] as $msg_id) {
                            $this->object_call($this->datacenter->sockets[$datacenter]->outgoing_messages[$msg_id]['content']['method'], $this->datacenter->sockets[$datacenter]->outgoing_messages[$msg_id]['content']['args'], ['datacenter' => $datacenter]);
                        }
                    } else {
                        $this->send_msgs_state_info($current_msg_id, $this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_ids'], $datacenter);
                    }
                    break;
                case 'msg_resend_ans_req':
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $only_updates = false;
                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                    $this->send_msgs_state_info($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_ids'], $datacenter);
                    foreach ($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['msg_ids'] as $msg_id) {
                        if (isset($this->datacenter->sockets[$datacenter]->incoming_messages[$msg_id]) && isset($this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$msg_id]['response']])) {
                            $this->object_call($this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$msg_id]['response']]['method'], $this->datacenter->sockets[$datacenter]->outgoing_messages[$this->datacenter->sockets[$datacenter]->incoming_messages[$msg_id]['response']]['args'], ['datacenter' => $datacenter]);
                        }
                    }
                    break;
                default:
                    $this->check_in_seq_no($datacenter, $current_msg_id);
                    $this->ack_incoming_message_id($current_msg_id, $datacenter); // Acknowledge that I received the server's response
                    $response_type = $this->constructors->find_by_predicate($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['_'])['type'];
                    switch ($response_type) {
                        case 'Updates':
                            unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);
                            $unset = true;
                            $this->handle_updates($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']);
                            $only_updates = true && $only_updates;
                            break;
                        default:
                            $only_updates = false;
                            \danog\MadelineProto\Logger::log(['Trying to assign a response of type '.$response_type.' to its request...'], \danog\MadelineProto\Logger::VERBOSE);
                            foreach ($this->datacenter->sockets[$datacenter]->new_outgoing as $key => $expecting) {
                                \danog\MadelineProto\Logger::log(['Does the request of return type '.$expecting['type'].' match?'], \danog\MadelineProto\Logger::VERBOSE);
                                if ($response_type === $expecting['type']) {
                                    \danog\MadelineProto\Logger::log(['Yes'], \danog\MadelineProto\Logger::VERBOSE);
                                    $this->datacenter->sockets[$datacenter]->outgoing_messages[$expecting['msg_id']]['response'] = $current_msg_id;
                                    unset($this->datacenter->sockets[$datacenter]->new_outgoing[$key]);
                                    unset($this->datacenter->sockets[$datacenter]->new_incoming[$current_msg_id]);

                                    break 2;
                                }
                                \danog\MadelineProto\Logger::log(['No'], \danog\MadelineProto\Logger::VERBOSE);
                            }

                            throw new \danog\MadelineProto\ResponseException('Dunno how to handle '.PHP_EOL.var_export($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content'], true));
                            break;
                    }
                    break;
            }

            if (isset($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['result']['_'])) {
                switch ($this->constructors->find_by_predicate($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['result']['_'])['type']) {
                    case 'Update':
                    $this->handle_update($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]['content']['result']);
                    break;

                }
            }
            if ($unset) {
                unset($this->datacenter->sockets[$datacenter]->incoming_messages[$current_msg_id]);
            }
        }

        return $only_updates;
    }

    public function handle_rpc_error($server_answer, &$aargs)
    {
        if (in_array($server_answer['error_message'], ['PERSISTENT_TIMESTAMP_EMPTY', 'PERSISTENT_TIMESTAMP_OUTDATED', 'PERSISTENT_TIMESTAMP_INVALID'])) {
            throw new \danog\MadelineProto\PTSException($server_answer['error_message']);
        }
        switch ($server_answer['error_code']) {
            case 303:
                $this->datacenter->curdc = $aargs['datacenter'] = (int) preg_replace('/[^0-9]+/', '', $server_answer['error_message']);

                throw new \danog\MadelineProto\Exception('Received request to switch to DC '.$this->datacenter->curdc);
            case 401:
                switch ($server_answer['error_message']) {
                    case 'USER_DEACTIVATED':
                    case 'SESSION_REVOKED':
                    case 'SESSION_EXPIRED':
                        $this->datacenter->sockets[$aargs['datacenter']]->temp_auth_key = null;
                        $this->datacenter->sockets[$aargs['datacenter']]->auth_key = null;
                        $this->authorized = self::NOT_LOGGED_IN;
                        $this->authorization = null;
                        $this->init_authorization(); // idk
                        throw new \danog\MadelineProto\RPCErrorException($server_answer['error_message'], $server_answer['error_code']);
                    case 'AUTH_KEY_UNREGISTERED':
                    case 'AUTH_KEY_INVALID':
                        $this->datacenter->sockets[$aargs['datacenter']]->temp_auth_key = null;
                        $this->init_authorization(); // idk
                        throw new \danog\MadelineProto\RPCErrorException($server_answer['error_message'], $server_answer['error_code']);
                }
            case 420:
                $seconds = preg_replace('/[^0-9]+/', '', $server_answer['error_message']);
                $limit = isset($aargs['FloodWaitLimit']) ? $aargs['FloodWaitLimit'] : $this->settings['flood_timeout']['wait_if_lt'];
                if (is_numeric($seconds) && $seconds < $limit) {
                    \danog\MadelineProto\Logger::log(['Flood, waiting '.$seconds.' seconds...'], \danog\MadelineProto\Logger::NOTICE);
                    sleep($seconds);

                    throw new \danog\MadelineProto\Exception('Re-executing query...');
                }
            default:
                throw new \danog\MadelineProto\RPCErrorException($server_answer['error_message'], $server_answer['error_code']);
        }
    }

    public function handle_updates($updates)
    {
        if (!$this->settings['updates']['handle_updates']) {
            return;
        }
        \danog\MadelineProto\Logger::log(['Parsing updates received via the socket...'], \danog\MadelineProto\Logger::VERBOSE);
        $this->updates[]= $updates;
    }

    public function get_updates($params = [])
    {
        if (!$this->settings['updates']['handle_updates']) {
            return;
        }
        $time = microtime(true);
        $this->method_call('ping', ['ping_id' => $this->updates_key], ['datacenter' => $this->datacenter->curdc]);
        $default_params = ['offset' => 0, 'limit' => null, 'timeout' => 0];
        foreach ($default_params as $key => $default) {
            if (!isset($params[$key])) {
                $params[$key] = $default;
            }
        }
        $params['timeout'] = (int) ($params['timeout'] * 1000000 - (microtime(true) - $time));
        usleep($params['timeout'] > 0 ? $params['timeout'] : 0);
        if (empty($this->updates)) {
            return [];
        }
        if ($params['offset'] < 0) {
            $params['offset'] = array_reverse(array_keys((array) $this->updates))[abs($params['offset']) - 1];
        }
        $updates = [];
        $supdates = (array) $this->updates;
        ksort($supdates);
        foreach ($supdates as $key => $value) {
            if ($params['offset'] > $key) {
                unset($this->updates[$key]);
            } elseif ($params['limit'] === null || count($updates) < $params['limit']) {
                $updates[] = ['update_id' => $key, 'update' => $value];
            }
        }
        return $updates;
    }
}
