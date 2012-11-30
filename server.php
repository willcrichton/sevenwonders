#!/php -q
<?php

// Set date to avoid errors
date_default_timezone_set("America/New_York");

function packet($args, $type){
        $args = is_array($args) ? $args : array('data' => $args);
        $args['messageType'] = $type;
        return json_encode($args);
}

// Run from command prompt > php demo.php
require_once("includes/websocket.server.php");
require_once("wonders.php");

// Main server class
class WonderServer implements IWebSocketServerObserver{
        protected $debug = true;
        protected $server;
        protected $users = array();
        protected $games = array();

        public function __construct(){
                $this->server = new WebSocketServer('tcp://0.0.0.0:12345', 'superdupersecretkey');
                $this->server->addObserver($this);
                //$this->server->addUriHandler("", new GameHandler());
        }

        public function onConnect(IWebSocketConnection $user){
                $this->users[$user->getId()] = $user;
                $user->sendString(packet("Guest {$user->getId()}", "myname"));
                foreach($this->games as $game) 
                        if(!$game->started)
                                $user->sendString(packet(array('name' => $game->name, 'creator' => $game->creator->name, 'id' => $game->id), "newgame"));

                $this->say("{$user->getId()} connected");
        }

        public function broadcastAll($msg, $exclude=false){
                foreach($this->users as $u) 
                        if(!$exclude || $u != $exclude) $u->sendString($msg);
        }

        public function broadcastTo($msg, $players, $exclude=false){
                foreach($players as $player)
                        if(!$exclude || $player != $exclude) $player->sendString($msg);
        }

        public function onMessage(IWebSocketConnection $user, IWebSocketMessage $msg){
                $arr = $msg->getData();
                $arr = json_decode($arr, true);
                switch($arr['messageType']){
                        case 'newgame':
                                $game = new SevenWonders();
                                $game->name = $arr['name'];
                                $game->maxplayers = intval($arr['players']);
                                $game->id = count($this->games);
                                $game->server = $this;
                                $game->addPlayer($user);

                                // ERRORS NOT SHOWING ON CLIENT: FIX FIX FIX
                                if($game->maxplayers > 7 or $game->maxplayers < 1)
                                        return $user->sendString(packet('Cannot create game: number of players invalid', 'error'));
                                elseif($game->name == '') 
                                        return $user->sendString(packet('Game needs a valid name', 'error'));
                               
                                $this->games[] = $game;
                                $packet = packet(array('name' => $game->name, 'creator' => $game->creator->name, 'id' => $game->id), "newgame");
                                $this->broadcastAll($packet, $user);
                        break;

                        case 'joingame':
                                if(!isset($user->game)){
                                        $id = intval($arr['id']);
                                        if(isset($this->games[$id]) && !$this->games[$id]->started){
                                                $this->games[$id]->addPlayer($user);
                                        } else {
                                                // error game not exist/game already started
                                        }
                                } else {
                                        // error already in game
                                }
                        break;

                        case 'changename':
                                if(!isset($user->game)){
                                        $user->name = $arr['name'] == '' ? 'Minge Baggerson' : $arr['name'];
                                }
                                // Broadcast name change here in case they're hosting a game?
                        break;

                        default:
                                if(isset($user->game)) $user->game->onMessage($user, $arr);
                                else $user->sendString("Error: could not recognize command " . $arr['messageType']);
                        break;
                }
        }

        public function onDisconnect(IWebSocketConnection $user){
                $this->say("{$user->getId()} disconnected");
                foreach($this->games as $game)
                        if(in_array($user, $game->players))
                                $game->removePlayer($user);      
                unset($this->users[$user->getId()]);
        }

        public function onAdminMessage(IWebSocketConnection $user, IWebSocketMessage $msg){
                $this->say("Admin Message received!");

                $frame = WebSocketFrame::create(WebSocketOpcode::PongFrame);
                $user->sendFrame($frame);
        }

        public function say($msg){
                echo "Log: $msg \r\n";
        }

        public function run(){
                $this->server->run();
        }
}

// Start server
$server = new WonderServer();
$server->run();
?>