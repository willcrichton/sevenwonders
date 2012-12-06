<?php

require_once('scoring.php');

function arrowsToDirection($str){
    $directions = array();
    for($i = 0; $i < strlen($str); $i++){
        $directions[] = $str[$i] == '<' ? 'left' : ($str[$i] == '>' ? 'right' : 'self');
    }
    return $directions;
}

class WonderCard {
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
        if ($fields[2] == 'purple')
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

    static function csvResources($str, $buyable){
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
        foreach (WonderCard::csvNumPlayers($csv) as $nplayers) {
            $card = new WonderCard();
            $card->age        = $age;
            $card->name       = $csv[3];
            $card->color      = $csv[2];
            $card->command    = $csv[4];
            $card->freePrereq = $csv[0];

            $card->moneyCost = preg_match('/([0-9])/', $csv[1], $matches) ?
                $matches[1] : 0;
            $card->resourceCost = WonderCard::csvResources($csv[1], true);
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
        return $this->color == 'purple';
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

    function getPrereq(){
        return $this->freePrereq;
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
        if($this->moneyCost != 0)
            $user->addCoins(-1 * $this->moneyCost);

        // calculate resources/buying from neighbors here
        // include temp resources

        switch($this->color){
            case 'red':
                $user->military->add(intval($this->command));
                break;

            case 'blue':
                $user->points += intval($this->command);
                break;

            case 'yellow':
                if ($this->getAge() == 1) {
                    if (preg_match('/[0-9]/', $this->command)) {
                        $user->addCoins(intval($this->command));
                    } else {
                        $args = explode(' ', $this->command);
                        $directions = arrowsToDirection($args[0]);
                        $resources = WonderCard::csvResources($args[1], false);
                        foreach ($resources as $resource) {
                            foreach ($directions as $dir) {
                                $user->addDiscount($dir, $resource);
                            }
                        }
                    }
                } elseif($this->getAge() == 2) {
                    // check for yellow non-buyable resources
                    if(strpos($this->command, '/') !== false){
                        $res = WonderCard::csvResources($this->command, false);
                        $user->addResource($res[0]);
                    } else {
                        $args = explode(' ', $this->command);
                        $directions = arrowsToDirection($args[0]);
                        $coins = 0;
                        $color = $args[1];
                        $mult = intval($args[2]);
                        foreach($directions as $dir){
                            $pl = $dir == 'left' ? $user->leftPlayer : ($dir == 'right' ? $user->rightPlayer : $user);
                            foreach($pl->cardsPlayed as $c){
                                if($c->getColor() == $color)
                                    $coins += $mult;
                            }
                        }
                        $user->addCoins($coins);
                    }
                } elseif($this->getAge() == 3) {
                    $coins = $this->thirdAgeYellowCoins();
                    $points = $this->thirdAgeYellowPoints();
                    $color = $this->thirdAgeYellowColor();
                    // Be sure to count this yellow card for coin increase if
                    // we get coins per yellow card.
                    $coinsToGive = ($color == 'yellow' ? $coins : 0);
                    if ($color == 'wonder') {
                        // TODO: check for wonder construction here
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

            case 'green':
                $user->science->add(intval($command));
                break;

            case 'purple':
                if ($this->getName() == 'Scientists Guild') {
                    $user->addScience(Science::ANY);
                }
                // none of these give coins or anything, so don't need to check
                // until the end
                break;

            case 'brown': case 'grey':
                foreach (WonderCard::csvResources($this->command, true) as $r)
                    $user->addResource($r);
                break;
        }
    }

    public function points(Player $player) {
        if ($this->age != 3)
            return 0;
        if ($this->color == 'yellow') {
            $mult = $card->thirdAgeYellowPoints();
            $color = $card->thirdAgeYellowColor();
            $sum = 0;
            if($color == 'wonder'){
                // TODO: check for wonders here
            } else {
                foreach($player->cardsPlayed as $c){
                    if ($c->color == $color)
                        $sum += $mult;
                }
            }
            return $sum;
        }
        if (!$card->isGuild() || $card->getName() == "Scientists Guild")
            return 0;

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
                    // TODO: check for wonder here
                    break;
                // $mult points for each brown/grey/blue card
                case 'brown,grey,blue':
                    foreach (explode(',', $color) as $subcolor) {
                        foreach ($pl->cardsPlayed as $c) {
                            if ($c->getColor() == $color)
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
    }

    public function json() {
        return array(
            'name' => $this->getName(),
            'color' => $this->getColor()
        );
    }
}

class WonderDeck {
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
            $this->addCards(WonderCard::import($age, str_getcsv($line)));
        }
    }
}

$deck = new WonderDeck();
$deck->import();

?>
