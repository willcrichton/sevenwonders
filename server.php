#!/php -q
<?php

// Set date to avoid errors
date_default_timezone_set("America/New_York");

function gentoken() {
    $chars = "abcdefghijklmnopqrstuvwxyz1234567890";
    $string = "";
    $nchars = strlen($chars);
    for ($i = 0; $i < 30; $i++)
        $string .= $chars[mt_rand(0, $nchars - 1)];
    return $string;
}

// Run from command prompt > php demo.php
require_once("includes/websocket.server.php");
require_once("wonders.php");
require_once("player.php");

// Main server class
class WonderServer implements IWebSocketServerObserver{
    protected $debug = true;
    protected $server;
    protected $users = array(); // all users ever (keyed by $user->id)
    protected $conns = array(); // all active connections (keyed by $conn->id)
    protected $games = array();

    public function __construct(){
        $this->server = new WebSocketServer('tcp://0.0.0.0:12345', 'superdupersecretkey');
        $this->server->addObserver($this);
    }

    public function onConnect(IWebSocketConnection $user){
    }

    public function broadcast($type, $msg, $exclude=null){
        foreach($this->conns as $u)
            if($u != $exclude)
                $u->send($type, $msg);
    }

    public function onMessage(IWebSocketConnection $conn, IWebSocketMessage $msg){
        $arr = json_decode($msg->getData(), true);
        // If this is a new websocket connection, handle the user up front
        if ($arr['messageType'] == 'myid') {
            if (isset($this->users[$arr['id']])) {
                $user = $this->users[$arr['id']];
            } else {
                $user = new Player(gentoken(), $conn->getId());
            }
            $this->users[$user->id()] = $user;
            $this->conns[$conn->getId()] = $user;
            $user->setConnection($conn);
            $user->send('myname',
                        array('name' => $user->name(),
                              'id'   => $user->id()));

            if ($user->game() != null) {
                if ($user->game()->started)
                    $user->rejoinGame();
                else
                    $user->rejoinWaitingRoom();
            } else {
                foreach($this->games as $game) {
                    if ($game->started)
                        continue;
                    $user->send('newgame',
                                array('name' => $game->name,
                                      'creator' => $game->creator->name(),
                                      'id' => $game->id));
                }
            }

            $this->say("{$user->id()} connected");
            return;
        }

        // Otherwise we better have a user set for them, and then continue on
        // as normally when processing the message
        if (!isset($this->conns[$conn->getId()]))
            return;
        $user = $this->conns[$conn->getId()];

        switch($arr['messageType']){
            case 'newgame':
                if ($user->game() != null)
                    return;
                // ERRORS NOT SHOWING ON CLIENT: FIX FIX FIX
                if($arr['name'] == '')
                    return $user->send('error', 'Game needs a valid name');

                $game = new SevenWonders();
                $game->maxplayers = intval($arr['players']);
                $game->name = $arr['name'];
                $game->id = gentoken();
                $game->server = $this;
                $game->addPlayer($user);

                $this->games[$game->id] = $game;

                if ($game->maxplayers > 1)
                    $this->broadcast('newgame',
                                     array('name' => $game->name,
                                           'creator' => $game->creator->name(),
                                           'id' => $game->id), $user);
                break;

            case 'joingame':
                if ($user->game() != null)
                    break;
                $id = $arr['id'];
                if (!isset($this->games[$id]) || $this->games[$id]->started)
                    break;
                $this->games[$id]->addPlayer($user);
                break;

            case 'changename':
                if ($user->game() == null && $arr['name'] != '') {
                    $user->setName($arr['name']);
                }
                // Broadcast name change here in case they're hosting a game?
                break;

            default:
                if($user->game() != null)
                    $user->game()->onMessage($user, $arr);
                else
                    $user->send('error', "Error: could not recognize command " . $arr['messageType']);
                break;
        }
    }

    public function onDisconnect(IWebSocketConnection $conn){
        if (!isset($this->conns[$conn->getId()]))
            return;
        $user = $this->conns[$conn->getId()];
        unset($this->conns[$conn->getId()]);
        $this->say("{$user->id()} disconnected");
    }

    public function onAdminMessage(IWebSocketConnection $conn,
                                   IWebSocketMessage $msg) {
        $this->say("Admin Message received!");

        $frame = WebSocketFrame::create(WebSocketOpcode::PongFrame);
        $conn->sendFrame($frame);
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
