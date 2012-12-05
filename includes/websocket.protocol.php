<?php

class WebSocketConnectionFactory {
    public static function fromSocketData(WebSocketSocket $socket, $data) {
        $headers = WebSocketFunctions::parseHeaders($data);

        if (isset($headers['Sec-Websocket-Key1'])) {
            $s = new WebSocketConnectionHixie($socket, $headers, $data);
            $s->sendHandshakeResponse();
        } else if (strpos($data, '<policy-file-request/>') === 0) {
            $s = new WebSocketConnectionFlash($socket, $data);
        } else {
            $s = new WebSocketConnectionHybi($socket, $headers);
            $s->sendHandshakeResponse();
        }



        return $s;
    }

}

interface IWebSocketConnection {
    public function sendHandshakeResponse();

    public function readFrame($data);
    public function sendFrame(IWebSocketFrame $frame);
    public function sendMessage(IWebSocketMessage $msg);
    public function sendString($msg);

    public function getHeaders();
    public function getUriRequested();
    public function getCookies();

    public function getIp();

    public function disconnect();
}

class Resource {
    public $buyable = true;
    public $resources;
}

abstract class WebSocketConnection implements IWebSocketConnection {
    protected $_headers = array();

    /**
     *
     * @var WebSocketSocket
     */
    protected $_socket = null;
    protected $_cookies = array();
    public $parameters = null;

    /**
     * Hacking in custom stuff.
     * Where's the best place for this? How does PHP OOP WORK??
     */

    public $name;
    public $game;
    public $coins = 0;
    public $permResources = array();
    public $tempResources = array();
    public $wonder;
    public $order;
    public $hand;
    public $selectedCard;
    public $cardsPlayed = array();
    public $military = 0;
    public $militaryPoints = array(0,0,0,0,0,0);
    public $points = 0;
    public $science = array(0, 0, 0, 0);
    public $isTrashing = false;
    public $isBuildWonder = false;
    public $leftPlayer;
    public $rightPlayer;
    public $discounts = array('left' => array(), 'right' => array());

    public function checkResourceCost($cost, $resources){
        $allZero = true;
        foreach($cost as $resource => $amount){
            if($amount > 0){
                $allZero = false;
                break;
            }
        }
        if($allZero) return true;
        if(count($resources) == 0) return false;

        $resource = array_pop($resources);
        if(is_array($resource->resources)){
            foreach($resource->resources as $possibility){
                if(!isset($cost[$possibility])) continue;
                $cost[$possibility]--;
                if($this->checkResourceCost($cost, $resources)) return true;
                $cost[$possibility]++;
            }
            return $this->checkResourceCost($cost, $resources);
        } else {
            $possibility = $resource->resources;
            if(isset($cost[$possibility])){
                $cost[$resource->resources]--;
                if($this->checkResourceCost($cost, $resources)) return true;
                $cost[$possibility]++;
            } else {
                return $this->checkResourceCost($cost, $resources);
            }
        }

        return false;
    }

    public function canPlayCard(WonderCard $card, $sendError = false){
        // check for duplicates
        foreach($this->cardsPlayed as $cardPlayed){
            if($cardPlayed->getName() == $card->getName()){
                if($sendError) $this->sendError("You've already played this card");
                return false;
            }
        }

        // check if it's a prerequisite for being free
        foreach($this->cardsPlayed as $cardPlayed)
            if($cardPlayed->getName() == $card->getPrereq()) return true;

        // check if player has enough money
        if($card->getMoneyCost() > $this->coins){
            if($sendError) $this->sendError("You don't have enough coins to play this card");
            return false;
        }

        // check if player has necessary resources
        $cost = $card->getResourceCost();
        $availableResources = array_merge($this->permResources, $this->tempResources);
        if(!$this->checkResourceCost($cost, $availableResources)){
            if($sendError) $this->sendError("You don't have enough resources to play this card");
            return false;
        }

        return true;
    }

    public function canSellResource($resource){
        if(!isset($this->permResources[$resource])) return false;
        return isset($this->permResources[$resource]['buy']);
    }

    public function sendError($error){
        $packet = packet($error, 'error');
        $this->sendString($packet);
    }

    public function addCoins($coins){
        $this->coins += $coins;
        $this->sendString(packet($this->coins, 'coins'));
    }

    public function addResource($resources, $amount, $buyable = true){
        for($i = 0; $i < $amount; $i++){
            $resource = new Resource();
            $resource->buyable = $buyable;
            $resource->resources = $resources;
            $this->permResources[] = $resource;
            $this->sendString(packet(array('resources' => $this->permResources), 'resources'));
        }
    }

    public function addScience($element){
        if(!isset($this->science[$element]))
            $this->science[$element] = 1;
        else
            $this->science[$element]++;
    }

    public function evaluateMilitary($age){
        if($this->leftPlayer->military < $this->military){
            $this->militaryPoints[$age * 2 - 1] += 1;
        } elseif($this->leftPlayer->military > $this->military){
            $this->militaryPoints[0] += 1;
        }

        if($this->rightPlayer->military < $this->military){
            $this->militaryPoints[$age * 2 - 1] += 1;
        } elseif($this->rightPlayer->military > $this->military){
            $this->militaryPoints[0] += 1;
        }

        $this->sendString(packet($this->militaryPoints, 'military'));
    }

    public function calcScience($science, $wildcards){
        if($wildcards > 0){
            $possibles = array();
            for($i = 1; $i <= 3; $i++){
                $science[$i - 1]--; $science[$i]++;
                array_push($possibles, $this->calcScience($science, $wildcards - 1));
            }
            return max($possibles);
        } else {
            $total = 0;
            for($i = 1; $i <= 3; $i++)
                $total += pow($science[$i], 2);
            if($total == 0) return 0;
            $total += min(array_slice($science, 1)) * 7;
            return $total;
        }
    }

    public function calcPoints(){
        // blue cards
        $total = $this->points;

        // coins
        $total += floor($this->coins / 3);

        // military
        foreach($this->militaryPoints as $mult => $tokens)
            $total += ($mult == 0 ? -1 : $mult) * $tokens;

        // science
        $total += $this->calcScience($this->science, $this->science[0]);

        // 3rd age yellow cards + guild cards
        foreach($this->cardsPlayed as $card){
            if($card->getAge() == 3){
                if($card->getColor() == 'yellow'){
                    preg_match('/\((.)\)\{(.)\} (.+)?/', $card->getCommand(), $matches);
                    $mult = $matches[2]; $color = $matches[3];
                    $sum = 0;
                    if($color == 'wonder'){
                        // check for wonders here
                    } else {
                        foreach($this->cardsPlayed as $c){
                            if($c->getColor() == $color) $sum++;
                        }
                    }
                    $total += intval($mult) * $sum;
                } elseif($card->getColor() == 'purple' && $card->getName() != "Scientists Guild"){
                    $args = explode(' ', $card->getCommand());
                    $directions = arrowsToDirection($args[0]);
                    $color = $args[1];
                    $mult = intval($args[2]);
                    /************* THIS NEEDS TO BE TESTED ****************/
                    foreach($directions as $dir){
                        $pl = $dir == 'left' ? $this->leftPlayer : ($dir == 'right' ? $this->rightPlayer : $this);
                        switch($color){
                            case '-1':
                                $total += $mult * (isset($pl->militaryPoints[0]) ? $pl->militaryPoints[0] : 0);
                                break;
                            case 'wonder':
                                // check for wonder here
                                break;
                            case 'brown,grey,blue':
                                foreach(explode(',', $color) as $subcolor){
                                    foreach($pl->cardsPlayed as $c){
                                        if($c->getColor() == $subcolor) $total += $mult;
                                    }
                                }
                                break;
                            default:
                                foreach($pl->cardsPlayed as $c){
                                    if($c->getColor() == $color) $total += $mult;
                                }
                                break;
                        }
                    }
                }
            }
        }

        return $total;
    }

    public function __construct(WebSocketSocket $socket, array $headers) {
        $this->setHeaders($headers);
        $this->_socket = $socket;
        $this->name = "Guest " . $this->getId();
    }

    public function getIp(){
        return stream_socket_get_name($this->_socket->getResource(), true);
    }

    public function getId() {
        return (int)$this->_socket->getResource();
    }

    public function sendFrame(IWebSocketFrame $frame) {
        if ($this->_socket->write($frame->encode()) === false)
            return FALSE;
    }

    public function sendMessage(IWebSocketMessage $msg) {
        foreach ($msg->getFrames() as $frame) {
            if ($this->sendFrame($frame) === false)
                return FALSE;
        }

        return TRUE;
    }

    public function getHeaders() {
        return $this->_headers;
    }

    public function setHeaders($headers) {
        $this->_headers = $headers;

        if (array_key_exists('Cookie', $this->_headers) && is_array($this->_headers['Cookie'])) {
            $this->cookie = array();
        } else {
            if (array_key_exists("Cookie", $this->_headers)) {
                $this->_cookies = WebSocketFunctions::cookie_parse($this->_headers['Cookie']);
            } else
                $this->_cookies = array();
        }

        $this->getQueryParts();
    }

    public function getCookies() {
        return $this->_cookies;
    }

    public function getUriRequested() {
        return $this->_headers['GET'];
    }

    protected function getQueryParts() {
        $url = $this->getUriRequested();

        if (($pos = strpos($url, "?")) == -1) {
            $this->parameters = array();
        }

        $q = substr($url, strpos($url, "?") + 1);

        $kvpairs = explode("&", $q);
        $this->parameters = array();

        foreach ($kvpairs as $kv) {
            if (strpos($kv, "=") == -1)
                continue;

            @list($k, $v) = explode("=", $kv);

            $this->parameters[urldecode($k)] = urldecode($v);
        }

    }

    public function getAdminKey() {
        return isset($this->_headers['Admin-Key']) ? $this->_headers['Admin-Key'] : null;
    }

    public function getSocket() {
        return $this->_socket;
    }

}

class WebSocketConnectionFlash {
    public function __construct($socket, $data) {
        $this->_socket = $socket;
        $this->_socket->onFlashXMLRequest($this);
    }

    public function sendString($msg) {
        $this->_socket->write($msg);
    }

    public function disconnect() {
        $this->_socket->disconnect();
    }

}

class WebSocketConnectionHybi extends WebSocketConnection {
    private $_openMessage = null;
    private $lastFrame = null;

    public function sendHandshakeResponse() {
        // Check for newer handshake
        $challenge = isset($this->_headers['Sec-Websocket-Key']) ? $this->_headers['Sec-Websocket-Key'] : null;

        // Build response
        $response = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n" . "Upgrade: WebSocket\r\n" . "Connection: Upgrade\r\n";

        // Build HYBI response
        $response .= "Sec-WebSocket-Accept: " . WebSocketFunctions::calcHybiResponse($challenge) . "\r\n\r\n";

        $this->_socket->write($response);

        WebSocketFunctions::say("HYBI Response SENT!");
    }

    public function readFrame($data) {
        $frames = array();
        while (!empty($data)) {
            $frame = WebSocketFrame::decode($data, $this->lastFrame);
            if ($frame->isReady()) {

                if (WebSocketOpcode::isControlFrame($frame->getType()))
                    $this->processControlFrame($frame);
                else
                    $this->processMessageFrame($frame);

                $this->lastFrame = null;
            } else {
                $this->lastFrame = $frame;
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Process a Message Frame
     *
     * Appends or creates a new message and attaches it to the user sending it.
     *
     * When the last frame of a message is received, the message is sent for processing to the
     * abstract WebSocket::onMessage() method.
     *
     * @param IWebSocketUser $user
     * @param WebSocketFrame $frame
     */
    protected function processMessageFrame(WebSocketFrame $frame) {
        if ($this->_openMessage && $this->_openMessage->isFinalised() == false) {
            $this->_openMessage->takeFrame($frame);
        } else {
            $this->_openMessage = WebSocketMessage::fromFrame($frame);
        }

        if ($this->_openMessage && $this->_openMessage->isFinalised()) {
            $this->_socket->onMessage($this->_openMessage);
            $this->_openMessage = null;
        }
    }

    /**
     * Handle incoming control frames
     *
     * Sends Pong on Ping and closes the connection after a Close request.
     *
     * @param IWebSocketUser $user
     * @param WebSocketFrame $frame
     */
    protected function processControlFrame(WebSocketFrame $frame) {
        switch($frame->getType()) {
            case WebSocketOpcode::CloseFrame :
                $frame = WebSocketFrame::create(WebSocketOpcode::CloseFrame);
                $this->sendFrame($frame);

                $this->_socket->disconnect();
                break;
            case WebSocketOpcode::PingFrame :
                $frame = WebSocketFrame::create(WebSocketOpcode::PongFrame);
                $this->sendFrame($frame);
                break;
        }
    }

    public function sendString($msg) {
        try {
            $m = WebSocketMessage::create($msg);

            return $this->sendMessage($m);
        } catch(Exception $e) {
            $this->disconnect();
        }
    }

    public function disconnect() {
        $f = WebSocketFrame::create(WebSocketOpcode::CloseFrame);
        $this->sendFrame($f);

        $this->_socket->disconnect();
    }

}

class WebSocketConnectionHixie extends WebSocketConnection {
    private $_clientHandshake;

    public function __construct(WebSocketSocket $socket, array $headers, $clientHandshake) {
        $this->_clientHandshake = $clientHandshake;
        parent::__construct($socket, $headers);
    }

    public function sendHandshakeResponse() {
        // Last 8 bytes of the client's handshake are used for key calculation later
        $l8b = substr($this->_clientHandshake, -8);

        // Check for 2-key based handshake (Hixie protocol draft)
        $key1 = isset($this->_headers['Sec-Websocket-Key1']) ? $this->_headers['Sec-Websocket-Key1'] : null;
        $key2 = isset($this->_headers['Sec-Websocket-Key2']) ? $this->_headers['Sec-Websocket-Key2'] : null;

        // Origin checking (TODO)
        $origin = isset($this->_headers['Origin']) ? $this->_headers['Origin'] : null;
        $host = $this->_headers['Host'];
        $location = $this->_headers['GET'];

        // Build response
        $response = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n" . "Upgrade: WebSocket\r\n" . "Connection: Upgrade\r\n";

        // Build HIXIE response
        $response .= "Sec-WebSocket-Origin: $origin\r\n" . "Sec-WebSocket-Location: ws://{$host}$location\r\n";
        $response .= "\r\n" . WebSocketFunctions::calcHixieResponse($key1, $key2, $l8b);

        $this->_socket->write($response);
        echo "HIXIE Response SENT!";
    }

    public function readFrame($data) {
        $f = WebSocketFrame76::decode($data);
        $m = WebSocketMessage76::fromFrame($f);

        $this->_socket->onMessage($m);

        return array($f);
    }

    public function sendString($msg) {
        $m = WebSocketMessage76::create($msg);

        return $this->sendMessage($m);
    }

    public function disconnect() {
        $this->_socket->disconnect();
    }

}
