<?php

require_once('scoring.php');

class Player {
    // General player state
    private $_id;
    private $_name;
    private $_conn;
    private $_game;

    // Game state
    public $coins;
    public $permResources;
    public $tempResources;
    public $wonder;
    public $order;
    public $hand;
    public $selectedCard;
    public $cardsPlayed;
    public $military;
    public $militaryPoints;
    public $points;
    public $science;
    public $isTrashing;
    public $isBuildWonder;
    public $leftPlayer;
    public $rightPlayer;
    public $discounts;

    public function __construct($id, $unique) {
        $this->_name = "Guest $unique";
        $this->_id = $id;
    }

    public function id() {
        return $this->_id;
    }

    public function name() {
        return $this->_name;
    }

    public function setName($name) {
        $this->_name = $name;
    }

    public function info() {
        return "User {$this->name()} ({$this->id()})";
    }

    public function setGame($game) {
        if ($this->_game != null)
            throw new Exception("already in a game");
        $this->_game = $game;
        $this->coins = 0;
        $this->permResources = array();
        $this->tempResources = array();
        $this->wonder = null;
        $this->order = -1;
        $this->hand = null;
        $this->selectedCard = null;
        $this->cardsPlayed = array();
        $this->points = 0;
        $this->military = new Military();
        $this->science = new Science();
        $this->isTrashing = false;
        $this->isBuildWonder = false;
        $this->leftPlayer = null;
        $this->rightPlayer = null;
        $this->discounts = array('left' => array(), 'right' => array());
    }

    public function game() {
        return $this->_game;
    }

    public function setConnection(IWebSocketConnection $conn) {
        $this->conn = $conn;
    }

    public function send($type, $msg) {
        if (!isset($this->conn))
            return;
        $msg = is_array($msg) ? $msg : array('data' => $msg);
        $msg['messageType'] = $type;
        $this->conn->sendString(json_encode($msg));
    }

    public function canPlayCard(WonderCard $card){
        // check for duplicates
        foreach ($this->cardsPlayed as $cardPlayed) {
            if ($cardPlayed->getName() == $card->getName())
                return "You've already played this card";
        }

        // check if it's a prerequisite for being free
        foreach ($this->cardsPlayed as $cardPlayed)
            if ($cardPlayed->getName() == $card->getPrereq())
                return '';

        // check if player has enough money
        if ($card->getMoneyCost() > $this->coins)
            return "You don't have enough coins";

        // check if player has necessary resources
        $cost = $card->getResourceCost();
        $availableResources = array_merge($this->permResources, $this->tempResources);
        if (!Resource::satisfiable($cost, $availableResources))
            return "You don't have enough resources";

        return '';
    }

    public function canSellResource($resource){
        if(!isset($this->permResources[$resource])) return false;
        return isset($this->permResources[$resource]['buy']);
    }

    public function sendError($error){
        $this->send('error', $error);
    }

    public function addCoins($coins){
        $this->coins += $coins;
        $this->send('coins', $this->coins);
    }

    public function addResource(Resource $resource, $buyable = true) {
        $this->permResources[] = $resource;
        $this->send('resources', array('resources' => $this->permResources));
    }

    public function evaluateMilitary($age){
        $this->military->fight($this->leftPlayer->military, $age);
        $this->military->fight($this->rightPlayer->military, $age);
        $this->send('military', $this->military->json());
    }

    public function calcPoints(){
        $total = $this->points;                 // blue cards
        $total += floor($this->coins / 3);      // coins
        $total += $this->military->points();    // military
        $total += $this->science->points();     // science

        // 3rd age yellow cards + guild cards (others all return 0)
        foreach($this->cardsPlayed as $card)
            $total += $card->points($this);

        return $total;
    }

    public function addDiscount($dir, Resource $res) {
        $this->discounts[$dir][] = $res;
    }

    public function neighbor($dir) {
        if ($dir == 'left')
            return $this->leftPlayer;
        else if ($dir == 'right')
            return $this->rightPlayer;
        return $this; // 'self'
    }

    public function sendHand() {
        $info = array_map(function($c) { return $c->json(); }, $this->hand);
        $this->send('hand',
                    array('age' => $this->_game->age, 'cards' => $info));
    }

    public function sendStartInfo($playerInfo) {
        $startInfo = array(
            "coins" => $this->coins,
            "wonder" => $this->wonder["name"],
            "plinfo" => $playerInfo,
            "resource" => $this->wonder['resource'],
            "neighbors" => array('left' => $this->leftPlayer->id(),
                                 'right' => $this->rightPlayer->id())
        );
        $this->send("startinfo", $startInfo);
    }

    public function rejoinGame() {
        $this->sendStartInfo($this->_game->playerInfo);
    }

}
