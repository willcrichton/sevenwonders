<?php

require_once('scoring.php');

function arrowsToDirection($str){
    $directions = array();
    for($i = 0; $i < strlen($str); $i++){
        $directions[] = $str[$i] == '<' ? 'left' : ($str[$i] == '>' ? 'right' : 'self');
    }
    return $directions;
}

class Card {
    const BLUE = 'blue';
    const GREEN = 'green';
    const YELLOW = 'yellow';
    const PURPLE = 'purple';
    const GREY = 'grey';
    const RED = 'red';
    const BROWN = 'brown';

    private $name;
    private $color;
    private $moneyCost;
    private $resourceCost;
    private $age;
    private $numPlayers;
    private $command;
    private $playFunc;
    private $freePrereq;

    static function csvNumPlayers($fields){
        // All guilds only have one copy and have a special way of being added
        // to the deck, so we don't deal with that here
        if ($fields[2] == Card::PURPLE)
            return array(1);

        // Otherwise columns 7-11 imply that with N-4 people, there should be K
        // copies of this card. The array returned has elements meaning that
        // for that number of people, another card of this type should be added.
        $numplayers = array();
        $last = 0;
        for($i = 7; $i <= 11; $i++){
            $num = intval($fields[$i]);
            if($num > $last){
                $numplayers[] = $i - 4;
                $last = $num;
            }
        }
        return $numplayers;
    }

    public static function csvResources($str, $buyable){
        $resources = array(
            'S' => Resource::STONE,
            'T' => Resource::WOOD,
            'W' => Resource::WOOD,
            'O' => Resource::ORE,
            'C' => Resource::CLAY,
            'L' => Resource::LINEN,
            'G' => Resource::GLASS,
            'P' => Resource::PAPER
        );
        $cost = array();
        if (strpos($str, '/') !== false){
            $resource = new Resource(true, $buyable);
            for($i = 0; $i < strlen($str); $i++){
                if (isset($resources[$str[$i]]))
                    $resource->add($resources[$str[$i]]);
            }
            return array($resource);
        }

        $ret = array();
        for($i = 0; $i < strlen($str); $i++){
            if (isset($resources[$str[$i]])) {
                $res = new Resource(false, $buyable);
                $res->add($resources[$str[$i]]);
                $ret[] = $res;
            }
        }
        return $ret;
    }

    public static function import($age, $csv) {
        $cards = array();
        foreach (Card::csvNumPlayers($csv) as $nplayers) {
            $card = new Card();
            $card->age        = $age;
            $card->name       = $csv[3];
            $card->color      = $csv[2];
            $card->command    = $csv[4];
            $card->freePrereq = $csv[0];

            $card->moneyCost = preg_match('/([0-9])/', $csv[1], $matches) ?
                $matches[1] : 0;
            $card->resourceCost = Card::csvResources($csv[1], true);
            $card->numPlayers = $nplayers;
            if (getenv('DEBUG')) {
                $card->numPlayers = 1; // use all cards in development
            }
			
            $cards[] = $card;
        }

        return $cards;
    }

    function getName(){
        return $this->name;
    }

    function getColor(){
        return $this->color;
    }

    function isGuild(){
        return $this->color == Card::PURPLE;
    }

    function getMoneyCost(){
        return $this->moneyCost;
    }

    function getResourceCost(){
        return $this->resourceCost;
    }

    function getAge(){
        return $this->age;
    }

    function getNumPlayers(){
        return $this->numPlayers;
    }

    function hasPrereq(Card $card) {
        // Stupid forum actually has two prerequisites
        if ($this->name == 'Forum')
            return strstr($card->name, 'Trading Post') !== false;

        return $this->freePrereq == $card->name;
    }

    private function thirdAgeYellowPoints() {
        preg_match('/\((.)\)\{(.)\} (.+)?/', $this->command, $matches);
        return intval($matches[2]);
    }

    private function thirdAgeYellowColor() {
        preg_match('/\((.)\)\{(.)\} (.+)?/', $this->command, $matches);
        return $matches[3];
    }

    private function thirdAgeYellowCoins() {
        preg_match('/\((.)\)\{(.)\} (.+)?/', $this->command, $matches);
        return intval($matches[1]);
    }

    function play(Player $user){
        switch($this->color){
            case Card::RED:
                $user->military->add(intval($this->command));
                break;

            case Card::BLUE:
                // do nothing, calculate points later (see Player::calcPoints)
                break;

            case Card::YELLOW:
                if ($this->getAge() == 1) {
                    if (preg_match('/[0-9]/', $this->command)) {
                        $user->addCoins(intval($this->command));
                    } else {
                        $args = explode(' ', $this->command);
                        $directions = arrowsToDirection($args[0]);
                        $resources = Card::csvResources($args[1], false);
                        foreach ($resources as $resource)
                            foreach ($directions as $dir)
                                $user->addDiscount($dir, $resource);
                    }
                } elseif($this->getAge() == 2) {
                    // check for yellow non-buyable resources
                    if(strpos($this->command, '/') !== false){
                        $res = Card::csvResources($this->command, false);
                        $user->addResource($res[0]);
                    } else {
                        $args = explode(' ', $this->command);
                        $directions = arrowsToDirection($args[0]);
                        $coins = 0;
                        $color = $args[1];
                        $mult = intval($args[2]);
                        foreach($directions as $dir){
                            $pl = $user->neighbor($dir);
                            foreach($pl->cardsPlayed as $c){
                                if($c->getColor() == $color)
                                    $coins += $mult;
                            }
                        }
                        $user->addCoins($coins);
                    }
                } elseif($this->getAge() == 3) {
                    $coins = $this->thirdAgeYellowCoins();
                    $color = $this->thirdAgeYellowColor();
                    // Be sure to count this yellow card for coin increase if
                    // we get coins per yellow card.
                    $coinsToGive = ($color == Card::YELLOW ? $coins : 0);
                    if ($color == 'wonder') {
                        $coinsToGive = $coins * $user->wonderStage;
                    } else {
                        foreach ($user->cardsPlayed as $card) {
                            if($card->getColor() == $color){
                                $coinsToGive += $coins;
                            }
                        }
                    }
                    if ($coinsToGive > 0)
                        $user->addCoins($coinsToGive);
                }
                break;

            case Card::GREEN:
                $user->science->add(intval($this->command));
                break;

            case Card::PURPLE:
                if ($this->getName() == 'Scientists Guild') {
                    $user->science->add(Science::ANY);
                }
                // none of these give coins or anything, so don't need to check
                // until the end
                break;

            case Card::BROWN: case Card::GREY:
                foreach (Card::csvResources($this->command, true) as $r)
                    $user->addResource($r);
                break;
        }
    }

    public function points(Player $player) {
        switch($this->color){
            case Card::YELLOW:
                if($this->age != 3) return 0;
                $mult = $this->thirdAgeYellowPoints();
                $color = $this->thirdAgeYellowColor();
                $sum = 0;
                if($color == 'wonder'){
                    $sum = $player->wonderStage;
                } else {
                    foreach($player->cardsPlayed as $c){
                        if ($c->color == $color)
                            $sum += $mult;
                    }
                }
                return $sum;

            case Card::PURPLE:
                if($this->getName() == "Scientists Guild") return 0;
                $args = explode(' ', $this->command);
                $color = $args[1];
                $mult = intval($args[2]);
                $total = 0;
                /************* THIS NEEDS TO BE TESTED ****************/
                foreach(arrowsToDirection($args[0]) as $dir){
                    $pl = $player->neighbor($dir);
                    switch ($color) {
                        // $mult points for each military loss
                        case '-1':
                            $total += $mult * $pl->military->losses();
                            break;
                        // $mult points for each wonder stage built
                        case 'wonder':
                            $total += $mult * $pl->wonderStage;
                            break;
                        // $mult points for each brown/grey/blue card
                        case 'brown,grey,purple':
                            foreach (explode(',', $color) as $subcolor) {
                                foreach ($pl->cardsPlayed as $c) {
                                    if ($c->getColor() == $subcolor)
                                        $total += $mult;
                                }
                            }
                            break;
                        // $mult cards for each $color card played
                        default:
                            foreach($pl->cardsPlayed as $c){
                                if($c->getColor() == $color)
                                    $total += $mult;
                            }
                            break;
                    }
                }
                return $total;

            case Card::BLUE:
                return intval($this->command);
				}

        return 0;
    }

    public function json() {
        return array(
            'name' => $this->getName(),
            'color' => $this->getColor()
        );
    }
}

class Deck {
    private $cards = array();
    private $guilds = array();
    private $hands = array();

    public function addCards($cards){
        foreach ($cards as $card) {
            if ($card->isGuild()) {
                $this->guilds[] = $card;
            } else {
                $this->cards[] = $card;
            }
        }
    }

    public function deal($age, $players){
        $numplayers = count($players);
        $playableCards = array();

        if ($age == 3) {
            shuffle($this->guilds);
            for ($i = 0; $i < $numplayers + 2; $i++) {
                $playableCards[] = $this->guilds[$i];
            }
        }

        foreach ($this->cards as $card) {
            if ($card->getNumPlayers() <= $numplayers and
                    $card->getAge() == $age)
                $playableCards[] = $card;
        }

        shuffle($playableCards);

        foreach ($players as $player)
            $player->hand = array_splice($playableCards, 0, 7);
    }

    public function import() {
        $this->importAge(1);
        $this->importAge(2);
        $this->importAge(3);
    }

    private function importAge($age) {
        $lines = explode("\r", file_get_contents("cards/age$age.csv"));
        foreach ($lines as $line){
            $this->addCards(Card::import($age, str_getcsv($line)));
        }
    }
}

$deck = new Deck();
$deck->import();

?>
