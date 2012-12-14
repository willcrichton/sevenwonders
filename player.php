<?php

require_once('scoring.php');

class Player {
    const TRASHING  = 'trashing';
    const BUYING    = 'buying';
    const BUILDING  = 'building';
    const USINGFREE = 'usingfree';

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
    public $leftPlayer;
    public $rightPlayer;
    public $discounts;
    public $state;              // one of the constants above (or unset)

    public $canHaveFreeCard;    // state for olympia's free card
    public $hasFreeCard;

    public $canPlayTwoBuilt;    // state for babylon's play2 stage
    public $secondPending;      // second card being played
    public $secondState;        // what's happening to the second card
    public $secondCost;         // pending cost of the second card

    // Figuring out card costs
    private $possibilities;

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
        $this->wonderStage = 0;
        $this->leftPlayer = null;
        $this->rightPlayer = null;
        $this->discounts = array('left' => array(), 'right' => array());
        $this->canHaveFreeCard = false;
        $this->hasFreeCard = false;
        $this->canPlayTwoBuilt = false;
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
        // called once per turn, reset calculated card possibilities
        $this->possibilities = array();
        if(isset($this->hand)){
            $info = array_map(function($c) { return $c->json(); }, $this->hand);
            $this->send('hand',
                       array('age' => $this->_game->age, 'cards' => $info));
        }
    }

    public function sendStartInfo($playerInfo, $isRejoin = false) {
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
            'played' => array_map($tojson, $this->cardsPlayed),
            'rejoin' => $isRejoin
        );
        $this->send("startinfo", $startInfo);
        if ($this->hasFreeCard)
            $this->getFreeCard();
        if ($this->canPlayTwoBuilt)
            $this->send('canplay2', '');
    }

    public function rejoinGame() {
        $this->sendStartInfo($this->_game->playerInfo, true);
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
        $this->possibilities[$card->getName()] =
            array('combs' => $possibilities, 'type' => $type);

        // Send off everything we just found
        $this->send('possibilities', array('combs' => $possibilities));
    }

    private function calculateCost(WonderCard $card, $type) {
        if ($type == 'play') {
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

    public function cardCost(WonderCard $card, $selection, $type){
        // Make sure we've pre-calculated the cost of this card and that the
        // specified selection is in bounds
        if (!isset($this->possibilities[$card->getName()]))
            return false;
        $arr = $this->possibilities[$card->getName()];
        if (!isset($arr['combs'][$selection]) || $arr['type'] != $type)
            return false;

        unset($this->possibilities[$card->getName()]);
        return $arr['combs'][$selection];
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
            case '1free':    // olympia's 1 free card per age
                $this->getFreeCard();
                break;
            case 'guild':    // olympia's steal a guild at the end of the game
                break;
            case 'discard':  // halikarnassus's play from the discard pile
                break;
            case 'play2':    // babylon's play both cards at the end of a hand
                $this->canPlayTwoBuilt = true;
                $this->send('canplay2', '');
                break;

            case 'discount': // olympia's discount COWS for both L/R
                $resource = new Resource(false, false);
                $resource->add(Resource::CLAY);
                $resource->add(Resource::ORE);
                $resource->add(Resource::WOOD);
                $resource->add(Resource::STONE);
                $this->addDiscount('left', $resource);
                $this->addDiscount('right', $resource);
                break;
        }
    }

    public function getFreeCard() {
        $this->canHaveFreeCard = true;
        $this->hasFreeCard = true;
        $this->send('freecard', array('hasfree' => true));
    }

    public function useFreeCard() {
        $this->hasFreeCard = false;
        $this->send('freecard', array('hasfree' => false));
    }

    public function isPlayingCard() {
        return $this->state == self::BUYING || $this->state == self::USINGFREE;
    }

    public function playCard(SevenWonders $game, WonderCard $card, $state,
                             $costarr) {
        switch ($state) {
            case Player::TRASHING:
                $this->addCoins(3);
                $game->discard[] = $card;
                break;

            case Player::BUYING:
                $card->play($this);
                $this->cardsPlayed[] = $card;
                $this->addCoins(-1 * $card->getMoneyCost());
                break;

            case Player::BUILDING:
                $this->playWonderStage();
                break;

            case Player::USINGFREE:
                $this->useFreeCard();
                $card->play($this);
                $this->cardsPlayed[] = $card;
                break;

            default:
                throw new Error("unimplemented play state");
        }

        unset($this->hand[array_search($card, $this->hand)]);

        // Consume money cost for this this and pay adjacent thiss
        foreach ($costarr as $dir => $cost) {
            if ($dir == 'left')
                $this->leftPlayer->addCoins($cost);
            else if ($dir == 'right')
                $this->rightPlayer->addCoins($cost);
            $this->addCoins(-1 * $cost);
        }
    }

    public function canPlayTwo() {
        // With babylon's play2 wonder stage, you've either built it in the past
        // so you can play two, or you are building it currently, enabling
        // yourself to play two cards.
        return $this->canPlayTwoBuilt ||
              (isset($this->state) &&
               $this->state == Player::BUILDING &&
               isset($this->wonder['stages'][$this->wonderStage]['custom']) &&
               $this->wonder['stages'][$this->wonderStage]['custom'] == 'play2');
    }
}
