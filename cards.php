<?php

function array_inc(&$arr, $idx, $amt = 1) {
    if (isset($arr[$idx]))
        $arr[$idx] += $amt;
    else
        $arr[$idx] = $amt;
}

class WonderCard {
    protected $name;
    protected $color;
    protected $moneyCost;
    protected $resourceCost;
    protected $age;
    protected $numPlayers;
    protected $command;
    protected $playFunc;
    protected $freePrereq;

    static function parseFunc($command, $card, $player, $ignore_this){
        switch($card->getColor()){
            case 'red':
                $player->military += intval($command);
                break;

            case 'blue':
                $player->points += intval($command);
                break;

            case 'yellow':
                if($card->getAge() == 1){
                    if(preg_match('/[0-9]/', $command))
                        $player->addCoins(intval($command));
                    else {
                        $args = explode(' ', $command);
                        $directions = arrowsToDirection($args[0]);
                        $resources = getResourceCost($args[1]);
                        foreach($resources as $resource => $amount){
                            foreach($directions as $dir){
                                $player->discounts[$dir][$resource] = !isset($player->discounts[$dir][$resource]) ? 1
                                    : $player->discounts[$dir][$resource] + 1;
                            }
                        }
                    }
                } elseif($card->getAge() == 2){
                    // check for yellow non-buyable resources
                    if(strpos($command, '/') !== false){
                        $resources = getResourceCost($command);
                        $player->addResource(array_keys($resources), 1, false);
                    } else {
                        $args = explode(' ', $command);
                        $directions = arrowsToDirection($args[0]);
                        $coins = 0;
                        $color = $args[1];
                        $mult = intval($args[2]);
                        foreach($directions as $dir){
                            $pl = $dir == 'left' ? $player->leftPlayer : ($dir == 'right' ? $player->rightPlayer : $player);
                            foreach($pl->cardsPlayed as $c){
                                if($c->getColor() == $color) $coins += $mult;
                            }
                        }
                        $player->addCoins($coins);
                    }
                } elseif($card->getAge() == 3){
                    preg_match('/\((.)\)\{(.)\} (.+)?/', $command, $matches);
                    // when/how do we want to evaluate points??
                    $coins = $matches[1]; $color = $matches[3];
                    $coinsToGive = 0;
                    if($color == 'wonder'){
                        // check for wonder construction here
                    } else {
                        foreach($player->cardsPlayed as $card){
                            if($card->getColor() == $color){
                                $coinsToGive += $coins;
                            }
                        }
                    }
                    if($coinsToGive > 0) $player->addCoins($coinsToGive);
                }
                break;

            case 'green':
                $player->addScience(intval($command));
                break;

            case 'purple':
                if($card->getName() == 'Scientists Guild'){
                    $player->addScience(0);
                }
                // none of these give coins or anything, so don't need to check
                // until the end
                break;

            case 'brown': case 'grey':
                // CHECK FOR DOUBLE RESOURCE VS TWO OPTION RESOURCE
                $resources = WonderCards::csvResourceCost($command);
                if(strpos($command, '/') !== false){
                    $player->addResource(array_keys($resources), 1, true);
                } else {
                    foreach($resources as $resource => $amount){
                        $player->addResource($resource, $amount, true);
                    }
                }
                break;
        }
    }

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

    static function csvResourceCost($str){
        $resources = array(
            'S' => 'stone',
            'T' => 'wood',
            'W' => 'wood',
            'O' => 'ore',
            'C' => 'clay',
            'L' => 'linen',
            'G' => 'glass',
            'P' => 'paper'
        );
        $cost = array();
        for($i = 0; $i < strlen($str); $i++){
            if (isset($resources[$str[$i]]))
                array_inc($cost, $resources[$str[$i]]);
        }
        return $cost;
    }

    static function import($age, $csv) {
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
            $card->resourceCost = WonderCard::csvResourceCost($csv[1]);
            $card->numPlayers = $nplayers;
            if (isset($_ENV['DEBUG'])) {
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

    function getCommand(){
        return $this->command;
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

    function play($user, $args){
        if($this->moneyCost != 0)
            $user->addCoins(-1 * $this->moneyCost);
        // calculate resources/buying from neighbors here
        // include temp resources
        return WonderCard::parseFunc($this->command, $this, $user, $args);
    }

    public function __call($closure, $args)
    {
        call_user_func_array($this->$closure, $args);
    }
}

class WonderDeck {
    protected $cards = array();
    protected $guilds = array();
    protected $hands = array();

    public function addCards($cards){
        foreach ($cards as $card) {
            if ($card->isGuild()) {
                $this->guilds[] = $card;
            } else {
                $this->cards[] = $card;
            }
        }
    }

    public function cardInfo($cards, $players = array()){
        $info = array();
        foreach($cards as $id => $card){
            $cInfo = array(
                    'name' => $card->getName(),
                    'color' => $card->getColor(),
                    'id' => $id
                    );
            foreach($players as $pl){
                if($pl->getId() == $id and
                   ($pl->isTrashing or $pl->isBuildWonder))
                    $cInfo['trashing'] = true;
            }
            $info[] = $cInfo;
        }
        return $info;
    }

    public function deal($age, $players){
        $numplayers = count($players);
        $playableCards = array();

        if($age == 3){
            shuffle($this->guilds);
            for($i = 0; $i < $numplayers + 2; $i++){
                $playableCards[] = $guilds[$i];
            }
        }

        foreach($this->cards as $card) {
            if($card->getNumPlayers() <= $numplayers and
                    $card->getAge() == $age)
                $playableCards[] = $card;
        }

        shuffle($playableCards);

        foreach($players as $player){
            $hand = array_splice($playableCards, 0, 7);
            $this->hands[$player->getId()] = $hand;
            $player->hand = $hand;
            $packet = packet(array('cards' => $this->cardInfo($hand, array()), 'age' => $age), "hand");
            $player->sendString($packet);
        }
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
