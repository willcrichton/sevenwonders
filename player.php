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
    public $wonder;
    public $wonderName;
    public $wonderStage;
    public $wonderSide;
    public $order;
    public $hand;
    public $selectedCard;
    public $pendingCost;        // cost of $selectedCard
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

    // Figuring out card costs
    private $_lastCostCard;
    private $_lastCost;

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
        $this->wonderStage = 0;
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

    public function canSellResource($resource){
        if(!isset($this->permResources[$resource])) return false;
        return isset($this->permResources[$resource]['buy']);
    }

    public function sendError($error){
        $this->send('error', $error);
    }

    public function addCoins($coins){
        if ($coins == 0)
            return;
        $this->coins += $coins;
        $this->send('coins', $this->coins);
    }

    public function addResource(Resource $resource) {
        $this->permResources[] = $resource;
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
        if(isset($this->hand)){
            $info = array_map(function($c) { return $c->json(); }, $this->hand);
            $this->send('hand',
                       array('age' => $this->_game->age, 'cards' => $info));
        }
    }

    public function sendStartInfo($playerInfo) {
        $tojson = function($a) { return $a->json(); };
        $wonderInfo = array("name" => $this->wonderName,
                            "stage" => $this->wonderStage);
        if (isset($this->wonder['resource']))
            $wonderInfo['resource'] = $this->wonder['resource']->json();
        $startInfo = array(
            "coins" => $this->coins,
            "wonder" => $wonderInfo,
            "wonderside" => $this->wonderSide,
            "plinfo" => $playerInfo,
            "military" => $this->military->json(),
            "neighbors" => array('left' => $this->leftPlayer->id(),
                                 'right' => $this->rightPlayer->id()),
            'leftcards' => array_map($tojson, $this->leftPlayer->cardsPlayed),
            'rightcards' => array_map($tojson, $this->rightPlayer->cardsPlayed),
            'played' => array_map($tojson, $this->cardsPlayed)
        );
        $this->send("startinfo", $startInfo);
    }

    public function rejoinGame() {
        $this->sendStartInfo($this->_game->playerInfo);
        $this->sendHand();
    }

    public function rejoinWaitingRoom() {
        $players = array();
        foreach ($this->game()->players as $player) {
            if ($player != $this)
                $players[] = $player->name();
        }
        $this->send('joingame', array('players' => $players));
    }

    public function findCost(WonderCard $card, $type) {
        $possibilities = $this->calculateCost($card, $type);

        // Save off what we just calculated so we can verify a cost strategy
        // when one is provided when playing the card
        $this->_lastCost = $possibilities;
        $this->_lastCostCard = $card;

        // Send off everything we just found
        $this->send('possibilities', array('combs' => $possibilities));
    }

    private function calculateCost(WonderCard $card, $type) {
        if ($type == 'card') {
            // check for duplicates
            foreach ($this->cardsPlayed as $cardPlayed)
                if ($cardPlayed->getName() == $card->getName())
                    return array();

            // check if it's a prerequisite for being free
            foreach ($this->cardsPlayed as $cardPlayed)
                if ($card->hasPrereq($cardPlayed))
                    return array(array());

            $required = $card->getResourceCost();
        } else { // $type == 'wonder'
            // Can't over-build the wonder
            if ($this->wonderStage == count($this->wonder['stages']))
                return array();
            $stage = $this->wonder['stages'][$this->wonderStage];
            $required = $stage['requirements'];
        }

        // Otherwise, we're going to have to pay for this card somehow
        $have = array();

        // We get all our resources for free
        foreach ($this->permResources as $resource)
            $have[] = ResourceOption::me($resource);
        // Add in all the left player's resources, factoring in discounts
        foreach ($this->leftPlayer->permResources as $resource) {
            if (!$resource->buyable())
                continue;
            $have[] = ResourceOption::left($resource,
                            $resource->discount($this->discounts['left']));
        }
        // Add in all the right player's resources, factoring discounts
        foreach ($this->rightPlayer->permResources as $resource) {
            if (!$resource->buyable())
                continue;
            $have[] = ResourceOption::right($resource,
                            $resource->discount($this->discounts['right']));
        }

        // Figure out how we can pay neighbors to satisfy our requirements
        $possible = Resource::satisfy($required, $have,
                                      $this->coins - $card->getMoneyCost());
        return $possible;
    }

    public function cardCost(WonderCard $card, $selection){
        // Make sure we've pre-calculated the cost of this card and that the
        // specified selection is in bounds
        if (!isset($this->_lastCost) || !isset($this->_lastCost[$selection]) ||
            $this->_lastCostCard->getName() != $card->getName())
            return false;

        $ret = $this->_lastCost[$selection];
        unset($this->_lastCost);
        unset($this->_lastCostCard);
        return $ret;
    }

    public function playWonderStage() {
        $stage = $this->wonder['stages'][$this->wonderStage];
        $this->wonderStage++;
        // Do all the easy things first
        if (isset($stage['military']))
            $this->military->add($stage['military']);
        if (isset($stage['coins']))
            $this->addCoins($stage['coins']);
        if (isset($stage['points']))
            $this->points += $stage['points'];
        if (isset($stage['science']))
            $this->science->add(Science::ANY); // only babylon for now
        if (isset($stage['resource']))
            $this->addResource($stage['resource']);
        if (!isset($stage['custom']))
            return;

        switch ($stage['custom']) {
            case '1free':   // olympia's 1 free card per age
                break;
            case 'guild':   // olympia's steal a guild at the end of the game
                break;
            case 'discard': // halikarnassus's play from the discard pile
                break;
            case 'play2':   // babylon's play both cards at the end of a hand
                break;
        }
    }
}
